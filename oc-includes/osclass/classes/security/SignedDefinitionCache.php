<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

use HTMLPurifier_DefinitionCache_Serializer;

/**
 * HTMLPurifier's file cache, with each file signed so a planted one is never unserialized.
 *
 * Files are written to a temporary name and renamed into place, so a reader never sees
 * half a file, and every failure is a quiet cache miss instead of a warning.
 */
class SignedDefinitionCache extends HTMLPurifier_DefinitionCache_Serializer
{
    public function add($def, $config)
    {
        if (!$this->checkDefType($def)) {
            return false;
        }
        $file = $this->generateFilePath($config);
        if (is_file($file)) {
            return false;
        }

        return $this->store($file, $def, $config);
    }

    public function set($def, $config)
    {
        if (!$this->checkDefType($def)) {
            return false;
        }

        return $this->store($this->generateFilePath($config), $def, $config);
    }

    public function replace($def, $config)
    {
        if (!$this->checkDefType($def)) {
            return false;
        }
        $file = $this->generateFilePath($config);
        if (!is_file($file)) {
            return false;
        }

        return $this->store($file, $def, $config);
    }

    public function get($config)
    {
        $file = $this->generateFilePath($config);
        $key  = PurifierCache::key();
        if ($key === false || !is_file($file)) {
            return false;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw) || strlen($raw) <= 64
            || !hash_equals(hash_hmac('sha256', substr($raw, 64), $key), substr($raw, 0, 64))) {
            @unlink($file);

            return false;
        }

        return unserialize(substr($raw, 64));
    }

    public function flush($config)
    {
        return is_writable($this->generateDirectoryPath($config)) && parent::flush($config);
    }

    public function cleanup($config)
    {
        return is_writable($this->generateDirectoryPath($config)) && parent::cleanup($config);
    }

    /**
     * @param \HTMLPurifier_Definition $def
     * @param \HTMLPurifier_Config     $config
     * @param string                   $file
     *
     * @return bool
     */
    private function store($file, $def, $config)
    {
        $key = PurifierCache::key();
        $dir = $this->generateDirectoryPath($config);
        if ($key === false) {
            return false;
        }
        if (!is_dir($dir) && (!is_writable(dirname($dir)) || (!@mkdir($dir, 0755) && !is_dir($dir)))) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }
        $data = serialize($def);
        // A leading dot keeps the half-written file out of flush() and cleanup().
        $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, hash_hmac('sha256', $data, $key) . $data) === false) {
            @unlink($tmp);

            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
