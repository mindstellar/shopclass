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
 * Pins the installer's database step: values kept after an error, the password never echoed,
 * the flagged field with its short error, the message shown once, and the More options
 * disclosure opening only for the fields inside it.
 *
 * DB-free.  Usage: php tests/install-database-step.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

function __($text)
{
    return $text;
}

function _e($text)
{
    echo $text;
}

require_once LIB_PATH . 'vendor/autoload.php';
require_once LIB_PATH . 'osclass/helpers/hSanitize.php';
require_once LIB_PATH . 'osclass/install-functions.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['install_nonce'] = 'nonce123';

$render = static function ($error, array $form_data = array()): string {
    ob_start();
    include LIB_PATH . 'osclass/installer/gui/install-database.php';

    return (string) ob_get_clean();
};
$tag = static fn (string $html, string $id): string => preg_match('/<(?:input|select)[^>]*\bid="' . $id . '"[^>]*>/', $html, $m) ? $m[0] : '';

harness_section('through display_database_config()');
// Runs first: the function uses include_once, so it renders nothing after $render has run.
ob_start();
display_database_config(array('dbhost' => 'db.example', 'dbname' => 'shop', 'username' => 'bob', 'tableprefix' => 'xy_', 'password' => 'secret'), install_db_error_message(1045));
$html = (string) ob_get_clean();
check('display_database_config() flags #password for code 1045', str_contains($tag($html, 'password'), 'aria-invalid="true"') && !str_contains($html, 'secret'));
check('display_database_config() keeps the typed values', str_contains($tag($html, 'dbhost'), 'value="db.example"') && str_contains($tag($html, 'tableprefix'), 'value="xy_"'));

harness_section('first visit, no error');
$html = $render(null);
check('the shared error panel is there but hidden', (bool) preg_match('/<div id="ins-db-error" class="ins-panel ins-panel-danger" role="alert"[^>]*\shidden>/', $html));
check('Test connection is hidden until the script shows it', (bool) preg_match('/<button type="button" id="ins-test-btn"[^>]*\shidden>/', $html));
check('no field is flagged', !str_contains($html, 'aria-invalid'));
check('every error line is hidden', substr_count($html, 'class="ins-field-error"') === 7 && substr_count($html, '-error" hidden>') === 7);
check('posts to step 3 with the nonce', str_contains($html, 'name="step" value="3"') && str_contains($html, 'name="install_nonce" value="nonce123"'));
check('new lede', str_contains($html, 'Your host gives you these four details'));
check('labels', str_contains($html, '>Database host<') && str_contains($html, '>Database name<') && str_contains($html, '>Username<') && str_contains($html, '>Password<'));
check('Back goes to step 1', str_contains($html, 'href="install.php?step=1"'));
check('Test connection button', str_contains($html, 'id="ins-test-btn"'));
check('submit is Connect with a spinner', (bool) preg_match('/<button type="submit" id="ins-connect-btn"[^>]*>\s*<span class="ins-spinner"[^>]*><\/span>\s*<span class="ins-btn-label">Connect<\/span>/', $html));
check('createdb and reset_unfinished are there', $tag($html, 'createdb') !== '' && $tag($html, 'reset_unfinished') !== '');
check('More options starts closed', !preg_match('/<details class="ins-disclosure"\s+open/', $html));

harness_section('wrong password');
$html = $render(array('error' => 'Access denied for user', 'field' => 'password'), array('dbhost' => 'db.example', 'dbname' => 'shop', 'username' => 'bob', 'password' => 'secret"x'));
check('host, name and user are kept', str_contains($tag($html, 'dbhost'), 'value="db.example"') && str_contains($tag($html, 'dbname'), 'value="shop"') && str_contains($tag($html, 'username'), 'value="bob"'));
check('password value is empty', str_contains($tag($html, 'password'), 'value=""') && !str_contains($html, 'secret'));
check('#password is flagged', str_contains($tag($html, 'password'), 'aria-invalid="true"'));
check('#password-error is visible with its text', (bool) preg_match('/<p class="ins-field-error" id="password-error">.*?<span>Check this password\.<\/span>/s', $html));
check('other fields are not flagged', !str_contains($tag($html, 'dbhost'), 'aria-invalid') && !str_contains($tag($html, 'dbname'), 'aria-invalid'));
check('the alert note holds the message once', substr_count($html, 'role="alert"') === 1 && substr_count($html, 'Access denied for user') === 1 && (bool) preg_match('/<div id="ins-db-error" class="ins-panel ins-panel-danger" role="alert" tabindex="-1">/', $html));
check('the alert note has a title', str_contains($html, 'Couldn&#039;t connect to the database'));
check('the alert note carries an icon', (bool) preg_match('/role="alert"[^>]*>\s*<svg class="ins-icon ins-panel-icon"/', $html));
check('More options stays closed', !preg_match('/<details class="ins-disclosure"\s+open/', $html));

