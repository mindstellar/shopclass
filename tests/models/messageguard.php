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
use mindstellar\security\SignedPayload;

// null deletes the row and blanks the loaded copy, which a reload alone keeps.
$setPref = static function (string $key, ?string $value): void {
    if ($value === null) {
        osc_delete_preference($key);
        Preference::getInstance()->set($key, '');

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
pin('words with a dot do not count', 0, MessageGuard::countLinks('I use Node.js, e.g. version 2.5 works.'));
pin('a link inside markup counts once', 1, MessageGuard::countLinks('<a href="https://x.test/">here</a>'));
pin('a bare domain on a common ending counts', 2, MessageGuard::countLinks('evil.com and buy.shop today'));
pin('a bare domain with a query counts', 1, MessageGuard::countLinks('see evil.example?ref=promo'));
pin('look-alike dots are read as dots', 1, MessageGuard::countLinks('visit evil．com'));
pin('an e-mail address is not a link', 0, MessageGuard::countLinks('write to me at jane@gmail.com'));

harness_section('MessageGuard: the link limit');

$setPref('message_max_links', null);
pin('unset, the limit is 1', 1, MessageGuard::maxLinks());
pin('one link passes', null, MessageGuard::messageError('Call me or see https://a.test'));
check('two links are refused', is_string(MessageGuard::messageError('https://a.test and https://b.test')));

harness_section('MessageGuard: the length limit');

$setPref('message_max_length', null);
pin('unset, the limit is 5000', 5000, MessageGuard::maxLength());
pin('5000 characters pass', null, MessageGuard::messageError(str_repeat('é', 5000)));
check('5001 are refused', is_string(MessageGuard::messageError(str_repeat("é", 5001))));
$setPref('message_max_length', '0');
pin('0 allows any length', null, MessageGuard::messageError(str_repeat('a', 20000)));
$setPref('message_max_length', null);

harness_section('MessageGuard: names and phone numbers');

pin('a plain name passes', null, MessageGuard::fieldError(array('María José O\'Neil'), ''));
pin('an empty phone passes', null, MessageGuard::fieldError(array('Ann'), ''));
foreach (array('+1 (555) 010-2030', '020 7946 0958', '555.010.2030 ext 12', '+44 20 7946 0958 x3') as $ok) {
    pin('phone ' . $ok . ' passes', null, MessageGuard::fieldError(array('Ann'), $ok));
}
foreach (array('http://spam.example/x', 'call 555 now', '<b>555</b>', str_repeat('1', 31)) as $bad) {
    check('phone ' . substr($bad, 0, 20) . ' is refused', is_string(MessageGuard::fieldError(array('Ann'), $bad)));
}
foreach (array('http://spam.example/promo', 'Visit buy.shop', 'Ann <b>', str_repeat('a', 101)) as $bad) {
    check('name ' . substr($bad, 0, 20) . ' is refused', is_string(MessageGuard::fieldError(array($bad))));
}
check('every name passed is checked', is_string(MessageGuard::fieldError(array('Ann', 'spam.com/x'))));
foreach (array('spam*1@evil.test', 'a|b@evil.test', '!x@evil.test') as $bad) {
    check('an address with ' . $bad[strcspn($bad, '*|!')] . ' is refused', is_string(MessageGuard::refusal($bad, 'hi')));
}

$setPref('message_max_links', '0');
pin('0 is kept, not read as unset', 0, MessageGuard::maxLinks());
check('with 0, one link is refused', is_string(MessageGuard::messageError('https://a.test')));
pin('with 0, plain text passes', null, MessageGuard::messageError('Is it still for sale?'));
$setPref('message_max_links', null);

/* ----------------------------------------------------------------------------
 * The signed report link.
 * ------------------------------------------------------------------------- */
harness_section('MessageGuard: report link');

$url = MessageGuard::reportUrl('spammer@example.test', 'seller@example.test');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
$token = (string) ($query['t'] ?? '');

pin('the link opens the report page', 'report', $query['action'] ?? null);
$read = MessageGuard::readReport($token);
pin('a real token reads back the sender', 'spammer@example.test', $read['sender'] ?? null);
pin('and the recipient', 'seller@example.test', $read['recipient'] ?? null);
parse_str((string) parse_url(MessageGuard::reportUrl('spammer@example.test', 'seller@example.test'), PHP_URL_QUERY), $query2);
$read2 = MessageGuard::readReport((string) ($query2['t'] ?? ''));
check('each link carries its own id', strlen((string) ($read['nonce'] ?? '')) === 32 && ($read['nonce'] ?? '') !== ($read2['nonce'] ?? ''));
[$payload, $sig] = explode('.', $token);
$forged = rtrim(strtr(base64_encode(json_encode(array('s' => 'someone@else.test', 'r' => 'x@y.test', 'n' => str_repeat('a', 32), 'x' => time() + 60))), '+/', '-_'), '=');
pin('a changed payload is refused', null, MessageGuard::readReport($forged . '.' . $sig));
pin('a changed signature is refused', null, MessageGuard::readReport($payload . '.' . strrev($sig)));
pin('garbage is refused', null, MessageGuard::readReport('not-a-token'));
pin(
    'an expired link is refused',
    null,
    MessageGuard::readReport(SignedPayload::pack('report-sender', array('s' => 'a@b.test', 'r' => 'c@d.test', 'n' => str_repeat('b', 32)), -1))
);
pin(
    'a token signed for another purpose is refused',
    null,
    MessageGuard::readReport(SignedPayload::pack('message-confirm', array('s' => 'a@b.test', 'r' => 'c@d.test', 'n' => str_repeat('c', 32)), 60))
);

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
pin('a ban matches the address as it is stored, spaces and symbols removed', 1, osc_is_banned(' all@example .test<>', '10.0.0.1'));
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

harness_section('MessageGuard: ban patterns match the address only');

pin('special characters are escaped', 'john|+x@gmail.com', MessageGuard::literalPattern('John+x@gmail.com'));
MessageGuard::banSender('john+x@gmail.com', 'seller@example.test');
pin('a plus address is banned', 1, osc_is_banned('john+x@gmail.com', '10.0.0.1', 'messages'));
pin('a look-alike without the plus is not', 0, osc_is_banned('johnx@gmail.com', '10.0.0.1', 'messages'));
pin('nor one with the letter repeated', 0, osc_is_banned('johnnx@gmail.com', '10.0.0.1', 'messages'));

harness_section('MessageGuard: a report link works once');

parse_str((string) parse_url(MessageGuard::reportUrl('once@example.test', 'seller@example.test'), PHP_URL_QUERY), $q);
pin('the first use files the report', 'done', MessageGuard::report((string) $q['t']));
pin('the same link again is refused', 'used', MessageGuard::report((string) $q['t']));
$admin->query("DELETE FROM $table WHERE s_email = 'once@example.test'");
pin('and cannot bring back a ban the admin lifted', 'used', MessageGuard::report((string) $q['t']));
pin('the lifted ban stays lifted', 0, osc_is_banned('once@example.test', '10.0.0.1', 'messages'));
pin('a forged link files nothing', 'invalid', MessageGuard::report('forged.token'));

harness_section('MessageGuard: a report from site contact mail bans for good, admins only');

if (!function_exists('osc_is_admin_user_logged_in')) {
    function osc_is_admin_user_logged_in()
    {
        return false;
    }
}
$setPref('message_report_link', '0');
check(
    'contact-form mail carries the link even with member reports off',
    strpos(MessageGuard::reportFooter('a@b.test', 'owner@example.test', true), 'action=report') !== false
);
$setPref('message_report_link', null);
parse_str((string) parse_url(MessageGuard::reportUrl('forever@example.test', 'owner@example.test', true), PHP_URL_QUERY), $q);
pin('the link says it is permanent', true, MessageGuard::readReport((string) $q['t'])['permanent'] ?? null);
pin('a member link does not', false, $read['permanent'] ?? null);
pin('without a signed-in admin nothing is banned', 'admin', MessageGuard::report((string) $q['t']));
pin('the address is still free', 0, osc_is_banned('forever@example.test', '10.0.0.1'));

MessageGuard::banSender('forever@example.test', 'owner@example.test', true);
$row = $admin->query("SELECT s_scope, dt_expires FROM $table WHERE s_email = 'forever@example.test'")->fetch_assoc();
pin('a permanent ban blocks everything', 'all', $row['s_scope'] ?? null);
pin('and never ends', true, is_array($row) && array_key_exists('dt_expires', $row) && $row['dt_expires'] === null);
pin('so sign-in and posting are blocked too', 1, osc_is_banned('forever@example.test', '10.0.0.1'));

harness_section('the ban list is cached, and every write clears it');
$seed('cached@example.test', 'all', null);
\mindstellar\security\BanRuleStore::forget();
pin('a new rule is read', 1, osc_is_banned('cached@example.test', '10.0.0.1'));
pin('a second check runs no query', 0, harness_query_count(static fn () => osc_is_banned('cached@example.test', '10.0.0.1')));
$cachedId = (int) $admin->query("SELECT pk_i_id FROM $table WHERE s_email = 'cached@example.test'")->fetch_row()[0];
BanRule::getInstance()->deleteByPrimaryKey($cachedId);
pin('a delete through the BanRule model clears it', 0, osc_is_banned('cached@example.test', '10.0.0.1'));
\mindstellar\security\BanRuleStore::add('t', '', 'added@example.test', 'all', null);
pin('so does an add through the store', 1, osc_is_banned('added@example.test', '10.0.0.1'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/messageguard.php */
