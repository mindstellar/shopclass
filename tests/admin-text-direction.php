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
 * The admin and its login page put dir="rtl" on <html> when the admin's language is right-to-left,
 * and only then. The visitor's language does not count.
 *
 * DB-free.  Usage:  php tests/admin-text-direction.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';

function osc_admin_language()
{
    return 'en_US';
}
function osc_get_preference($key, $section = 'osclass')
{
    return '';
}
function osc_logged_admin_id()
{
    return 1;
}
function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

// What the shells call besides the locale helpers; their output does not matter here.
foreach (array('osc_admin_base_url', 'osc_admin_breadcrumb', 'osc_api_admin_session_meta', 'osc_asset_url_versioned', 'osc_assets_url', 'osc_base_url', 'osc_csrf_token_url', 'osc_current_admin_theme_js_url', 'osc_current_admin_theme_styles_url', 'osc_draw_admin_menu', 'osc_logged_admin_email', 'osc_logged_admin_name', 'osc_logged_admin_username', 'osc_page_title', 'osc_run_hook', 'osc_show_flash_message') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ""; }');
    }
}
function osc_admin_base_path()
{
    return __DIR__ . '/lib/';
}
function __($s)
{
    return $s;
}
function _e($s)
{
    echo $s;
}
function osc_apply_filter($hook, $value = '')
{
    return $value;
}
function osc_is_moderator()
{
    return true;
}
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '7.1.0');
}

$row = static fn (string $code, string $dir): array => array('pk_c_code' => $code, 's_direction' => $dir);
View::getInstance()->_exportVariableToView('login_admin_form', 'empty-template.php');
View::getInstance()->_exportVariableToView('adminLocales', array($row('ar_SA', 'rtl'), $row('en_US', 'ltr')));
View::getInstance()->_exportVariableToView('locales', array($row('ar_SA', 'rtl'), $row('en_US', 'ltr')));

/** The <html> tag a shell prints, from the real template. The page below <head> needs the whole admin, so its first error ends the render. */
function html_tag(string $file): string
{
    ob_start();
    try {
        include ABS_PATH . $file;
    } catch (\Error) {
    } finally {
        $out = (string) ob_get_clean();
    }
    preg_match('~<html[^>]*>~', $out, $m);

    return $m[0] ?? '';
}

$shells = array('oc-admin/themes/modern/parts/header.php', 'oc-admin/gui/main.php');
$dirs   = static fn (): array => array_map(static function (string $f): ?string {
    return preg_match('~\sdir="([^"]*)"~', html_tag($f), $m) ? $m[1] : null;
}, $shells);

harness_section('a right-to-left admin language');
Session::getInstance()->_setEphemeral('adminLocale', 'ar_SA');
pin('the helper says rtl', 'rtl', osc_admin_locale_text_direction());
pin('both shells print dir="rtl"', array('rtl', 'rtl'), $dirs());
check('the login shell says the language too', str_contains(html_tag('oc-admin/gui/main.php'), 'lang="ar-SA"'), html_tag('oc-admin/gui/main.php'));

harness_section('a left-to-right admin language');
Session::getInstance()->_setEphemeral('adminLocale', 'en_US');
pin('the helper says ltr', 'ltr', osc_admin_locale_text_direction());
pin('no shell prints dir', array(null, null), $dirs());

harness_section('the visitor reads right-to-left, the admin does not');
$_COOKIE['oc_userLocale'] = 'ar_SA';
pin('the visitor helper follows the visitor', 'rtl', osc_locale_text_direction());
pin('the admin keeps ltr', 'ltr', osc_admin_locale_text_direction());
pin('and no shell prints dir', array(null, null), $dirs());
unset($_COOKIE['oc_userLocale']);

harness_section('an admin language that is not enabled');
Session::getInstance()->_setEphemeral('adminLocale', 'he_IL');
pin('reads ltr', 'ltr', osc_admin_locale_text_direction());

exit(harness_result());

/* file end: ./tests/admin-text-direction.php */
