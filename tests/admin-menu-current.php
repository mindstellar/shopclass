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
$n = new AdminMenu();
$n->add_menu('Settings', $base . '?page=settings', 'settings', 'administrator');
$n->add_submenu_divider('settings', 'Shop', 'settings_group_shop');
$n->add_submenu_divider('settings', 'System', 'settings_group_system');
$n->add_submenu('settings', 'Long', $base . '?a', 's-long', 'administrator', null, 'settings_group_shop');
$n->add_submenu('settings', 'Short', $base . '?b', 's-short', 'administrator', null, 'shop');
pin('the short and the long group name place an entry in the same group',
    ['divider_settings_group_shop', 's-long', 's-short', 'divider_settings_group_system'],
    array_keys($n->get_array_menu()['settings']['sub']));

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

harness_section('Core Settings map');

foreach (['osc_admin_base_url' => static fn ($withFile = false) => 'https://example.test/oc-admin/' . ($withFile ? 'index.php' : ''),
          'osc_billing_enabled' => static fn () => true,
          'osc_billing_credits_enabled' => static fn () => true,
          'osc_is_shop_mode' => static fn () => false,
          'osc_is_moderator' => static fn () => true,
          'osc_add_hook' => static fn () => null,
          'osc_run_hook' => static fn () => null,
          'osc_customiser_spec' => static fn () => null] as $fn => $impl) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return call_user_func_array($GLOBALS["__stub_' . $fn . '"], func_get_args()); }');
    }
    $GLOBALS['__stub_' . $fn] = $impl;
}

$real = new AdminMenu();
$real->init();
$map = [];
foreach ($real->settingsGroups() as $grp) {
    $map[$grp['id']] = array_column($grp['entries'], 'title');
}
pin('Settings groups and tabs, in order', [
    'settings_group_general'  => ['General', 'Permalinks', 'Languages', 'Sitemap'],
    'settings_group_content'  => ['Comments', 'Latest searches'],
    'settings_group_email'    => ['Email templates', 'Mail server'],
    'settings_group_security' => ['Spam and bots', 'Keyword blocklist', 'Ban rules'],
    'settings_group_system'   => ['Storage', 'API', 'Advanced'],
], $map);

$full = $real->get_array_menu();
$subIds = static fn (string $menu): array => array_map(static fn ($e) => $e[2], array_values($full[$menu]['sub'] ?? []));
pin('Listings, Users, Media and Billing keep their own Settings entry, last', [
    ['items_settings'], ['users_settings'], ['media_settings'], ['billing_settings'],
], [
    array_slice($subIds('items'), -1), array_slice($subIds('users'), -1),
    array_slice($subIds('media'), -1), array_slice($subIds('billing'), -1),
]);
pin('Ban rules is no longer in Users', false, in_array('users_ban', $subIds('users'), true));

$basesReal = ['https://example.test/oc-admin/index.php?', 'https://example.test/oc-admin/'];
foreach ([
    'page=items&action=settings'           => ['items', 'items_settings'],
    'page=users&action=settings'           => ['users', 'users_settings'],
    'page=settings&action=media'           => ['media', 'media_settings'],
    'page=settings&action=billing'         => ['billing', 'billing_settings'],
    'page=settings&action=billing&tab=pricing' => ['billing', 'billing_settings'],
    'page=users&action=ban'                => ['settings', 'users_ban'],
    'page=users&action=ban_edit&id=3'      => ['settings', 'users_ban'],
] as $query => $want) {
    pin("$query lights " . implode(' > ', $want), $want, AdminMenu::current($full, $query, 'x', false, $basesReal));
}
pin('Manage users stays in Users', ['users', 'users_manage'], AdminMenu::current($full, 'page=users', 'users', false, $basesReal));
pin('a section settings screen has no group tab row', [], $real->settingsGroupTabs('items_settings'));

$real->add_submenu('users', 'Subscribers', 'https://example.test/s', 'p-subs', 'administrator');
$real->add_submenu('billing', 'Tax', 'https://example.test/t', 'p-tax', 'administrator');
$real->add_submenu('settings', 'Mail extra', 'https://example.test/m', 'p-mail', 'administrator', null, 'settings_group_email');
$real->add_submenu('settings', 'Lost', 'https://example.test/l', 'p-lost', 'administrator', null, 'nope');
pin('a plugin page placed in billing goes before Settings', ['billing_credits', 'p-tax', 'billing_settings'],
    array_slice(array_map(static fn ($e) => $e[2], array_values($real->get_array_menu()['billing']['sub'])), -3));
$after = [];
foreach ($real->settingsGroups() as $grp) {
    $after[$grp['id']] = array_column($grp['entries'], 'id');
}
$ord = new AdminMenu();
$ord->init();
$ord->add_submenu('billing', 'Tax', 'https://example.test/t', 'settings-page-tax', 'administrator');
$ord->add_submenu('billing', 'Offers', 'https://example.test/o', 'p-offers', 'administrator', null, null, [], 20);
$ord->add_submenu('billing', 'Shipping', 'https://example.test/s', 'p-ship', 'administrator', null, null, [], 10);
$ord->add_submenu('billing', 'Downloads', 'https://example.test/d', 'p-dl', 'administrator');
pin('Billing: core first, plugin lists by weight, plugin settings pages, then Settings',
    ['billing_orders', 'billing_packages', 'billing_credits', 'p-dl', 'p-ship', 'p-offers', 'settings-page-tax', 'billing_settings'],
    array_map(static fn ($e) => $e[2], array_values($ord->get_array_menu()['billing']['sub'])));
pin('a plugin page in Users goes before its Settings entry', ['p-subs', 'users_settings'], array_slice(array_map(static fn ($e) => $e[2], array_values($real->get_array_menu()['users']['sub'])), -2));
pin('an unknown group goes to Other', ['p-lost'], $after['settings_group_other']);
pin('a long group id places into Email', true, in_array('p-mail', $after['settings_group_email'], true));
pin('the tab row of Security holds the three screens', ['Spam and bots', 'Keyword blocklist', 'Ban rules'],
    array_column($real->settingsGroupTabs('users_ban'), 'label'));

$GLOBALS['__stub_osc_billing_enabled'] = static fn () => false;
$off = new AdminMenu();
$off->init();
$off->add_submenu('billing', 'Tax', 'https://example.test/t', 'p-tax', 'administrator');
$offGroups = [];
foreach ($off->settingsGroups() as $grp) {
    $offGroups[$grp['id']] = array_column($grp['entries'], 'id');
}
pin('with billing off the switch is in System and a billing page falls to Other',
    [true, ['p-tax']], [in_array('settings_billing', $offGroups['settings_group_system'], true), $offGroups['settings_group_other']]);
pin('with billing off there is no Billing section', false, isset($off->get_array_menu()['billing']));

require_once ABS_PATH . 'oc-includes/osclass/classes/admin/ui/Form.php';
pin('a section heading id is a stable slug of its title', ['s-server-rules-nginx', 's-server-rules-htaccess', 's-keys'],
    [\mindstellar\admin\ui\Form::anchor('Server rules (nginx)'), \mindstellar\admin\ui\Form::anchor('Server rules (.htaccess)'), \mindstellar\admin\ui\Form::anchor(' Keys ')]);

exit(harness_result());

/* file end: ./tests/admin-menu-current.php */
