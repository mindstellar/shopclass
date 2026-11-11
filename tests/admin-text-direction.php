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

$row = static fn (string $code, string $dir): array => array('pk_c_code' => $code, 's_direction' => $dir);
View::getInstance()->_exportVariableToView('adminLocales', array($row('ar_SA', 'rtl'), $row('en_US', 'ltr')));
View::getInstance()->_exportVariableToView('locales', array($row('ar_SA', 'rtl'), $row('en_US', 'ltr')));

/** The <html> tag a shell prints: its own code, run up to and including that tag. */
function html_tag(string $file): string
{
    $src = (string) file_get_contents(ABS_PATH . $file);
    $end = (int) strpos($src, "\n", (int) strpos($src, "\n<html") + 1);
    ob_start();
    eval('?>' . substr($src, 0, $end));

    return trim((string) strrchr("\n" . (string) ob_get_clean(), "\n"));
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
