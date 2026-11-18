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
 * The sidebar prints no notice dot beside a section or a sub-item; the bell carries every notice.
 *
 * Usage: php tests/admin-menu-marks.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

$GLOBALS['moderator'] = false;

function osc_is_moderator()
{
    return $GLOBALS['moderator'];
}

foreach (['__' => 'return $s;', '_n' => 'return $n === 1 ? $s : $p;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($s, $p = "", $n = 0) { ' . $body . ' }');
    }
}
if (!function_exists('osc_admin_notice_name')) {
    function osc_admin_notice_name(int $count, int $action): string
    {
        if ($count < 1) {
            return '';
        }
        $text = $count . ($count === 1 ? ' notice' : ' notices');

        return $action > 0 ? $text . ', ' . $action . ($action === 1 ? ' needs action' : ' need action') : $text;
    }
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/admin-ui-stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/classes/core/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';

$tools = ['Tools', 'https://example.test/oc-admin/?page=tools', 'tools', 'administrator', 'bi bi-wrench', 'sub' => [
    ['Backup', 'https://example.test/oc-admin/?page=tools&action=backup', 'tools_backup', 'administrator'],
    ['Maintenance', 'https://example.test/oc-admin/?page=tools&action=maintenance', 'tools_maintenance', 'administrator'],
]];
$users = ['Users', 'https://example.test/oc-admin/?page=users', 'users', 'moderator', 'bi bi-people'];

$menu = new AdminMenu();
$call = new ReflectionMethod($menu, 'renderMenu');
$html = $call->invoke($menu, 'tools', $tools, '', '') . $call->invoke($menu, 'users', $users, '', '');

harness_section('no sidebar dot');
check('the menu renders its rows', strpos($html, 'Backup') !== false && strpos($html, 'Users') !== false);
check('no notice dot is printed', strpos($html, 'osc-dot') === false && strpos($html, 'data-osc-mark') === false);

exit(harness_result());
