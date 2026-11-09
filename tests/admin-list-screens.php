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
 * The ten DataTable list screens draw their table through osc_admin_data_table(), keep their
 * form and the three row-class filters, and carry no copied thead.
 *
 * Usage:  php tests/admin-list-screens.php
 */

require_once __DIR__ . '/lib/harness.php';

$base = dirname(__DIR__) . '/oc-admin/themes/modern/';

$screens = array(
    'items/index.php', 'items/reported.php', 'users/index.php', 'users/alerts.php', 'users/ban.php',
    'comments/index.php', 'pages/index.php', 'settings/keywordBlock.php', 'tools/logs.php', 'plugins/index.php',
);

foreach ($screens as $file) {
    harness_section($file);
    $src = (string)file_get_contents($base . $file);
    check('one osc_admin_data_table() call', substr_count($src, 'osc_admin_data_table(') === 1);
    check('no copied thead', strpos($src, '<thead') === false);
    check('no hand-made sort class', strpos($src, 'sorting_') === false);
    check('passes the sort and direction', strpos($src, "'sort'      => \$sort") !== false
        && strpos($src, "'direction' => \$direction") !== false);
    check('no table-row-actions copy', strpos($src, 'id="table-row-actions"') === false);
    if ($file !== 'tools/logs.php') {
        check('keeps the datatablesForm', strpos($src, 'datatablesForm') !== false);
    }
}

harness_section('row-class filters');
$filters = array(
    'items/index.php'    => 'datatable_listing_class',
    'users/index.php'    => 'datatable_user_class',
    'comments/index.php' => 'datatable_comment_class',
);
foreach ($filters as $file => $hook) {
    $src = (string)file_get_contents($base . $file);
    check("$file calls $hook with the raw row and the row", strpos(
        $src,
        "'row_class' => static fn (\$key, \$row) => osc_apply_filter('$hook', array(), \$aRawRows[\$key], \$row)"
    ) !== false);
}

harness_section('screen specifics');
$reported = (string)file_get_contents($base . 'items/reported.php');
check('reported blanks zero report counts', strpos($reported, "array('spam', 'bad', 'rep', 'exp', 'off')") !== false
    && strpos($reported, "'cell'") !== false);
$plugins = (string)file_get_contents($base . 'plugins/index.php');
check('plugins keep the row id, state class and market data', strpos($plugins, "'pkg-'") !== false
    && strpos($plugins, "'plugin-' .") !== false && strpos($plugins, "'data-market-item'") !== false);

exit(harness_result());
