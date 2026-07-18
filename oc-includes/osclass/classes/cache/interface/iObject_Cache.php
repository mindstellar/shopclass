<?php
/*
 * This file is part of Osclass (Mindstellar).
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
    public static function is_supported();

    /**
     * @param     $key
     * @param     $data
     * @param int $expire
     *
     * @return mixed
     */
    public function add($key, $data, $expire = 0);

    /**
     * @param     $key
     * @param     $data
     * @param int $expire
     *
     * @return mixed
     */
    public function set($key, $data, $expire = 0);

    /**
     * @param      $key
     * @param null $found
     *
     * @return mixed
     */
    public function get($key, &$found = null);

    /**
     * @param $key
     *
     * @return mixed
     */
    public function delete($key);

    public function flush();

    public function stats(); // return string

    public function _get_cache();

    public function __destruct();
}
