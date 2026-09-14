<?php
// Stand-in for arboocr_demo, used only by EngineTest. Reads its own argv to
// decide what to emit, so tests can exercise both the happy path and error
// paths without a real binary or models.

$args = $argv;
array_shift($args); // drop script path

// --download-models is a fetch-and-exit mode with no --image to dispatch on,
// so it gets its own branch first. It echoes the argv it was handed, which is
// how EngineTest asserts the real subprocess invocation (the flag builder is
// checked separately via reflection) — the real binary prints a per-file
// status report here instead.
//
// --legacy-cli stands in for a pre-v0.3.0 binary, which has no
// --download-models flag at all: cxxopts rejects the unknown option and exits
// 1 with a usage error on stderr. The pin is v0.3.0 now, so this is no longer
// the default binary — it is the one a caller gets by overriding 'binPath'
// with an older build, which must still fail loudly rather than silently.
if (in_array('--download-models', $args, true)) {
    if (in_array('--legacy-cli', $args, true)) {
        fwrite(STDERR, "Option '--download-models' does not exist\n");
        exit(1);
    }
    echo implode(' ', $args), "\n";
    exit(0);
}

if (in_array('--fail', $args, true)) {
    fwrite(STDERR, "simulated engine failure\n");
    exit(2);
}

if (in_array('--garbage', $args, true)) {
    echo "not json\n";
    exit(0);
}

if (in_array('--noisy-stderr', $args, true)) {
    // Past a pipe's OS buffer (~64KB), written before any stdout — the real
    // arboocr_demo does this via ONNXRuntime schema-registration warnings.
    fwrite(STDERR, str_repeat("noise\n", 20000));
}

$imageIdx = array_search('--image', $args, true);
$image = $imageIdx !== false ? ($args[$imageIdx + 1] ?? '') : '';

// --images-from is batch mode: one process over a newline-delimited list file,
// one JSON array on stdout in list order. Each path is echoed back as its
// line's text so the tests can assert positional matching.
if (($listIdx = array_search('--images-from', $args, true)) !== false) {
    // What a bad flag actually does: exit 1 with no JSON on stdout, which the
    // engine must not confuse with the ordinary "a page came back empty" exit
    // 1 that still carries the array.
    if (in_array('--batch-usage-error', $args, true)) {
        fwrite(STDERR, "Option '--images-from' does not exist\n");
        exit(1);
    }

    $paths = [];
    foreach (explode("\n", (string) file_get_contents($args[$listIdx + 1] ?? '')) as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $paths[] = $line;
        }
    }
    if (in_array('--batch-short', $args, true) && $paths !== []) {
        array_pop($paths);
    }

    echo json_encode(array_map(static fn (string $p) => [
        'backend' => 'cpu',
        'image' => basename($p),
        'elapsedMs' => 12.5,
        'lines' => [['text' => $p, 'score' => 0.9, 'detScore' => 0.8, 'polygon' => [['x' => 1.0, 'y' => 2.0]]]],
    ], $paths), JSON_PRESERVE_ZERO_FRACTION) . "\n";

    // A batch exits 1 when any image came back empty — an ordinary outcome
    // that still carries the JSON the caller asked for.
    exit(in_array('--batch-exit1', $args, true) ? 1 : 0);
}

$line = [
    'text' => 'hello', 'score' => 0.9, 'detScore' => 0.8,
    'polygon' => [['x' => 1.0, 'y' => 2.0]],
];

// arboOCR >= v0.2.0: --word-boxes adds a per-line `words` array.
if (in_array('--word-boxes=true', $args, true)) {
    $line['words'] = [
        ['text' => 'hel', 'score' => 0.95, 'polygon' => [['x' => 1.0, 'y' => 2.0]]],
        ['text' => 'lo', 'score' => 0.85, 'polygon' => [['x' => 3.0, 'y' => 2.0]]],
    ];
}

echo json_encode([
    'backend' => 'cpu',
    'image' => basename($image),
    'elapsedMs' => 12.5,
    'lines' => [$line],
], JSON_PRESERVE_ZERO_FRACTION) . "\n";
exit(0);
