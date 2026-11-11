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
 * The sidebar notice dots: on a section and a sub-item, none when nothing is marked, a hidden dot
 * for hidden-only rows, the spoken name inside the link, and no change to the rest of the menu.
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

/** One section's menu markup with the given marks. */
function menu_html(string $id, array $section, ?array $marks, string $current = ''): string
{
    $menu = new AdminMenu();
    $prop = new ReflectionProperty($menu, 'noticeMarks');
    $prop->setValue($menu, $marks);
    $call = new ReflectionMethod($menu, 'renderMenu');

    return $call->invoke($menu, $id, $section, $current, '');
}

$action = ['group' => 'action', 'count' => 2, 'action' => 1];

harness_section('no notices');
$plain = menu_html('tools', $tools, []);
check('no dot is printed', strpos($plain, 'osc-dot') === false && strpos($plain, 'data-osc-mark') === false);
pin('marks for other sections change nothing', $plain, menu_html('tools', $tools, ['users' => $action, 'tools/other' => $action]));

harness_section('a dot on the section and on the sub-item');
$html = menu_html('tools', $tools, ['tools' => $action, 'tools/tools_backup' => $action]);
check('the section has a red dot', (bool) preg_match('~Tools.*?<span class="osc-dot is-action" data-osc-mark="tools" title="2 notices, 1 needs action"></span>~s', $html));
check('the backup item has a dot, maintenance has none', substr_count($html, 'osc-dot') === 2 && strpos($html, 'data-osc-mark="tools/tools_backup"') !== false);
check('the name is in the link text', strpos($html, '<span class="visually-hidden" data-osc-mark-name="tools">, 2 notices, 1 needs action</span></a>') !== false);
check('the dot comes after the label, inside the link', (bool) preg_match('~<a class="nav-link py-1\s*" id="tools_backup"[^>]*>Backup <span class="osc-dot is-action"~', $html));
check('without the dot the rest of the markup is as before', str_replace(
    [' <span class="osc-dot is-action" data-osc-mark="tools" title="2 notices, 1 needs action"></span><span class="visually-hidden" data-osc-mark-name="tools">, 2 notices, 1 needs action</span>',
     ' <span class="osc-dot is-action" data-osc-mark="tools/tools_backup" title="2 notices, 1 needs action"></span><span class="visually-hidden" data-osc-mark-name="tools/tools_backup">, 2 notices, 1 needs action</span>'],
    '',
    $html
) === $plain);

harness_section('heads-up and hidden-only');
$head = menu_html('tools', $tools, ['tools' => ['group' => 'headsup', 'count' => 1, 'action' => 0]]);
check('a heads-up dot', strpos($head, 'osc-dot is-headsup') !== false && strpos($head, ', 1 notice<') !== false);
$only = menu_html('tools', $tools, ['tools' => ['group' => '', 'count' => 0, 'action' => 0]]);
check('hidden-only prints a hidden dot with no name', strpos($only, '<span class="osc-dot" data-osc-mark="tools" hidden></span>') !== false
    && strpos($only, 'data-osc-mark-name="tools"></span>') !== false);

harness_section('moderator');
$GLOBALS['moderator'] = true;
check('a moderator gets no tools row, so no dot', menu_html('tools', $tools, ['tools' => $action]) === '');
check('a moderator keeps the dot on a section they can open', strpos(menu_html('users', $users, ['users' => $action]), 'osc-dot is-action') !== false);
$GLOBALS['moderator'] = false;

exit(harness_result());
