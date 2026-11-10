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
 * The list screens use the shared filter, status, count and callout components: no hand-built
 * table-toolbar or badge classes, and the filter keeps the ids the controllers and CSS read.
 *
 * Usage:  php tests/admin-lists-components.php
 */

declare(strict_types=1);

use mindstellar\admin\ui\Form;

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/admin-ui-stubs.php';

function osc_run_hook($hook, ...$args)
{
}
function osc_add_filter($hook, $fn, $priority = 5)
{
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

if (!function_exists('_e')) {
    function _e($text)
    {
        echo $text;
    }
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/** Collapses the indentation of a PHP template so markup can be compared exactly. */
function squeezed(string $html): string
{
    return trim((string)preg_replace('~\s+~', ' ', (string)preg_replace('~>\s+<~', '><', $html)));
}

$root  = ABS_PATH;
$files = array(
    'oc-admin/themes/modern/users/alerts.php',
    'oc-admin/themes/modern/settings/currencies.php',
    'oc-admin/themes/modern/tools/logs.php',
    'oc-admin/themes/modern/billing/documents.php',
    'oc-admin/themes/modern/media/index.php',
    'oc-admin/themes/modern/messages/index.php',
    'oc-includes/osclass/classes/datatables/ItemsDataTable.php',
    'oc-includes/osclass/classes/datatables/AlertsDataTable.php',
    'oc-includes/osclass/classes/controller/admin/CAdminLanguages.php',
);

harness_section('no hand-built markup in the list screens');
foreach ($files as $file) {
    $src = (string)file_get_contents($root . $file);
    check($file . ': no table-toolbar', !str_contains($src, 'table-toolbar'));
    check($file . ': no badge bg-', !str_contains($src, 'badge bg-'));
    check($file . ': no raw osc-status span', !str_contains($src, '<span class="osc-status'));
}
foreach (array('users/alerts.php', 'tools/logs.php', 'billing/documents.php', 'media/index.php') as $f) {
    check($f . ' uses osc_admin_list_filter', str_contains((string)file_get_contents($root . 'oc-admin/themes/modern/' . $f), 'osc_admin_list_filter('));
}
check(
    'alerts uses osc_admin_callout and keeps shortcut-filters / fPattern',
    str_contains($a = (string)file_get_contents($root . 'oc-admin/themes/modern/users/alerts.php'), "osc_admin_callout('warning'")
        && str_contains($a, "'shortcut-filters'") && str_contains($a, "'fPattern'")
);
check('messages uses osc_admin_status', str_contains((string)file_get_contents($root . 'oc-admin/themes/modern/messages/index.php'), "osc_admin_status('',"));
check('language Yes/No are status badges', str_contains((string)file_get_contents($root . 'oc-includes/osclass/classes/controller/admin/CAdminLanguages.php'), "'active' : 'inactive'"));

harness_section('rendered filters');
$logs = html_of(static fn () => osc_admin_list_filter(array(
    'page'   => 'tools',
    'hidden' => array('action' => 'logs'),
    'active' => true,
    'reset'  => 'https://example.test/r',
    'fields' => array(
        array('type' => 'select', 'name' => 'section', 'label' => 'Section', 'value' => 'b', 'placeholder' => 'All', 'options' => array('a' => 'a', 'b' => 'b')),
        array('name' => 'q', 'value' => 'x', 'placeholder' => 'details'),
    ),
)));
check('logs filter: select, search, reset', preg_match('~<option value="b"\s+selected~', $logs) === 1 && str_contains($logs, 'name="q"') && str_contains($logs, 'osc-filter-reset'), $logs);
$alerts = html_of(static fn () => osc_admin_list_filter(array(
    'id'     => 'shortcut-filters',
    'page'   => 'users',
    'hidden' => array('action' => 'alerts', 'held' => '1'),
    'fields' => array(array('name' => 'sSearch', 'id' => 'fPattern', 'value' => 'a@b')),
)));
check(
    'alerts filter keeps its id, hidden held and search id',
    str_contains($alerts, 'id="shortcut-filters"') && str_contains($alerts, 'name="held"') && str_contains($alerts, 'id="fPattern"'),
    $alerts
);

harness_section('rendered status cells');
$two = Form::statusBadge('active', 'Yes') . Form::statusBadge('inactive', 'No') . osc_admin_count(3, '', 'a: 2, b: 1');
check(
    'two language cells and a report count',
    $two === '<span class="osc-status status-active">Yes</span><span class="osc-status status-inactive">No</span>'
        . ' <span class="osc-count" title="a: 2, b: 1">3</span>',
    $two
);

exit(harness_result());
