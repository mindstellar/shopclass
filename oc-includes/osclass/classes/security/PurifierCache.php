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
use HTMLPurifier_DefinitionCacheFactory;

/**
 * Where HTMLPurifier keeps its built definitions between requests.
 *
 * Building a definition costs about as much as the purifying itself, so it is cached in
 * a randomly named folder under uploads, each file signed with the site's signing key.
 * With no writable folder or no key, the purifier builds in memory as before.
 */
class PurifierCache
{
    private const IMPL = 'ShopclassSigned';

    /** @var string|false|null resolved folder, false when unusable, null before the first call */
    private static $dir;

    /** @var string|false|null */
    private static $key;

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
        if ($dir === false || self::key() === false) {
            $config->set('Cache.DefinitionImpl', null);

            return;
        }
        HTMLPurifier_DefinitionCacheFactory::instance()->register(self::IMPL, SignedDefinitionCache::class);
        $config->set('Cache.DefinitionImpl', self::IMPL);
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

        if (!defined('UPLOADS_PATH') || defined('OSC_INSTALLING')) {
            return false;
        }
        $found = glob(UPLOADS_PATH . 'purifier-*', GLOB_ONLYDIR);
        if ($found) {
            $dir = $found[0];
        } else {
            // Checked first: core's error log records a failed mkdir() even when silenced.
            if (!is_writable(UPLOADS_PATH)) {
                return false;
            }
            $dir = UPLOADS_PATH . 'purifier-' . bin2hex(random_bytes(12));
            if (!@mkdir($dir, 0755) && !is_dir($dir)) {
                return false;
            }
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
     * The key cache files are signed with, or false when the site has none yet.
     *
     * @return string|false
     */
    public static function key()
    {
        if (self::$key !== null) {
            return self::$key;
        }
        self::$key = false;
        if (defined('OSC_INSTALLING')) {
            return false;
        }
        try {
            $secret = SigningKey::get();
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        return self::$key = hash_hmac('sha256', 'htmlpurifier-definition-cache', $secret);
    }

    /**
     * Forget the resolved folder and key, so the next call looks again.
     *
     * @return void
     */
    public static function reset()
    {
        self::$dir = null;
        self::$key = null;
    }
}
