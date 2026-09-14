<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Interface iObject_Cache
 */
interface iObject_Cache
{
    /**
     * Whether the backing store this driver needs is available on this system.
     *
     * @return bool
     */
    public static function is_supported();

    /**
     * Store data under a key only when that key is not already set.
     *
     * @param int|string $key
     * @param mixed      $data
     * @param int        $expire seconds; 0 means the driver's default expiration
     *
     * @return bool False when the key already exists, true on success.
     */
    public function add($key, $data, $expire = 0);

    /**
     * Store data under a key, replacing anything already there.
     *
     * @param int|string $key
     * @param mixed      $data
     * @param int        $expire seconds; 0 means the driver's default expiration
     *
     * @return bool
     */
    public function set($key, $data, $expire = 0);

    /**
     * Read the value stored under a key.
     *
     * @param int|string $key
     * @param bool|null  $found set by reference to whether the key was present
     *
     * @return mixed False on a miss, the cached value otherwise.
     */
    public function get($key, &$found = null);

    /**
     * Remove the value stored under a key.
     *
     * @param int|string $key
     *
     * @return bool False when nothing was deleted, true on success.
     */
    public function delete($key);

    /**
     * Discard everything this driver holds.
     *
     * @return bool
     */
    public function flush();

    /**
     * Echo a debug panel of hit/miss counters for this request.
     *
     * @return void
     */
    public function stats();

    /**
     * The driver's identifier, i.e. the OSC_CACHE value that selects it.
     *
     * @return string
     */
    public function _get_cache();

    /**
     * @return void
     */
    public function __destruct();
}
