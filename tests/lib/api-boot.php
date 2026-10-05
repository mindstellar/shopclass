<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Boot for the DB-free API tests: the autoloader, the harness, and the real hook and ETag
 * helpers. api_with_filter() adds a callback for the length of one call.
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__, 2) . '/');
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/test-clock.php';
require_once __DIR__ . '/api-doubles.php';

if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';

/**
 * Run $body with $callback added to $hook, then remove it.
 *
 * @return mixed what $body returns
 */
function api_with_filter(string $hook, callable $callback, callable $body)
{
    osc_add_filter($hook, $callback);
    try {
        return $body();
    } finally {
        osc_remove_filter($hook, $callback);
    }
}
