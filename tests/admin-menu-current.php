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
 * AdminMenu::current(): which sidebar entry a page highlights, including two entries that
 * share one link.
 *
 * DB-free.  Usage:  php tests/admin-menu-current.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';

if (!function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}

$base  = 'https://example.test/oc-admin/index.php';
$bases = [$base . '?', 'https://example.test/oc-admin/'];
$menu  = [
    'dash'     => ['Dashboard', 'https://example.test/oc-admin/', 'dash', 'moderator'],
    'plugins'  => ['Plugins', $base . '?page=plugins', 'plugins', 'administrator', 'sub' => [
        ['Plugins', $base . '?page=plugins', 'plugins_manage', 'administrator'],
        ['API keys (Settings > API)', $base . '?page=settings&action=api', 'listing-import-keys', 'administrator'],
    ]],
    'settings' => ['Settings', $base . '?page=settings', 'settings', 'administrator', 'sub' => [
        ['General', $base . '?page=settings', 'settings_general', 'administrator'],
        ['API', $base . '?page=settings&action=api', 'settings_api', 'administrator'],
    ]],
];

harness_section('AdminMenu::current');
pin('a plugin shortcut to a core screen does not take its highlight', ['settings', 'settings_api'], AdminMenu::current($menu, 'page=settings&action=api', 'settings', false, $bases));
pin('...whichever section comes first', ['settings', 'settings_api'], AdminMenu::current(array_reverse($menu, true), 'page=settings&action=api', 'settings', false, $bases));
pin('the longest matching link still wins', ['settings', 'settings_general'], AdminMenu::current($menu, 'page=settings&action=general', 'settings', false, $bases));
pin('a plugin page highlights its own entry', ['plugins', 'plugins_manage'], AdminMenu::current($menu, 'page=plugins', 'plugins', false, $bases));
pin('no query string is the dashboard', ['dash', ''], AdminMenu::current($menu, '', null, false, $bases));

harness_section('Group placement');

$m = new AdminMenu();
$m->add_menu('Settings', $base . '?page=settings', 'settings', 'administrator');
$m->add_submenu_divider('settings', 'Site', 'settings_group_site');
$m->add_submenu('settings', 'General', $base . '?page=settings', 'settings_general', 'administrator');
$m->add_submenu_divider('settings', 'Shop', 'settings_group_shop');
$m->add_submenu_divider('settings', 'System', 'settings_group_system');
$m->add_submenu('settings', 'Storage', $base . '?page=settings&action=storage', 'settings_storage', 'administrator');
$m->add_submenu('settings', 'Tax', $base . '?page=settings&action=custom&id=tax', 's-tax', 'administrator', null, 'settings_group_shop');
$m->add_submenu('settings', 'Shipping', $base . '?page=plugins&action=renderplugin&route=shipping-zones', 's-ship', 'administrator', null, 'settings_group_shop', [$base . '?page=settings&action=custom&id=shipping']);
$m->add_submenu('settings', 'Elsewhere', $base . '?page=x', 's-else', 'administrator', null, 'settings_group_nope');
$m->add_submenu('settings', 'Old call', $base . '?page=y', 's-old', 'administrator');
pin('entries land at the end of their group, in order; unknown group and old calls append',
    ['divider_settings_group_site', 'settings_general', 'divider_settings_group_shop', 's-tax', 's-ship', 'divider_settings_group_system', 'settings_storage', 's-else', 's-old'],
    array_keys($m->get_array_menu()['settings']['sub']));

$tabbed = $m->get_array_menu();
pin('a tab url highlights the entry that claims it', ['settings', 's-ship'], AdminMenu::current($tabbed, 'page=settings&action=custom&id=shipping', 'settings', false, $bases));
pin('the first tab highlights it too', ['settings', 's-ship'], AdminMenu::current($tabbed, 'page=plugins&action=renderplugin&route=shipping-zones', 'plugins', false, $bases));
pin('another settings page keeps its own', ['settings', 's-tax'], AdminMenu::current($tabbed, 'page=settings&action=custom&id=tax', 'settings', false, $bases));

harness_section('Settings groups');

$core = new ReflectionProperty(AdminMenu::class, 'aCoreSubmenus');
$core->setAccessible(true);
$g = new AdminMenu();
$g->add_menu('Settings', $base . '?page=settings', 'settings', 'administrator');
$g->add_submenu_divider('settings', 'Site', 'settings_group_site');
$g->add_submenu('settings', 'General', $base . '?page=settings', 'settings_general', 'administrator');
$g->add_submenu('settings', 'Sitemap', $base . '?page=settings&action=sitemap', 'settings_sitemap', 'administrator');
$g->add_submenu_divider('settings', 'Shop', 'settings_group_shop');
$g->add_submenu_divider('settings', 'System', 'settings_group_system');
$g->add_submenu('settings', 'Storage', $base . '?page=settings&action=storage', 'settings_storage', 'administrator');
$core->setValue($g, ['settings' => array_keys($g->get_array_menu()['settings']['sub'])]);

pin('an empty Shop group is left out', ['settings_group_site', 'settings_group_system'], array_column($g->settingsGroups(), 'id'));

$g->add_submenu('settings', 'Tax', $base . '?page=settings&action=custom&id=tax', 's-tax', 'administrator', null, 'settings_group_shop');
$g->add_submenu('settings', 'Offers', $base . '?page=plugins&route=offers', 's-offers', 'administrator', null, 'settings_group_shop');
$g->add_submenu('settings', 'Old call', $base . '?page=y', 's-old', 'administrator');

pin('a plugin entry placed by group joins it and a bare one goes to Other',
    ['settings_group_site', 'settings_group_shop', 'settings_group_system', 'settings_group_other'],
    array_column($g->settingsGroups(), 'id'));
pin('the Shop group lists its entries in order', ['s-tax', 's-offers'], array_column($g->settingsGroups()[1]['entries'], 'id'));
pin('Other holds the ungrouped entry', ['s-old'], array_column($g->settingsGroups()[3]['entries'], 'id'));

$tabs = $g->settingsGroupTabs('s-offers');
pin('the tab row is the group, with the current screen active', [['Tax', false], ['Offers', true]], array_map(static fn ($t) => [$t['label'], $t['active']], $tabs));
pin('a group with one screen draws no row', [], $g->settingsGroupTabs('s-old'));
pin('an unknown entry draws no row', [], $g->settingsGroupTabs('nope'));

exit(harness_result());

/* file end: ./tests/admin-menu-current.php */
