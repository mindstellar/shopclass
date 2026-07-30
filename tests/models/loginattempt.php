<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Characterization pins for the LoginAttempt model.
 *
 * LoginAttempt is the ledger \mindstellar\security\LoginThrottle counts, so the
 * behaviour that matters is arithmetic rather than round-tripping: a count that
 * reads one row too few lets an extra guess through, and one that reads a row
 * too many locks somebody out early.
 *
 * The window boundary is pinned deliberately. Both count methods compare with
 * dt_date > ?, so an attempt landing exactly on the cutoff is outside the
 * window. That is the safer direction to be wrong in -- it expires an attempt
 * early rather than holding it against someone forever -- but it is a choice,
 * and the throttle's arithmetic is built on it.
 *
 * Every fixture is written with raw mysqli on the admin connection, never
 * through the code under test, so a bug in record() cannot hide itself by
 * corrupting the rows the counts are then checked against.
 *
 * Usage:  php tests/models/loginattempt.php     (standalone, own scratch database)
 *         php tests/run-models.php loginattempt (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_loginattempt');
$table = DB_TABLE_PREFIX . 't_login_attempt';

$model = LoginAttempt::newInstance();

$truncate = static function () use ($admin, $table): void {
    $admin->query("TRUNCATE TABLE $table");
};

/**
 * Seed one row with raw mysqli.
 */
$seed = static function ($context, $account, $ip, $date) use ($admin, $table): void {
    $stmt = $admin->prepare(
        "INSERT INTO $table (s_context, s_account, s_ip, dt_date) VALUES (?, ?, ?, ?)"
    );
    $stmt->bind_param('ssss', $context, $account, $ip, $date);
    $stmt->execute();
    $stmt->close();
};

/**
 * @return array
 */
$rows = static function () use ($admin, $table): array {
    $res = $admin->query("SELECT * FROM $table ORDER BY pk_i_id");
    $out = array();
    while ($row = $res->fetch_assoc()) {
        $out[] = $row;
    }
    $res->free();

    return $out;
};

$at = static function ($secondsAgo): string {
    return date('Y-m-d H:i:s', time() - $secondsAgo);
};

/* ----------------------------------------------------------------------------
 * Surface
 * ------------------------------------------------------------------------- */
harness_section('LoginAttempt: public surface');

check('LoginAttempt extends DAO', is_subclass_of('LoginAttempt', 'DAO'));
pin('table name', DB_TABLE_PREFIX . 't_login_attempt', $model->getTableName());
pin('primary key', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist',
    array('pk_i_id', 's_context', 's_account', 's_ip', 'dt_date'),
    $model->getFields()
);
pin('newInstance signature', 'public static newInstance()', harness_method_signature('LoginAttempt', 'newInstance'));
check('newInstance is a singleton', LoginAttempt::newInstance() === $model);

/* ----------------------------------------------------------------------------
 * record()
 * ------------------------------------------------------------------------- */
harness_section('LoginAttempt::record');

$truncate();
$ret = $model->record('web', 'alice', '203.0.113.9', '2026-07-29 10:00:00');
pin('a write reports one row affected', 1, $ret);

$written = $rows();
check('exactly one row was written', count($written) === 1, (string)count($written));
$row = $written[0] ?? array();
pin('s_context round-trips', 'web', $row['s_context'] ?? null);
pin('s_account round-trips', 'alice', $row['s_account'] ?? null);
pin('s_ip round-trips', '203.0.113.9', $row['s_ip'] ?? null);
pin('dt_date round-trips', '2026-07-29 10:00:00', $row['dt_date'] ?? null);

$truncate();
$model->record('web', str_repeat('x', 250), '203.0.113.9', '2026-07-29 10:00:00');
$row = $rows()[0] ?? array();
check(
    'an over-length account is cut to the column width before the insert',
    isset($row['s_account']) && strlen($row['s_account']) === 191,
    isset($row['s_account']) ? (string)strlen($row['s_account']) : 'missing'
);

/* ----------------------------------------------------------------------------
 * countByIp / countByAccount — the arithmetic the throttle rests on
 * ------------------------------------------------------------------------- */
harness_section('LoginAttempt: counting inside the window');

$truncate();
$seed('web', 'alice', '198.51.100.1', $at(60));
$seed('web', 'bob', '198.51.100.1', $at(120));
$seed('web', 'alice', '198.51.100.2', $at(180));
$seed('admin', 'alice', '198.51.100.1', $at(60));

pin('countByIp counts every account that address tried', 3, $model->countByIp('198.51.100.1', $at(900)));
pin('countByIp ignores other addresses', 1, $model->countByIp('198.51.100.2', $at(900)));
pin('countByIp on an unseen address is zero', 0, $model->countByIp('203.0.113.77', $at(900)));

pin('countByAccount counts one account across addresses', 2, $model->countByAccount('web', 'alice', $at(900)));
pin('countByAccount is scoped to its context', 1, $model->countByAccount('admin', 'alice', $at(900)));
pin('countByAccount on an unseen account is zero', 0, $model->countByAccount('web', 'nobody', $at(900)));

harness_section('LoginAttempt: the window boundary');

$truncate();
$cutoff = $at(300);
$seed('web', 'alice', '198.51.100.1', $cutoff);
pin('an attempt exactly on the cutoff is outside the window', 0, $model->countByAccount('web', 'alice', $cutoff));
pin('and outside it by address too', 0, $model->countByIp('198.51.100.1', $cutoff));

$truncate();
$seed('web', 'alice', '198.51.100.1', $at(299));
pin('an attempt one second inside the window counts', 1, $model->countByAccount('web', 'alice', $at(300)));

$truncate();
$seed('web', 'alice', '198.51.100.1', $at(3600));
pin('an attempt older than the window does not count', 0, $model->countByAccount('web', 'alice', $at(900)));
pin('and is invisible to the address count', 0, $model->countByIp('198.51.100.1', $at(900)));

harness_section('LoginAttempt: counting an over-length account');

$truncate();
$long = str_repeat('y', 250);
$model->record('web', $long, '198.51.100.1', $at(60));
pin(
    'a count for an over-length account matches the row record() wrote',
    1,
    $model->countByAccount('web', $long, $at(900))
);

/* ----------------------------------------------------------------------------
 * oldestByIp / oldestByAccount — what the retry countdown is built from
 * ------------------------------------------------------------------------- */
harness_section('LoginAttempt: oldest counted attempt');

$truncate();
$seed('web', 'alice', '198.51.100.1', '2026-07-29 10:00:00');
$seed('web', 'alice', '198.51.100.1', '2026-07-29 10:05:00');
$seed('web', 'alice', '198.51.100.1', '2026-07-29 10:10:00');

pin('oldestByIp returns the earliest in the window', '2026-07-29 10:00:00', $model->oldestByIp('198.51.100.1', '2026-07-29 09:00:00'));
pin('oldestByAccount returns the earliest in the window', '2026-07-29 10:00:00', $model->oldestByAccount('web', 'alice', '2026-07-29 09:00:00'));
pin(
    'oldestByIp respects the window and skips what has aged out',
    '2026-07-29 10:10:00',
    $model->oldestByIp('198.51.100.1', '2026-07-29 10:06:00')
);
check('oldestByIp is null when nothing is in the window', $model->oldestByIp('198.51.100.1', '2026-07-29 11:00:00') === null);
check('oldestByAccount is null when nothing is in the window', $model->oldestByAccount('web', 'alice', '2026-07-29 11:00:00') === null);

/* ----------------------------------------------------------------------------
 * clearAccount / clearIp / pruneBefore
 * ------------------------------------------------------------------------- */
harness_section('LoginAttempt: clearing');

$truncate();
$seed('web', 'alice', '198.51.100.1', $at(60));
$seed('web', 'alice', '198.51.100.2', $at(60));
$seed('web', 'bob', '198.51.100.1', $at(60));
$seed('admin', 'alice', '198.51.100.1', $at(60));

pin('clearAccount removes that account in that context only', 2, $model->clearAccount('web', 'alice'));
pin('the other context survives', 1, $model->countByAccount('admin', 'alice', $at(900)));
pin('the other account survives', 1, $model->countByAccount('web', 'bob', $at(900)));

$truncate();
$seed('web', 'alice', '198.51.100.1', $at(60));
$seed('admin', 'bob', '198.51.100.1', $at(60));
$seed('web', 'carol', '198.51.100.2', $at(60));
pin('clearIp removes every context for that address', 2, $model->clearIp('198.51.100.1'));
pin('another address is untouched', 1, $model->countByIp('198.51.100.2', $at(900)));

harness_section('LoginAttempt: pruning');

$truncate();
$seed('web', 'alice', '198.51.100.1', '2026-07-20 10:00:00');
$seed('web', 'bob', '198.51.100.1', '2026-07-25 10:00:00');
$seed('web', 'carol', '198.51.100.1', '2026-07-29 10:00:00');

pin('pruneBefore removes everything at or before the cutoff', 2, $model->pruneBefore('2026-07-25 10:00:00'));
check('the newer row survives', count($rows()) === 1, (string)count($rows()));
pin('pruning again removes nothing', 0, $model->pruneBefore('2026-07-25 10:00:00'));

/* ----------------------------------------------------------------------------
 * Query cost
 * ------------------------------------------------------------------------- */
harness_section('LoginAttempt: query cost');

$truncate();
pin('one record() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->record('web', 'alice', '198.51.100.1', date('Y-m-d H:i:s'));
}));
pin('one countByIp() call costs one query', 1, harness_query_count(static function () use ($model, $at) {
    $model->countByIp('198.51.100.1', $at(900));
}));

