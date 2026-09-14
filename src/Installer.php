<?php

declare(strict_types=1);

namespace Arbo\Ocr;

/**
 * Composer post-install/post-update hook. Downloads the arboOCR release
 * binary matching this package's pinned version (composer.json
 * extra.arboocr-version) for the host OS, and extracts it to bin/<platform>/
 * next to this file. Re-downloads whenever the pin changes — the installed
 * tag is recorded in a marker file alongside the binary, see needsInstall().
 * Never fails composer install/update on error — arboOCR
 * still works if the caller points Engine at a manually-downloaded binary
 * via the `binPath` option.
 */
final class Installer
{
    private const REPO = 'wafik/ArboOCR';

    /**
     * Name of the file written inside bin/<platform>/ recording which
     * release tag the binary sitting next to it was extracted from.
     * Dot-prefixed so it never collides with an archive member.
     */
    private const VERSION_MARKER = '.arboocr-version';

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

            if (!self::needsInstall($targetDir, $binName, $version)) {
                return; // the pinned version is already installed
            }

            $asset = $platform === 'windows-x64' ? 'arboocr-windows-x64.zip' : 'arboocr-linux-x64.tar.gz';
            $url = "https://github.com/" . self::REPO . "/releases/download/{$version}/{$asset}";

            self::downloadAndExtract($url, $targetDir, $asset);
            if ($platform === 'linux-x64') {
                @chmod($targetDir . '/' . $binName, 0755);
            }
            // Only once the new binary is actually on disk — a marker written
            // any earlier would claim a version that isn't there, and the next
            // run would trust it and skip the download.
            self::writeVersionMarker($targetDir, $version);
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
     * extra.arboocr-version) — currently v0.3.0, the release that added model
     * auto-download, so Engine's 'noDownload'/'modelsUrl' options and
     * Engine::ensureModels() work against the binary this installs, with no
     * 'binPath' override needed. They stay strictly opt-in all the same: a
     * caller who points 'binPath' at an older build still gets no unknown
     * flags emitted, and so no usage error.
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
                . '(e.g. "v0.3.0") in ' . $composerPath . ', or download a binary '
                . 'manually from https://github.com/' . self::REPO . '/releases and '
                . "pass 'binPath' to Engine.",
            );
        }

        return $version;
    }

    /**
     * Whether bin/<platform>/ has to be (re)populated for $version.
     *
     * DO NOT reduce this back to "is the binary there?" — that was the bug.
     * run() used to return early on is_file($binary), which made bumping
     * extra.arboocr-version in composer.json a silent no-op for everyone who
     * already had a binary: composer reported success, the previous release's
     * executable stayed exactly where it was, and the wrapper carried on
     * driving a CLI contract it no longer matched. Nothing anywhere reported
     * a problem; the only way to get the new build was to delete
     * bin/<platform>/ by hand, which nobody knew to do. arbo-ocr-go and
     * arbo-ocr-rust shipped the identical bug and fixed it the same way.
     *
     * So the question is "is the *pinned* version installed?", answered from
     * a marker written next to the binary at install time. A marker that is
     * missing, unreadable or empty counts as a mismatch and triggers a fresh
     * download: an install made before the marker existed is of unknown
     * provenance, and assuming such an install is current is precisely the
     * failure this replaced. Re-downloading a binary that turns out to have
     * been fine costs one archive; skipping one that wasn't costs silent
     * wrong behaviour.
     */
    private static function needsInstall(string $targetDir, string $binName, string $version): bool
    {
        if (!is_file($targetDir . '/' . $binName)) {
            return true;
        }

        return self::installedVersion($targetDir) !== $version;
    }

    /**
     * The release tag recorded in bin/<platform>/.arboocr-version, or null
     * when there is no readable, non-empty marker — which is how a legacy
     * install reports "unknown version". Null never equals a pinned tag
     * (pinnedVersion() rejects the empty string), so it always reads as a
     * mismatch downstream.
     */
    private static function installedVersion(string $targetDir): ?string
    {
        $marker = $targetDir . '/' . self::VERSION_MARKER;
        if (!is_file($marker)) {
            return null;
        }

        $recorded = @file_get_contents($marker);
        if ($recorded === false) {
            return null;
        }

        $recorded = trim($recorded);

        return $recorded === '' ? null : $recorded;
    }

    private static function writeVersionMarker(string $targetDir, string $version): void
    {
        @file_put_contents($targetDir . '/' . self::VERSION_MARKER, $version . "\n");
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
        self::flattenArchiveRoot($targetDir, $assetName);

        @unlink($downloadFile);
    }

    /**
     * The linux tar.gz wraps everything in one top-level folder
     * (arboocr-linux-x64/...); the windows zip is already flat. Where that
     * folder is present, move its contents up into $targetDir so callers
     * always get bin/<platform>/arboocr_demo directly, never
     * bin/<platform>/arboocr-linux-x64/arboocr_demo.
     *
     * The folder is located by name, derived from the asset filename, rather
     * than by the old "did the extract leave exactly one entry here?" test.
     * That test silently only worked on a clean directory, so it stopped
     * firing the moment re-downloads became possible: unpacking over an
     * existing install leaves the previous version's files sitting alongside
     * the new folder, the entry count is no longer 1, and the new binary
     * would stay stranded one level down while the stale one kept the path
     * Engine actually looks at. That is the same silent-no-op failure
     * needsInstall() exists to prevent, one layer further down, and it would
     * have made the version marker assert a release that was not on disk.
     */
    private static function flattenArchiveRoot(string $targetDir, string $assetName): void
    {
        $rootName = preg_replace('/\.(zip|tar\.gz)$/', '', $assetName);
        if ($rootName === null || $rootName === '' || !is_dir($targetDir . '/' . $rootName)) {
            return;
        }

        $subdir = $targetDir . '/' . $rootName;
        foreach (array_diff(scandir($subdir) ?: [], ['.', '..']) as $item) {
            $dest = $targetDir . '/' . $item;
            // On an upgrade $dest is the previous version's file. rename()
            // cannot be relied on to clobber across platforms, so clear the
            // way first — by this point the replacement is already fully
            // downloaded and extracted, so there is nothing left to lose.
            if (is_file($dest)) {
                @unlink($dest);
            }
            if (!rename($subdir . '/' . $item, $dest)) {
                throw new \RuntimeException("Could not move {$item} into {$targetDir}");
            }
        }
        @rmdir($subdir);
    }
}
