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
 * Pins the Plugins screen's Installed tab as an admin data table (bulk form, status tabs, search,
 * row actions, paging) and the Updates tab as a table, with every action link and update hook kept.
 * Usage:  php tests/admin-plugins-table.php
 */

$src = file_get_contents(dirname(__DIR__) . '/oc-admin/themes/modern/plugins/index.php');
$mkt = file_get_contents(dirname(__DIR__) . '/oc-admin/themes/modern/parts/market.php');
require_once __DIR__ . '/lib/harness.php';

harness_section('Installed tab');
$dt = file_get_contents(dirname(__DIR__) . '/oc-includes/osclass/classes/datatables/PluginsDataTable.php');
check('a data table form with bulk actions', strpos($src, '<form id="datatablesForm"') !== false
    && strpos($src, "'bulk'     => array('options' => __get('bulk_options'), 'form' => 'datatablesForm')") !== false
    && strpos($src, 'osc_admin_bulk_confirm_dialog()') !== false);
check('renders the table from the datatable columns', strpos($src, '<table class="table plugins-table"') !== false
    && preg_match('/foreach \(\$columns as \$k => \$v\).*?<th class="col-/s', $src) === 1);
check('one row per plugin, cells named for phones', preg_match('/foreach \(\$rows as \$key => \$row\).*?<tr id="pkg-.*?data-col-name=.*?<\/tr>/s', $src) === 1);
check('status tabs and a search box', strpos($src, 'osc_admin_tab_nav($tabs)') !== false && strpos($src, "'name'        => 'sSearch'") !== false);
check('row actions sit in the table', strpos($src, 'id="table-row-actions"') !== false && strpos($dt, '<div class="actions"><ul>') !== false);
check('paged like the other tables', strpos($src, 'osc_admin_pagination($aData)') !== false);
check('no longer a card list', strpos($src, 'osc_package_list_open') === false && strpos($src, 'osc_package_row(') === false);
foreach (array('Settings', 'Website', 'Support', 'Uninstall', 'Delete files', 'Enable', 'Disable', 'Install', 'Details') as $word) {
    check("keeps the $word action", strpos($dt, "__('$word')") !== false);
}
foreach (array('uninstall_dialog(', 'delete_plugin(', 'osc_csrf_token_url()', 'data-market-open-detail') as $needle) {
    check("keeps $needle", strpos($dt, $needle) !== false);
}
check('the detail dialog still finds its row', strpos($src, 'data-market-item=') !== false);

harness_section('Updates tab');
check('renders a table inside the list wrapper', preg_match('/class="market-updates-list[^"]*">\s*<table class="table"/', $mkt) === 1);
check('rows keep the hooks market.js reads', strpos($mkt, '<tr class="market-update-item"') !== false
    && strpos($mkt, 'data-market-slug=') !== false && strpos($mkt, "'update'") !== false);
check('Update all stays', strpos($mkt, 'market-update-all') !== false);

exit(harness_result());
