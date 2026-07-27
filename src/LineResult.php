<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * One recognized text line. `polygon` is a list of ['x' => float, 'y' => float]
 * points, in the order arboOCR reports them (clockwise from top-left-ish).
 */
final class LineResult
{
    /** @param array<int, array{x: float, y: float}> $polygon */
    public function __construct(
        public readonly string $text,
        public readonly float $score,
        public readonly float $detScore,
        public readonly array $polygon,
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
        );
    }
}
