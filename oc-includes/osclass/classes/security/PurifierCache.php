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

use HTMLPurifier_Config;

/**
 * Where HTMLPurifier keeps its built definitions between requests.
 *
 * Building a definition costs about as much as the purifying itself, so it is cached
 * in a folder under uploads whose name is derived from the site's database secret.
 * When that folder cannot be made or written, the purifier builds in memory as before.
 */
class PurifierCache
{
    /** @var string|false|null resolved folder, false when unusable, null before the first call */
    private static $dir;

    /**
     * Point a purifier config at the cache folder, or at no cache when there is none.
     *
     * @param HTMLPurifier_Config $config
     *
     * @return void
     */
    public static function apply(HTMLPurifier_Config $config)
    {
        $dir = self::dir();
        if ($dir === false) {
            $config->set('Cache.DefinitionImpl', null);

            return;
        }
        $config->set('Cache.SerializerPath', $dir);
    }

    /**
     * The cache folder, created on first use, or false when it cannot be used.
     *
     * @return string|false
     */
    public static function dir()
    {
        if (self::$dir !== null) {
            return self::$dir;
        }
        self::$dir = false;

        if (!defined('UPLOADS_PATH') || !defined('DB_PASSWORD')) {
            return false;
        }
        $secret = DB_PASSWORD . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|'
            . (defined('DB_USER') ? DB_USER : '') . '|' . (defined('ABS_PATH') ? ABS_PATH : '');
        $dir    = UPLOADS_PATH . 'purifier-' . substr(hash_hmac('sha256', 'htmlpurifier', $secret), 0, 24);

        // Checked first: core's error log records a failed mkdir() even when silenced.
        if (!is_dir($dir) && (!is_writable(UPLOADS_PATH) || (!@mkdir($dir, 0755) && !is_dir($dir)))) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n");
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }

        return self::$dir = $dir;
    }

    /**
     * Forget the resolved folder, so the next call looks again.
     *
     * @return void
     */
    public static function reset()
    {
        self::$dir = null;
    }
}
