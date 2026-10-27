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
 * The stock admin theme draws page heads and panels through the one copy in hAdminUi.php.
 * The theme file loads first here, so a copy coming back to it would win and fail these pins.
 *
 * Usage:  php tests/admin-ui-single-copy.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';

class Params
{
    public static function getParam($key, $a = false, $b = true)
    {
        return '';
    }

    public static function getParamString(string $key): string
    {
        return '';
    }
}

class AdminMenu
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function currentIds(): array
    {
        return array('settings', 'settings_general');
    }

    public function settingsGroupTabs($entry, $moderator): array
    {
        return array(array('label' => 'Site', 'url' => 'https://example.test/a', 'current' => true));
    }
}

function osc_is_moderator()
{
    return false;
}

function osc_admin_base_url($index = false)
{
    return 'https://example.test/oc-admin/index.php';
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Field.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

function render(callable $fn): string
{
    ob_start();
    $fn();

    return (string)ob_get_clean();
}

harness_section('one copy');
$helpers = realpath(ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php');
foreach (array('osc_admin_page_head', 'osc_admin_panel_open', 'osc_admin_panel_close') as $fn) {
    pin($fn . ' is defined in hAdminUi.php', $helpers, (new ReflectionFunction($fn))->getFileName());
}

harness_section('page head');
$first = render(static fn () => osc_admin_page_head('General'));
check('the page title is a level-2 heading', str_contains($first, '<h2 class="render-title osc-page-title">General</h2>'), $first);
check('a Settings page draws its group tabs under the title', str_contains($first, 'osc-tabnav-group'), $first);
$second = render(static fn () => osc_admin_page_head('Section', array(), array('level' => 3)));
check('a section asks for level 3 and is not the page title', str_contains($second, '<h3 class="render-title">Section</h3>'), $second);
check('the group tabs are drawn once per page', !str_contains($second, 'osc-tabnav-group'), $second);

exit(harness_result());
