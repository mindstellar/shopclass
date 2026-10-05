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
 * DeferredMail: e-mails sent while a hold is open go out when it ends, and none when it
 * ends in an exception.
 *
 * DB-free.  Usage:  php tests/deferred-mail.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\utility\DeferredMail;

$sent = [];
$send = static function (array $params) use (&$sent): void {
    $sent[] = $params['subject'];
};

pin('with no hold an e-mail is not held', false, DeferredMail::hold(['subject' => 'now']));

$result = DeferredMail::during(static function () use (&$sent): string {
    DeferredMail::hold(['subject' => 'one']);
    DeferredMail::hold(['subject' => 'two']);
    $GLOBALS['sentDuring'] = $sent;

    return 'done';
}, $send);
pin('held while the work runs, sent in order once it returns', [[], ['one', 'two'], 'done'], [$GLOBALS['sentDuring'], $sent, $result]);

$sent = [];
try {
    DeferredMail::during(static function (): void {
        DeferredMail::hold(['subject' => 'rolled back']);

        throw new RuntimeException('failed');
    }, $send);
} catch (RuntimeException $e) {
}
pin('dropped when the work throws', [], $sent);
pin('and the hold is closed after', false, DeferredMail::hold(['subject' => 'later']));

$sent = [];
DeferredMail::during(static function () use ($send, &$sent): void {
    DeferredMail::during(static fn () => DeferredMail::hold(['subject' => 'inner']), $send);
    $GLOBALS['sentAfterInner'] = $sent;
}, $send);
pin('a hold inside another sends with the outer one', [[], ['inner']], [$GLOBALS['sentAfterInner'], $sent]);

$sent   = [];
$logged = ini_set('error_log', '/dev/null');
$result = DeferredMail::during(static function (): string {
    DeferredMail::hold(['subject' => 'throws']);
    DeferredMail::hold(['subject' => 'after']);

    return 'committed';
}, static function (array $params) use (&$sent): void {
    if ($params['subject'] === 'throws') {
        throw new RuntimeException('mail hook failed');
    }
    $sent[] = $params['subject'];
});
ini_set('error_log', (string) $logged);
pin('a send that throws is logged, the rest still go, and the work still succeeds', ['committed', ['after']], [$result, $sent]);

exit(harness_result());