/* ----------------------------------------------------------------------------
 * The per-account limit is excused by a solved captcha, and by nothing else.
 *
 * evaluate() used to infer this from osc_captcha_enabled() -- a global setting
 * standing in for a per-request fact. The recovery form rendered no captcha on a
 * fresh session, so with a provider merely configured it lost the per-account
 * limit for a captcha nobody solved. The caller states it now, and the default is
 * false so a call site that says nothing keeps the limit. These pin all three.
 *
 * No REMOTE_ADDR under CLI, so ip() is '' and the address counter is skipped --
 * what these exercise is the account counter on its own.
 * ------------------------------------------------------------------------- */
harness_section('LoginThrottle: the captcha exemption');

$truncate();
for ($i = 0; $i < 10; $i++) {           // osc_login_throttle_max_account() default
    $seed('web-recover', 'victim@example.com', '203.0.113.50', $at(60));
}

pin(
    'over the account limit, an unsolved captcha blocks',
    'blocked',
    \mindstellar\security\LoginThrottle::evaluate('web-recover', 'victim@example.com', false)['status']
);
pin(
    'over the account limit, a solved captcha is let through',
    'ok',
    \mindstellar\security\LoginThrottle::evaluate('web-recover', 'victim@example.com', true)['status']
);
pin(
    'omitting the argument keeps the limit rather than excusing it',
    'blocked',
    \mindstellar\security\LoginThrottle::evaluate('web-recover', 'victim@example.com')['status']
);

