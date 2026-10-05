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
 * KeyValue on t_key_value and the osc_kv_* helpers: values round-trip as JSON, keys are exact,
 * expired keys read as absent, a claim goes to one caller and is taken over once expired
 * or stale, prune works in batches, and malformed groups, keys, states and values are refused.
 *
 * Usage:  php tests/models/kv.php        (standalone, own scratch database)
 *         php tests/run-models.php kv    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\model\KeyValue;

$admin = scratchdb_session('osc_models_kv');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hKv.php';
$table = DB_TABLE_PREFIX . 't_key_value';
$kv    = new KeyValue();
$now   = 1_800_000_000;
$rows  = static fn (string $where = '1'): int => (int) $admin->query("SELECT COUNT(*) FROM $table WHERE $where")->fetch_row()[0];
$refused = static function (callable $fn): bool {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }

    return false;
};

harness_section('helpers: JSON round trip');
$value = ['name' => 'Zoë', 'n' => 3, 'f' => 1.5, 'ok' => true, 'list' => [1, 'two'], 'url' => 'https://a/b'];
osc_kv_set('test', 'profile', $value);
pin('an array comes back as stored', $value, osc_kv_get('test', 'profile'));
foreach (['int' => 42, 'string' => 'hello', 'false' => false, 'empty' => []] as $k => $v) {
    osc_kv_set('test', $k, $v);
}
pin('scalars and an empty array round-trip', [42, 'hello', false, []], [osc_kv_get('test', 'int'), osc_kv_get('test', 'string'), osc_kv_get('test', 'false', 'x'), osc_kv_get('test', 'empty')]);
osc_kv_set('test', 'nothing', null);
pin('a stored null is null, not the default', null, osc_kv_get('test', 'nothing', 'default'));
pin('an absent key gives the default', 'default', osc_kv_get('test', 'absent', 'default'));
pin('stored as JSON', '{"name":"Zoë","n":3,"f":1.5,"ok":true,"list":[1,"two"],"url":"https://a/b"}', $admin->query("SELECT s_value FROM $table WHERE s_key = 'profile'")->fetch_row()[0]);
osc_kv_set('test', 'int', 43);
pin('set replaces a value', 43, osc_kv_get('test', 'int'));
pin('delete removes a key', [true, 'gone'], [osc_kv_delete('test', 'int'), osc_kv_get('test', 'int', 'gone')]);
pin('deleting nothing says so', false, osc_kv_delete('test', 'int'));
check('an unencodable value is refused', $refused(static fn () => osc_kv_set('test', 'bad', "\xB1\x31")));
check('a ttl below one second is refused', $refused(static fn () => osc_kv_set('test', 'x', 1, 0)));

harness_section('keys');
osc_kv_set('test', 'Case', 'upper');
osc_kv_set('test', 'case', 'lower');
pin('keys are case-sensitive', ['upper', 'lower'], [osc_kv_get('test', 'Case'), osc_kv_get('test', 'case')]);
osc_kv_set('other', 'case', 'other group');
pin('groups keep their own keys', ['lower', 'other group'], [osc_kv_get('test', 'case'), osc_kv_get('other', 'case')]);
$long = str_repeat('ü', 191);
osc_kv_set('test', $long, 'long');
pin('a 191-character UTF-8 key fits', 'long', osc_kv_get('test', $long));
pin('a readable key with punctuation works', 'ok', (static function () {
    osc_kv_set('test', 'user:42/prefs#a b', 'ok');

    return osc_kv_get('test', 'user:42/prefs#a b');
})());

harness_section('validation');
foreach (['' => 'an empty group', 'Upper' => 'an upper-case group', '_lead' => 'a group starting with _', 'a b' => 'a group with a space', str_repeat('g', 65) => 'a 65-character group'] as $group => $label) {
    check($label . ' is refused', $refused(static fn () => $kv->get((string) $group, 'k')));
}
foreach (['' => 'an empty key', str_repeat('k', 192) => 'a 192-character key', "a\nb" => 'a key with a newline', ' a' => 'a key with a leading space', 'a ' => 'a key with a trailing space', "\xC3" => 'a key that is not UTF-8'] as $key => $label) {
    check($label . ' is refused', $refused(static fn () => $kv->get('test', (string) $key)));
}
check('a malformed state is refused', $refused(static fn () => $kv->set('test', 'k', null, null, 'Locked')));
check('a 17-character state is refused', $refused(static fn () => $kv->set('test', 'k', null, null, str_repeat('s', 17))));
check('a value that is not UTF-8 is refused', $refused(static fn () => $kv->set('test', 'k', "\xB1")));
check('a claim that has already expired is refused', $refused(static fn () => $kv->claim('test', 'k', $now, 'locked', null, $now)));
check('osc_kv_claim refuses a ttl below one second', $refused(static fn () => osc_kv_claim('test', 'k', 0)));
pin('nothing refused was written', 0, $rows("s_key = 'k'"));

harness_section('expiry');
$kv->set('test', 'short', 'v', $now + 10, null, $now);
pin('a key is live before its expiry', 'v', $kv->get('test', 'short', $now + 9)['value'] ?? null);
pin('and absent from it on', null, $kv->get('test', 'short', $now + 10));
$kv->set('test', 'past', '"old"', time() - 1);
pin('the helper treats an expired key as absent', 'default', osc_kv_get('test', 'past', 'default'));
osc_kv_set('test', 'ttl', 'v', 60);
$expires = $kv->get('test', 'ttl')['expires'] ?? 0;
check('a helper ttl sets the expiry', $expires >= time() + 59 && $expires <= time() + 60);
pin('touch moves a live key\'s expiry', [1, 'v'], [$kv->touch('test', 'short', $now + 100, $now + 5), $kv->get('test', 'short', $now + 50)['value'] ?? null]);
pin('touch leaves an expired key alone', 0, $kv->touch('test', 'short', $now + 500, $now + 100));

