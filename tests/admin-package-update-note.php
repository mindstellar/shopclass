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
 * The "Update to version X" line under an installed plugin or theme, and the stacked state and
 * site-mode badges in the plugins Status cell. DB-free.
 *
 * Usage: php tests/admin-package-update-note.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
define('OSCLASS_VERSION', '7.1.0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

function osc_admin_status($state, $word)
{
    echo '<span class="osc-status status-' . $state . '">' . $word . '</span>';
}
function osc_site_mode()
{
    return 'shop';
}
function osc_admin_callout()
{
}
function osc_admin_count()
{
}
function osc_admin_empty()
{
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/market.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/package-ui.php';

$row = array(
    'slug' => 'tax-rates', 'name' => 'Tax', 'installed_version' => '1.0', 'new_version' => '1.2',
    'compat' => array('status' => 'compatible', 'blocked' => false, 'reason' => ''),
);
$meta = array('writable' => true, 'disabled' => false);
$note = static function (array $row, array $meta): string {
    ob_start();
    osc_market_render_update_note($row, $meta);

    return (string) ob_get_clean();
};

harness_section('update available');
$html = $note($row, $meta);
check('shows an Update to version button', strpos($html, 'Update to version 1.2') !== false, $html);
check('the button is the shared update action', strpos($html, 'class="btn btn-sm btn-primary market-card-action market-action-btn"') !== false
    && strpos($html, 'data-market-action="update"') !== false && strpos($html, 'data-market-slug="tax-rates"') !== false
    && strpos($html, 'data-market-version="1.2"') !== false, $html);
check('the note is marked so the script can remove it', strpos($html, 'data-market-update-note') !== false);

harness_section('blocked');
foreach (array(
    'incompatible release' => array(array('compat' => array('status' => 'incompatible', 'blocked' => true, 'reason' => 'Needs PHP 9.')) + $row, $meta, 'Needs PHP 9.'),
    'demo or in-app updates off' => array($row, array('writable' => true, 'disabled' => true), 'disabled'),
    'folder not writable' => array($row, array('writable' => false, 'disabled' => false), 'writable'),
) as $label => [$r, $m, $word]) {
    $html = $note($r, $m);
    check("$label: shows the reason", strpos($html, $word) !== false, $html);
    check("$label: no button", strpos($html, '<button') === false, $html);
}

harness_section('status badges');
$html = osc_package_status_badges('uninstalled', array('classifieds'));
check('state and mode badge are separate stacked items', substr_count($html, 'class="osc-pkg-badge"') === 2
    && strpos($html, 'class="osc-pkg-badges"') !== false && strpos($html, 'Not marked for') !== false, $html);
check('one badge when the mode matches', substr_count(osc_package_status_badges('active', array('shop')), 'class="osc-pkg-badge"') === 1);

exit(harness_result());
