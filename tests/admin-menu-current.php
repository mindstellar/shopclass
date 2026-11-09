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
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/classes/core/base/SharedInstance.php';
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

harness_section('A page reached only through tabs');
$menu['appearance'] = ['Appearance', $base . '?page=appearance', 'appearance', 'administrator', 'sub' => [
    ['Manage themes', $base . '?page=appearance', 'appearance_manage', 'administrator'],
    ['Theme settings', $base . '?page=settings&action=custom&id=settings-theme', 'appearance_theme', 'administrator'],
]];
$tabs = [
    ['label' => 'Settings', 'url' => $base . '?page=settings&action=custom&id=settings-theme'],
    ['label' => 'SEO', 'url' => $base . '?page=settings&action=custom&id=seo-theme', 'active' => true],
];
pin('lights the entry of its first tab in the menu', ['appearance', 'appearance_theme'], AdminMenu::forTabs($menu, $tabs, false, $bases));
pin('skips a tab that is not in the menu', ['appearance', 'appearance_theme'], AdminMenu::forTabs($menu, array_reverse($tabs), false, $bases));
pin('no tab in the menu gives no entry', null, AdminMenu::forTabs($menu, [['label' => 'SEO', 'url' => $base . '?page=settings&action=custom&id=seo-theme']], false, $bases));

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
          'osc_mode_text' => static fn ($classifieds, $shop) => $classifieds,
          'osc_is_moderator' => static fn () => true,
          'osc_add_hook' => static fn () => null,
          'osc_run_hook' => static fn () => null,
          'osc_customiser_spec' => static fn () => null] as $fn => $impl) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return call_user_func_array($GLOBALS["__stub_' . $fn . '"], func_get_args()); }');
    }
    $GLOBALS['__stub_' . $fn] = $impl;
}

// The Submissions entry asks the database; this test has none, so its answer is cached up front.
\mindstellar\cache\CacheGroup::remember('form_submission_menu', 'visible', static fn (): int => 1, 60);

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
    'settings_group_security' => ['Sign-in', 'Captcha', 'Spam filter', 'Limits'],
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
pin('Ban rules is in Users, before its Settings entry', ['users_ban', 'users_settings'], array_slice($subIds('users'), -2));

