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
    /**
     * Adapter id stored in t_item_resource.s_storage.
     *
     * @return string
     */
    public function getId(): string
    {
        return 'local';
    }

    /**
     * Copies the file into UPLOADS_PATH . $key, overwriting whatever is there.
     *
     * @param string $localPath
     * @param string $key
     * @param string $contentType Ignored: the local filesystem stores no content type
     *
     * @return bool false when the copy failed
     */
    public function put(string $localPath, string $key, string $contentType): bool
    {
        try {
            (new FileSystem())->copy($localPath, UPLOADS_PATH . $key, true);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Reads the file stored under $key.
     *
     * @param string $key
     *
     * @return string|false false when the file is missing or unreadable
     */
    public function get(string $key): string|false
    {
        $path = UPLOADS_PATH . $key;
        if (!is_file($path)) {
            return false;
        }

        return file_get_contents($path);
    }

    /**
     * Whether UPLOADS_PATH . $key exists.
     *
     * @param string $key
     *
     * @return bool
     */
    public function exists(string $key): bool
    {
        return file_exists(UPLOADS_PATH . $key);
    }

    /**
     * Removes the file under $key; refuses directories and missing paths.
     *
     * @param string $key
     *
     * @return bool
     */
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

    /**
     * Public URL under oc-content/uploads/.
     *
     * @param string $key
     *
     * @return string
     */
    public function url(string $key): string
    {
        return osc_base_url() . 'oc-content/uploads/' . $key;
    }

    /**
     * Always false: this adapter is the local filesystem.
     *
     * @return bool
     */
    public function isRemote(): bool
    {
        return false;
    }

    /**
     * Always true: uploads are served directly by the web server.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return true;
    }
}
