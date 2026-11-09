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
 * The dashboard, the four Statistics screens and the Version page sit in the shared body and
 * panels, with no Bootstrap row, widget box or duplicate chart caption.
 * Usage: php tests/admin-dashboard-structure.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

$dir   = ABS_PATH . 'oc-admin/themes/modern/';
$files = array('main/index.php', 'stats/items.php', 'stats/users.php', 'stats/comments.php', 'stats/reports.php', 'tools/version.php');

harness_section('shared structure');
foreach ($files as $f) {
    $src = (string) file_get_contents($dir . $f);
    check("$f: no widget-box", !preg_match('/class="[^"]*\\bwidget-box(?:-title|-content)?(?![\\w-])/', $src));
    check("$f: no bootstrap row", !preg_match('/class="row[ "]/', $src));
    check("$f: no stats-title markup", !str_contains($src, 'stats-title'));
    check("$f: body opened once per close", substr_count($src, 'osc_admin_body_open(') === substr_count($src, 'osc_admin_body_close('));
    check("$f: panels balance", substr_count($src, 'osc_admin_panel_open(') === substr_count($src, 'osc_admin_panel_close('));
}

harness_section('dashboard');
$src = (string) file_get_contents($dir . 'main/index.php');
check('body id dashboard', str_contains($src, "'id' => 'dashboard'"));
check('to ship panel keeps its id', str_contains($src, "'id' => 'dash-to-ship'"));
check('cards in a grid body', str_contains($src, "'class' => 'dash-cards'"));
foreach (array('placeholder-listing', 'widget-box-stats-listings') as $id) {
    check("keeps #$id", str_contains($src, $id));
}

harness_section('statistics charts keep their ids');
$ids = array(
    'stats/items.php'    => array('placeholder', 'placeholder_total', 'placeholder_alerts', 'placeholder_subscribers'),
    'stats/users.php'    => array('placeholder', 'by_country', 'by_region'),
    'stats/comments.php' => array('placeholder'),
    'stats/reports.php'  => array('placeholder'),
);
foreach ($ids as $f => $list) {
    $src = (string) file_get_contents($dir . $f);
    check("$f: #stats-page body", str_contains($src, "'id' => 'stats-page'"));
    foreach ($list as $id) {
        check("$f: id=\"$id\"", str_contains($src, 'id="' . $id . '"'));
    }
}

harness_section('styles');
$scss = (string) file_get_contents($dir . 'scss/pages-dashboard.scss');
check('dash-stats has no margin-bottom', !preg_match('/\.dash-stats\s*\{[^}]*margin-bottom/s', $scss));
check('dash-cards grid is defined', str_contains($scss, '.dash-cards'));
check('stats-title class stays defined', str_contains((string) file_get_contents($dir . 'scss/widget-box.scss') . (string) file_get_contents($dir . 'scss/pages-stats.scss'), 'stats-title'));
exit(harness_result());
