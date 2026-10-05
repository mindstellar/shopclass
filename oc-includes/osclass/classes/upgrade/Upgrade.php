<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Created by Navjot Tomer (Mindstellar).
 * User: navjottomer
 * Date: 07/05/20
 * Time: 4:49 PM
 * License is provided in root directory.
 */

namespace mindstellar\upgrade;

use mindstellar\utility\FileSystem;
use mindstellar\utility\Zip;
use RuntimeException;

/**
 * Class upgrade
 *
 * @package mindstellar\osclass\classes
 */
class Upgrade
{
    /**
     * @var bool
     */
    private $packageInfoValid;

    /**
     * @var \mindstellar\utility\Zip
     */
    private $Zip;

    /**
     * @var \mindstellar\utility\FileSystem
     */
    private $FileSystem;

    /**
     * @var \mindstellar\upgrade\UpgradePackage
     */
    private $objPackage;

    /**
     * Upgrade constructor.
     *
     * @param \mindstellar\upgrade\UpgradePackage $packageObj
     */
    public function __construct(UpgradePackage $packageObj)
    {
        $this->objPackage = $packageObj;
        $this->validatePackageInfo();
        $this->Zip        = new Zip();
        $this->FileSystem = new FileSystem();
    }

    /**
     * Flag the package info as usable, and reject a checksum-carrying package from a host
     * that is not on the allowlist.
     *
     * @return void
     * @throws \RuntimeException when a verified download points outside the allowed hosts
     */
    private function validatePackageInfo()
    {
        $this->packageInfoValid = false;
        if (is_array($this->objPackage->getFilteredFiles())
            && filter_var($this->objPackage->getSourceUrl(), FILTER_VALIDATE_URL)
        ) {
            $this->packageInfoValid = true;
        }

        // Only a checksum-carrying package (resolved through the Catalog, always a GitHub
        // release asset) is held to the host allowlist; a package resolved from a site's own
        // "Plugin/Theme update URI" never gets a checksum and is left free to point anywhere,
        // as it always has, so existing self-hosted update setups keep working.
        if ($this->packageInfoValid
            && $this->objPackage->getSha256() !== null
            && !FileSystem::isAllowedPackageHost($this->objPackage->getSourceUrl())
        ) {
            throw new RuntimeException(
                __('Package source host is not on the allowed list for verified downloads.')
            );
        }
    }

    /**
     * Do an actual upgrade
     *
     * @return void
     * @throws \RuntimeException when the package info is invalid, incompatible or not upgradable
     * @throws \Exception
     */
    public function doUpgrade()
    {
        if ($this->packageInfoValid !== true) {
            throw new RuntimeException(__('Unable to follow upgrade, invalid package info'));
        }
        if ($this->objPackage->isCompatible() && $this->objPackage->isUpgradable()) {
            $this->processUpgrade();
        } else {
            throw new RuntimeException($this->objPackage->getTitle() . ' ' . 'is not compatible/upgradable.');
        }
    }

    /**
     * The folder holding the package's index.php: the zip root, or one of its allowed
     * top-level folders, or null when there is none.
     *
     * @param string        $extracted
     * @param array<string> $folders
     *
     * @return string|null
     */
    public static function packageRoot(string $extracted, array $folders): ?string
    {
        if (file_exists($extracted . '/index.php')) {
            return $extracted;
        }
        foreach ($folders as $folder) {
            if ($folder !== '' && file_exists($extracted . '/' . $folder . '/index.php')) {
                return $extracted . '/' . $folder;
            }
        }

        return null;
    }

    /**
     * Target paths the package would write but this PHP user cannot. A new file counts as
     * writable when its nearest existing parent folder is.
     *
     * @param string        $originDir
     * @param string        $targetDir
     * @param array<string> $filter    names sync() skips
     * @param int           $limit     stop after this many
     *
     * @return array<string>
     */
    public static function unwritable(string $originDir, string $targetDir, array $filter = [], int $limit = 6): array
    {
        $originDir = rtrim($originDir, '/\\');
        $targetDir = rtrim($targetDir, '/\\');
        $iterator  = (new \mindstellar\utility\FileSystem())->filteredIterator($originDir, $filter, \RecursiveIteratorIterator::LEAVES_ONLY);

        $blocked = [];
        $checked = [];
        foreach ($iterator as $file) {
            $target = $targetDir . substr($file->getPathname(), strlen($originDir));
            $path   = $target;
            while (!file_exists($path) && dirname($path) !== $path) {
                $path = dirname($path);
            }
            if (isset($checked[$path])) {
                continue;
            }
            $checked[$path] = true;
            if (!is_writable($path)) {
                $blocked[] = $path;
                if (count($blocked) >= $limit) {
                    break;
                }
            }
        }

        return $blocked;
    }

