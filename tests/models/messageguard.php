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
 * MessageGuard: the link limit on messages, the signed "Report the sender" link, and
 * the message-only, expiring ban rules a report writes.
 *
 * Usage:  php tests/models/messageguard.php          (standalone, own scratch database)
 *         php tests/run-models.php messageguard      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_messageguard');
$table = DB_TABLE_PREFIX . 't_ban_rule';

if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
// The translation stack is more than this file needs; the wording is not what these pins read.
if (!function_exists('__')) {
    function __($key)
    {
        return $key;
    }
}
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('_mn')) {
    function _mn($single, $plural, $count)
    {
        return $count == 1 ? $single : $plural;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';

use mindstellar\security\MessageGuard;

// null deletes the row and blanks the loaded copy, which a reload alone keeps.
$setPref = static function (string $key, ?string $value): void {
    if ($value === null) {
        osc_delete_preference($key);
        Preference::newInstance()->set($key, '');

        return;
    }
    osc_set_preference($key, $value, 'osclass', 'STRING');
    osc_reset_preferences();
};

/* ----------------------------------------------------------------------------
 * Counting links.
 * ------------------------------------------------------------------------- */
harness_section('MessageGuard: counting links');

pin('no text, no links', 0, MessageGuard::countLinks(''));
pin('a link with a scheme counts', 1, MessageGuard::countLinks('See https://example.com now'));
pin('www. counts, and so does a second scheme', 2, MessageGuard::countLinks('www.a.com and http://b.org/x'));
pin('a bare domain with a path counts', 1, MessageGuard::countLinks('go to example.com/deal'));
pin('words with a dot do not count', 0, MessageGuard::countLinks('I use Node.js, e.g. for example.com work.'));
pin('a link inside markup counts once', 1, MessageGuard::countLinks('<a href="https://x.test/">here</a>'));

harness_section('MessageGuard: the link limit');

$setPref('message_max_links', null);
pin('unset, the limit is 1', 1, MessageGuard::maxLinks());
pin('one link passes', null, MessageGuard::linkError('Call me or see https://a.test'));
check('two links are refused', is_string(MessageGuard::linkError('https://a.test', 'and https://b.test')));

$setPref('message_max_links', '0');
pin('0 is kept, not read as unset', 0, MessageGuard::maxLinks());
check('with 0, one link is refused', is_string(MessageGuard::linkError('https://a.test')));
pin('with 0, plain text passes', null, MessageGuard::linkError('Is it still for sale?'));
$setPref('message_max_links', null);

/* ----------------------------------------------------------------------------
 * The signed report link.
 * ------------------------------------------------------------------------- */
harness_section('MessageGuard: report link');

$url = MessageGuard::reportUrl('spammer@example.test', 'seller@example.test');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
$token = (string) ($query['t'] ?? '');

pin('the link opens the report page', 'report', $query['action'] ?? null);
pin(
    'a real token reads back',
    array('sender' => 'spammer@example.test', 'recipient' => 'seller@example.test'),
    MessageGuard::readReport($token)
);
[$payload, $sig] = explode('.', $token);
$forged = rtrim(strtr(base64_encode(json_encode(array('s' => 'someone@else.test', 'r' => 'x@y.test', 't' => time()))), '+/', '-_'), '=');
pin('a changed payload is refused', null, MessageGuard::readReport($forged . '.' . $sig));
pin('a changed signature is refused', null, MessageGuard::readReport($payload . '.' . strrev($sig)));
pin('garbage is refused', null, MessageGuard::readReport('not-a-token'));

$sign = new ReflectionMethod(MessageGuard::class, 'sign');
if (PHP_VERSION_ID < 80100) {
    $sign->setAccessible(true);
}
$old = rtrim(strtr(base64_encode(json_encode(
    array('s' => 'a@b.test', 'r' => 'c@d.test', 't' => time() - 31 * 86400)
)), '+/', '-_'), '=');
pin('a link older than 30 days is refused', null, MessageGuard::readReport($old . '.' . $sign->invoke(null, $old)));

$setPref('message_report_link', '0');
pin('with the report link off, no footer', '', MessageGuard::reportFooter('a@b.test', 'c@d.test'));
$setPref('message_report_link', null);
check('by default mail carries the footer', strpos(MessageGuard::reportFooter('a@b.test', 'c@d.test'), 'action=report') !== false);

/* ----------------------------------------------------------------------------
 * Ban rules: scope and expiry.
 * ------------------------------------------------------------------------- */
harness_section('MessageGuard: ban scope and expiry');

$future = date('Y-m-d H:i:s', time() + 86400);
$past   = date('Y-m-d H:i:s', time() - 86400);
$seed   = static function (string $email, string $scope, ?string $expires) use ($admin, $table): void {
    seed_exec(
        $admin,
        "INSERT INTO $table (s_name, s_ip, s_email, s_scope, dt_expires) VALUES ('t', '', ?, ?, ?)",
        'sss',
        array($email, $scope, $expires)
    );
};
$seed('all@example.test', 'all', null);
$seed('msg@example.test', 'messages', $future);
$seed('ended@example.test', 'messages', $past);
$seed('endedall@example.test', 'all', $past);

pin('a full ban still bans', 1, osc_is_banned('all@example.test', '10.0.0.1'));
pin('a message ban does not block sign-in or posting', 0, osc_is_banned('msg@example.test', '10.0.0.1'));
pin('a message ban blocks the message forms', 1, osc_is_banned('msg@example.test', '10.0.0.1', 'messages'));
pin('a full ban also blocks the message forms', 1, osc_is_banned('all@example.test', '10.0.0.1', 'messages'));
pin('an ended message ban blocks nothing', 0, osc_is_banned('ended@example.test', '10.0.0.1', 'messages'));
pin('an ended full ban blocks nothing', 0, osc_is_banned('endedall@example.test', '10.0.0.1'));
pin('osc_is_email_banned leaves message bans out', false, osc_is_email_banned('msg@example.test'));

harness_section('MessageGuard: a report writes a message ban');

$setPref('message_report_days', null);
check('a report is saved', MessageGuard::banSender('!new*@example.test', 'seller@example.test'));
$row = $admin->query("SELECT * FROM $table WHERE s_email = 'new@example.test'")->fetch_assoc();
pin('pattern characters are dropped, so the address matches literally', 'new@example.test', $row['s_email'] ?? null);
pin('the rule blocks messages only', 'messages', $row['s_scope'] ?? null);
check('the rule ends in 30 days', abs(strtotime((string) $row['dt_expires']) - strtotime('+30 days')) < 120);
pin('the name says who reported it', 'Reported by seller@example.test', $row['s_name'] ?? null);
pin('the reported address is now blocked from messages', 1, osc_is_banned('new@example.test', '10.0.0.1', 'messages'));

MessageGuard::banSender('new@example.test', 'other@example.test');
pin(
    'a second report extends the ban instead of adding a rule',
    '1',
    $admin->query("SELECT COUNT(*) c FROM $table WHERE s_email = 'new@example.test'")->fetch_assoc()['c']
);

MessageGuard::purgeExpired();
pin(
    'the daily purge deletes ended rules only',
    '0',
    $admin->query("SELECT COUNT(*) c FROM $table WHERE dt_expires IS NOT NULL AND dt_expires <= NOW()")->fetch_assoc()['c']
);
pin(
    'rules with no end date stay',
    '1',
    $admin->query("SELECT COUNT(*) c FROM $table WHERE s_email = 'all@example.test'")->fetch_assoc()['c']
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/messageguard.php */
