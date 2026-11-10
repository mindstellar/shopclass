<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Stand-ins and small helpers repeated across the DB-free admin UI tests.
 *
 * Require it before the real helpers load, only where hPlugins.php and hDefines.php are never loaded.
 */

if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}

// A test that loads the real hPlugins.php defines OSC_TEST_REAL_HOOKS first.
if (!defined('OSC_TEST_REAL_HOOKS') && !function_exists('osc_add_hook')) {
    function osc_add_hook($hook, $fn, $priority = 5)
    {
    }
}

if (!function_exists('osc_locale_thousands_sep')) {
    function osc_locale_thousands_sep()
    {
        return ',';
    }
}

if (!function_exists('html_of')) {
    /** What a callable echoes. */
    function html_of(callable $fn): string
    {
        ob_start();
        $fn();

        return (string)ob_get_clean();
    }
}

if (!function_exists('squeeze')) {
    /** Markup with runs of whitespace closed up, so a test can compare it. */
    function squeeze(string $html): string
    {
        $html = (string)preg_replace('/\s+/', ' ', $html);

        return trim((string)preg_replace(array('/>\s+/', '/\s+</'), array('>', '<'), $html));
    }
}
