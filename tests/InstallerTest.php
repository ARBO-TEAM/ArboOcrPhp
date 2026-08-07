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
        $gzPath = self::makeTarGzFixture('fake-binary-contents');
        $targetDir = sys_get_temp_dir() . '/installer-test-target-' . uniqid();

        try {
            self::downloadAndExtract('file://' . $gzPath, $targetDir);

            self::assertFileExists($targetDir . '/arboocr_demo');
            self::assertSame('fake-binary-contents', file_get_contents($targetDir . '/arboocr_demo'));
        } finally {
            @unlink($gzPath);
            self::removeDir($targetDir);
        }
    }

    /**
     * The upgrade path: extracting on top of a previous install. Flattening
     * used to key off "the target directory holds exactly one entry", which
     * is only ever true of a clean directory — so once re-downloads became
     * possible, the old release's files stayed put, the new archive root was
     * left un-flattened, and the fresh binary ended up stranded in
     * bin/<platform>/arboocr-linux-x64/ while the stale one kept the exact
     * path Engine resolves to. The download would succeed and change nothing.
     */
    public function testDownloadAndExtractReplacesAPreviousInstall(): void
    {
        $gzPath = self::makeTarGzFixture('new-binary-contents');
        $targetDir = sys_get_temp_dir() . '/installer-test-upgrade-' . uniqid();
        mkdir($targetDir, 0755, true);
        // Stand in for an existing install of an older release.
        file_put_contents($targetDir . '/arboocr_demo', 'stale-binary-contents');
        file_put_contents($targetDir . '/LICENSE', 'stale-license');

        try {
            self::downloadAndExtract('file://' . $gzPath, $targetDir);

            self::assertSame('new-binary-contents', file_get_contents($targetDir . '/arboocr_demo'));
            self::assertDirectoryDoesNotExist($targetDir . '/arboocr-linux-x64');
        } finally {
            @unlink($gzPath);
            self::removeDir($targetDir);
        }
    }

    /**
     * The fast path has to stay fast: when the pinned release is already the
     * one on disk, composer install/update must not re-fetch a ~13-21 MB
     * archive on every single run.
     */
    public function testNeedsInstallIsFalseWhenPinnedVersionIsAlreadyInstalled(): void
    {
        $dir = self::makeFakeInstall('v0.2.0');

        try {
            self::assertFalse(self::needsInstall($dir, 'v0.2.0'));
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * Regression, and the reason the marker exists at all: run() used to
     * return early whenever *a* binary was present, so bumping
     * extra.arboocr-version in place was a silent no-op — composer said
     * "success" and left the previous release's executable untouched. A
     * recorded tag that differs from the pin must force a re-download.
     */
    public function testNeedsInstallIsTrueWhenInstalledVersionDiffers(): void
    {
        $dir = self::makeFakeInstall('v0.1.0-php1');

        try {
            self::assertTrue(self::needsInstall($dir, 'v0.2.0'));
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * A binary installed before the marker existed. Its provenance is
     * unknowable, so it counts as a mismatch and gets upgraded — "there is
     * a file there, assume it is current" is exactly the bug being fixed.
     */
    public function testNeedsInstallIsTrueWhenMarkerIsMissing(): void
    {
        $dir = self::makeFakeInstall(null);

        try {
            self::assertTrue(self::needsInstall($dir, 'v0.2.0'));
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * A marker that is present but yields nothing usable (truncated write,
     * unreadable file) is no more informative than no marker at all, and
     * must fail the same way: re-download rather than assume.
     */
    public function testNeedsInstallIsTrueWhenMarkerIsEmpty(): void
    {
        $dir = self::makeFakeInstall('');

        try {
            self::assertTrue(self::needsInstall($dir, 'v0.2.0'));
        } finally {
            self::removeDir($dir);
        }
    }

    public function testNeedsInstallIsTrueWhenBinaryIsMissing(): void
    {
        $dir = self::makeFakeInstall('v0.2.0');
        @unlink($dir . '/arboocr_demo');

        try {
            self::assertTrue(self::needsInstall($dir, 'v0.2.0'));
        } finally {
            self::removeDir($dir);
        }
    }

    /**
     * A local tar.gz standing in for a release asset, so the extract path can
     * be exercised over a file:// URL with no network access. The nested
     * arboocr-linux-x64/ root mirrors the real linux archive.
     */
    private static function makeTarGzFixture(string $binaryContents): string
    {
        $tarPath = tempnam(sys_get_temp_dir(), 'installer-test-') . '.tar';
        $phar = new PharData($tarPath);
        $phar->addFromString('arboocr-linux-x64/arboocr_demo', $binaryContents);
        $phar->addFromString('arboocr-linux-x64/LICENSE', 'fake-license');
        $phar->compress(\Phar::GZ);
        unset($phar);
        @unlink($tarPath);

        return $tarPath . '.gz';
    }

    private static function downloadAndExtract(string $url, string $targetDir): void
    {
        $method = new \ReflectionMethod(Installer::class, 'downloadAndExtract');
        $method->setAccessible(true);
        $method->invoke(null, $url, $targetDir, 'arboocr-linux-x64.tar.gz');
    }

    /**
     * A fake bin/<platform>/ holding the binary, plus a version marker when
     * $recordedVersion is not null (null models a pre-marker install).
     */
    private static function makeFakeInstall(?string $recordedVersion): string
    {
        $dir = sys_get_temp_dir() . '/installer-version-test-' . uniqid();
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/arboocr_demo', 'fake-binary-contents');
        if ($recordedVersion !== null) {
            file_put_contents($dir . '/.arboocr-version', $recordedVersion === '' ? '' : $recordedVersion . "\n");
        }

        return $dir;
    }

    private static function needsInstall(string $targetDir, string $pinnedVersion): bool
    {
        $method = new \ReflectionMethod(Installer::class, 'needsInstall');
        $method->setAccessible(true);

        return $method->invoke(null, $targetDir, 'arboocr_demo', $pinnedVersion);
    }

    /** Remove a fixture directory, including the dot-prefixed marker glob() skips. */
    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
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
