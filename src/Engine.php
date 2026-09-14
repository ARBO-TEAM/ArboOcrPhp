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
     *   noDownload?: bool,
     *   modelsUrl?: string,
     *   minDetBoxArea?: float,
     *   spaceRecovery?: bool,
     *   enableCpuMemArena?: bool,
     * } $options 'binPath' is normally a single executable path (string).
     *   'minConfidence', 'recBatchNum', 'detLimitSideLen', 'wordBoxes' and
     *   'logLevel' require arboOCR >= v0.2.0. 'wordBoxes' adds a per-line
     *   `words` array to the JSON, surfaced as LineResult::$words.
     *   'noDownload' and 'modelsUrl' drive model auto-download and require
     *   arboOCR >= v0.3.0 — which is what the pinned tag installs, see
     *   Installer::pinnedVersion(). Both stay strictly opt-in: leave them out
     *   (the default) and flagsFromOptions() emits nothing for them at all,
     *   which is what keeps this class working when 'binPath' points at a
     *   pre-v0.3.0 build, whose parser exits 1 on an unknown option.
     *   'minDetBoxArea', 'spaceRecovery' and 'enableCpuMemArena' require
     *   arboOCR >= v0.4.0, and are opt-in for that same reason: a build older
     *   than v0.4.0 does not know these flags and rejects them outright.
     *   'minDetBoxArea' is a float where 0 is a real setting (it disables the
     *   box-area cut), so it rides $scalarMap — which keys off
     *   array_key_exists and therefore emits an explicit 0.0 while staying
     *   silent when the option is absent. The two booleans deliberately do
     *   NOT ride $boolMap, which would emit "--flag=false" for an explicit
     *   false; they emit a single "--flag=true" token only when true, and
     *   nothing at all otherwise.
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
        $argv = [...$this->binCommand, '--image', $imagePath, '--json', ...$this->flagsFromOptions()];
        [$stdout, $stderr, $exitCode] = $this->runBinary($argv);

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
     * Prefetches the models for the configured 'ocrVersion'/'modelType' into
     * arboOCR's own model cache by running `arboocr_demo --download-models`,
     * which downloads and exits without doing any OCR — so it takes no image.
     * Call it from a Docker build step or at process startup so the first
     * recognize() doesn't pay for the download mid-request.
     *
     * Deliberately the same shape as Installer::run() is for the binary: one
     * blocking call, no progress reporting, idempotent — an already-cached
     * model is a no-op. The binary's own per-file precedence still applies: an
     * explicit 'detModelPath'/'clsModelPath'/'recModelPath'/'dictPath' is used
     * as given and never substituted by a download, a file already sitting in
     * 'modelsDir' wins without touching the network, and only then is the file
     * fetched and SHA-256 verified.
     *
     * Requires arboOCR >= v0.3.0, the release that added model auto-download —
     * which is the pinned tag (see Installer::pinnedVersion()), so this works
     * against the installed binary out of the box. A pre-v0.3.0 build supplied
     * via 'binPath' has no --download-models flag and answers with a usage
     * error and exit 1, surfaced as an OcrException.
     *
     * @return string The binary's per-file status report (stdout), one line
     *   per model file.
     *
     * @throws OcrException if the process can't be started or exits non-zero.
     */
    public function ensureModels(): string
    {
        $argv = [...$this->binCommand, '--download-models', ...$this->flagsFromOptions()];
        [$stdout, $stderr, $exitCode] = $this->runBinary($argv);

        if ($exitCode !== 0) {
            throw new OcrException(
                "arboocr_demo --download-models exited with code {$exitCode}",
                $exitCode,
                $stderr,
            );
        }

        return trim($stdout);
    }

    /**
     * Runs the binary once and returns [stdout, stderr, exitCode]. Shared by
     * recognize() and ensureModels() so both get the identical process
     * handling — in particular the stderr-to-file trick below, which is not
     * optional on any platform.
     *
     * @param list<string> $argv
     * @return array{0: string, 1: string, 2: int}
     *
     * @throws OcrException if the binary is missing or the process can't start.
     */
    private function runBinary(array $argv): array
    {
        // Only check is_file() for the plain-single-path form — an array
        // binCommand's first element (e.g. PHP_BINARY) is a command name
        // resolved via PATH, not necessarily a direct file path.
        if (count($this->binCommand) === 1 && !is_file($this->binCommand[0])) {
            throw new OcrException("arboocr_demo binary not found at {$this->binCommand[0]}. "
                . "Run 'composer install' or pass 'binPath' explicitly.");
        }

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

        return [$stdout, $stderr, $exitCode];
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
            // v0.4.0. Rides this map rather than the bool one because 0 is a
            // meaningful value (it disables the cut) and array_key_exists is
            // what tells "passed 0.0" apart from "not passed at all".
            'minDetBoxArea' => 'min-det-box-area',
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

        // Model auto-download passthroughs. These deliberately do NOT ride
        // $scalarMap/$boolMap, which key off "was the option supplied at all":
        // an explicit 'noDownload' => false would then emit
        // "--no-download=false", and 'modelsUrl' => '' an empty --models-url.
        // Both flags postdate the pinned arboOCR release entirely, and its
        // cxxopts parser exits 1 on an unknown option — so anything short of a
        // real opt-in has to put nothing on the argv, or every call against the
        // pinned binary breaks, including from callers who never asked for
        // anything to do with downloads.
        if (!empty($this->options['noDownload'])) {
            // Single-token "=" form, same as the bool flags above.
            $argv[] = '--no-download=true';
        }
        $modelsUrl = (string) ($this->options['modelsUrl'] ?? '');
        if ($modelsUrl !== '') {
            $argv[] = '--models-url';
            $argv[] = $modelsUrl;
        }

        // The two v0.4.0 booleans, same opt-in-only shape as --no-download
        // above and for the same reason: an older binary treats them as
        // unknown options and exits 1. They stay out of $boolMap because that
        // loop emits "--flag=false" whenever the key is present, and false is
        // already the binary's own default — restating it would only break
        // pre-v0.4.0 callers for no gain.
        if (!empty($this->options['spaceRecovery'])) {
            $argv[] = '--space-recovery=true';
        }
        if (!empty($this->options['enableCpuMemArena'])) {
            $argv[] = '--enable-cpu-mem-arena=true';
        }

        return $argv;
    }
}
