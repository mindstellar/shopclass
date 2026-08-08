<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\market;

use mindstellar\utility\FileSystem;
use mindstellar\utility\Zip;
use RuntimeException;
use Throwable;

/**
 * Class Installer
 *
 * Installs and updates plugins/themes resolved from the market catalog: verifies
 * the download against an allowed host and a required sha256, stages the zip and
 * validates its shape before anything touches the live directory, and backs up
 * the directory it replaces so a failed swap can be rolled back.
 *
 * This wraps `mindstellar\upgrade\Upgrade`/`UpgradePackage` rather than replacing
 * them; those classes stay the substrate for the legacy, unverified "Plugin/Theme
 * update URI" path.
 *
 * @package mindstellar\market
 */
final class Installer
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{1,40}$/';

    private string $basePath;

    private string $downloadsPath;

    private string $backupsPath;

    private function __construct(bool $isTheme)
    {
        $this->basePath      = $isTheme ? THEMES_PATH : PLUGINS_PATH;
        $this->downloadsPath = CONTENT_PATH . 'downloads/';
        $this->backupsPath   = $this->downloadsPath . 'backups/';
    }

    public static function forPlugins(): self
    {
        return new self(false);
    }

    public static function forThemes(): self
    {
        return new self(true);
    }

    /**
     * @param array $versionEntry catalog version entry: version, requires, requires_php,
     *                            tested, url, sha256, size
     *
     * @return array{ok:bool, message:string, slug:string, version:?string, rolled_back:bool}
     */
    public function install(string $slug, array $versionEntry): array
    {
        return $this->execute($slug, $versionEntry);
    }

    /**
     * @param array $versionEntry catalog version entry: version, requires, requires_php,
     *                            tested, url, sha256, size
     *
     * @return array{ok:bool, message:string, slug:string, version:?string, rolled_back:bool}
     */
    public function update(string $slug, array $versionEntry): array
    {
        return $this->execute($slug, $versionEntry);
    }

    /**
     * Restore the backup taken by the last install/update of this slug.
     *
     * @return array{ok:bool, message:string, slug:string, version:?string, rolled_back:bool}
     */
    public function rollback(string $slug): array
    {
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            return $this->result(false, __('Invalid package slug.'), $slug, null, false);
        }

        $backupZipPath = $this->findLastBackup($slug);
        if ($backupZipPath === null) {
            return $this->result(false, __('No backup was found to roll back to.'), $slug, null, false);
        }

        $targetDir = $this->basePath . $slug;
        if (!$this->restoreBackup($targetDir, $backupZipPath)) {
            return $this->result(false, __('Rollback failed; the backup could not be restored.'), $slug, null, false);
        }

        $header = $this->parseHeader($targetDir . '/index.php');

        return $this->result(
            true,
            __('Package rolled back to its previous version.'),
            $slug,
            $header['version'] !== '' ? $header['version'] : null,
            true
        );
    }

    /**
     * Shared install/update pipeline: verify -> preflight -> download -> stage ->
     * validate -> back up -> swap. Every exit before the swap leaves the live
     * directory and the filesystem exactly as they were found.
     */
    private function execute(string $slug, array $versionEntry): array
    {
        if (defined('DEMO')) {
            return $this->result(false, __('Package installs are disabled in demo mode.'), $slug, null, false);
        }

        if (osc_self_update_disabled()) {
            return $this->result(
                false,
                __('Package installs are disabled on this deployment.'),
                $slug,
                null,
                false
            );
        }

        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            return $this->result(false, __('Invalid package slug.'), $slug, null, false);
        }

        $version = trim((string) ($versionEntry['version'] ?? ''));
        $url     = trim((string) ($versionEntry['url'] ?? ''));
        $sha256  = $versionEntry['sha256'] ?? null;

        if ($version === '' || $url === '') {
            return $this->result(false, __('The catalog entry is missing a version or a download url.'), $slug, null, false);
        }

        if (!is_string($sha256) || !preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            return $this->result(
                false,
                __('Refusing to install: the catalog entry carries no checksum to verify the download against.'),
                $slug,
                null,
                false
            );
        }
        $sha256 = strtolower($sha256);

        if (!filter_var($url, FILTER_VALIDATE_URL) || !FileSystem::isAllowedPackageHost($url)) {
            return $this->result(
                false,
                __('Package source host is not on the allowed list for verified downloads.'),
                $slug,
                null,
                false
            );
        }

        // Compatibility::evaluate() reads 'tested_up_to'; tolerate a catalog entry
        // that carries it as 'tested' (the shorthand used in the entry shape) without
        // depending on which spelling the catalog writer used.
        $compatInfo = $versionEntry;
        if (!isset($compatInfo['tested_up_to']) && isset($versionEntry['tested'])) {
            $compatInfo['tested_up_to'] = $versionEntry['tested'];
        }

        $verdict = Compatibility::evaluate($compatInfo);
        if ($verdict['blocked']) {
            return $this->result(false, $verdict['reason'], $slug, null, false);
        }

        $targetDir = rtrim($this->basePath . $slug, '/');

        $writable = $this->checkWritable($targetDir);
        if ($writable !== null) {
            return $this->result(false, $writable, $slug, null, false);
        }

        $fs  = new FileSystem();
        $zip = new Zip();

        $uniqueId    = $fs->generateUniqueId('installer_');
        $zipFile     = $this->downloadsPath . $uniqueId . '.zip';
        $extractPath = $this->downloadsPath . $uniqueId;

        try {
            $downloaded = $fs->downloadFile($url, $zipFile, null, true, $sha256);
            if (!$downloaded) {
                return $this->result(
                    false,
                    __('Download failed, or the downloaded file did not match the expected checksum.'),
                    $slug,
                    null,
                    false
                );
            }

            $resultCode = $zip->unzipFile($downloaded, $extractPath);
            if ($resultCode !== 1) {
                return $this->result(false, __('The package archive is invalid or unsafe.'), $slug, null, false);
            }

            $staged = $this->validateStagedPackage($extractPath, $slug, $version);
            if (!$staged['ok']) {
                return $this->result(false, $staged['reason'], $slug, null, false);
            }

            return $this->swap($slug, $targetDir, $staged['packageDir'], $version);
        } catch (Throwable $e) {
            return $this->result(false, sprintf(__('Install failed: %s'), $e->getMessage()), $slug, null, false);
        } finally {
            $fs->remove($zipFile);
            $fs->remove($extractPath);
        }
    }

    /**
     * Confirms nothing that follows will fail for want of a writable target, before
     * any network request is made. Returns null when writable, an error message
     * otherwise.
     */
    private function checkWritable(string $targetDir): ?string
    {
        // The swap replaces the directory entry itself (remove + rename), so the
        // parent must be writable whether or not the target already exists.
        if (!is_writable(dirname($targetDir))) {
            return __('The installation directory is not writable.');
        }

        if (file_exists($targetDir) && !is_writable($targetDir)) {
            return __('The existing package directory is not writable.');
        }

        if (!is_dir($this->downloadsPath) && !@mkdir($this->downloadsPath, 0755, true) && !is_dir($this->downloadsPath)) {
            return __('The downloads directory could not be created.');
        }
        if (!is_writable($this->downloadsPath)) {
            return __('The downloads directory is not writable.');
        }

        return null;
    }

    /**
     * Confirms the extracted zip matches the shape PACKAGE-SPEC §2 requires: a
     * single top-level directory named for the slug, containing an index.php with
     * a parseable header whose version matches the catalog entry, and whose
     * declared compatibility does not block the running core/PHP.
     *
     * @return array{ok:bool, reason:string, packageDir:string}
     */
    private function validateStagedPackage(string $extractRoot, string $slug, string $expectedVersion): array
    {
        $entries = array_values(array_diff((array) @scandir($extractRoot), ['.', '..']));

        if (count($entries) !== 1) {
            return ['ok' => false, 'reason' => __('The package archive must contain exactly one top-level directory.'), 'packageDir' => ''];
        }

        $packageDir = rtrim($extractRoot, '/') . '/' . $entries[0];

        if ($entries[0] !== $slug || !is_dir($packageDir)) {
            return [
                'ok'         => false,
                'reason'     => sprintf(__("The package's top-level directory must be named \"%s\"."), $slug),
                'packageDir' => '',
            ];
        }

        $indexFile = $packageDir . '/index.php';
        if (!is_file($indexFile)) {
            return ['ok' => false, 'reason' => __('The package is missing its index.php.'), 'packageDir' => ''];
        }

        $header = $this->parseHeader($indexFile);
        if ($header['version'] === '') {
            return ['ok' => false, 'reason' => __("The package's index.php has no parseable Version header."), 'packageDir' => ''];
        }

        if (version_compare($header['version'], $expectedVersion, '!=')) {
            return [
                'ok'         => false,
                'reason'     => sprintf(
                    __('The package header declares version %1$s, but the catalog entry is for %2$s.'),
                    $header['version'],
                    $expectedVersion
                ),
                'packageDir' => '',
            ];
        }

        $verdict = Compatibility::evaluate($header);
        if ($verdict['blocked']) {
            return ['ok' => false, 'reason' => $verdict['reason'], 'packageDir' => ''];
        }

        return ['ok' => true, 'reason' => '', 'packageDir' => $packageDir];
    }

    /**
     * Backs up an existing target directory, then atomically swaps the staged
     * directory into its place. Any failure after the backup restores it and
     * reports rolled_back: true; any failure before it never touches the target.
     */
    private function swap(string $slug, string $targetDir, string $stagedPackageDir, string $newVersion): array
    {
        $fs           = new FileSystem();
        $hadExisting  = file_exists($targetDir);
        $backupZipPath = null;

        try {
            if ($hadExisting) {
                $backupZipPath = $this->backupExisting($slug, $targetDir);
                if ($backupZipPath === null) {
                    return $this->result(
                        false,
                        __('Unable to back up the existing package before replacing it.'),
                        $slug,
                        null,
                        false
                    );
                }
                $fs->remove($targetDir);
            }

            $fs->rename($stagedPackageDir, $targetDir);

            if (!is_file($targetDir . '/index.php')) {
                throw new RuntimeException(__('The package swap did not produce a valid package directory.'));
            }

            return $this->result(true, __('Package installed.'), $slug, $newVersion, false);
        } catch (Throwable $e) {
            $rolledBack = false;
            if ($hadExisting && $backupZipPath !== null) {
                $rolledBack = $this->restoreBackup($targetDir, $backupZipPath);
            } else {
                // Nothing existed before this attempt; leave no half-written directory behind.
                $fs->remove($targetDir);
            }

            return $this->result(
                false,
                sprintf(__('Install failed: %s'), $e->getMessage()),
                $slug,
                null,
                $rolledBack
            );
        }
    }

    /**
     * Zips the current target directory to oc-content/downloads/backups/<slug>-<version>.zip
     * and records it as the slug's most recent backup. Returns the zip path on
     * success, null on any failure (nothing is left behind on failure).
     */
    private function backupExisting(string $slug, string $targetDir): ?string
    {
        if (!is_dir($this->backupsPath) && !@mkdir($this->backupsPath, 0755, true) && !is_dir($this->backupsPath)) {
            return null;
        }

        $currentHeader  = $this->parseHeader($targetDir . '/index.php');
        $currentVersion = $currentHeader['version'] !== '' ? $currentHeader['version'] : ('unknown-' . time());
        $backupFilename = $slug . '-' . preg_replace('/[^A-Za-z0-9.\-]/', '_', $currentVersion) . '.zip';
        $backupZipPath  = $this->backupsPath . $backupFilename;

        $zip = new Zip();
        if (@file_exists($backupZipPath)) {
            (new FileSystem())->remove($backupZipPath);
        }

        $zipped = $zip->zipFolder($targetDir, $backupZipPath);
        if (!$zipped || !is_file($backupZipPath)) {
            return null;
        }

        // Exact-match pointer (never a glob) so slugs sharing a hyphenated prefix
        // (e.g. "sample" and "sample-forms") can never resolve each other's backup.
        if (@file_put_contents($this->backupsPath . $slug . '.last-backup', $backupFilename, LOCK_EX) === false) {
            return null;
        }

        return $backupZipPath;
    }

    /**
     * Removes whatever is currently at $targetDir and re-extracts $backupZipPath
     * in its place. The backup zip's entries are stored relative to ABS_PATH (as
     * produced by Zip::zipFolder()), so extracting it there reconstructs the
     * original directory at its original path.
     */
    private function restoreBackup(string $targetDir, string $backupZipPath): bool
    {
        if (!is_file($backupZipPath)) {
            return false;
        }

        $fs  = new FileSystem();
        $zip = new Zip();

        try {
            $fs->remove($targetDir);
            $resultCode = $zip->unzipFile($backupZipPath, ABS_PATH);

            return $resultCode === 1 && is_file($targetDir . '/index.php');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return string|null the backup zip path recorded for $slug's last install/update,
     *                      or null when no pointer or no zip exists
     */
    private function findLastBackup(string $slug): ?string
    {
        $pointer = $this->backupsPath . $slug . '.last-backup';
        if (!is_file($pointer)) {
            return null;
        }

        $filename = trim((string) @file_get_contents($pointer));
        if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '..') !== false) {
            return null;
        }

        $backupZipPath = $this->backupsPath . $filename;

        return is_file($backupZipPath) ? $backupZipPath : null;
    }

    /**
     * Reads the same header fields core's own plugin/theme parsers read
     * (PACKAGE-SPEC §3.1: a case-insensitive `Field:` substring match, value ends
     * at end of line), applied directly to a staged file that has not been
     * installed yet and therefore cannot be read through Plugins::getInfo() /
     * WebThemes::loadThemeInfo() (both resolve paths under the live install).
     *
     * @return array{version:string, requires:string, requires_php:string, tested_up_to:string}
     */
    private function parseHeader(string $indexFile): array
    {
        $info = ['version' => '', 'requires' => '', 'requires_php' => '', 'tested_up_to' => ''];

        if (!is_file($indexFile)) {
            return $info;
        }

        $contents = @file_get_contents($indexFile);
        if ($contents === false) {
            return $info;
        }

        $fields = [
            'version'      => 'Version',
            'requires'     => 'Requires Shopclass',
            'requires_php' => 'Requires PHP',
            'tested_up_to' => 'Tested up to',
        ];

        foreach ($fields as $key => $field) {
            if (preg_match('|' . preg_quote($field, '|') . ':([^\r\t\n]*)|i', $contents, $match)) {
                $info[$key] = trim($match[1]);
            }
        }

        return $info;
    }

    /**
     * @return array{ok:bool, message:string, slug:string, version:?string, rolled_back:bool}
     */
    private function result(bool $ok, string $message, string $slug, ?string $version, bool $rolledBack): array
    {
        return [
            'ok'          => $ok,
            'message'     => $message,
            'slug'        => $slug,
            'version'     => $version,
            'rolled_back' => $rolledBack,
        ];
    }
}
