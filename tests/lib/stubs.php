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
 * Shared no-op/passthrough stand-ins repeated across the DB-free characterization tests.
 *
 * Opt-in: a test requires this file only where the real helper (hTranslations.php,
 * hPlugins.php) is never loaded, since none of these are guarded in the real files and a
 * stub defined first would make the real one fatal with "Cannot redeclare".
 *
 * Usage:  require_once __DIR__ . '/lib/stubs.php';  (or '../lib/stubs.php' under tests/models/)
 */

if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($hook, $content = '', ...$args)
    {
        return $content;
    }
}

if (!function_exists('osc_run_hook')) {
    function osc_run_hook($hook, ...$args)
    {
    }
}

if (!function_exists('osc_esc_html')) {
    function osc_esc_html($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

/* file end: ./tests/lib/stubs.php */
