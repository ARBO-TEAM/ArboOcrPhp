<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * Composer post-install/post-update hook. Downloads the arboOCR release
 * binary matching this package's pinned version (composer.json
 * extra.arboocr-version) for the host OS, and extracts it to bin/<platform>/
 * next to this file. Never fails composer install/update on error — arboOCR
 * still works if the caller points Engine at a manually-downloaded binary
 * via the `binPath` option.
 */
final class Installer
{
    private const REPO = 'wafik/ArboOCR';

    public static function run(): void
    {
        try {
            $platform = self::detectPlatform();
            if ($platform === null) {
                fwrite(STDERR, "[arbo-ocr-php] Unsupported OS for auto-download. "
                    . "Download a release manually from "
                    . "https://github.com/" . self::REPO . "/releases and pass "
                    . "'binPath' to Engine.\n");
                return;
            }

            $version = self::pinnedVersion();
            $targetDir = __DIR__ . '/../bin/' . $platform;
            $binName = $platform === 'windows-x64' ? 'arboocr_demo.exe' : 'arboocr_demo';

            if (is_file($targetDir . '/' . $binName)) {
                return; // already installed
            }

            $asset = $platform === 'windows-x64' ? 'arboocr-windows-x64.zip' : 'arboocr-linux-x64.tar.gz';
            $url = "https://github.com/" . self::REPO . "/releases/download/{$version}/{$asset}";

            self::downloadAndExtract($url, $targetDir, $asset);
            if ($platform === 'linux-x64') {
                @chmod($targetDir . '/' . $binName, 0755);
            }
            fwrite(STDOUT, "[arbo-ocr-php] Installed arboocr_demo ({$platform}, {$version}) to {$targetDir}\n");
        } catch (\LogicException $e) {
            // Misconfiguration (see pinnedVersion()), not a transient failure:
            // re-running 'composer install' will not help, so don't suggest it.
            // Still non-fatal — the Installer's contract is that it never breaks
            // composer install/update — but loud and clearly distinct from the
            // download-failure message below.
            @fwrite(STDERR, "[arbo-ocr-php] Misconfigured: " . $e->getMessage() . "\n");
        } catch (\Throwable $e) {
            @fwrite(STDERR, "[arbo-ocr-php] Could not auto-download arboOCR binary: "
                . $e->getMessage() . "\nDownload manually from "
                . ($url ?? 'https://github.com/' . self::REPO . '/releases')
                . " and pass 'binPath' to Engine, or re-run 'composer install'.\n");
        }
    }

    public static function detectPlatform(): ?string
    {
        $family = PHP_OS_FAMILY;
        return match ($family) {
            'Windows' => 'windows-x64',
            'Linux' => 'linux-x64',
            default => null,
        };
    }

    /**
     * The release tag this package is pinned to (composer.json
     * extra.arboocr-version).
     *
     * @param ?string $composerPath Override the composer.json location —
     *   only for tests; production callers pass nothing.
     *
     * @throws \LogicException if extra.arboocr-version is missing or empty.
     *   This deliberately does NOT fall back to GitHub's "latest" release.
     *   The whole model of this package is a *pinned* binary: Engine's flag
     *   mapping and PageResult's JSON parsing are written against one
     *   specific arboocr_demo CLI contract. Silently installing whatever
     *   happens to be newest would surface a contract mismatch as a
     *   confusing runtime error far from its cause. A missing pin is a
     *   misconfiguration, not a transient failure — retrying can't fix it,
     *   so say so plainly and let a human re-pin.
     */
    public static function pinnedVersion(?string $composerPath = null): string
    {
        $composerPath ??= __DIR__ . '/../composer.json';
        $composerJson = json_decode((string) file_get_contents($composerPath), true);
        $version = $composerJson['extra']['arboocr-version'] ?? null;

        if (!is_string($version) || $version === '') {
            throw new \LogicException(
                "composer.json has no 'extra.arboocr-version', so there is no way to "
                . "tell which arboOCR release to download. Set it to a release tag "
                . '(e.g. "v0.2.0") in ' . $composerPath . ', or download a binary '
                . 'manually from https://github.com/' . self::REPO . '/releases and '
                . "pass 'binPath' to Engine.",
            );
        }

        return $version;
    }

    private static function downloadAndExtract(string $url, string $targetDir, string $assetName): void
    {
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException("Could not create {$targetDir}");
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'arboocr-dl-');
        if ($tmpFile === false) {
            throw new \RuntimeException('Could not create temp file for download');
        }

        // PharData/ZipArchive detect archive format from the file extension,
        // but tempnam() never produces one — rename to match before use.
        $isZip = str_ends_with($assetName, '.zip');
        $downloadFile = $tmpFile . ($isZip ? '.zip' : '.tar.gz');
        rename($tmpFile, $downloadFile);

        $ctx = stream_context_create(['http' => ['follow_location' => 1, 'timeout' => 120]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            @unlink($downloadFile);
            throw new \RuntimeException("Download failed: {$url}");
        }
        file_put_contents($downloadFile, $data);

        if ($isZip) {
            $zip = new \ZipArchive();
            if ($zip->open($downloadFile) !== true) {
                @unlink($downloadFile);
                throw new \RuntimeException("Could not open downloaded zip: {$assetName}");
            }
            $zip->extractTo($targetDir);
            $zip->close();
        } else {
            $phar = new \PharData($downloadFile);
            $phar->extractTo($targetDir, overwrite: true);
        }
        self::flattenSingleSubdir($targetDir);

        @unlink($downloadFile);
    }

    /**
     * The release archives contain one top-level folder (e.g.
     * arboocr-windows-x64/...). Move its contents up into $targetDir so
     * callers get bin/<platform>/arboocr_demo directly, not
     * bin/<platform>/arboocr-windows-x64/arboocr_demo.
     */
    private static function flattenSingleSubdir(string $targetDir): void
    {
        $entries = array_values(array_diff(scandir($targetDir) ?: [], ['.', '..']));
        if (count($entries) !== 1 || !is_dir($targetDir . '/' . $entries[0])) {
            return;
        }
        $subdir = $targetDir . '/' . $entries[0];
        foreach (array_diff(scandir($subdir) ?: [], ['.', '..']) as $item) {
            rename($subdir . '/' . $item, $targetDir . '/' . $item);
        }
        rmdir($subdir);
    }
}
