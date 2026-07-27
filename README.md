# arbo-ocr-php

PHP wrapper for [arboOCR](https://github.com/wafik/ArboOCR) — runs the
prebuilt `arboocr_demo` binary via `proc_open`, no C++ build required.

## Install

```bash
composer require arbo/ocr-php
```

On install, a Composer hook downloads the matching arboOCR release binary
(Windows or Linux, auto-detected) into `bin/<platform>/`. If the auto-download
fails (offline install, unsupported OS), download a release manually from
the [arboOCR releases page](https://github.com/wafik/ArboOCR/releases) and
pass `binPath` explicitly (see below).

You also need the OCR models — arboOCR does not bundle them. See
[arboOCR's Models section](https://github.com/wafik/ArboOCR#models) for
download instructions, then point `modelsDir` at the folder.

## Usage

```php
use Arbo\Ocr\Engine;

$engine = new Engine([
    'modelsDir' => '/path/to/models',
    // 'binPath' => '/custom/path/to/arboocr_demo', // optional override
    // 'modelType' => 'small', // tiny/small/medium — default small
    // 'useAngleCls' => true,
    // 'useCuda' => true,
]);

$result = $engine->recognize('/path/to/image.jpg');

echo $result->backend, "\n";       // cpu / cuda / tensorrt
foreach ($result->lines as $line) {
    echo $line->text, ' (', $line->score, ")\n";
}
```

An empty `$result->lines` array means no text was found — not an error.
`Engine::recognize()` throws `Arbo\Ocr\OcrException` only when the process
itself fails to start, exits non-zero, or produces unparseable output.

## Quick example (tiny model, fastest)

For a fast local smoke test, use `modelType: 'tiny'` — the smallest/fastest
PP-OCRv6 recognizer. If you have an arboOCR checkout handy, its `models/`
folder already contains the tiny det/rec/cls ONNX files (no extra download):

```php
use Arbo\Ocr\Engine;

$engine = new Engine([
    'modelsDir' => '/path/to/arboOCR/models', // e.g. a local arboOCR checkout's models/ dir
    'modelType' => 'tiny',
]);

$result = $engine->recognize('/path/to/receipt.jpg');

printf("backend=%s lines=%d elapsedMs=%.1f\n", $result->backend, count($result->lines), $result->elapsedMs);
foreach ($result->lines as $line) {
    printf("  %-40s score=%.3f\n", $line->text, $line->score);
}
```

The `tiny` model trades some accuracy for speed — good for quick local
testing; switch to `small` (the default) or `medium` for production-quality
recognition.

## How it works

This package never builds or vendors arboOCR's C++ source. It downloads a
prebuilt, self-contained binary (binary + required shared libraries, no
source, no ONNX models) from arboOCR's GitHub Releases, and calls it as a
subprocess per image with a `--json` flag, parsing the JSON result. See
arboOCR's [`docs/superpowers/specs/2026-07-27-php-integration-design.md`](https://github.com/wafik/ArboOCR/blob/main/docs/superpowers/specs/2026-07-27-php-integration-design.md)
for the full design.

## License

Apache-2.0
