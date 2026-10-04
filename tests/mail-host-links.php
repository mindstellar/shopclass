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
 * When the site address comes from the Host header, a mail must not link to it: links
 * move to OSC_CLI_URL, and with none set the mail is not sent.
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

    public static function newInstance()
    {
        return new self();
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
define('OSC_WEB_PATH_FROM_REQUEST', true);
define('WEB_PATH', 'http://attacker.example/');

$errors = array();
set_error_handler(static function ($no, $str) use (&$errors) {
    $errors[] = $str;

    return true;
});
$send = static function (string $body): ?array {
    RecordingMailer::$last = null;
    osc_sendMail(array('to' => 'a@b.example', 'subject' => 's', 'body' => $body, 'alt_body' => $body));

    return RecordingMailer::$last;
};

harness_section('no trusted address configured');
$sent = $send('<a href="http://attacker.example/user/recover?x=1">reset</a>');
pin('a mail linking to the request host is not sent', null, $sent);
check('...and an error is logged', count($errors) === 1 && strpos($errors[0], 'WEB_PATH') !== false);
$sent = $send('Your ad was received.');
check('a mail with no link to it still goes out', is_array($sent));

harness_section('OSC_CLI_URL configured');
define('OSC_TRUSTED_WEB_PATH', 'https://shop.example.com/');
$sent = $send('<a href="http://attacker.example/user/recover?x=1">reset</a>');
check('the link moves to the trusted address', is_array($sent) && strpos($sent['Body'], 'https://shop.example.com/user/recover?x=1') !== false);
check('...and nothing points at the request host', is_array($sent) && stripos($sent['Body'] . $sent['AltBody'], 'attacker.example') === false);

exit(harness_result());
