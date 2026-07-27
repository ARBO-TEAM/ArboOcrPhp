<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * Thrown when the arboocr_demo process fails to start, exits non-zero, or
 * produces stdout that isn't valid JSON. An empty `lines` array in a
 * successful (exit 0) result is NOT an error — arboOCR's own contract is
 * that "no text found" is a normal, valid PageResult.
 */
final class OcrException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode = -1, public readonly string $stderr = '')
    {
        parent::__construct($message);
    }
}
