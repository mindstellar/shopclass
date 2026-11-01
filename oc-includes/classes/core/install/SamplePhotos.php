<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\install;

use mindstellar\upgrade\Upgrade;
use mindstellar\utility\FileSystem;
use mindstellar\utility\Zip;

/**
 * The photo pack for the sample listings. It is not shipped with core: it is downloaded once,
 * checked against a pinned sha256, and its root *.webp files are unzipped to a temp folder.
 *
 * @package mindstellar\install
 */
final class SamplePhotos
{
    public const URL = 'https://github.com/mindstellar/shopclass-sample-data/releases/download/v1.0.0/sample-photos.zip';

    public const SHA256 = 'be0ceeeda9d6a16552cc083a377b994e4dcaead17a6caf38d5bc0e54c70cbaf4';

    private const MAX_FILES = 200;

    private const MAX_FILE_BYTES = 2097152;

    private const MAX_TOTAL_BYTES = 52428800;

    private ?string $localZip;

    private string $sha256;

    private string $url;

    private string $workPath;

    private ?string $zipFile = null;

    private ?string $folder = null;

    private bool $missing = false;

    /**
     * @param string|null $localZip a copy of the pack on disk to use instead of the download; it must match the same checksum
     * @param string      $sha256   checksum the zip must match
     * @param string      $url      https download on an allowed package host
     * @param string|null $workPath folder for the zip and the photos; oc-content/downloads/ when null
     */
    public function __construct(?string $localZip = null, string $sha256 = self::SHA256, string $url = self::URL, ?string $workPath = null)
    {
        $this->localZip = $localZip;
        $this->sha256   = strtolower($sha256);
        $this->url      = $url;
        $this->workPath = rtrim($workPath ?? CONTENT_PATH . 'downloads/', '/') . '/';
    }

    /**
     * Download the pack, or read the local copy, and unzip its photos. Call clean() afterwards.
     *
     * @return string|null folder of the photos, or null when there are none to use
     */
    public function fetch(): ?string
    {
        $folder        = $this->unpack();
        $this->missing = $folder === null;

        return $folder;
    }

    /**
     * Whether the last fetch() found no photos to use, so the installer can say so.
     *
     * @return bool
     */
    public function missing(): bool
    {
        return $this->missing;
    }

    /**
     * Get the zip and unzip its photos.
     *
     * @return string|null folder of the photos
     */
    private function unpack(): ?string
    {
        if (osc_package_installs_disabled() || !Upgrade::downloadsReady($this->workPath)) {
            return null;
        }
        $fs = new FileSystem();
        try {
            $id  = $fs->generateUniqueId('sample_');
            $zip = $this->localZip;
            if ($zip === null) {
                if (!FileSystem::isPackageDownloadUrl($this->url)) {
                    return null;
                }
                // downloadFile() checks the sha256.
                $this->zipFile = $this->workPath . $id . '.zip';
                $zip           = $fs->downloadFile($this->url, $this->zipFile, null, true, $this->sha256);
                if (!is_string($zip)) {
                    return null;
                }
            } elseif (!is_file($zip) || !hash_equals($this->sha256, (string) hash_file('sha256', $zip))) {
                return null;
            }
            $this->folder = $this->workPath . $id;
            if ($this->unzip($zip, $this->folder)) {
                return $this->folder . '/';
            }
            $this->clean();

            return null;
        } catch (\Throwable $e) {
            error_log('Shopclass install: sample photos could not be fetched: ' . $e->getMessage());
            $this->clean();

            return null;
        } finally {
            if ($this->zipFile !== null) {
                $fs->remove($this->zipFile);
                $this->zipFile = null;
            }
        }
    }

    /**
     * Whether a folder is one this class unpacked in its work folder.
     *
     * @param string $folder
     *
     * @return bool
     */
    public function owns(string $folder): bool
    {
        $real = realpath($folder);
        $work = realpath($this->workPath);

        return $real !== false && $work !== false && is_dir($real)
            && dirname($real) === $work && str_starts_with(basename($real), 'sample_');
    }

    /**
     * Delete a photo folder an earlier request unpacked.
     *
     * @param string $folder
     *
     * @return void
     */
    public function discard(string $folder): void
    {
        if ($this->owns($folder)) {
            (new FileSystem())->remove($folder);
        }
    }

    /**
     * Delete the unzipped photos.
     *
     * @return void
     */
    public function clean(): void
    {
        if ($this->folder !== null) {
            (new FileSystem())->remove($this->folder);
            $this->folder = null;
        }
    }

    /**
     * Write the root *.webp files to $to and drop any that is not a real webp. An unsafe entry
     * or one over the limits rejects the pack.
     *
     * @param string $zipFile
     * @param string $to
     *
     * @return bool true when at least one photo was written
     */
    private function unzip(string $zipFile, string $to): bool
    {
        $zip = (new Zip())
            ->limit(self::MAX_FILES, self::MAX_FILE_BYTES, self::MAX_TOTAL_BYTES)
            ->onlyRootFiles('/^[a-z0-9][a-z0-9-]*\.webp$/');
        if ($zip->unzipFile($zipFile, $to) !== 1) {
            return false;
        }
        $photos = glob($to . '/*.webp') ?: array();
        foreach ($photos as $i => $photo) {
            // A webp file starts "RIFF" .... "WEBP".
            $head = (string) file_get_contents($photo, false, null, 0, 12);
            if (strncmp($head, 'RIFF', 4) !== 0 || substr($head, 8, 4) !== 'WEBP') {
                unlink($photo);
                unset($photos[$i]);
            }
        }

        return $photos !== array();
    }
}
