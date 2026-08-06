# arbo-ocr-php

PHP wrapper for [arboOCR](https://github.com/wafik/ArboOCR) — runs the
prebuilt `arboocr_demo` binary via `proc_open`, no C++ build required.

## Install

```bash
composer require arbo/ocr-php
```

On install, a Composer hook downloads the matching arboOCR release binary
(Windows or Linux, auto-detected) into `bin/<platform>/`. As of
[`v0.1.0-php1`](https://github.com/wafik/ArboOCR/releases/tag/v0.1.0-php1)
(published), this auto-download is live and verified working end to end —
no manual binary step needed. If it fails anyway (offline install,
unsupported OS), download a release manually from the
[arboOCR releases page](https://github.com/wafik/ArboOCR/releases) and pass
`binPath` explicitly (see below).

You also need the OCR models — arboOCR does not bundle them. See
[Models](#models) below for exactly which files each `modelType` needs and
where to get them.

## Models

arboOCR doesn't bundle OCR models — you point `modelsDir` at a folder of
PP-OCRv6 ONNX files. Only the recognizer has size variants; the detector is
always one file regardless of `modelType`:

| File | Needed for | Varies by `modelType`? |
|---|---|---|
| `PP-OCRv6_det.onnx` | detection | no — always this one file |
| `PP-OCRv6_rec_tiny.onnx` + `PP-OCRv6_rec_tiny_dict.txt` | `modelType: 'tiny'` | yes |
| `PP-OCRv6_rec_small.onnx` + `PP-OCRv6_rec_small_dict.txt` | `modelType: 'small'` (default) | yes |
| `PP-OCRv6_rec_medium.onnx` + `PP-OCRv6_rec_medium_dict.txt` | `modelType: 'medium'` | yes |
| `PP-OCRv6_cls.onnx` | angle classification, only if `useAngleCls` | no |

You only need the recognizer size(s) you'll actually use — e.g. for
`modelType: 'small'` alone, `modelsDir` just needs `PP-OCRv6_det.onnx` +
`PP-OCRv6_rec_small.onnx` + `PP-OCRv6_rec_small_dict.txt`. Switching sizes
later is just changing `modelType`; `modelsDir` can hold all three sizes
side by side if you want to switch freely.

**Getting the files** — arboOCR doesn't host default download URLs (see its
own [Models section](https://github.com/wafik/ArboOCR#models)), so pick
whichever applies:
- Already have a Python `rapidocr` install? Copy its `models/` directory
  over, renaming files to match the layout above.
- Have your own PP-OCRv6 ONNX export? Place/rename the files as above.
- A local arboOCR checkout's `models/` directory already has the detector,
  classifier, and all three recognizer sizes — handy for local dev (see the
  tiny-model example below).

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

## Benchmark

`arbo-ocr-php` was compared against arbo-ocr-go and arbo-ocr-rust on the
same 5-image SROIE smoke set — all three call the identical `arboocr_demo`
binary, so accuracy is the same across all three; this measures wrapper
overhead only (subprocess spawn − arboocr_demo's own reported time):

| Size | arbo-php | arbo-go | arbo-rust |
|--------|----------:|---------:|-----------:|
| tiny | 193 ms | 137 ms | 131 ms |
| small | 231 ms | 171 ms | 172 ms |
| medium | 303 ms | 248 ms | 249 ms |

PHP's overhead is consistently ~55–65ms higher than Go/Rust — `php.exe`
interpreter startup on top of `proc_open`, vs. a compiled binary paying
only process-spawn cost. Same accuracy across all three; all three match
or beat a PP-OCRv6-based Node/Bun reference implementation on this sample
at every size. Full methodology in the "wrapper benchmark" section of the
internal `compare/RESULTS.md` companion doc (not published in this repo).

## License

Apache-2.0
