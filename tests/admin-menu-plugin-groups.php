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
 * The Plugins section groups each plugin's links under its name. A plugin whose links sit in
 * another section gets one link there too, and a plugin with no screens gets no group.
 *
 * DB-free. Usage: php tests/admin-menu-plugin-groups.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

$GLOBALS['moderator'] = false;

function osc_is_moderator()
{
    return $GLOBALS['moderator'];
}

if (!function_exists('__')) {
    function __($text, $domain = 'core')
    {
        return $text;
    }
}

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/classes/core/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminMenu.php';

$base = 'https://example.test/oc-admin/index.php';
$m    = AdminMenu::getInstance();
$m->add_menu('Plugins', $base . '?page=plugins', 'plugins', 'administrator');
$m->add_submenu('plugins', 'Manage plugins', $base . '?page=plugins', 'plugins_manage', 'administrator');
$m->add_menu('Billing', $base . '?page=billing', 'billing', 'administrator');
$m->add_menu('Tools', $base . '?page=tools', 'tools', 'administrator');
$m->add_submenu('billing', 'Core billing', $base . '?page=billing&action=core', 'billing_core', 'administrator');

Plugins::runAs('plugin', 'booking', static function () use ($m, $base): void {
    $m->add_submenu('plugins', 'Bookings', $base . '?page=plugins&action=renderplugin&route=booking-admin', 'booking-bookings', 'administrator');
    $m->add_submenu('plugins', 'Settings', $base . '?page=settings&action=custom&id=booking', 'settings-page-booking', 'administrator');
});
Plugins::runAs('plugin', 'search', static function () use ($m, $base): void {
    $m->add_submenu('plugins', 'Search index', $base . '?page=plugins&action=renderplugin&route=search-index', 'search-index', 'administrator');
    $m->add_submenu('plugins', 'Settings', $base . '?page=settings&action=custom&id=search', 'search-settings', 'administrator');
});
Plugins::runAs('plugin', 'tax', static function () use ($m, $base): void {
    $m->add_submenu('billing', 'Tax', $base . '?page=settings&action=custom&id=tax', 'settings-page-tax', 'administrator');
});
Plugins::runAs('plugin', 'translator', static function () use ($m, $base): void {
    $m->add_submenu('tools', 'Translator', $base . '?page=plugins&action=renderplugin&route=translator', 'translator', 'administrator');
});

// A third-party plugin of the old kind: menu calls straight from its entry file.
$dir = sys_get_temp_dir() . '/osc-plugin-groups-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/index.php', '<?php osc_add_admin_submenu_divider("plugins", "Old plugin", "oldie_head");'
    . ' osc_admin_menu_plugins("Old screen", "' . $base . '?page=plugins&action=renderplugin&file=oldie/admin.php", "oldie-screen");');
Plugins::includeAs('plugin', 'oldie', $dir . '/index.php');
unlink($dir . '/index.php');
rmdir($dir);

// Registered by core code on a plugin's behalf: the renderplugin file names the plugin.
$m->add_submenu('plugins', 'Legacy screen', $base . '?page=plugins&action=renderplugin&file=legacy/admin.php', 'legacy-screen', 'administrator');
$m->add_submenu('plugins', 'Core extra', $base . '?page=plugins&action=market', 'plugins_market', 'administrator');

$names = array(
    'booking'    => 'Booking',
    'search'     => 'Search',
    'tax'        => 'Tax',
    'translator' => 'Translator',
    'oldie'      => 'Old plugin',
    'legacy'     => 'Legacy',
    'quiet'      => 'Quiet',
);
$names_prop = new ReflectionProperty($m, 'activeNames');
$names_prop->setValue($m, $names);
$groups   = $m->pluginGroups($names);
$byPlugin = array_column($groups, null, 'plugin');

harness_section('Grouped by the plugin that added them');
pin('one group per plugin with screens, by name', array('booking', 'legacy', 'oldie', 'search', 'tax', 'translator'), array_column($groups, 'plugin'));
pin('the heading is the plugin name', 'Booking', $byPlugin['booking']['title'] ?? null);
pin('its links, in the order added', array('booking-bookings', 'settings-page-booking'), array_column($byPlugin['booking']['entries'] ?? array(), 'id'));
pin('two Settings links sit in two groups', array('search-index' => 'Search index', 'search-settings' => 'Settings'), array_column($byPlugin['search']['entries'] ?? array(), 'title', 'id'));

