<?php

declare(strict_types=1);

namespace Arbo\Ocr\Tests;

use Arbo\Ocr\Engine;
use Arbo\Ocr\OcrException;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    /** @return list<string> */
    private function fakeBin(array $extraArgs = []): array
    {
        return [PHP_BINARY, __DIR__ . '/fixtures/fake_arboocr.php', ...$extraArgs];
    }

    public function testRecognizeParsesSuccessfulJsonOutput(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin()]);
        $result = $engine->recognize('/some/page.jpg');

        self::assertSame('cpu', $result->backend);
        self::assertSame('page.jpg', $result->image);
        self::assertSame(12.5, $result->elapsedMs);
        self::assertCount(1, $result->lines);
        self::assertSame('hello', $result->lines[0]->text);
        self::assertSame(0.9, $result->lines[0]->score);
        self::assertSame(1.0, $result->lines[0]->polygon[0]['x']);
    }

    /**
     * Regression test: cxxopts binds a bool flag's value only via "=" — a
     * bare "--angle" followed by a separate "true"/"false" token leaves the
     * flag implicitly true and the value ignored (confirmed against the
     * real arboocr_demo binary). flagsFromOptions() must always emit bool
     * options as a single "--flag=value" token.
     */
    public function testBoolFlagsUseSingleTokenForm(): void
    {
        $engine = new Engine([
            'binPath' => $this->fakeBin(),
            'useAngleCls' => false,
            'useCuda' => true,
            'useTensorrt' => false,
            'useFp16' => false,
            'useClahe' => true,
        ]);

        $method = new \ReflectionMethod(Engine::class, 'flagsFromOptions');
        $method->setAccessible(true);
        $flags = $method->invoke($engine);

        self::assertContains('--angle=false', $flags);
        self::assertContains('--cuda=true', $flags);
        self::assertContains('--tensorrt=false', $flags);
        self::assertContains('--fp16=false', $flags);
        self::assertContains('--clahe=true', $flags);
        foreach (['--angle', '--cuda', '--tensorrt', '--fp16', '--clahe'] as $bareFlag) {
            self::assertNotContains($bareFlag, $flags, "{$bareFlag} must not appear as a bare token");
        }
    }

    public function testRecognizeThrowsOnNonZeroExit(): void
    {
        $this->expectException(OcrException::class);
        $this->expectExceptionMessageMatches('/exited with code/');

        $engine = new Engine(['binPath' => $this->fakeBin(['--fail'])]);
        $engine->recognize('/some/page.jpg');
    }

    public function testRecognizeDoesNotDeadlockOnLargeStderr(): void
    {
        // Regression: reading stdout and stderr sequentially deadlocks once
        // the child fills a pipe buffer on the stream not yet being read.
        $engine = new Engine(['binPath' => $this->fakeBin(['--noisy-stderr'])]);
        $result = $engine->recognize('/some/page.jpg');

        self::assertSame('cpu', $result->backend);
    }

    public function testRecognizeThrowsOnUnparseableOutput(): void
    {
        $this->expectException(OcrException::class);

        $engine = new Engine(['binPath' => $this->fakeBin(['--garbage'])]);
        $engine->recognize('/some/page.jpg');
    }

    public function testRecognizeThrowsWhenBinaryMissing(): void
    {
        $this->expectException(OcrException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $engine = new Engine(['binPath' => '/no/such/binary']);
        $engine->recognize('/some/page.jpg');
    }

    public function testFlagsFromOptionsMapToCliFlags(): void
    {
        // Indirect check: modelsDir/useAngleCls etc. must reach argv without
        // erroring proc_open and must not break the fake binary's parsing
        // (it only reads --image, so any well-formed extra flags are fine).
        $engine = new Engine([
            'binPath' => $this->fakeBin(),
            'modelsDir' => 'models',
            'useAngleCls' => true,
            'useCuda' => false,
        ]);
        $result = $engine->recognize('/some/page.jpg');
        self::assertSame('cpu', $result->backend);
    }
}