    /**
     * Explain which files block the upgrade and how to get past it.
     *
     * @param array<string> $paths
     *
     * @return string
     */
    private static function unwritableMessage(array $paths): string
    {
        $user = \mindstellar\admin\SystemChecks::userName(function_exists('posix_geteuid') ? posix_geteuid() : null);

        return sprintf(
            __('Nothing was changed. The web server user (%1$s) cannot write to: %2$s. Give that user write access, or run "php oc-cli.php core:update" as the owner of the files.'),
            $user !== '' ? $user : __('unknown'),
            implode(', ', array_map(static fn ($p) => str_replace(ABS_PATH, '', $p), $paths))
        );
    }

    /**
     * process package upgrade
     *
     * @return void
     * @throws \RuntimeException on a zip whose layout has no index.php at the expected root
     * @throws \Exception
     */
    private function processUpgrade()
    {
        $extracted_package_path = $this->downloadPackageAndExtract();
        if (!$extracted_package_path) {
            throw new RuntimeException(__('The download failed, or did not match its checksum. Nothing was changed.'));
        }

        try {
            $originDir = self::packageRoot($extracted_package_path, $this->objPackage->getFolderNames());
            if ($originDir === null) {
                throw new RuntimeException(
                    __("Invalid Zip package, it's not in valid format.")
                );
            }

            $blocked = self::unwritable(
                $originDir,
                $this->objPackage->getTargetDirectory(),
                $this->objPackage->getFilteredFiles()
            );
            if ($blocked !== []) {
                throw new RuntimeException(self::unwritableMessage($blocked));
            }

            // Enable maintenance mode. The marker locks visitors out even when the admin
            // has chosen banner-only maintenance, since files are being replaced.
            $this->FileSystem->writeToFile(ABS_PATH . '.maintenance', OSC_MAINTENANCE_UPGRADE_MARKER);

            if ($this->FileSystem->exists($originDir)) {
                $this->FileSystem->sync(
                    $originDir . '/',
                    $this->objPackage->getTargetDirectory(),
                    null,
                    $this->objPackage->getFilteredFiles() //Don't overwrite these files or directory while upgrading
                );
                // A server that caches compiled PHP would otherwise keep running the old files.
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
            } else {
                throw new RuntimeException(
                    $originDir . ' '
                    . __("doesn't exists, unknown error occurred while downloading and extracting package.")
                );
            }

            $this->objPackage->afterProcessUpgrade();
        } finally {
            // Unconditional: leaving the extracted temp directory or the maintenance flag
            // behind on a thrown error is its own follow-up problem for the next request.
            $this->FileSystem->remove($extracted_package_path);
            $this->FileSystem->remove(ABS_PATH . '.maintenance');
            osc_purge_page_cache('upgrade');
        }
    }

    /**
     * Download and extract upgrade package
     *
     * @return bool|string return extracted package path on success or false on failure.
     * @throws \Exception
     */
    private function downloadPackageAndExtract()
    {
        $unique_id       = $this->FileSystem->generateUniqueId('package_');
        $unique_filename = $unique_id . '.zip';
        $download_path   = CONTENT_PATH . 'downloads/';
        $zip_file        = $download_path . $unique_filename;
        $extract_path    = $download_path . $unique_id;

        if (!is_dir($download_path)) {
            @mkdir($download_path, 0755, true);
        }
        if (!is_dir($download_path) || !is_writable($download_path)) {
            throw new RuntimeException(self::unwritableMessage([$download_path]));
        }

        try {
            $downloaded = $this->FileSystem->downloadFile(
                $this->objPackage->getSourceUrl(),
                $zip_file,
                null,
                true,
                $this->objPackage->getSha256()
            );

            if (!$downloaded) {
                return false;
            }

            $resultCode = $this->Zip->unzipFile($downloaded, $extract_path);
            if ($resultCode === 1) {
                return $extract_path;
            }
            throw new RuntimeException(__('Unable to unzip package file.'));
        } catch (\Throwable $e) {
            // unzipFile() can still leave partial output in $extract_path on a -1 (read/write)
            // failure part-way through; never leave that behind on any failure path.
            $this->FileSystem->remove($extract_path);
            throw $e;
        } finally {
            $this->FileSystem->remove($zip_file);
        }
    }
}
