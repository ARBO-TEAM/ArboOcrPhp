<?php
// Stand-in for arboocr_demo, used only by EngineTest. Reads its own argv to
// decide what to emit, so tests can exercise both the happy path and error
// paths without a real binary or models.

$args = $argv;
array_shift($args); // drop script path

if (in_array('--fail', $args, true)) {
    fwrite(STDERR, "simulated engine failure\n");
    exit(2);
}

if (in_array('--garbage', $args, true)) {
    echo "not json\n";
    exit(0);
}

$imageIdx = array_search('--image', $args, true);
$image = $imageIdx !== false ? ($args[$imageIdx + 1] ?? '') : '';

echo json_encode([
    'backend' => 'cpu',
    'image' => basename($image),
    'elapsedMs' => 12.5,
    'lines' => [
        ['text' => 'hello', 'score' => 0.9, 'detScore' => 0.8,
         'polygon' => [['x' => 1.0, 'y' => 2.0]]],
    ],
], JSON_PRESERVE_ZERO_FRACTION) . "\n";
exit(0);