harness_section('claim');
pin('a new key costs one query', 1, harness_query_count(static function () use ($kv, $now, &$first): void {
    $first = $kv->claim('test', 'job', $now + 60, 'locked', 'one', $now);
}));
pin('the first caller gets it', true, $first);
pin('the second does not, in two queries', 2, harness_query_count(static function () use ($kv, $now, &$second): void {
    $second = $kv->claim('test', 'job', $now + 61, 'locked', 'two', $now + 1);
}));
pin('so it is refused', false, $second);
pin('and the first value stays', ['one', 'locked'], [$kv->get('test', 'job', $now + 1)['value'], $kv->get('test', 'job', $now + 1)['state']]);
pin('once expired, one caller takes it over', [true, false], [
    $kv->claim('test', 'job', $now + 120, 'locked', 'three', $now + 60),
    $kv->claim('test', 'job', $now + 120, 'locked', 'four', $now + 60),
]);
pin('with its own value', 'three', $kv->get('test', 'job', $now + 61)['value']);
pin('a stale lock is taken over once', [false, true, false], [
    $kv->claim('test', 'job', $now + 500, 'locked', 'five', $now + 70, $now + 59),
    $kv->claim('test', 'job', $now + 500, 'locked', 'six', $now + 70, $now + 61),
    $kv->claim('test', 'job', $now + 500, 'locked', 'seven', $now + 70, $now + 61),
]);
$kv->update('test', 'job', 'result', 'done', 'locked', $now + 71);
pin('a finished row is not stale', false, $kv->claim('test', 'job', $now + 500, 'locked', 'eight', $now + 400, $now + 399));
pin('update with the wrong state changes nothing', 0, $kv->update('test', 'job', 'x', 'done', 'locked', $now + 72));
osc_kv_set('test', 'plain', 'v');
pin('a live plain value cannot be claimed', false, osc_kv_claim('test', 'plain', 60));
pin('osc_kv_claim takes an absent key once', [true, false], [osc_kv_claim('test', 'lock', 60), osc_kv_claim('test', 'lock', 60)]);
pin('delete with a state removes only that state', [0, 1], [$kv->delete('test', 'job', 'locked'), $kv->delete('test', 'job', 'done')]);

harness_section('prune');
$admin->query("DELETE FROM $table");
for ($i = 0; $i < 25; $i++) {
    $kv->set('prune', 'old' . $i, null, $now - 1, null, $now - 100);
}
$kv->set('prune', 'live', null, $now + 100, null, $now);
$kv->set('prune', 'forever', null, null, null, $now);
$kv->set('other', 'old', null, $now - 1, null, $now - 100);
pin('maxRounds caps one run', 20, $kv->prune($now, 10, 2));
pin('the next run finishes, in batches of ten', [6, 1], [$kv->prune($now, 10), harness_query_count(static fn () => $kv->prune($now, 10))]);
pin('live and never-expiring keys stay', 2, $rows());

harness_section('delete a group');
osc_kv_set('acme', 'one', 1);
osc_kv_set('acme', 'two', 2, 60);
osc_kv_set('acme-other', 'one', 1);
pin('osc_kv_delete_group removes every key of the group and only those', [2, 0, 1], [osc_kv_delete_group('acme'), $rows("s_group = 'acme'"), $rows("s_group = 'acme-other'")]);
pin('an empty group removes nothing', 0, osc_kv_delete_group('acme'));
check('a malformed group is refused', $refused(static fn () => osc_kv_delete_group('Bad Group')));

harness_section('times are UTC, whatever the time zone');
$zone = date_default_timezone_get();
date_default_timezone_set('America/New_York');
$kv->set('test', 'utc', 'x', 1_800_003_600, null, 1_800_000_000);
pin('written as UTC', ['2027-01-15 08:00:00', '2027-01-15 09:00:00'], $admin->query("SELECT dt_created, dt_expires FROM $table WHERE s_key = 'utc'")->fetch_row());
date_default_timezone_set('Asia/Kolkata');
pin('read back the same in another zone', [1_800_000_000, 1_800_003_600], [$kv->get('test', 'utc', 1_800_000_000)['created'] ?? null, $kv->get('test', 'utc', 1_800_000_000)['expires'] ?? null]);
pin('so it expires when it should', null, $kv->get('test', 'utc', 1_800_003_600));
date_default_timezone_set('America/New_York');
// 2026-11-01 01:45 EDT; half an hour later the clocks have fallen back to 01:15 EST.
$fallBack = 1_793_511_900;
$kv->set('test', 'dst', 'x', $fallBack + 1800, null, $fallBack);
pin('across a DST fall-back an expiry stays after now', 'x', $kv->get('test', 'dst', $fallBack)['value'] ?? null);
pin('and a lock taken then is still held a minute later', [true, false], [$kv->claim('test', 'dst-lock', $fallBack + 1800, 'locked', null, $fallBack), $kv->claim('test', 'dst-lock', $fallBack + 1800, 'locked', null, $fallBack + 60)]);
date_default_timezone_set($zone);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
