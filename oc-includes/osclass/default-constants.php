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

if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '5.3.0.dev3');
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

if (function_exists('memory_get_usage') && ( (int) @ini_get('memory_limit') < abs((int) OSC_MEMORY_LIMIT) )) {
    @ini_set('memory_limit', OSC_MEMORY_LIMIT);
}

if (!defined('CLI')) {
    define('CLI', false);
}

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