$truncate();
pin(
    'under the limit, a solved captcha is irrelevant',
    'ok',
    \mindstellar\security\LoginThrottle::evaluate('web-recover', 'victim@example.com', false)['status']
);

/* ----------------------------------------------------------------------------
 * LoginThrottle fails open when the ledger is missing.
 *
 * Not a model pin, but it belongs with this table: t_login_attempt arrives with
 * an upgrade, and the files are in place before the upgrade runs. If the
 * throttle threw during that gap it would refuse every sign-in including the
 * administrator's -- and the admin sign-in is where the upgrade is started
 * from, so the site would have locked out the only person able to fix it. This
 * is the pin that keeps that from regressing.
 * ------------------------------------------------------------------------- */
harness_section('LoginThrottle: ledger unavailable');

$admin->query("DROP TABLE IF EXISTS $table");

$verdict = \mindstellar\security\LoginThrottle::evaluate('web', 'alice');
pin('evaluate() lets the attempt through when the table is gone', 'ok', $verdict['status'] ?? null);
check(
    'recordFailure() swallows the failure instead of throwing',
    (static function () {
        try {
            \mindstellar\security\LoginThrottle::recordFailure('web', 'alice');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    })()
);
check(
    'clear() swallows the failure instead of throwing',
    (static function () {
        try {
            \mindstellar\security\LoginThrottle::clear('web', 'alice');

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    })()
);

// Put the table back so the runner's inter-file truncate still finds it.
$admin->query(
    "CREATE TABLE $table ("
    . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
    . " s_context VARCHAR(20) NOT NULL DEFAULT '',"
    . " s_account VARCHAR(191) NOT NULL DEFAULT '',"
    . " s_ip VARCHAR(45) NOT NULL DEFAULT '',"
    . ' dt_date DATETIME NOT NULL,'
    . ' PRIMARY KEY (pk_i_id),'
    . ' INDEX idx_ip (s_ip, dt_date),'
    . ' INDEX idx_account (s_context, s_account(64), dt_date),'
    . ' INDEX idx_date (dt_date)'
    . ") ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
);

$truncate();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/loginattempt.php */
