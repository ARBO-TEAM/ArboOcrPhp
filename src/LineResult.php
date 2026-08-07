<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * One recognized text line. `polygon` is a list of ['x' => float, 'y' => float]
 * points, in the order arboOCR reports them (clockwise from top-left-ish).
 *
 * `words` holds the per-word boxes arboOCR emits when Engine is constructed
 * with `wordBoxes: true` (arboOCR >= v0.2.0); it is an empty array otherwise.
 * Like `polygon`, it is carried through as the raw decoded JSON rather than a
 * typed object, so a future change to the word payload doesn't silently drop
 * fields.
 */
final class LineResult
{
    /**
     * @param array<int, array{x: float, y: float}> $polygon
     * @param array<int, array<string, mixed>>      $words
     */
    public function __construct(
        public readonly string $text,
        public readonly float $score,
        public readonly float $detScore,
        public readonly array $polygon,
        public readonly array $words = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            text: (string) ($data['text'] ?? ''),
            score: (float) ($data['score'] ?? 0.0),
            detScore: (float) ($data['detScore'] ?? 0.0),
            polygon: $data['polygon'] ?? [],
            words: is_array($data['words'] ?? null) ? $data['words'] : [],
        );
    }
}
