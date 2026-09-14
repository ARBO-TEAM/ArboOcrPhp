<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * Runs the prebuilt arboocr_demo binary via proc_open and parses its
 * --json output. Requires no C++ build — only the binary the Installer
 * downloaded (or one you point at manually via the 'binPath' option).
 */
final class Engine
{
    /** @var list<string> */
    private array $binCommand;
    private array $options;

    /**
     * @param array{
     *   binPath?: string|list<string>,
     *   modelsDir?: string,
     *   ocrVersion?: string,
     *   modelType?: string,
     *   useAngleCls?: bool,
     *   useCuda?: bool,
     *   useTensorrt?: bool,
     *   useFp16?: bool,
     *   useClahe?: bool,
     *   detModelPath?: string,
     *   clsModelPath?: string,
     *   recModelPath?: string,
     *   dictPath?: string,
     *   minConfidence?: float,
     *   recBatchNum?: int,
     *   detLimitSideLen?: int,
     *   wordBoxes?: bool,
     *   logLevel?: string,
     * } $options 'binPath' is normally a single executable path (string).
     *   'minConfidence', 'recBatchNum', 'detLimitSideLen', 'wordBoxes' and
     *   'logLevel' require arboOCR >= v0.2.0. 'wordBoxes' adds a per-line
     *   `words` array to the JSON, surfaced as LineResult::$words.
     *   arboocr_demo is silent on stderr unless 'logLevel' is set; captured
     *   stderr is only ever attached to OcrException, never treated as a
     *   failure signal on its own.
     *   An array form (e.g. [PHP_BINARY, 'script.php']) is also accepted
     *   for wrapping a non-directly-executable command — used by the test
     *   suite to invoke a fake binary through the PHP interpreter.
     */
    public function __construct(array $options = [])
    {
        $bin = $options['binPath'] ?? self::defaultBinPath();
        $this->binCommand = is_array($bin) ? $bin : [$bin];
        unset($options['binPath']);
        $this->options = $options;
    }

    public static function defaultBinPath(): string
    {
        $platform = Installer::detectPlatform();
        $binName = $platform === 'windows-x64' ? 'arboocr_demo.exe' : 'arboocr_demo';
        return __DIR__ . '/../bin/' . ($platform ?? 'linux-x64') . '/' . $binName;
    }

