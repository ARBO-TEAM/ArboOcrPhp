<?php

declare(strict_types=1);

namespace Arbo\Ocr\Tests;

use Arbo\Ocr\Installer;
use PharData;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    /**
     * tempnam() produces a file with no extension; PharData refuses to open
     * such a path even though the content is a valid tar.gz. This exercises
     * the real downloadAndExtract() against a local tar.gz fixture (via a
     * file:// URL, no network) so the extension bug can't regress.
     */
    public function testDownloadAndExtractHandlesTarGzWithoutNetworkAccess(): void
    {
        $tarPath = tempnam(sys_get_temp_dir(), 'installer-test-') . '.tar';
        $phar = new PharData($tarPath);
        $phar->addFromString('arboocr-linux-x64/arboocr_demo', 'fake-binary-contents');
        $phar->addFromString('arboocr-linux-x64/LICENSE', 'fake-license');
        $phar->compress(\Phar::GZ);
        $gzPath = $tarPath . '.gz';
        unset($phar);
        @unlink($tarPath);

        $targetDir = sys_get_temp_dir() . '/installer-test-target-' . uniqid();

        try {
            $method = new \ReflectionMethod(Installer::class, 'downloadAndExtract');
            $method->setAccessible(true);
            $method->invoke(null, 'file://' . $gzPath, $targetDir, 'arboocr-linux-x64.tar.gz');

            self::assertFileExists($targetDir . '/arboocr_demo');
            self::assertSame('fake-binary-contents', file_get_contents($targetDir . '/arboocr_demo'));
        } finally {
            @unlink($gzPath);
            array_map('unlink', glob($targetDir . '/*') ?: []);
            @rmdir($targetDir);
        }
    }

    /**
     * pinnedVersion() must read the tag straight out of composer.json's
     * extra.arboocr-version — that pin is the single source of truth for
     * which release the Composer hook downloads.
     */
    public function testPinnedVersionReadsTagFromComposerJson(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
        $expected = $composer['extra']['arboocr-version'];

        self::assertIsString($expected);
        self::assertNotSame('', $expected);
        self::assertSame($expected, Installer::pinnedVersion());
    }

    /**
     * Regression: pinnedVersion() used to fall back to the literal string
     * 'latest', which built https://.../releases/download/latest/<asset> —
     * a 404, because GitHub's latest-asset path is /releases/latest/download/
     * (the version segment and the word "latest" swap places). Rather than
     * fix that URL, the fallback is gone: this package's contract is a
     * *pinned* binary, so a missing pin is a misconfiguration that must be
     * reported, not papered over with an unpinned download.
     */
    public function testPinnedVersionThrowsWhenPinIsMissing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'installer-nopin-') . '.json';
        file_put_contents($path, json_encode(['name' => 'arbo/ocr-php', 'extra' => []]));

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessageMatches('/arboocr-version/');
            Installer::pinnedVersion($path);
        } finally {
            @unlink($path);
        }
    }

    public function testPinnedVersionThrowsWhenPinIsEmpty(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'installer-emptypin-') . '.json';
        file_put_contents($path, json_encode(['extra' => ['arboocr-version' => '']]));

        try {
            $this->expectException(\LogicException::class);
            Installer::pinnedVersion($path);
        } finally {
            @unlink($path);
        }
    }
}
