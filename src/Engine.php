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
     * } $options 'binPath' is normally a single executable path (string).
     *   'minConfidence', 'recBatchNum', 'detLimitSideLen', 'wordBoxes' and
     *   'logLevel' require arboOCR >= v0.2.0. 'wordBoxes' adds a per-line
     *   `words` array to the JSON, surfaced as LineResult::$words.
     *   'noDownload' and 'modelsUrl' drive model auto-download and need the
     *   arboOCR release that adds it — newer than the pinned tag, see
     *   Installer::pinnedVersion(). Both are strictly opt-in: leave them out
     *   (the default) and flagsFromOptions() emits nothing for them at all,
     *   which is exactly what keeps this class working against the pinned
     *   binary, whose parser exits 1 on an unknown option.
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
     * Requires the arboOCR release that adds model auto-download — newer than
     * the pinned tag (see Installer::pinnedVersion()). The pinned binary has
     * no --download-models flag and answers with a usage error and exit 1, so
     * until that pin is bumped this only works against a newer binary supplied
     * via the 'binPath' option.
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

        return $argv;
    }
}
