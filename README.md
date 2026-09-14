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
via `extra.arboocr-version` in `composer.json`, and this *binary*
auto-download is live and verified working end to end — no manual binary step
needed. If it fails anyway (offline install, unsupported OS), download a
release manually from the
[arboOCR releases page](https://github.com/wafik/ArboOCR/releases) and pass
`binPath` explicitly (see below).

The pin is deliberate and required: `Engine`'s flag mapping and the JSON
parsing are written against one specific `arboocr_demo` CLI contract. If
`extra.arboocr-version` is missing, the installer reports a clear
misconfiguration error instead of guessing at a "latest" release (it still
won't fail your `composer install`).

The OCR models are not bundled either, but as of the pinned `v0.4.0` binary
they are no longer a manual step: a model that isn't already on disk is
downloaded, SHA-256 verified and cached on first run. See [Models](#models)
below for exactly which files each `modelType` uses, and for the ways to
supply them yourself when you'd rather not touch the network.

## Models

arboOCR doesn't bundle OCR models, but the pinned `v0.4.0` binary fetches the
ones it needs itself — see [Automatic download](#automatic-download) below.
Pointing `modelsDir` at a folder of PP-OCRv6 ONNX files is now optional: it is
how you keep a run fully offline, or pin an exact set of weights. Only the
recognizer has size variants; the detector is always one file regardless of
`modelType`:

| File | Needed for | Varies by `modelType`? |
|---|---|---|
| `PP-OCRv6_det.onnx` | detection | no — always this one file |
| `PP-OCRv6_rec_tiny.onnx` + `PP-OCRv6_rec_tiny_dict.txt` | `modelType: 'tiny'` | yes |
| `PP-OCRv6_rec_small.onnx` + `PP-OCRv6_rec_small_dict.txt` | `modelType: 'small'` (default) | yes |
| `PP-OCRv6_rec_medium.onnx` + `PP-OCRv6_rec_medium_dict.txt` | `modelType: 'medium'` | yes |
| `PP-OCRv6_cls.onnx` | angle classification, only if `useAngleCls` | no |

Only the recognizer size(s) you actually use get fetched — e.g. for
`modelType: 'small'` alone that's `PP-OCRv6_det.onnx` +
`PP-OCRv6_rec_small.onnx` + `PP-OCRv6_rec_small_dict.txt`. Switching sizes
later is just changing `modelType`, which pulls that size on next use; a
`modelsDir` can hold all three sizes side by side if you want to switch
freely with no network at all.

**Supplying the files yourself** — worth doing when you want a run that
provably never reaches the network, or a build baked with exact weights.
A file already present in `modelsDir` always wins over a download, so any of
these is enough:
- Already have a Python `rapidocr` install? Copy its `models/` directory
  over, renaming files to match the layout above.
- Have your own PP-OCRv6 ONNX export? Place/rename the files as above.
- A local arboOCR checkout's `models/` directory already has the detector,
  classifier, and all three recognizer sizes — handy for local dev (see the
  tiny-model example below).
- Prefetch into the cache ahead of time with `Engine::ensureModels()` (below)
  — a Docker build step, say — so runtime never downloads anything.

### Automatic download

The pinned `v0.4.0` binary fetches any model it's missing and verifies it by
SHA-256 before use, which is what makes `modelsDir` optional. Files come from
`https://github.com/ARBO-TEAM/arbo-ocr-models/releases/download/models-v1/`
unless you point it elsewhere. Its precedence, per file:

1. An explicit model path (`detModelPath`, `clsModelPath`, `recModelPath`,
   `dictPath`) is used exactly as given and is never substituted by a
   download.
2. Otherwise a file already sitting in `modelsDir` wins — zero network.
3. Only then is the file downloaded into the model cache and verified.

Two `Engine` options steer it. Both are strictly opt-in — omit them, or leave
them at their falsey default, and no CLI flag is emitted at all, which is what
keeps this package working when `binPath` points at a pre-`v0.3.0` build (an
unknown option makes those exit 1 with a usage error):

| Option | CLI flag | Meaning |
|---|---|---|
| `noDownload` (bool) | `--no-download` | Never fetch a missing model — fail instead. For runs that must not silently reach the network. |
| `modelsUrl` (string) | `--models-url <url>` | Directory URL to fetch missing models from, e.g. an internal mirror, instead of the default upstream location. |

`Engine::ensureModels()` prefetches the models for the configured
`ocrVersion`/`modelType` so the first `recognize()` doesn't pay for the
download — useful in a Docker build step or at process startup:

```php
use Arbo\Ocr\Engine;

$engine = new Engine([
    'modelType' => 'small',
]);

echo $engine->ensureModels();  // per-file status report, one line per model
```

It runs `arboocr_demo --download-models`, which downloads and exits without
doing any OCR, and returns the binary's per-file status report. Deliberately
the same shape as the Composer install hook is for the binary: one blocking
call, no progress reporting, idempotent — an already-cached model is a no-op.
If you override `binPath` with a pre-`v0.3.0` build it throws an
`Arbo\Ocr\OcrException` carrying that binary's usage error
(`$e->exitCode === 1`), since the flag doesn't exist there.

### Environment variables

`recognize()` and `ensureModels()` run `arboocr_demo` as a child process, so
it inherits the parent's environment. These need no option, and — like the
options above — need arboOCR >= `v0.3.0`, which the pinned tag satisfies:

| Variable | Effect |
|---|---|
| `ARBOOCR_OFFLINE=1` | Never download a missing model — the environment form of `noDownload`. |
| `ARBOOCR_CACHE_DIR` | Override the model cache directory (below); models land in `$ARBOOCR_CACHE_DIR/models-v1`. |
| `ARBOOCR_MODELS_URL` | Directory URL to fetch missing models from — the environment form of `modelsUrl`. |

### Model cache directory

Downloaded models land in a tag-scoped cache directory, so a future change to
the model set is a cache miss rather than a silent stale hit — the same
reasoning behind pinning the binary to a release tag:

| OS | Path |
|---|---|
| Windows | `%LOCALAPPDATA%\arboOCR\models\models-v1` |
| macOS | `~/Library/Caches/arboOCR/models/models-v1` |
| Linux | `$XDG_CACHE_HOME/arboOCR/models/models-v1`, or `~/.cache/arboOCR/models/models-v1` when `XDG_CACHE_HOME` is unset |

That is arboOCR's own model cache, separate from this package's binary
directory at `bin/<platform>/`. The macOS row applies only to a binary you
supply yourself: `Installer::detectPlatform()` covers Windows and Linux x64
only, matching the published release assets.

## Usage

```php
use Arbo\Ocr\Engine;

$engine = new Engine([
    // 'modelsDir' => '/path/to/models', // optional — missing models are fetched
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

    // arboOCR >= v0.3.0 — see "Automatic download" above:
    // 'noDownload' => true,                            // fail rather than fetch
    // 'modelsUrl' => 'https://mirror.internal/models/', // fetch from an internal mirror

    // arboOCR >= v0.4.0:
    // 'minDetBoxArea' => 20,       // drop det boxes at or below this area; 0 disables
    // 'spaceRecovery' => true,     // recover inter-word spaces CTC decode swallows
    // 'enableCpuMemArena' => true, // leave ORT CPU memory arena on: faster, higher RSS
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

### Detection and recognition tuning

These three require arboOCR >= `v0.4.0`, and like the download options above
they are strictly opt-in — each is emitted only when you actually set it, so
`binPath` pointed at an older build never sees a flag it would reject:

| Option | CLI flag | Meaning |
|---|---|---|
| `minDetBoxArea` (float) | `--min-det-box-area <float>` | Drop detection boxes at or below this area in detector-input pixels. `0` disables the cut; omitting the option leaves the binary's default of `20`. |
| `spaceRecovery` (bool) | `--space-recovery=true` | Recover the inter-word spaces a greedy CTC decode swallows. Opt-in because it can also introduce spurious ones. |
| `enableCpuMemArena` (bool) | `--enable-cpu-mem-arena=true` | Leave ONNXRuntime's CPU memory arena on: faster, at a higher resident set size. |

`minDetBoxArea` is a float where `0` is a *real* setting rather than a
stand-in for "unset", which is why it is emitted whenever the key is present
and an explicit `0` reaches the binary as `--min-det-box-area 0`. The two
booleans are the opposite case: `false` is already the binary's own default,
so an explicit `false` (or omitting them) puts **nothing** on the command line
— a pre-`v0.4.0` binary answers `--space-recovery=false` with a usage error
and exit 1, and restating a default would buy nothing in exchange.

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

Over 5 SROIE receipts batching saved 35–44% of wall time at `tiny`, 16–29% at
`small` and 11–17% at `medium`, with identical text on every image
(`bench_batch_all.py` in the internal `compare/` harness). Those are ranges
across all five wrapper languages, not point values, and deliberately so: the
saving is not this wrapper's — it is `(process start + model load) / total`,
which `arboocr_demo` owns, and the raw binary with no wrapper at all measures
31% / 19% / 10%. That share moves with the model size and the list length
rather than being a fixed percentage.

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
### GPU backends

`useCuda` / `useTensorrt` request a GPU execution provider; `$result->backend`
reports which one actually ran, so a silent fall back to `cpu` is visible.
These need `v0.3.0` or newer to work from a release archive at all: every
release before it shipped without the `onnxruntime_providers_shared` library,
so the CUDA and TensorRT providers had nothing to load and the binary fell
back to CPU no matter what you passed. `v0.3.0` ships that library, and since
it is the pinned tag, the Composer hook installs it alongside the binary. You
still need a matching CUDA/TensorRT runtime on the host.

## Quick example (tiny model, fastest)

For a fast local smoke test, use `modelType: 'tiny'` — the smallest/fastest
PP-OCRv6 recognizer. Leave `modelsDir` out and the tiny det/rec/cls files are
fetched and cached on first run; point it at a local arboOCR checkout's
`models/` folder instead and nothing is downloaded at all:

```php
use Arbo\Ocr\Engine;

$engine = new Engine([
    // 'modelsDir' => '/path/to/arboOCR/models', // optional: skip the download
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
source) from arboOCR's GitHub Releases, and calls it as a subprocess per image
with a `--json` flag, parsing the JSON result. The ONNX models are not in that
archive — the binary fetches and caches those itself on first use, separately
from this package's `bin/<platform>/` directory. See
arboOCR's [`docs/superpowers/specs/2026-07-27-php-integration-design.md`](https://github.com/wafik/ArboOCR/blob/main/docs/superpowers/specs/2026-07-27-php-integration-design.md)
for the full design.

## Benchmark

`arbo-ocr-php` was benchmarked against the other five arbo wrapper arms —
`arbo-cpp`, `arbo-go`, `arbo-rust`, `arbo-python`, `arbo-js` — on a
40-image SROIE sample. All six drive the **same pinned `arboocr_demo`
v0.4.0 binary**, so accuracy is identical across the arms by construction
(84.6 / 86.1 / 86.3% at tiny/small/medium) and the only thing left to
compare is each wrapper's own per-call cost:

| Arm | tiny | small | medium |
|-----|-----:|------:|-------:|
| arbo-cpp (raw binary, no wrapper) | 322 / 179 | 662 / 478 | 1825 / 1578 |
| arbo-php | 358 / 169 | 718 / 487 | 1875 / 1569 |
| arbo-go | 302 / 167 | 753 / 544 | 1877 / 1619 |
| arbo-rust | 300 / 167 | 657 / 481 | 1866 / 1613 |
| arbo-python | 381 / 171 | 744 / 492 | 2006 / 1663 |
| arbo-js | 427 / 220 | 744 / 515 | 1948 / 1645 |

Average wall ms / engine ms per image; `engine ms` is `arboocr_demo`'s own
reported inference time, and the `arbo-cpp` row is the raw binary with no
wrapper process in between — the floor the wrappers sit on. What the table
does *not* support is a ranking of the wrappers: on this run the raw-binary
row is slower than both compiled wrappers at `tiny`, and the `arbo-go` arm
picked up a slow tail on a few `small` images (`engine ms` 544 for it
against 478–515 for the other five arms, on the same binary and the same
images). An earlier round of this comparison did rank the wrappers — PHP
~55–65 ms above Go/Rust — but each package then installed its own arboOCR
release, so that spread was engine-version drift between arms, not wrapper
overhead. Every arbo arm here still beats the `ppu-paddle-ocr` Node/Bun
reference on both similarity and wall time at every size (82.8 / 83.5 /
84.7% at 581 / 944 / 2040 ms).

Absolute milliseconds come from one session on one machine; thermal state
and background load move every row, so these figures are comparable within
this table only — never against another session's numbers.

Measured by the internal `compare/` harness (`bench_wrappers.py`, one
process per image, each calling the pinned binary once) in its 2026-09-14
run; raw results in `out/bench_wrappers_n40.json`. The harness and its
output are not published in this repo.

## License

Apache-2.0
