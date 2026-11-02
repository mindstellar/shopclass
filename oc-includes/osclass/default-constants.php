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

if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '7.1.0.beta4');
}

// The oldest version that may update straight to this release, or '' for any. An older site is
// offered a release in between first. The release build copies it into the release notes.
if (!defined('OSCLASS_UPDATE_FROM')) {
    define('OSCLASS_UPDATE_FROM', '7.0.12');
}

// Roughly where a search engine stops showing a description, and the budget
// meta_description() fills — most distinguishing part first.
if (!defined('OSC_META_DESCRIPTION_LENGTH')) {
    define('OSC_META_DESCRIPTION_LENGTH', 155);
}

if (!defined('MULTISITE')) {
    define('MULTISITE', 0);
}

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}

if (!defined('LIB_PATH')) {
    define('LIB_PATH', ABS_PATH . 'oc-includes/');
}

if (!defined('CONTENT_PATH')) {
    define('CONTENT_PATH', ABS_PATH . 'oc-content/');
}

if (!defined('CONTENT_WEB_PATH') && defined('WEB_PATH')) {
    define('CONTENT_WEB_PATH', WEB_PATH . 'oc-content/');
}

if (!defined('THEMES_PATH')) {
    define('THEMES_PATH', CONTENT_PATH . 'themes/');
}

if (!defined('THEMES_WEB_PATH') && defined('CONTENT_WEB_PATH')) {
    define('THEMES_WEB_PATH', CONTENT_WEB_PATH . 'themes/');
}

if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', CONTENT_PATH . 'plugins/');
}

if (!defined('PLUGINS_WEB_PATH') && defined('CONTENT_WEB_PATH')) {
    define('PLUGINS_WEB_PATH', CONTENT_WEB_PATH . 'plugins/');
}

if (!defined('TRANSLATIONS_PATH')) {
    define('TRANSLATIONS_PATH', CONTENT_PATH . 'languages/');
}

if (!defined('TRANSLATIONS_WEB_PATH') && defined('CONTENT_WEB_PATH')) {
    define('TRANSLATIONS_WEB_PATH', CONTENT_WEB_PATH . 'languages/');
}

if (!defined('UPLOADS_PATH')) {
    define('UPLOADS_PATH', CONTENT_PATH . 'uploads/');
}

if (!defined('UPLOADS_WEB_PATH') && defined('CONTENT_WEB_PATH')) {
    define('UPLOADS_WEB_PATH', CONTENT_WEB_PATH . 'uploads/');
}

if (!defined('OSC_DEBUG_DB')) {
    define('OSC_DEBUG_DB', false);
}

if (!defined('OSC_DEBUG_DB_LOG')) {
    define('OSC_DEBUG_DB_LOG', false);
}

if (!defined('OSC_DEBUG_DB_EXPLAIN')) {
    define('OSC_DEBUG_DB_EXPLAIN', false);
}

if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}

if (!defined('OSC_DEBUG_LOG')) {
    define('OSC_DEBUG_LOG', false);
}

if (!defined('OSC_MEMORY_LIMIT')) {
    define('OSC_MEMORY_LIMIT', '32M');
}

if (!function_exists('osc_ini_bytes')) {
    /**
     * A php.ini size such as 128M, 1G or 512K in bytes; -1 (unlimited) comes back as -1.
     * It runs before the autoloader, so it cannot call Formatting::iniBytes(); a test keeps the two equal.
     */
    function osc_ini_bytes(string $size): int
    {
        $size = trim($size);
        if ($size === '' || (int) $size < 0) {
            return -1;
        }
        $bytes = (int) $size;

        return match (strtoupper(substr($size, -1))) {
            'G'     => $bytes * 1073741824,
            'M'     => $bytes * 1048576,
            'K'     => $bytes * 1024,
            default => $bytes,
        };
    }
}

// Only ever raise the limit; a host's bigger or unlimited setting stays.
$oscMemoryNow = osc_ini_bytes((string) ini_get('memory_limit'));
$oscMemoryWant = osc_ini_bytes((string) OSC_MEMORY_LIMIT);
if ($oscMemoryNow !== -1 && ($oscMemoryWant === -1 || $oscMemoryNow < $oscMemoryWant)) {
    @ini_set('memory_limit', (string) OSC_MEMORY_LIMIT);
}
unset($oscMemoryNow, $oscMemoryWant);

if (!defined('CLI')) {
    define('CLI', false);
}

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
