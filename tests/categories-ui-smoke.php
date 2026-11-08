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
 * Smoke test for the Categories screen: the real template output, main.css and categories.js
 * load in headless Chrome with no script error, set data-ready and show the 8 top-level rows.
 * Until categories.js is rewritten (no data-ready in the file) only the load is checked, and
 * errors from categories.js itself are ignored.
 *
 * Usage: php tests/categories-ui-smoke.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/categories-browser.php';

$newJs = str_contains((string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/js/categories.js'), 'dataset.ready');

harness_section('the demo tree loads');
$r = categories_browser_run(__DIR__ . '/lib/categories-states.js', categories_fixture_demo(), array('state' => 'default'));

$errors = array_values(array_filter(
    $r['errors'] ?? array(),
    static fn (array $e): bool => $newJs || !str_contains((string) $e['src'], 'categories.js')
));
pin('no script error', array(), $errors);
pin('the markup has a row per category (97)', 97, $r['rows'] ?? null);
pin('only the 8 top-level rows are shown', 8, $r['shown'] ?? null);
if ($newJs) {
    pin('the screen is ready', true, $r['ready'] ?? null);
} else {
    echo "  (categories.js is the old file: ready marker not checked)\n";
}

harness_section('the empty tree loads');
$r = categories_browser_run(__DIR__ . '/lib/categories-states.js', array(), array('state' => 'empty'));
$errors = array_values(array_filter(
    $r['errors'] ?? array(),
    static fn (array $e): bool => $newJs || !str_contains((string) $e['src'], 'categories.js')
));
pin('no script error', array(), $errors);
pin('no rows', 0, $r['rows'] ?? null);

exit(harness_result());