harness_section('wrong host');
$html = $render(array('error' => 'Unknown host', 'field' => 'dbhost'));
check('#dbhost is flagged with its text', str_contains($tag($html, 'dbhost'), 'aria-invalid="true"') && str_contains($html, '<span>Check the host.</span>'));
check('More options stays closed', !preg_match('/<details class="ins-disclosure"\s+open/', $html));

harness_section('wrong table prefix');
$html = $render(array('error' => 'Bad prefix', 'field' => 'tableprefix'));
check('More options is open', (bool) preg_match('/<details class="ins-disclosure"\s+open/', $html));
check('#tableprefix is flagged with its text', str_contains($tag($html, 'tableprefix'), 'aria-invalid="true"') && str_contains($html, '<span>Check the table prefix.</span>'));

harness_section('unfinished install');
$html = $render(array('error' => 'Tables exist', 'field' => 'reset_unfinished'));
check('More options is open', (bool) preg_match('/<details class="ins-disclosure"\s+open/', $html));
check('the checkbox is flagged', str_contains($tag($html, 'reset_unfinished'), 'aria-invalid="true"'));

harness_section('real error producers');
$codes = array(1045 => 'password', 2002 => 'dbhost', 2003 => 'dbhost', 1049 => 'dbname', 1050 => 'tableprefix', 1142 => 'username');
foreach ($codes as $code => $field) {
    check("MySQL code $code flags $field", install_db_error_message($code, array('dbhost' => 'h', 'dbname' => 'd'))['field'] === $field);
}
$page = (string) file_get_contents(LIB_PATH . 'osclass/installer/gui/install.php');
$branch = preg_match('/\$form_data = array\((.*?)\);\s*display_database_config\(\$form_data, \$error\);/s', $page, $m) ? $m[1] : '';
$keys = array('dbhost', 'username', 'dbname', 'tableprefix', 'createdb');
check('the step 3 error branch builds $form_data from the post and passes it on', $branch !== '' && count(array_filter($keys, static fn (string $k): bool => str_contains($branch, "'$k'") && str_contains($branch, "getParam('$k')"))) === count($keys));

harness_section('markup sources');
$src = (string) file_get_contents(LIB_PATH . 'osclass/installer/gui/install-database.php');
check('uses the field helper', str_contains($src, 'install_field('));
check('no pasted field or reveal markup', !str_contains($src, 'class="ins-field') && !str_contains($src, 'ins-icon-hide'));

harness_section('shopclass defaults');
$html = $render(null);
check('database name and user default to shopclass', str_contains($tag($html, 'dbname'), 'value="shopclass"') && str_contains($tag($html, 'username'), 'value="shopclass"'));
check('table prefix stays oc_', str_contains($tag($html, 'tableprefix'), 'value="oc_"'));
check('no check row is written by hand', !str_contains($src, 'class="ins-check"') && str_contains($src, 'install_check('));
check('the error panel comes from the helper', !str_contains($src, 'class="ins-panel') && str_contains($src, 'install_panel('));

harness_section('session expired panel');
ob_start();
install_session_expired_panel();
$html = (string) ob_get_clean();
check('it has the headline', str_contains($html, '<h1 class="ins-headline">Your session ended</h1>'));
check('it is a stage with a body and an action bar', str_contains($html, 'class="ins-stage"') && str_contains($html, 'class="ins-stage-body"') && str_contains($html, 'class="ins-actions"'));
check('it keeps the explanation and the restart button', str_contains($html, 'Your session expired before setup finished.') && (bool) preg_match('/<a class="ins-btn ins-btn-primary" href="install.php">Restart the installer<\/a>/', $html));

exit(harness_result());