harness_section('A link a plugin put in another section moves into its group');
pin('a Billing link', array(array('title' => 'Tax', 'url' => $base . '?page=settings&action=custom&id=tax', 'id' => 'settings-page-tax')), $byPlugin['tax']['entries'] ?? null);
pin('a Tools link', array(array('title' => 'Translator', 'url' => $base . '?page=plugins&action=renderplugin&route=translator', 'id' => 'translator')), $byPlugin['translator']['entries'] ?? null);
$sections = static function (AdminMenu $m, array $names): array {
    $html = array();
    foreach (array('billing', 'tools') as $id) {
        $r = new ReflectionMethod($m, 'renderSubMenu');
        $html[$id] = $r->invoke($m, $id, $m->get_array_menu()[$id]['sub'], false, 'plugins', '');
    }

    return $html;
};
$other = $sections($m, $names);
check('Billing no longer shows it', strpos($other['billing'], 'settings-page-tax') === false);
check('Tools no longer shows it', strpos($other['tools'], 'id="translator"') === false);
check('the menu data keeps the entry for search and breadcrumbs', isset($m->get_array_menu()['billing']['sub']['settings-page-tax'], $m->get_array_menu()['tools']['sub']['translator']));

harness_section('Old-style and ownerless registrations');
pin('a plugin calling the helpers from its entry file is grouped', array('oldie-screen'), array_column($byPlugin['oldie']['entries'] ?? array(), 'id'));
pin('a renderplugin file link names its plugin', array('legacy-screen'), array_column($byPlugin['legacy']['entries'] ?? array(), 'id'));
check('a plugin with no screens gets no group', !isset($byPlugin['quiet']));

harness_section('The sidebar list');
$render = new ReflectionMethod($m, 'renderPluginList');
$html   = $render->invoke($m, $names, 'plugins', 'booking-bookings');
$at     = static fn (string $needle): int => (int) strpos($html, $needle);
check('Manage plugins and core links come first', $at('id="plugins_manage"') < $at('submenu-divide') && $at('id="plugins_market"') < $at('submenu-divide'));
check('each heading is a group heading', substr_count($html, '<li class="submenu-divide">') === 6);
check('the plugin’s own heading is not drawn twice', substr_count($html, 'Old plugin') === 1);
check('a link sits under its plugin heading', $at('>Booking<') < $at('id="settings-page-booking"') && $at('id="settings-page-booking"') < $at('>Legacy<'));
check('the current link is marked', strpos($html, 'id="booking-bookings" href="' . $base . '?page=plugins&action=renderplugin&route=booking-admin" aria-current="page"') !== false);
check('ids stay unique', count(array_unique($ids = (preg_match_all('/ id="([^"]+)"/', $html, $mm) ? $mm[1] : array()))) === count($ids));

harness_section('Attribution');
$ownerProp = new ReflectionProperty($m, 'aOwner');
$owners    = $ownerProp->getValue($m);
pin('a link added by a running plugin is that plugin’s', 'booking', $owners['plugins']['booking-bookings'] ?? null);
pin('a renderplugin route link is its route file’s plugin', 'legacy', $owners['plugins']['legacy-screen'] ?? null);
pin('a core link has no plugin', null, $owners['plugins']['plugins_market'] ?? null);

harness_section('Which entry the sidebar lights');
function osc_admin_base_url($full = false)
{
    return $full ? 'https://example.test/oc-admin/index.php' : 'https://example.test/oc-admin/';
}
$lit = static function (string $query, string $page) use ($m): array {
    $_SERVER['QUERY_STRING'] = $query;
    Params::init();
    Params::setParam('page', $page);

    return $m->currentIds();
};
pin('a plugin link in Billing lights its group link and Plugins', array('plugins', 'settings-page-tax'), $lit('page=settings&action=custom&id=tax', 'settings'));
pin('a plugin link in Tools lights its group link and Plugins', array('plugins', 'translator'), $lit('page=plugins&action=renderplugin&route=translator', 'plugins'));
pin('a renderplugin link lights itself', array('plugins', 'booking-bookings'), $lit('page=plugins&action=renderplugin&route=booking-admin', 'plugins'));
pin('a core Billing link still lights Billing', array('billing', 'billing_core'), $lit('page=billing&action=core', 'billing'));
$html = $render->invoke($m, $names, 'plugins', 'settings-page-tax');
check('the group link is the one marked', strpos($html, 'id="settings-page-tax" href="' . $base . '?page=settings&action=custom&id=tax" aria-current="page"') !== false);

harness_section('A declared settings page records its plugin');
Plugins::runAs('plugin', 'tax', static function (): void {
    \mindstellar\settings\SettingsPageRegistry::getInstance()->register('tax-owner-test', array('title' => 'Tax', 'menu' => '', 'fields' => array(array('name' => 'rate', 'type' => 'text', 'label' => 'Rate'))));
});
pin('the page knows its plugin', 'tax', \mindstellar\settings\SettingsPageRegistry::getInstance()->get('tax-owner-test')['plugin'] ?? null);

exit(harness_result());
