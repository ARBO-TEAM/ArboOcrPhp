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

    /**
     * Exit code 2 (model-load / recognition failure) is new in arboOCR
     * v0.2.0. Engine tests `$exitCode !== 0`, not `=== 1`, so any non-zero
     * code must surface as an OcrException carrying that exact code.
     */
    public function testNonZeroExitCodeIsPreservedOnException(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin(['--fail'])]);

        try {
            $engine->recognize('/some/page.jpg');
            self::fail('Expected OcrException');
        } catch (OcrException $e) {
            self::assertSame(2, $e->exitCode);
            self::assertStringContainsString('simulated engine failure', $e->stderr);
        }
    }

    /**
     * v0.2.0 flags. min-confidence is a float: assert the emitted token is
     * "0.55" and not a comma-decimal rendering under a European locale.
     */
    public function testV020ScalarFlagsAreEmitted(): void
    {
        $engine = new Engine([
            'binPath' => $this->fakeBin(),
            'minConfidence' => 0.55,
            'recBatchNum' => 8,
            'detLimitSideLen' => 960,
            'logLevel' => 'debug',
        ]);

        $method = new \ReflectionMethod(Engine::class, 'flagsFromOptions');
        $method->setAccessible(true);
        $flags = $method->invoke($engine);

        foreach ([
            '--min-confidence' => '0.55',
            '--rec-batch-num' => '8',
            '--det-limit-side-len' => '960',
            '--log-level' => 'debug',
        ] as $flag => $value) {
            $idx = array_search($flag, $flags, true);
            self::assertNotFalse($idx, "{$flag} must be emitted");
            self::assertSame($value, $flags[$idx + 1]);
        }
    }

    public function testWordBoxesFlagUsesSingleTokenBoolForm(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin(), 'wordBoxes' => true]);

        $method = new \ReflectionMethod(Engine::class, 'flagsFromOptions');
        $method->setAccessible(true);
        $flags = $method->invoke($engine);

        self::assertContains('--word-boxes=true', $flags);
        self::assertNotContains('--word-boxes', $flags);
    }

    public function testWordBoxesPopulatesPerLineWords(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin(), 'wordBoxes' => true]);
        $result = $engine->recognize('/some/page.jpg');

        self::assertCount(2, $result->lines[0]->words);
        self::assertSame('hel', $result->lines[0]->words[0]['text']);
    }

    /** Without wordBoxes the JSON carries no `words` key — must not warn or fail. */
    public function testWordsDefaultsToEmptyArrayWhenAbsent(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin()]);
        $result = $engine->recognize('/some/page.jpg');

        self::assertSame([], $result->lines[0]->words);
    }

    /**
     * The load-bearing test for anybody on the pinned arboOCR release.
     * --no-download and --models-url postdate it entirely, and cxxopts exits 1
     * with a usage error on an unknown option — so an Engine that was never
     * told about downloads must build argv byte-for-byte identical to what it
     * built before these options existed. Not "--no-download=false", not an
     * empty "--models-url": nothing at all.
     *
     * Asserted as an exact empty array rather than just the absence of the two
     * flags, because any future unconditional append anywhere in
     * flagsFromOptions() breaks pinned-binary users the same way, and only an
     * exact match catches that.
     */
    public function testDefaultOptionsEmitNoModelDownloadFlags(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin()]);

        $method = new \ReflectionMethod(Engine::class, 'flagsFromOptions');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($engine));
    }

    /**
     * Explicitly opting *out* must be indistinguishable from never mentioning
     * them: 'noDownload' => false and 'modelsUrl' => '' are the two ways a
     * caller lands on the default by accident (a config array built from
     * getenv(), say), and either one leaking onto argv kills the pinned binary.
     */
    public function testFalseyModelDownloadOptionsEmitNoFlags(): void
    {
        $engine = new Engine([
            'binPath' => $this->fakeBin(),
            'noDownload' => false,
            'modelsUrl' => '',
        ]);

        $method = new \ReflectionMethod(Engine::class, 'flagsFromOptions');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($engine));
    }

    public function testModelDownloadFlagsAreEmittedWhenSet(): void
    {
        $engine = new Engine([
            'binPath' => $this->fakeBin(),
            'noDownload' => true,
            'modelsUrl' => 'https://mirror.internal/arboocr/models-v1/',
        ]);

        $method = new \ReflectionMethod(Engine::class, 'flagsFromOptions');
        $method->setAccessible(true);
        $flags = $method->invoke($engine);

        // Single-token "=" form for the bool, same as --word-boxes: cxxopts
        // binds a bool's value only via "=".
        self::assertContains('--no-download=true', $flags);
        self::assertNotContains('--no-download', $flags, '--no-download must not appear as a bare token');

        $idx = array_search('--models-url', $flags, true);
        self::assertNotFalse($idx, '--models-url must be emitted');
        self::assertSame('https://mirror.internal/arboocr/models-v1/', $flags[$idx + 1]);
    }

    /**
     * ensureModels() runs the binary for real, so this asserts the actual
     * invocation and not just the flag builder: --download-models is present,
     * the config-derived flags ride along, and no --image is passed — the
     * binary fetches and exits without opening an image.
     */
    public function testEnsureModelsInvokesDownloadModelsWithoutImage(): void
    {
        $engine = new Engine([
            'binPath' => $this->fakeBin(),
            'ocrVersion' => 'PP-OCRv6',
            'modelType' => 'small',
            'modelsUrl' => 'https://mirror.internal/models/',
        ]);

        // The fake echoes the argv it received back on stdout.
        $argv = $engine->ensureModels();

        self::assertStringContainsString('--download-models', $argv);
        self::assertStringContainsString('--ocr-version PP-OCRv6', $argv);
        self::assertStringContainsString('--model-type small', $argv);
        self::assertStringContainsString('--models-url https://mirror.internal/models/', $argv);
        self::assertStringNotContainsString('--image', $argv);
    }

    /**
     * Against the pinned v0.2.0 binary --download-models is an unknown option:
     * cxxopts prints a usage error and exits 1. That must surface as a typed
     * OcrException carrying the exit code and stderr, like every other
     * subprocess failure — not as a silent success.
     */
    public function testEnsureModelsThrowsWhenBinaryPredatesTheFlag(): void
    {
        $engine = new Engine(['binPath' => $this->fakeBin(['--legacy-cli'])]);

        try {
            $engine->ensureModels();
            self::fail('Expected OcrException');
        } catch (OcrException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('--download-models', $e->getMessage());
            self::assertStringContainsString('does not exist', $e->stderr);
        }
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
