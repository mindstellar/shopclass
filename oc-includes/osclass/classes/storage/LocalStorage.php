<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\storage;

use mindstellar\utility\FileSystem;

/**
 * Class LocalStorage
 *
 * Default storage adapter: files live on the local filesystem under
 * UPLOADS_PATH (oc-content/uploads/), keyed relative to that directory,
 * exactly as item resources have always been stored.
 *
 * @package mindstellar\storage
 */
class LocalStorage implements StorageAdapter
{
    public function getId(): string
    {
        return 'local';
    }

    public function put(string $localPath, string $key, string $contentType): bool
    {
        try {
            (new FileSystem())->copy($localPath, UPLOADS_PATH . $key, true);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get(string $key): string|false
    {
        $path = UPLOADS_PATH . $key;
        if (!is_file($path)) {
            return false;
        }

        return file_get_contents($path);
    }

    public function exists(string $key): bool
    {
        return file_exists(UPLOADS_PATH . $key);
    }

    public function delete(string $key): bool
    {
        $path = UPLOADS_PATH . $key;
        if (!file_exists($path) || is_dir($path)) {
            return false;
        }

        try {
            (new FileSystem())->remove($path);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function url(string $key): string
    {
        return osc_base_url() . 'oc-content/uploads/' . $key;
    }

    public function isRemote(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }
}
