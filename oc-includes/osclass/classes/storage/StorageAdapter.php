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

/**
 * Interface StorageAdapter
 *
 * Contract for item-resource storage backends. An adapter stores and serves
 * files addressed by a key relative to its own root (for the bundled local
 * adapter that root is oc-content/uploads/).
 *
 * @package mindstellar\storage
 */
interface StorageAdapter
{
    /**
     * Unique identifier for this adapter, stored in t_item_resource.s_storage.
     *
     * @return string
     */
    public function getId(): string;

    /**
     * Store the file at $localPath under $key.
     *
     * @param string $localPath
     * @param string $key
     * @param string $contentType
     *
     * @return bool
     */
    public function put(string $localPath, string $key, string $contentType): bool;

    /**
     * Read the contents stored under $key.
     *
     * @param string $key
     *
     * @return string|false
     */
    public function get(string $key): string|false;

    /**
     * @param string $key
     *
     * @return bool
     */
    public function exists(string $key): bool;

    /**
     * @param string $key
     *
     * @return bool
     */
    public function delete(string $key): bool;

    /**
     * Public URL for $key.
     *
     * @param string $key
     *
     * @return string
     */
    public function url(string $key): string;

    /**
     * Whether this adapter stores files off the local filesystem.
     *
     * @return bool
     */
    public function isRemote(): bool;

    /**
     * Whether url() returns a directly-accessible (non-expiring, non-presigned) URL.
     *
     * @return bool
     */
    public function isPublic(): bool;
}
