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

exit(harness_result());
