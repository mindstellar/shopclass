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
 * KvIdempotencyStore on t_key_value: the first claim locks, a second sees the lock, a
 * completed key returns its answer, only the lock holder completes or releases, a dead lock is
 * taken over only by the same request, an expired key by one caller, prune drops expired keys,
 * and a claim is one query.
 *
 * Usage:  php tests/models/api-idempotency.php        (standalone, own scratch database)
 *         php tests/run-models.php api-idempotency    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\api\idempotency\IdempotencyRecord;
use mindstellar\api\idempotency\KvIdempotencyStore;
use mindstellar\model\KeyValue;

$admin = scratchdb_session('osc_models_api_idempotency');
$table = DB_TABLE_PREFIX . 't_key_value';
$model = new KvIdempotencyStore();
$group = "s_group = '" . KvIdempotencyStore::GROUP . "'";
$value = static fn (string $hash): array => json_decode((string) $admin->query("SELECT s_value FROM $table WHERE $group AND s_key = '$hash'")->fetch_row()[0], true);
$now   = 1_800_000_000;
$h1    = str_repeat('a', 64);
$fp    = str_repeat('f', 64);

harness_section('claim');
pin('a new key is locked for this caller, in one query', 1, harness_query_count(static function () use ($model, $h1, $fp, $now, &$first): void {
    $first = $model->claim($h1, $fp, $now, $now + 86400, 120, 'lock-1');
}));
pin('so claim returned nothing to replay', null, $first);
$again = $model->claim($h1, $fp, $now + 1, $now + 86401, 120, 'lock-x');
pin('a second claim sees the lock and its request', [true, $fp, null], [$again->isLocked(), $again->fingerprint(), $again->response()]);

harness_section('complete');
$model->complete($h1, 'lock-x', 201, '{"status":201}');
pin('another request cannot complete a lock it does not hold', IdempotencyRecord::LOCKED, $admin->query("SELECT s_state FROM $table WHERE $group AND s_key = '$h1'")->fetch_row()[0]);
$model->release($h1, 'lock-x');
pin('nor release it', 1, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE $group AND s_key = '$h1'")->fetch_row()[0]);
$model->complete($h1, 'lock-1', 201, '{"status":201}');
$done = $model->claim($h1, $fp, $now + 2, $now + 86402, 120, 'lock-y');
pin('a completed key returns its answer', [false, '{"status":201}'], [$done->isLocked(), $done->response()]);
pin('the status is stored, and the lock token dropped', [201, null], [$value($h1)['status'], $value($h1)['lock']]);
$model->release($h1, 'lock-1');
pin('release leaves a completed key alone', 1, (int) $admin->query("SELECT COUNT(*) FROM $table")->fetch_row()[0]);
$model->complete($h1, 'lock-1', 500, '{"status":500}');
pin('a key no longer locked is not completed again', [201, '{"status":201}'], [$value($h1)['status'], $value($h1)['response']]);

harness_section('takeover');
$h2 = str_repeat('b', 64);
$model->claim($h2, $fp, $now, $now + 86400, 120, 'lock-a');
pin('a live lock is not taken over', true, $model->claim($h2, $fp, $now + 60, $now + 86460, 120, 'lock-b')?->isLocked());
pin('a dead lock is not taken over by another request', [true, $fp], (static fn ($r): array => [$r?->isLocked(), $r?->fingerprint()])($model->claim($h2, str_repeat('e', 64), $now + 121, $now + 86521, 120, 'lock-e')));
pin('but is by the same request sent again', null, $model->claim($h2, $fp, $now + 121, $now + 86521, 120, 'lock-b'));
pin('by one caller only', true, $model->claim($h2, $fp, $now + 121, $now + 86521, 120, 'lock-c')?->isLocked());
pin('the new holder\'s lock is stored', 'lock-b', $value($h2)['lock']);
$model->complete($h2, 'lock-a', 201, '{"status":201}');
pin('the first request, finishing late, does not overwrite the new holder', [IdempotencyRecord::LOCKED, null], [$admin->query("SELECT s_state FROM $table WHERE $group AND s_key = '$h2'")->fetch_row()[0], $value($h2)['response']]);
$model->release($h2, 'lock-a');
pin('nor drop its lock', 'lock-b', $value($h2)['lock']);
pin('an expired key is taken over', null, $model->claim($h1, $fp, $now + 86403, $now + 2 * 86400, 120, 'lock-2'));
pin('and starts locked again', IdempotencyRecord::LOCKED, $admin->query("SELECT s_state FROM $table WHERE $group AND s_key = '$h1'")->fetch_row()[0]);

harness_section('release and prune');
$model->release($h2, 'lock-b');
pin('release drops the holder\'s lock', 0, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE $group AND s_key = '$h2'")->fetch_row()[0]);
$model->claim(str_repeat('c', 64), $fp, $now, $now + 10, 120, 'lock-3');
pin('prune drops keys past their expiry, and only those', 1, (new KeyValue())->prune($now + 11));
pin('the rest stay', 1, (int) $admin->query("SELECT COUNT(*) FROM $table")->fetch_row()[0]);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
