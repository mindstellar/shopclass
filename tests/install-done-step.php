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
 * Pins the installer's done step partial: credentials, mail note and "To finish later".
 *
 * DB-free.  Usage: php tests/install-done-step.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

$GLOBALS['stub_title'] = 'Riverside Market';

function __($text)
{
    return $text;
}

function _e($text)
{
    echo $text;
}

function osc_page_title()
{
    return $GLOBALS['stub_title'];
}

function get_absolute_url()
{
    return 'https://example.test/shop/';
}

require_once LIB_PATH . 'vendor/autoload.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

$partial = ABS_PATH . 'oc-includes/osclass/installer/gui/install-finish.php';

$render = static function (array $later, ?string $mailError = null, string $email = 'owner@example.test') use ($partial): string {
    $GLOBALS['error'] = $mailError;
    $data = array('s_email' => $email, 'admin_user' => 'admin', 'password' => 'Tq7-maple');
    $ins_later = $later;
    $password = 'Tq7-maple';
    ob_start();
    include $partial;
    return (string) ob_get_clean();
};

$html = $render(array());

harness_section('credentials');
check('done headline can take focus', (bool) preg_match('/<h1[^>]*id="ins-done-title"[^>]*tabindex="-1"/', $html));
check('three credential rows', substr_count($html, 'class="ins-cred"') === 3);
check('password input is masked', (bool) preg_match('/<input[^>]*id="ins-cred-password"[^>]*type="password"|<input[^>]*type="password"[^>]*id="ins-cred-password"/', $html));
check('password has reveal and copy', str_contains($html, 'data-reveal-target="ins-cred-password"') && str_contains($html, 'data-copy-target="ins-cred-password"'));
check('admin address breaks after each slash', str_contains($html, 'id="ins-cred-admin">https:/<wbr>/<wbr>example.test/<wbr>shop/<wbr>oc-admin/<wbr></span>'));
check('admin address copies as the plain url', preg_match('/id="ins-cred-admin">(.*?)<\/span>/s', $html, $am) === 1 && strip_tags($am[1]) === 'https://example.test/shop/oc-admin/');
check('address and username are wrapping text, only the password is an input', substr_count($html, 'class="ins-cred-v"') === 2 && substr_count($html, 'readonly') === 1);
check('username and admin address have copy buttons', str_contains($html, 'data-copy-target="ins-cred-username"') && str_contains($html, 'data-copy-target="ins-cred-admin"'));

harness_section('heading and buttons');
check('heading uses the site name', str_contains($html, 'Riverside Market is ready'));
$GLOBALS['stub_title'] = '';
check('heading falls back with no site name', str_contains($render(array()), 'Your site is ready.'));
$GLOBALS['stub_title'] = 'Riverside Market';
check('view site and admin buttons', str_contains($html, 'View your site') && str_contains($html, 'Open your admin panel'));
check('seal and done head', str_contains($html, 'ins-done-seal') && str_contains($html, 'ins-done-head'));

harness_section('mail note');
check('mail note shows the address', str_contains($html, 'We also emailed these to <b>owner@example.test</b>.'));
$failed = $render(array(), 'smtp down');
check('failed mail says so', str_contains($failed, 'email your password.') && !str_contains($failed, 'We also emailed'));
check('no address also says so', str_contains($render(array(), null, ''), 'email your password.'));

harness_section('to finish later');
check('absent when nothing failed', !str_contains($html, 'To finish later') && !str_contains($html, 'ins-later'));
$items = array(
    array('what' => 'PayPal <b>Pro</b>', 'how' => 'Add it under Plugins → Add plugin.'),
    array('what' => '<script>alert(1)</script>', 'how' => 'Try again.'),
);
$later = $render($items);
check('lists both items', str_contains($later, 'To finish later') && substr_count($later, '<li>') === 2);
check('item text is escaped', str_contains($later, '&lt;b&gt;Pro&lt;/b&gt;') && !str_contains($later, '<b>Pro</b>'));
check('script in a name is escaped', !str_contains($later, '<script>') && str_contains($later, '&lt;script&gt;'));
check('no warning panels', !str_contains($later, 'ins-panel-warning') && !str_contains($html, 'ins-panel-warning'));

exit(harness_result());
