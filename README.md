# arbo-ocr-php

PHP wrapper for [arboOCR](https://github.com/wafik/ArboOCR) — runs the
prebuilt `arboocr_demo` binary via `proc_open`, no C++ build required.

## Install

```bash
composer require arbo/ocr-php
```

On install, a Composer hook downloads the matching arboOCR release binary
(Windows or Linux, auto-detected) into `bin/<platform>/`. This package is
pinned to
[`v0.4.0`](https://github.com/wafik/ArboOCR/releases/tag/v0.4.0)
via `extra.arboocr-version` in `composer.json`, and the auto-download is
live and verified working end to end — no manual binary step needed. If it
fails anyway (offline install, unsupported OS), download a release manually
from the
[arboOCR releases page](https://github.com/wafik/ArboOCR/releases) and pass
`binPath` explicitly (see below).

The pin is deliberate and required: `Engine`'s flag mapping and the JSON
parsing are written against one specific `arboocr_demo` CLI contract. If
`extra.arboocr-version` is missing, the installer reports a clear
misconfiguration error instead of guessing at a "latest" release (it still
won't fail your `composer install`).

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

    // arboOCR >= v0.2.0:
    // 'minConfidence' => 0.5,     // drop lines scoring below this
    // 'recBatchNum' => 8,         // recognizer batch size
    // 'detLimitSideLen' => 960,   // detector input long-side limit
    // 'wordBoxes' => true,        // also emit per-word boxes
    // 'logLevel' => 'debug',      // arboocr_demo is silent otherwise
]);

$result = $engine->recognize('/path/to/image.jpg');

echo $result->backend, "\n";       // cpu / cuda / tensorrt
foreach ($result->lines as $line) {
    echo $line->text, ' (', $line->score, ")\n";
    foreach ($line->words as $word) {   // empty unless 'wordBoxes' => true
        echo '  ', $word['text'], "\n";
    }
}
```

An empty `$result->lines` array means no text was found — not an error.
`Engine::recognize()` throws `Arbo\Ocr\OcrException` only when the process
itself fails to start, exits non-zero, or produces unparseable output. The
exception carries the exit code (`$e->exitCode` — v0.2.0 added code `2` for
model-load / recognition failure) and whatever the binary wrote to stderr
(`$e->stderr`). As of v0.2.0 `arboocr_demo` writes nothing to stderr unless
you pass `logLevel`, and output on stderr is never on its own treated as a
failure.

### Many images in one process — `recognizeBatch`

`recognize()` starts a fresh `arboocr_demo` for every image, and the process
start plus model load dominates a short page. `recognizeBatch()` runs **one**
process over a whole list instead:

```php
$pages = $engine->recognizeBatch(['/scans/001.jpg', '/scans/002.jpg', '/scans/003.jpg']);

foreach ($pages as $i => $page) {
    echo $page->image, ': ', count($page->lines), " lines\n";
    // $pages[$i] belongs to the $i-th path passed in
}
```

Over 5 SROIE receipts the saving measured 13.0% of wall time at `tiny`, 28.5%
at `small` and 13.6% at `medium`, with identical text on every image
(`bench_batch_go.py` in the internal `compare/` harness). That share is
`(process start + model load) / total`, so it moves with the model size and
the list length rather than being a fixed percentage.

Results are matched to inputs **by position**, and the count must agree —
`arboocr_demo` reports only an image's basename, so two same-named files in
different directories would be indistinguishable. A mismatch throws rather
than returning a shifted list. For the same reason a path that cannot survive
the newline-delimited list format (empty, containing a newline, or starting
with `#`, which the binary reads as a comment and would skip) is rejected
before anything runs.

A batch exits `1` when *any* image came back with no text. That is an ordinary
outcome, not a failure, and is tolerated as long as the JSON array is still on
stdout — a usage error (unknown flag) exits `1` too but leaves stdout empty,
and that one throws.

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
