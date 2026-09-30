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
 * MessageHold: mail to a member leaves only from a confirmed address. A guest's message
 * waits in the job queue until the link mailed to them is used, once.
 *
 * Usage:  php tests/models/messagehold.php          (standalone, own scratch database)
 *         php tests/run-models.php messagehold      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_messagehold');

if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
// Stand-ins for the request, mail and flash layers; what they were handed is what the pins read.
$GLOBALS['held_mail']  = array();
$GLOBALS['held_flash'] = array();
foreach (array('_m', '__') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key) { return $key; }');
    }
}
if (!function_exists('osc_sendMail')) {
    function osc_sendMail($params)
    {
        $GLOBALS['held_mail'][] = $params;

        return true;
    }
}
if (!function_exists('_osc_from_email_aux')) {
    function _osc_from_email_aux()
    {
        return 'noreply@example.test';
    }
}
if (!function_exists('osc_add_flash_info_message')) {
    function osc_add_flash_info_message($msg)
    {
        $GLOBALS['held_flash'][] = $msg;
    }
}
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg)
    {
        $GLOBALS['held_flash'][] = $msg;
    }
}
if (!function_exists('osc_is_web_user_logged_in')) {
    function osc_is_web_user_logged_in()
    {
        return false;
    }
}
if (!function_exists('osc_write_signed_redirect_cookie')) {
    function osc_write_signed_redirect_cookie($name, $value, $expiry)
    {
    }
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php'; // User::findByPrimaryKey() reads through the cache

// When the real osc_sendMail() is loaded (as in the full suite), its init_send_mail filter
// swaps in a mailer that records instead of sending.
if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    final class HoldTestMailer extends \PHPMailer\PHPMailer\PHPMailer
    {
        public function send()
        {
            $GLOBALS['held_mail'][] = array('to' => $this->getToAddresses()[0][0] ?? '', 'body' => $this->Body);

            return true;
        }
    }
    osc_add_filter('init_send_mail', static function () {
        return new HoldTestMailer(true);
    });
}

use mindstellar\security\MessageHold;
use mindstellar\security\SignedPayload;

$jobs = static function () use ($admin): int {
    return (int) $admin->query(
        'SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . "t_job_queue WHERE s_type = '" . MessageHold::JOB . "'"
    )->fetch_assoc()['c'];
};
$sent = array();
osc_add_hook('hook_email_contact_user', static function ($id, $email, $name, $phone, $message) use (&$sent) {
    $sent[] = array($id, $email, $message);
});
$memberId = seed_user($admin, 'member', 'member@example.test');
$args     = array(
    'id'          => $memberId,
    'yourEmail'   => 'guest@example.test',
    'yourName'    => 'Guest',
    'phoneNumber' => '',
    'message'     => 'Is it still for sale?',
);

harness_section('MessageHold: who counts as confirmed');

pin('nobody without an address', false, MessageHold::verified(''));
pin('a guest with no trust cookie is not', false, MessageHold::verified('guest@example.test'));
$_COOKIE['osc_msg_trust'] = SignedPayload::pack('message-trust', array('h' => hash('sha256', 'other@example.test')), 60);
pin('a trust cookie for another address does not count', false, MessageHold::verified('guest@example.test'));
$_COOKIE['osc_msg_trust'] = 'forged.cookie';
pin('a forged cookie does not count', false, MessageHold::verified('guest@example.test'));
unset($_COOKIE['osc_msg_trust']);

harness_section('MessageHold: a guest message waits');

pin('it is not sent', false, MessageHold::deliver('user_contact', 'Guest@Example.test', $args));
pin('it says it was held', true, MessageHold::held());
pin('the member got nothing', array(), $sent);
pin('one message waits in the queue', 1, $jobs());
// Another suite file turns on demo mode, which sends no mail at all; the mail pins need mail.
if (!defined('DEMO')) {
    pin('one confirm mail went to the typed address', 'Guest@Example.test', $GLOBALS['held_mail'][0]['to'] ?? null);
    check(
        'the confirm mail carries the link and none of the typed text',
        strpos((string) ($GLOBALS['held_mail'][0]['body'] ?? ''), 'action=confirm') !== false
        && strpos((string) ($GLOBALS['held_mail'][0]['body'] ?? ''), 'still for sale') === false
    );
}

MessageHold::deliver('user_contact', 'guest@example.test', array('message' => 'Second try') + $args);
pin('a second message from the same address does not queue', 1, $jobs());
if (!defined('DEMO')) {
    pin('nor send a second confirm mail', 1, count($GLOBALS['held_mail']));
}

harness_section('MessageHold: the link sends it, once');

$heldId = static function () use ($admin): int {
    return (int) $admin->query(
        'SELECT MAX(pk_i_id) i FROM ' . DB_TABLE_PREFIX . "t_job_queue WHERE s_type = '" . MessageHold::JOB . "'"
    )->fetch_assoc()['i'];
};
$tokenOf = static function (string $url): string {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return (string) ($q['t'] ?? '');
};
$token = $tokenOf(MessageHold::confirmUrl($heldId(), 'guest@example.test'));
pin('a forged link is refused', 'invalid', MessageHold::confirm('forged.token'));
pin(
    'a link naming another address takes nothing',
    'gone',
    MessageHold::confirm($tokenOf(MessageHold::confirmUrl($heldId(), 'someone@else.test')))
);
pin('and leaves the message waiting', 1, $jobs());
pin('the link sends the message', 'sent', MessageHold::confirm($token));
pin('the member got the first message', array(array($memberId, 'guest@example.test', 'Is it still for sale?')), $sent);
pin('the queue is empty again', 0, $jobs());
pin('the same link again sends nothing', 'gone', MessageHold::confirm($token));
pin('still one mail to the member', 1, count($sent));

harness_section('MessageHold: a confirmed browser sends at once');

pin('the address is now confirmed here', true, MessageHold::verified('GUEST@example.test'));
pin('the next message goes straight out', true, MessageHold::deliver('user_contact', 'guest@example.test', $args));
pin('it was not held', false, MessageHold::held());
pin('the member got it', 2, count($sent));
if (!defined('DEMO')) {
    pin('no new confirm mail', 1, count($GLOBALS['held_mail']));
}

harness_section('MessageHold: a message for a member who is gone');

unset($_COOKIE['osc_msg_trust']);
$goneId = seed_user($admin, 'gone', 'gone@example.test', 1, 0);
MessageHold::deliver('user_contact', 'late@example.test', array('id' => $goneId) + $args);
pin('is dropped, not sent', 'failed', MessageHold::confirm($tokenOf(MessageHold::confirmUrl($heldId(), 'late@example.test'))));
pin('the member got nothing more', 2, count($sent));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/messagehold.php */
