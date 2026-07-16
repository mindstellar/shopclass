<?php

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '5.3.0.dev');
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
