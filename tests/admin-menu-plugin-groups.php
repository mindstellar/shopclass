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
$settings = array('tax' => $base . '?page=settings&action=custom&id=tax');
$groups   = $m->pluginGroups($names, $settings);
$byPlugin = array_column($groups, null, 'plugin');

harness_section('Grouped by the plugin that added them');
pin('one group per plugin with screens, by name', array('booking', 'legacy', 'oldie', 'search', 'tax', 'translator'), array_column($groups, 'plugin'));
pin('the heading is the plugin name', 'Booking', $byPlugin['booking']['title'] ?? null);
pin('its links, in the order added', array('booking-bookings', 'settings-page-booking'), array_column($byPlugin['booking']['entries'] ?? array(), 'id'));
pin('two Settings links sit in two groups', array('search-index' => 'Search index', 'search-settings' => 'Settings'), array_column($byPlugin['search']['entries'] ?? array(), 'title', 'id'));

harness_section('A plugin whose links live elsewhere');
pin('gets its settings page', array(array('title' => 'Settings', 'url' => $base . '?page=settings&action=custom&id=tax', 'id' => 'plugin-link-tax')), $byPlugin['tax']['entries'] ?? null);
pin('...or its first screen', array(array('title' => 'Translator', 'url' => $base . '?page=plugins&action=renderplugin&route=translator', 'id' => 'plugin-link-translator')), $byPlugin['translator']['entries'] ?? null);
check('...and keeps the link where it was', isset($m->get_array_menu()['billing']['sub']['settings-page-tax'], $m->get_array_menu()['tools']['sub']['translator']));

harness_section('Old-style and ownerless registrations');
pin('a plugin calling the helpers from its entry file is grouped', array('oldie-screen'), array_column($byPlugin['oldie']['entries'] ?? array(), 'id'));
pin('a renderplugin file link names its plugin', array('legacy-screen'), array_column($byPlugin['legacy']['entries'] ?? array(), 'id'));
check('a plugin with no screens gets no group', !isset($byPlugin['quiet']));

harness_section('The sidebar list');
$render = new ReflectionMethod($m, 'renderPluginList');
$html   = $render->invoke($m, $names, $settings, 'plugins', 'booking-bookings');
$at     = static fn (string $needle): int => (int) strpos($html, $needle);
check('Manage plugins and core links come first', $at('id="plugins_manage"') < $at('submenu-divide') && $at('id="plugins_market"') < $at('submenu-divide'));
check('each heading is a group heading', substr_count($html, '<li class="submenu-divide">') === 6);
check('the plugin’s own heading is not drawn twice', substr_count($html, 'Old plugin') === 1);
check('a link sits under its plugin heading', $at('>Booking<') < $at('id="settings-page-booking"') && $at('id="settings-page-booking"') < $at('>Legacy<'));
check('the current link is marked', strpos($html, 'id="booking-bookings" href="' . $base . '?page=plugins&action=renderplugin&route=booking-admin" aria-current="page"') !== false);
check('ids stay unique', count(array_unique($ids = (preg_match_all('/ id="([^"]+)"/', $html, $mm) ? $mm[1] : array()))) === count($ids));

harness_section('A declared settings page records its plugin');
Plugins::runAs('plugin', 'tax', static function (): void {
    \mindstellar\settings\SettingsPageRegistry::getInstance()->register('tax-owner-test', array('title' => 'Tax', 'menu' => '', 'fields' => array(array('name' => 'rate', 'type' => 'text', 'label' => 'Rate'))));
});
pin('the page knows its plugin', 'tax', \mindstellar\settings\SettingsPageRegistry::getInstance()->get('tax-owner-test')['plugin'] ?? null);

exit(harness_result());
