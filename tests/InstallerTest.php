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
}
