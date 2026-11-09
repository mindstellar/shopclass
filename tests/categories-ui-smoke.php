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
 *
 * Usage: php tests/categories-ui-smoke.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/categories-browser.php';

harness_section('the demo tree loads');
$r = categories_browser_run(__DIR__ . '/lib/categories-states.js', categories_fixture_demo(), array('state' => 'default'));

pin('no script error', array(), $r['errors'] ?? array());
pin('the markup has a row per category (97)', 97, $r['rows'] ?? null);
pin('only the 8 top-level rows are shown', 8, $r['shown'] ?? null);
pin('the screen is ready', true, $r['ready'] ?? null);

harness_section('the empty tree loads');
$r = categories_browser_run(__DIR__ . '/lib/categories-states.js', array(), array('state' => 'empty'));
pin('no script error', array(), $r['errors'] ?? array());
pin('no rows', 0, $r['rows'] ?? null);

exit(harness_result());
