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
 * The billing order and wallet screens use the shared split body, panels, tables, status marks
 * and callouts, with no spacing utilities, and the phone layout still reaches the rail.
 *
 * Usage:  php tests/admin-billing-structure.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

$theme  = ABS_PATH . 'oc-admin/themes/modern/';
$order  = (string)file_get_contents($theme . 'billing/order.php');
$wallet = (string)file_get_contents($theme . 'billing/wallet.php');

foreach (array('billing/order.php' => $order, 'billing/wallet.php' => $wallet) as $name => $src) {
    harness_section($name);
    check('no hand-written table head', !str_contains($src, '<thead'));
    check('no hand-built callout', !str_contains($src, 'callout-block'));
    check('no raw status pill', preg_match('/class="osc-status/', $src) !== 1);
    check('no spacing utilities', preg_match('/\bm[tb]-\d/', $src) !== 1);
    check('a split body with the billing class', str_contains($src, "osc_admin_body_open(array('layout' => 'split', 'class' => 'billing-detail-grid'))"));
    check('one rail', substr_count($src, 'osc_admin_body_rail(') === 1);
    check('the body is closed', substr_count($src, 'osc_admin_body_close(') === 1);
    check('no section heading outside a panel', !str_contains($src, 'osc_admin_form_section('));
}

harness_section('order screen');
check('six tables on the shared helper', substr_count($order, 'osc_admin_table(') === 6, (string)substr_count($order, 'osc_admin_table('));
check('the next step keeps its class for the phone order', str_contains($order, "'class' => 'billing-detail-next'"));
check('the summary keeps its class', str_contains($order, "'class' => 'billing-detail-summary'"));
check('four callouts use the helper as html', substr_count($order, "osc_admin_callout('warning'") === 4 && substr_count($order, "array('html' => true));") >= 4);
check('status marks use the helper', substr_count($order, 'Form::statusBadge(') === 3);
check('send-now inputs keep their names', str_contains($order, 'name="qty[\' . (int) $line[\'id\'] . \']"'));
check('the Total row is a row header across the columns', str_contains($order, "'th' => true, 'colspan' => count(\$itemColumns) - 1"));
check('cell sub-lines are block elements', str_contains($order, '<div class="text-muted">') && !str_contains($order, '<br><span'));
check('address lines still join with br', str_contains($order, "implode('<br>', array_map('osc_esc_html', Receipts::addressLines"));

harness_section('wallet screen');
check('the history table uses the helper', substr_count($wallet, 'osc_admin_table(') === 1);
check('history sits in a panel', str_contains($wallet, "osc_admin_panel_open(__('History'))"));
check('a negative balance after still marks its cell', str_contains($wallet, "'class' => \$after < 0 ? 'balance-negative' : ''"));

harness_section('stylesheet');
$scss = (string)file_get_contents($theme . 'scss/pages-billing.scss');
check('the phone rule dissolves the rail as well', str_contains($scss, '.billing-detail-grid.osc-body-split > :is(div, aside)'));
check('the phone order rules stay', str_contains($scss, '.billing-detail-next') && str_contains($scss, '.billing-detail-summary'));
check('no second grid declaration', preg_match('/\.billing-detail-grid\s*\{[^}]*grid-template-columns/', $scss) !== 1);
check('no panel sibling margin', !str_contains($scss, ':is(.widget-box, .osc-panel) + :is(.widget-box, .osc-panel)'));

harness_section('Total row render');
ob_start();
osc_admin_table(
    array(
        'item'   => 'Item',
        'qty'    => array('label' => 'Qty', 'numeric' => true),
        'amount' => array('label' => 'Amount', 'numeric' => true),
    ),
    array(array(
        'item'   => array('html' => 'Total', 'th' => true, 'colspan' => 2),
        'amount' => array('html' => '<strong>9</strong>'),
    ))
);
$html = (string)ob_get_clean();
check('the row header spans the columns before the amount', str_contains($html, '<th scope="row" colspan="2" data-col-name="Item">Total</th>'), $html);
check('the amount stays in its numeric column', str_contains($html, '<td class="col-numeric" data-col-name="Amount"><strong>9</strong></td>'), $html);

exit(harness_result());
