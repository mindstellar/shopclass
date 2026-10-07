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
 * DeferredMail inside a database transaction it did not open: held e-mails go out only once
 * the outermost transaction commits, so a later failure in the same write sends none.
 *
 * Usage:  php tests/models/deferred-mail-commit.php        (standalone, own scratch database)
 *         php tests/run-models.php deferred-mail-commit    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Db;
use mindstellar\utility\DeferredMail;

$admin = scratchdb_session('osc_models_deferred_mail_commit');
$sent  = [];
$send  = static function (array $params) use (&$sent): void {
    $sent[] = $params['subject'];
};

harness_section('a hold inside an outer transaction');
try {
    Db::transaction(static function () use ($send, &$sent): void {
        DeferredMail::during(static fn () => DeferredMail::hold(['subject' => 'approved']), $send);
        $GLOBALS['sentBeforeFailure'] = $sent;

        throw new RuntimeException('a later step failed');
    });
} catch (RuntimeException $e) {
}
pin('nothing is sent while the outer transaction is open, and nothing after it rolls back', [[], []], [$GLOBALS['sentBeforeFailure'], $sent]);

Db::transaction(static function () use ($send, &$sent): void {
    DeferredMail::during(static fn () => DeferredMail::hold(['subject' => 'approved']), $send);
    $GLOBALS['sentBeforeCommit'] = $sent;
});
pin('sent once the outer transaction commits', [[], ['approved']], [$GLOBALS['sentBeforeCommit'], $sent]);

harness_section('an e-mail with no hold open');
pin('outside a transaction it is sent at once', false, DeferredMail::hold(['subject' => 'now']));
$held = null;
try {
    Db::transaction(static function () use (&$held): void {
        $held = DeferredMail::hold(['subject' => 'rolled back']);

        throw new RuntimeException('failed');
    });
} catch (RuntimeException $e) {
}
pin('inside a transaction it waits for the commit', true, $held);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