    /**
     * @throws OcrException if the process can't be started, exits non-zero,
     *   or its stdout isn't valid JSON. An empty PageResult::$lines is a
     *   normal, successful result — not an exception.
     */
    public function recognize(string $imagePath): PageResult
    {
        // Only check is_file() for the plain-single-path form — an array
        // binCommand's first element (e.g. PHP_BINARY) is a command name
        // resolved via PATH, not necessarily a direct file path.
        if (count($this->binCommand) === 1 && !is_file($this->binCommand[0])) {
            throw new OcrException("arboocr_demo binary not found at {$this->binCommand[0]}. "
                . "Run 'composer install' or pass 'binPath' explicitly.");
        }

        $argv = [...$this->binCommand, '--image', $imagePath, '--json', ...$this->flagsFromOptions()];

        // stderr goes to a temp file, not a pipe: arboocr_demo can write
        // well past a pipe's OS buffer (ONNXRuntime schema-registration
        // warnings) before producing any stdout, and reading two proc_open
        // pipes without deadlocking needs select() — which on Windows
        // doesn't support pipe handles (stream_select() returns bogus
        // immediate-ready results for them). A file sidesteps this on every
        // platform: the child never blocks writing it, so stdout alone is
        // safe to read to completion afterward.
        $stderrFile = tempnam(sys_get_temp_dir(), 'arboocr-stderr-');
        if ($stderrFile === false) {
            throw new OcrException('Could not create temp file for stderr capture');
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']];
        $process = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($stderrFile);
            throw new OcrException('Could not start process: ' . implode(' ', $this->binCommand));
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $stderr = (string) file_get_contents($stderrFile);
        @unlink($stderrFile);

        if ($exitCode !== 0) {
            throw new OcrException(
                "arboocr_demo exited with code {$exitCode}",
                $exitCode,
                $stderr,
            );
        }

        return PageResult::fromJson(trim($stdout));
    }

    /**
     * OCR many images with **one** arboocr_demo process
     * (`--images-from <list> --json`) and return one PageResult per input, in
     * input order.
     *
     * recognize() starts a fresh process per image, and the process start
     * plus model load dominates a short page; this pays it once for the whole
     * list instead.
     *
     * Results are matched to inputs **by position** because the binary
     * reports only a basename. That is sound only while the counts agree, so a
     * mismatch throws rather than returning a shifted list.
     *
     * A batch exits 1 when *any* image came back empty. That is an ordinary
     * outcome, not a failure, and is tolerated as long as the JSON array is
     * still on stdout — a usage error exits 1 too but leaves stdout empty, and
     * that one throws.
     *
     * @param list<string> $imagePaths
     * @return list<PageResult>
     * @throws OcrException if the process can't be started, fails for any
     *   reason other than the exit-1-with-JSON case above, or its stdout isn't
     *   a JSON array of the same length as $imagePaths.
     */
    public function recognizeBatch(array $imagePaths): array
    {
        if ($imagePaths === []) {
            return [];
        }

        if (count($this->binCommand) === 1 && !is_file($this->binCommand[0])) {
            throw new OcrException("arboocr_demo binary not found at {$this->binCommand[0]}. "
                . "Run 'composer install' or pass 'binPath' explicitly.");
        }

        // The list file is newline-delimited, and the binary skips blank lines
        // and '#' lines as comments. A path in either shape would be dropped
        // silently and shift every later result onto the wrong input, so it is
        // rejected up front rather than mis-attributed later.
        foreach ($imagePaths as $i => $path) {
            if ($path === '') {
                throw new OcrException("recognizeBatch: imagePaths[{$i}] is empty");
            }
            if (strpbrk($path, "\r\n") !== false) {
                throw new OcrException("recognizeBatch: imagePaths[{$i}] contains a newline, "
                    . "which the image list format cannot represent: {$path}");
            }
            if (str_starts_with(ltrim($path, " \t"), '#')) {
                throw new OcrException("recognizeBatch: imagePaths[{$i}] starts with '#', "
                    . "which arboocr_demo reads as a comment and would skip: {$path}");
            }
        }

        $listFile = tempnam(sys_get_temp_dir(), 'arboocr-list-');
        if ($listFile === false) {
            throw new OcrException('Could not create temp file for the image list');
        }
        if (file_put_contents($listFile, implode("\n", $imagePaths) . "\n") === false) {
            @unlink($listFile);
            throw new OcrException('Could not write the image list');
        }

        $argv = [...$this->binCommand, '--images-from', $listFile, '--json', ...$this->flagsFromOptions()];

        // Same stderr-to-a-file rationale as recognize(): a full stderr pipe
        // would block the child while we read stdout.
        $stderrFile = tempnam(sys_get_temp_dir(), 'arboocr-stderr-');
        if ($stderrFile === false) {
            @unlink($listFile);
            throw new OcrException('Could not create temp file for stderr capture');
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['file', $stderrFile, 'w']];
        $process = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($process)) {
            @unlink($listFile);
            @unlink($stderrFile);
            throw new OcrException('Could not start process: ' . implode(' ', $this->binCommand));
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $stderr = (string) file_get_contents($stderrFile);
        @unlink($listFile);
        @unlink($stderrFile);

        $trimmed = trim($stdout);
        if ($exitCode !== 0 && !($exitCode === 1 && str_starts_with($trimmed, '['))) {
            throw new OcrException(
                "arboocr_demo exited with code {$exitCode}",
                $exitCode,
                $stderr,
            );
        }

        $pages = json_decode($trimmed, true);
        if (!is_array($pages)) {
            throw new OcrException(
                'arboocr_demo --images-from produced unparseable output: ' . substr($trimmed, 0, 500)
            );
        }

        // Count first: every later check is positional, so a short or long
        // array has to fail here rather than shift text onto the wrong file.
        if (count($pages) !== count($imagePaths)) {
            throw new OcrException(sprintf(
                'arboocr_demo returned %d results for %d images; cannot match results to inputs by position',
                count($pages),
                count($imagePaths),
            ));
        }

        foreach ($pages as $i => $page) {
            if (!is_array($page) || !isset($page['lines']) || !is_array($page['lines'])) {
                throw new OcrException(
                    "arboocr_demo --images-from element {$i} has no 'lines' array: "
                    . substr(json_encode($page), 0, 500)
                );
            }
        }

        return array_map(static fn (array $page) => PageResult::fromArray($page), array_values($pages));
    }

    /** @return list<string> */
    private function flagsFromOptions(): array
    {
        // Scalar options, emitted as a "--flag" + "value" pair. Ints and
        // floats are cast to string here; PHP 8's float-to-string conversion
        // is locale-independent, so minConfidence 0.5 is always "0.5" and
        // never "0,5" under a comma-decimal locale.
        $scalarMap = [
            'modelsDir' => 'models-dir',
            'ocrVersion' => 'ocr-version',
            'modelType' => 'model-type',
            'detModelPath' => 'det-model',
            'clsModelPath' => 'cls-model',
            'recModelPath' => 'rec-model',
            'dictPath' => 'dict',
            'minConfidence' => 'min-confidence',
            'recBatchNum' => 'rec-batch-num',
            'detLimitSideLen' => 'det-limit-side-len',
            'logLevel' => 'log-level',
        ];
        $boolMap = [
            'useAngleCls' => 'angle',
            'useCuda' => 'cuda',
            'useTensorrt' => 'tensorrt',
            'useFp16' => 'fp16',
            'useClahe' => 'clahe',
            'wordBoxes' => 'word-boxes',
        ];

        $argv = [];
        foreach ($scalarMap as $optKey => $cliFlag) {
            if (array_key_exists($optKey, $this->options)) {
                $argv[] = "--{$cliFlag}";
                $argv[] = (string) $this->options[$optKey];
            }
        }
        foreach ($boolMap as $optKey => $cliFlag) {
            if (array_key_exists($optKey, $this->options)) {
                // cxxopts only binds a bool flag's value via "=" — a bare
                // "--flag" followed by a separate "true"/"false" token
                // leaves the flag implicitly true and the value ignored.
                $argv[] = "--{$cliFlag}=" . ($this->options[$optKey] ? 'true' : 'false');
            }
        }
        return $argv;
    }
}
