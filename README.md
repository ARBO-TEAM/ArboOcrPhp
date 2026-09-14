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
[`v0.3.0`](https://github.com/wafik/ArboOCR/releases/tag/v0.3.0)
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

The OCR models are not bundled either, but as of the pinned `v0.3.0` binary
they are no longer a manual step: a model that isn't already on disk is
downloaded, SHA-256 verified and cached on first run. See [Models](#models)
below for exactly which files each `modelType` uses, and for the ways to
supply them yourself when you'd rather not touch the network.

## Models

arboOCR doesn't bundle OCR models, but the pinned `v0.3.0` binary fetches the
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

The pinned `v0.3.0` binary fetches any model it's missing and verifies it by
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
options above — need arboOCR >= `v0.3.0`, which is the pinned tag:

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
