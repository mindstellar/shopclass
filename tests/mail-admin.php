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
 * osc_mail_admin(): one escaped paragraph per line, sent to the contact address, and false
 * instead of an exception when it cannot be sent.
 * DB-free. Sending is caught by DeferredMail. Usage:  php tests/mail-admin.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\mail\Mailer;
use mindstellar\utility\DeferredMail;

$GLOBALS['contact'] = 'admin@example.com';
function osc_contact_email()
{
    if ($GLOBALS['contact'] instanceof Throwable) {
        throw $GLOBALS['contact'];
    }

    return $GLOBALS['contact'];
}
function osc_mailserver_mail_from()
{
    return 'noreply@example.com';
}
function osc_esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

$sent = [];
$send = static function (array $params) use (&$sent): void {
    $sent[] = $params;
};

$result = DeferredMail::during(static fn () => Mailer::admin('Paid after close', ['Order #5 <b>paid</b>', 42, ['skipped'], 'Refund it & check']), $send);
pin('reports it as sent', true, $result);
pin('one mail to the contact address, from the site sender', [1, 'admin@example.com', 'noreply@example.com', 'Paid after close'], [count($sent), $sent[0]['to'] ?? null, $sent[0]['from'] ?? null, $sent[0]['subject'] ?? null]);
pin('each line escaped in its own paragraph, arrays left out', '<p>Order #5 &lt;b&gt;paid&lt;/b&gt;</p><p>42</p><p>Refund it &amp; check</p>', $sent[0]['body'] ?? null);

$sent               = [];
$GLOBALS['contact'] = '';
pin('no contact address: false and nothing sent', [false, []], [DeferredMail::during(static fn () => Mailer::admin('x', ['y']), $send), $sent]);

$GLOBALS['contact'] = new RuntimeException('preferences unavailable');
$logged             = ini_set('error_log', '/dev/null');
$threw              = false;
try {
    $result = Mailer::admin('x', ['y']);
} catch (Throwable $e) {
    $threw = true;
}
ini_set('error_log', (string) $logged);
pin('a failure returns false and does not throw', [false, false], [$result, $threw]);

foreach (['Error' => new Error('undefined thing'), 'TypeError' => new TypeError('wrong type')] as $label => $error) {
    $GLOBALS['contact'] = $error;
    $logged             = ini_set('error_log', '/dev/null');
    $threw              = false;
    try {
        $result = Mailer::admin('x', ['y']);
    } catch (Throwable $e) {
        $threw = true;
    }
    ini_set('error_log', (string) $logged);
    pin($label . ' from the contact lookup is caught too', [false, false], [$result, $threw]);
}

function osc_add_hook($hook, $callback)
{
}
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

$GLOBALS['contact'] = 'admin@example.com';
$sent               = [];
pin('osc_mail_admin() sends through the same path', [true, 1], [DeferredMail::during(static fn () => osc_mail_admin('s', ['l']), $send), count($sent)]);

function osc_page_title()
{
    return 'Acme';
}
if (!function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}
$sent     = [];
$endpoint = new mindstellar\webhook\Endpoint('ep1', 'https://hooks.example.com/in?token=T', [], '', false, 's', failures: 5, lastStatus: 'HTTP 500 <x>');
DeferredMail::during(static fn () => mindstellar\webhook\Delivery::mailPaused($endpoint), $send);
pin('a paused webhook mails the contact address through the same path', [1, 'admin@example.com', '[Acme] A webhook endpoint was paused'], [count($sent), $sent[0]['to'] ?? null, $sent[0]['subject'] ?? null]);
check('with its one line escaped', str_starts_with($sent[0]['body'] ?? '', '<p>Deliveries to ep1 (hooks.example.com) failed 5 times') && str_contains($sent[0]['body'], 'HTTP 500 &lt;x&gt;.'));

$sent   = [];
$notice = new ReflectionMethod(mindstellar\upgrade\AutoSecurityUpdate::class, 'mail');
$notice->setAccessible(true);
DeferredMail::during(static fn () => $notice->invoke(null, 'files', '6.4.1', '6.4.2', 'disk <full>.'), $send);
pin('a security update notice goes the same way', [1, 'admin@example.com', '[Acme] Security update 6.4.2 could not be installed'], [count($sent), $sent[0]['to'] ?? null, $sent[0]['subject'] ?? null]);
check('with its reason escaped', str_contains($sent[0]['body'] ?? '', 'stopped: disk &lt;full&gt;. Open Tools'));

// A demo install refuses every send, which is the send failing.
define('DEMO', true);
pin('osc_mail_admin() is false when the send fails', false, osc_mail_admin('s', ['l']));

exit(harness_result());
