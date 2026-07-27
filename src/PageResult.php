<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * Full-page OCR result — mirrors arboOCR's PagePrediction. Empty `lines` is
 * a normal, successful result (no text found), not an error.
 */
final class PageResult
{
    /** @param LineResult[] $lines */
    public function __construct(
        public readonly string $backend,
        public readonly string $image,
        public readonly float $elapsedMs,
        public readonly array $lines,
    ) {
    }

    /**
     * Parse the JSON stdout of `arboocr_demo --json`.
     *
     * @throws OcrException if $json is not a valid JSON object with the
     *   expected shape.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['lines']) || !is_array($data['lines'])) {
            throw new OcrException('arboocr_demo --json produced unparseable output: ' . substr($json, 0, 500));
        }

        $lines = array_map(
            static fn (array $line) => LineResult::fromArray($line),
            $data['lines'],
        );

        return new self(
            backend: (string) ($data['backend'] ?? ''),
            image: (string) ($data['image'] ?? ''),
            elapsedMs: (float) ($data['elapsedMs'] ?? 0.0),
            lines: $lines,
        );
    }
}
