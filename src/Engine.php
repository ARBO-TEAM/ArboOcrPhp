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
     * } $options 'binPath' is normally a single executable path (string).
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

        $argv = [...$this->binCommand, '--image', $imagePath, '--json'];
        foreach ($this->flagsFromOptions() as $flag => $value) {
            $argv[] = "--{$flag}";
            $argv[] = $value;
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

        if ($exitCode !== 0) {
            throw new OcrException(
                "arboocr_demo exited with code {$exitCode}",
                $exitCode,
                $stderr,
            );
        }

        return PageResult::fromJson(trim($stdout));
    }

    /** @return array<string, string> */
    private function flagsFromOptions(): array
    {
        $map = [
            'modelsDir' => 'models-dir',
            'ocrVersion' => 'ocr-version',
            'modelType' => 'model-type',
            'useAngleCls' => 'angle',
            'useCuda' => 'cuda',
            'useTensorrt' => 'tensorrt',
            'useFp16' => 'fp16',
            'useClahe' => 'clahe',
            'detModelPath' => 'det-model',
            'clsModelPath' => 'cls-model',
            'recModelPath' => 'rec-model',
            'dictPath' => 'dict',
        ];

        $flags = [];
        foreach ($map as $optKey => $cliFlag) {
            if (!array_key_exists($optKey, $this->options)) {
                continue;
            }
            $value = $this->options[$optKey];
            $flags[$cliFlag] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        return $flags;
    }
}
