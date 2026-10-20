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
 * When the site address comes from the Host header, mail links move to OSC_CLI_URL. With
 * none set, only a mail that carries a secret link is refused.
 *
 * DB-free.  Usage:  php tests/mail-host-links.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('LIB_PATH')) {
    define('LIB_PATH', ABS_PATH . 'oc-includes/');
}
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '0');
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use PHPMailer\PHPMailer\PHPMailer;

/** A mailer that keeps what it would have sent. */
class RecordingMailer extends PHPMailer
{
    public static $last;

    public function send()
    {
        self::$last = array('Body' => $this->Body, 'AltBody' => $this->AltBody, 'Subject' => $this->Subject);

        return true;
    }
}

$GLOBALS['__altOverride'] = null;
$GLOBALS['__initAlt']     = null;

function osc_apply_filter($tag, $value, ...$args)
{
    if ($tag === 'mail_layout_vars' && isset($GLOBALS['__accent'])) {
        $value['accent'] = $GLOBALS['__accent'];
    }
    if ($tag === 'init_send_mail') {
        $mail = new RecordingMailer(true);
        if ($GLOBALS['__initAlt'] !== null) {
            $mail->AltBody = $GLOBALS['__initAlt'];
        }

        return $mail;
    }
    if ($tag === 'pre_send_mail' && $GLOBALS['__altOverride'] !== null) {
        $value->AltBody = $GLOBALS['__altOverride'];
    }

    return $value;
}
function osc_mailserver_pop()
{
    return false;
}
function osc_mailserver_auth()
{
    return false;
}
function osc_mailserver_ssl()
{
    return '';
}
function osc_mailserver_username()
{
    return '';
}
function osc_mailserver_password()
{
    return '';
}
function osc_mailserver_host()
{
    return '';
}
function osc_mailserver_port()
{
    return '';
}
function osc_mailserver_mail_from()
{
    return 'site@example.com';
}
function osc_mailserver_name_from()
{
    return 'Example';
}
function osc_get_domain()
{
    return 'example.com';
}
function osc_page_title()
{
    return 'Example';
}

function osc_base_url()
{
    return 'https://example.com/';
}
function __($text)
{
    return $text;
}
function osc_esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function osc_current_user_locale()
{
    return 'en_US';
}
function osc_themes_path()
{
    return sys_get_temp_dir() . '/';
}
function osc_theme()
{
    return $GLOBALS['__siteTheme'] ?? '';
}
/** The site's theme, as far as the e-mail layout asks: a folder that can be swapped. */
class WebThemes
{
    public static $path = '';

    public static function getInstance()
    {
        return new self();
    }

    public static function newInstance()
    {
        return self::getInstance();
    }

    public function getCurrentThemePath()
    {
        return self::$path;
    }

    public function setCurrentTheme($theme)
    {
        self::$theme = $theme;
        self::$path  = osc_themes_path() . $theme . '/';
    }

    public static $theme = 'test';

    public function getCurrentTheme()
    {
        return self::$theme;
    }

    public function loadThemeInfo($theme)
    {
        return array();
    }
}

require_once __DIR__ . '/../oc-includes/osclass/utils.php';

// Constants cannot change within one run, so each set-up runs in its own process.
if (isset($argv[1])) {
    if ($argv[1] !== 'config-file') {
        define('OSC_WEB_PATH_FROM_REQUEST', true);
        define('WEB_PATH', 'http://example.com/');
    }
    if ($argv[1] === 'cli-url') {
        define('OSC_TRUSTED_WEB_PATH', 'https://shop.example.com/');
    }
    $errors = array();
    set_error_handler(static function ($no, $str) use (&$errors) {
        $errors[] = $str;

        return true;
    });
    $out = array();
    foreach (array('secret' => true, 'plain' => false) as $kind => $secret) {
        RecordingMailer::$last = null;
        $body = '<a href="http://example.com/user/recover/1/abc">reset</a> <a href="http://www.example.com/x">www</a>';
        osc_sendMail(array('to' => 'a@b.example', 'subject' => 's', 'body' => $body, 'secret_link' => $secret));
        $out[$kind] = RecordingMailer::$last;
    }
    $out['errors'] = $errors;
    echo json_encode($out);
    exit(0);
}

$run = static function (string $setup): array {
    $json = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($setup));

    return (array) json_decode((string) $json, true);
};

harness_section('config.php install');
$r = $run('config-file');
check('a mail with a secret link goes out', is_array($r['secret'] ?? null));
check('...with its links unchanged', strpos($r['secret']['Body'] ?? '', 'http://example.com/user/recover/1/abc') !== false);
pin('...and no warning', array(), $r['errors'] ?? null);

harness_section('address from the Host header, no OSC_CLI_URL');
$r = $run('host-only');
pin('a mail with a secret link is not sent', null, array_key_exists('secret', $r) ? $r['secret'] : 'missing');
check('...and a warning is logged', count($r['errors'] ?? array()) === 1 && strpos($r['errors'][0], 'OSC_CLI_URL') !== false);
check('a mail without one still goes out, though it links to www.example.com', is_array($r['plain'] ?? null));

harness_section('address from the Host header, OSC_CLI_URL set');
$r = $run('cli-url');
check('a mail with a secret link goes out', is_array($r['secret'] ?? null));
check('...linking to OSC_CLI_URL', strpos($r['secret']['Body'] ?? '', 'https://shop.example.com/user/recover/1/abc') !== false);
check('...and no longer to the request address', strpos(($r['secret']['Body'] ?? '') . ($r['secret']['AltBody'] ?? ''), 'http://example.com/') === false);
check('a different host that contains it is left alone', strpos($r['secret']['Body'] ?? '', 'http://www.example.com/x') !== false);
pin('no warning', array(), $r['errors'] ?? null);

exit(harness_result());