$basesReal = ['https://example.test/oc-admin/index.php?', 'https://example.test/oc-admin/'];
foreach ([
    'page=items&action=settings'           => ['items', 'items_settings'],
    'page=users&action=settings'           => ['users', 'users_settings'],
    'page=settings&action=media'           => ['media', 'media_settings'],
    'page=settings&action=billing'         => ['billing', 'billing_settings'],
    'page=settings&action=billing&tab=pricing' => ['billing', 'billing_settings'],
    'page=users&action=ban'                => ['users', 'users_ban'],
    'page=users&action=edit_ban_rule&id=3' => ['users', 'users_ban'],
    'page=users&action=create_ban_rule'    => ['users', 'users_ban'],
    'page=settings&action=spamNbots'       => ['settings', 'settings_spambots'],
    'page=settings&action=spamNbots&tab=signin' => ['settings', 'settings_spambots'],
    'page=settings&action=spamNbots&tab=captcha' => ['settings', 'settings_captcha'],
    'page=settings&action=spamNbots&tab=limits' => ['settings', 'settings_limits'],
    'page=settings&action=keyword_block'   => ['settings', 'settings_keyword_block'],
    'page=settings&action=keyword_block_edit&id=2' => ['settings', 'settings_keyword_block'],
    'page=messages&action=settings'        => ['items', 'items_messages'],
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
pin('the tab row of Security holds its four tabs, Captcha current', [['Sign-in', 'Captcha', 'Spam filter', 'Limits'], [false, true, false, false]],
    [array_column($real->settingsGroupTabs('settings_captcha'), 'label'), array_column($real->settingsGroupTabs('settings_captcha'), 'active')]);
pin('Ban rules draws no Settings tab row', [], $real->settingsGroupTabs('users_ban'));

$GLOBALS['__stub_osc_billing_enabled'] = static fn () => false;
$off = new AdminMenu();
$off->init();
$off->add_submenu('billing', 'Tax', 'https://example.test/t', 'p-tax', 'administrator');
$offGroups = [];
foreach ($off->settingsGroups() as $grp) {
    $offGroups[$grp['id']] = array_column($grp['entries'], 'id');
}
pin('with billing off the switch stays in System and a billing page goes under a Billing heading',
    [true, ['p-tax'], 'Billing', false],
    [in_array('settings_billing', $offGroups['settings_group_system'], true), $offGroups['settings_group_billing'] ?? null,
     array_column($off->settingsGroups(), 'title', 'id')['settings_group_billing'] ?? null, isset($offGroups['settings_group_other'])]);
$off->add_submenu('settings', 'Loose', 'https://example.test/x', 'p-loose', 'administrator');
$offGroups = array_column($off->settingsGroups(), 'entries', 'id');
pin('...and an ungrouped Settings page after it still goes to Other',
    [['p-tax'], ['p-loose']], [array_column($offGroups['settings_group_billing'], 'id'), array_column($offGroups['settings_group_other'], 'id')]);
pin('the Billing heading is left out while nothing is in it', false, in_array('settings_group_billing', array_column((function () {
    $m = new AdminMenu();
    $m->init();
    return $m->settingsGroups();
})(), 'id'), true));
$GLOBALS['__stub_osc_billing_enabled'] = static fn () => true;

harness_section('Plugin page section label');
if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($name, $value)
    {
        return isset($GLOBALS['__filters'][$name]) ? ($GLOBALS['__filters'][$name])($value) : $value;
    }
}
$GLOBALS['__stub_osc_is_moderator'] = static fn () => false;
$lbl = new AdminMenu();
$lbl->init();
$pluginUrl = static fn (string $route): string => 'https://example.test/oc-admin/index.php?page=plugins&action=renderplugin&route=' . $route;
$lbl->add_submenu('users', 'Subscribers', $pluginUrl('subs'), 'p-subs', 'administrator');
$lbl->add_submenu('billing', 'Shipping', $pluginUrl('ship'), 'p-ship', 'administrator');
$lbl->add_submenu('settings', 'Translator', $pluginUrl('tr'), 'p-tr', 'administrator');
$lbl->add_submenu('plugins', 'Tool', $pluginUrl('tool'), 'p-tool', 'administrator');
$labelFor = static function (string $route) use ($lbl): string {
    $_SERVER['QUERY_STRING'] = 'page=plugins&action=renderplugin&route=' . $route;
    $_GET = ['page' => 'plugins', 'action' => 'renderplugin', 'route' => $route];
    Params::init();
    return $lbl->pluginSectionLabel();
};
pin('a plugin page says the section that holds it', ['Users', 'Billing', 'Settings', 'Plugins', 'Plugins'],
    [$labelFor('subs'), $labelFor('ship'), $labelFor('tr'), $labelFor('tool'), $labelFor('nowhere')]);
$GLOBALS['__filters']['custom_plugin_title'] = static fn ($v) => 'Translator';
pin('custom_plugin_title still wins', 'Translator', $labelFor('subs'));
unset($GLOBALS['__filters']['custom_plugin_title'], $_SERVER['QUERY_STRING']);
$_GET = [];
Params::init();

harness_section('Unknown parent menu');
$child = static function (bool $debug): string {
    $log  = tempnam(sys_get_temp_dir(), 'menu');
    $code = ($debug ? "define('OSC_DEBUG', true);" : '')
        . "define('ABS_PATH', " . var_export(ABS_PATH, true) . ");"
        . "require ABS_PATH . 'oc-includes/vendor/autoload.php';"
        . "require ABS_PATH . 'oc-includes/classes/core/base/SharedInstance.php';"
        . "require ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';"
        . "\$m = new AdminMenu(); \$m->add_menu('Users', 'u', 'users');"
        . "\$m->add_submenu('users', 'Fine', 'f', 'p-fine'); \$m->add_submenu('nosuch', 'Lost', 'l', 'p-lost');";
    exec(escapeshellarg(PHP_BINARY) . ' -d log_errors=1 -d error_log=' . escapeshellarg($log) . ' -r ' . escapeshellarg($code));
    $out = (string) file_get_contents($log);
    unlink($log);
    return $out;
};
$logged = $child(true);
pin('in debug one warning names the entry and its missing parent', [1, true, true, false],
    [substr_count($logged, 'Admin menu:'), str_contains($logged, '"p-lost"'), str_contains($logged, '"nosuch"'), str_contains($logged, 'p-fine')]);
pin('without debug nothing is logged', '', $child(false));
pin('with billing off there is no Billing section', false, isset($off->get_array_menu()['billing']));

require_once ABS_PATH . 'oc-includes/classes/admin/ui/Form.php';
pin('a section heading id is a stable slug of its title', ['s-server-rules-nginx', 's-server-rules-htaccess', 's-keys'],
    [\mindstellar\admin\ui\Form::anchor('Server rules (nginx)'), \mindstellar\admin\ui\Form::anchor('Server rules (.htaccess)'), \mindstellar\admin\ui\Form::anchor(' Keys ')]);

exit(harness_result());

/* file end: ./tests/admin-menu-current.php */
