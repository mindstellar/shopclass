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
 * ApiCredential on t_api_credential: typed StoredKey values with the owner joined in one
 * query, a case-sensitive token id, one guarded revoke, family revoke, owners that must still
 * exist and be enabled, rows removed with their user or admin, and ApiKeys end to end over
 * the table in at most three queries per keyed request.
 *
 * Usage:  php tests/models/api-credential.php        (standalone, own scratch database)
 *         php tests/run-models.php api-credential    (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\auth\FailureCounter;
use mindstellar\api\auth\KeyOwner;
use mindstellar\api\auth\RefreshTokens;
use mindstellar\api\auth\Scopes;
use mindstellar\api\auth\StoredKey;
use mindstellar\api\auth\UserRows;
use mindstellar\api\ProblemException;
use mindstellar\api\ratelimit\RateBucket;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\Request;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$admin = scratchdb_session('osc_models_api_credential');
$table = DB_TABLE_PREFIX . 't_api_credential';
$model = new ApiCredential();

$adminId = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('A', 'a', ?, 'a@x.test', 0)", 's', [str_repeat('x', 60)]);
$modId   = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('M', 'm', ?, 'm@x.test', 1)", 's', [str_repeat('x', 60)]);
$userId  = seed_user($admin, 'apiuser', 'apiuser@example.test');
$blocked = seed_user($admin, 'blocked', 'blocked@example.test', 1, 0);

$newKey = static fn (string $tokenId, ?KeyOwner $owner, string $kind = CredentialKind::KEY, array $scopes = ['admin:listings'], ?string $family = null): StoredKey
    => new StoredKey(0, $kind, $tokenId, str_repeat('a', 64), 'CI', $scopes, $owner, null, true, null, null, null, $family);

harness_section('rows');
$id  = $model->insert($newKey('AAAAAAAAAAAAAAA1', KeyOwner::admin($adminId)));
$key = $model->find($id);
check('insert returns the new id', $id > 0);
pin('find returns a typed key with its owner joined in', [$id, CredentialKind::KEY, 'AAAAAAAAAAAAAAA1', ['admin:listings'], $adminId, false, true, null, null], [
    $key->id(), $key->kind(), $key->tokenId(), $key->scopes(), $key->owner()->adminId(), $key->owner()->isModerator(), $key->enabled(), $key->revokedAt(), $key->lastUsedAt(),
]);
check('the created time is set', $key->createdAt() !== null);
pin('found by token id', $id, $model->findByTokenId('AAAAAAAAAAAAAAA1')->id());
pin('the token id is case-sensitive', null, $model->findByTokenId('aaaaaaaaaaaaaaa1'));
pin('an unknown token id is null', null, $model->findByTokenId('ZZZZZZZZZZZZZZZZ'));
pin('findByTokenId is one query', 1, harness_query_count(static fn () => $model->findByTokenId('AAAAAAAAAAAAAAA1')));

$threw = static function (callable $fn): bool {
    try {
        $fn();
    } catch (\Throwable $e) {
        return true;
    }

    return false;
};
check('an unknown kind is refused', $threw(static fn () => $model->insert($newKey('B000000000000000', KeyOwner::admin($adminId), 'session'))));
check('a key with no owner is refused', $threw(static fn () => $model->insert($newKey('B000000000000001', null))));
check('a token id is unique', $threw(static fn () => $model->insert($newKey('AAAAAAAAAAAAAAA1', KeyOwner::admin($adminId)))));
$lower = $model->insert($newKey('aaaaaaaaaaaaaaa1', KeyOwner::admin($adminId)));
check('but only byte for byte: another case is another key', $lower > 0 && $model->findByTokenId('aaaaaaaaaaaaaaa1')->id() === $lower);

$model->touch($id, '192.0.2.9', 1_800_000_000);
pin('touch records when and where', ['192.0.2.9', 1_800_000_000], [
    $admin->query("SELECT s_last_ip FROM $table WHERE pk_i_id = $id")->fetch_row()[0], $model->find($id)->lastUsedAt(),
]);

harness_section('revoke');
pin('revoke disables and stamps', true, $model->revoke($id));
pin('the row stays', [false, true], [$model->find($id)->enabled(), $model->find($id)->revokedAt() !== null]);
pin('a second revoke changes nothing', false, $model->revoke($id));
pin('revoking a missing row changes nothing', false, $model->revoke(99999));

foreach (['R1', 'R2', 'R3'] as $i => $t) {
    $model->insert($newKey(str_pad($t, 16, '0'), KeyOwner::user($userId), CredentialKind::REFRESH, ['account:read'], $i < 2 ? 'FAMILY0000000001' : 'FAMILY0000000002'));
}
pin('a family revoke takes every token of that family', 2, $model->revokeFamily('FAMILY0000000001'));
pin('and only that family', 1, count(array_filter($model->listBy(CredentialKind::REFRESH, $userId), static fn (StoredKey $k): bool => $k->revokedAt() === null)));
pin('listBy filters by kind and owner', [3, 2, 0], [count($model->listBy(CredentialKind::REFRESH)), count($model->listBy(CredentialKind::KEY, null, $adminId)), count($model->listBy(CredentialKind::KEY, $userId))]);
$all = $model->listBy();
check('newest first', $all[0]->id() > $all[count($all) - 1]->id());

harness_section('owners');
$mk = $model->find($model->insert($newKey('MOD0000000000001', KeyOwner::admin($modId))));
pin('a moderator owner', [true, true], [$mk->owner()->isAdmin(), $mk->owner()->isModerator()]);
$uk = $model->find($model->insert($newKey('USR0000000000001', KeyOwner::user($userId))));
pin('an enabled user owner', [false, $userId], [$uk->owner()->isAdmin(), $uk->owner()->userId()]);
pin('a disabled user owns nothing', null, $model->find($model->insert($newKey('USR0000000000002', KeyOwner::user($blocked))))->owner());

harness_section('ApiKeys over the table');
$keys = new ApiKeys($model, new Scopes(), new SystemClock());
$made = $keys->create(CredentialKind::KEY, 'Deploy', ['admin:listings', 'admin:users'], KeyOwner::admin($adminId));
$cred = $keys->verify($made->token(), '192.0.2.1');
pin('a key made in the table verifies', [CredentialKind::KEY, $adminId, ['admin:listings', 'admin:users']], [$cred->kind(), $cred->adminId(), $cred->scopes()]);
pin('its use is stored', '192.0.2.1', $admin->query('SELECT s_last_ip FROM ' . $table . ' WHERE pk_i_id = ' . $made->id())->fetch_row()[0]);
$fresh = $keys->create(CredentialKind::KEY, 'Q', ['admin:listings'], KeyOwner::admin($adminId));
pin('a first use is two queries: the key with its owner, and the touch', 2, harness_query_count(static fn () => $keys->verify($fresh->token(), '192.0.2.1')));
pin('a use within five minutes is one', 1, harness_query_count(static fn () => $keys->verify($fresh->token(), '192.0.2.1')));
$modKey = $keys->create(CredentialKind::KEY, 'Mod', ['admin:listings', 'admin:users'], KeyOwner::admin($modId, true));
pin('a moderator key keeps the moderator set', ['admin:listings'], $keys->verify($modKey->token())->scopes());
$userKey = $keys->create(CredentialKind::KEY, 'Phone', ['listings:write'], KeyOwner::user($userId));
pin('a user key acts for its user', $userId, $keys->verify($userKey->token())->userId());

harness_section('queries per keyed request');
$auth    = api_test_authenticator($keys, new FailureCounter());
$limiter = RateLimiter::fromSite(new SystemClock());
$request = new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $made->token()], '192.0.2.50');
$queries = harness_query_count(static function () use ($auth, $limiter, $request): void {
    $credential = $auth->authenticate($request);
    $limiter->hit(new RateBucket('api_key', (string) $credential->id(), 120));
});
pin('failure check + key with owner + rate count: 3 queries', 3, $queries);

$keys->revoke($made->id());
pin('a revoked key fails', null, $keys->verify($made->token()));

harness_section('refresh families');
$refreshRow = static fn (string $tokenId, string $family) => new StoredKey(0, CredentialKind::REFRESH, $tokenId, str_repeat('b', 64), 'Phone', ['account:read'], KeyOwner::user($userId), null, true, time() + 3600, null, null, $family);
$f1 = $model->insert($refreshRow('F1T0000000000001', 'FAMA000000000001'));
$model->insert($refreshRow('F2T0000000000001', 'FAMB000000000001'));
$model->touch($f1, '198.51.100.7', time());
pin('the family and last address come back', ['FAMA000000000001', '198.51.100.7'], [$model->find($f1)->family(), $model->find($f1)->lastIp()]);
pin('revoking all but one family', 2, $model->revokeRefreshFor($userId, 'FAMA000000000001'));
pin('the kept one is live', null, $model->find($f1)->revokedAt());
pin('listBy can skip revoked rows', ['FAMA000000000001'], array_map(static fn (StoredKey $k): ?string => $k->family(), $model->listBy(CredentialKind::REFRESH, $userId, null, true)));
$admin->query("UPDATE $table SET dt_revoked = '2020-01-01 00:00:00' WHERE s_token_id = 'F2T0000000000001'");
$keptKeys = (int) $admin->query("SELECT COUNT(*) FROM $table WHERE e_kind <> 'refresh'")->fetch_row()[0];
pin('pruning drops refresh rows revoked before the cutoff', 1, $model->pruneRefresh(strtotime('2021-01-01')));
pin('and leaves keys alone', $keptKeys, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE e_kind <> 'refresh'")->fetch_row()[0]);

harness_section('transactions');
$fa = $model->insert($refreshRow('TX00000000000001', 'FAMT000000000001'));
try {
    $model->atomically(static function () use ($model, $fa): void {
        $model->lockFamily('FAMT000000000001');
        $model->revoke($fa);
        throw new \RuntimeException('stop');
    });
} catch (\RuntimeException $e) {
}
pin('atomically rolls back when its work throws', null, $model->find($fa)->revokedAt());
pin('and returns what its work returns', 7, $model->atomically(static fn () => 7));
$auto = $model->insert(new StoredKey(0, CredentialKind::KEY, 'AUTOKEY000000001', str_repeat('c', 64), 'k', array('listings:read'), KeyOwner::user($userId)));
$admin->query("UPDATE " . DB_TABLE_PREFIX . "t_user SET s_password = 'another-hash' WHERE pk_i_id = " . (int) $userId);
pin('a user key does not hang on the password hash', $userId, $model->find($auto)->owner()?->userId());
check('and the table has no password column', $admin->query("SHOW COLUMNS FROM $table LIKE 's_pw_bind'")->num_rows === 0);

harness_section('the sign-out stamp');
$userRow  = static fn (): array => $admin->query('SELECT * FROM ' . DB_TABLE_PREFIX . 't_user WHERE pk_i_id = ' . (int) $userId)->fetch_assoc();
$raise    = static function (string $table, int $id) use ($admin): void {
    // As if a sign-out ran with no listener to revoke what is stored.
    $admin->query('UPDATE ' . DB_TABLE_PREFIX . $table . ' SET i_auth_stamp = i_auth_stamp + 1 WHERE pk_i_id = ' . $id);
    scratchdb_forget_cache();
};
$refresh  = new RefreshTokens($model, new Scopes(), new UserRows(), 30, new SystemClock());
$stampKey = $keys->create(CredentialKind::KEY, 'Stamped', ['listings:write'], KeyOwner::user($userId));
$adminKey = $keys->create(CredentialKind::KEY, 'Stamped', ['admin:listings'], KeyOwner::admin($adminId));
$grant    = $refresh->start($userRow(), ['account:read'], 'Phone', '192.0.2.1');
pin('a key and a refresh token store the owner\'s stamp when issued', ['0', '0'], [
    $admin->query("SELECT i_auth_stamp FROM $table WHERE pk_i_id = " . $stampKey->id())->fetch_row()[0],
    $admin->query("SELECT i_auth_stamp FROM $table WHERE s_family = '" . $grant->family() . "'")->fetch_row()[0],
]);
$legacy = $keys->create(CredentialKind::KEY, 'Old', ['listings:write'], KeyOwner::user($userId));
$admin->query("UPDATE $table SET i_auth_stamp = NULL WHERE pk_i_id = " . $legacy->id());
$raise('t_user', $userId);
pin('once the user\'s stamp goes up their key is refused', null, $keys->verify($stampKey->token()));
$rotated = 'swapped';
try {
    $refresh->rotate($grant->token(), '192.0.2.1');
} catch (ProblemException $e) {
    $rotated = 'refused';
}
pin('and their refresh token cannot be swapped', 'refused', $rotated);
pin('a row from before stamps still works', $userId, $keys->verify($legacy->token())?->userId());
$newGrant = $refresh->start($userRow(), ['account:read'], 'Phone', '192.0.2.1');
pin('a key or sign-in made after works', [$userId, true], [
    $keys->verify($keys->create(CredentialKind::KEY, 'New', ['listings:write'], KeyOwner::user($userId))->token())?->userId(),
    $refresh->rotate($newGrant->token(), '192.0.2.1')->family() === $newGrant->family(),
]);
pin('an admin\'s key works until the admin\'s stamp goes up', [$adminId, null], [
    $keys->verify($adminKey->token())?->adminId(),
    (static function () use ($raise, $adminId, $keys, $adminKey) {
        $raise('t_admin', $adminId);

        return $keys->verify($adminKey->token());
    })(),
]);

harness_section('rows go with their owner');
// The scratch session turns key checks off for seeding; the cascade needs them on.
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_user WHERE pk_i_id = ' . (int) $userId);
pin('deleting a user removes their credentials', 0, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE fk_i_user_id = " . (int) $userId)->fetch_row()[0]);
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_admin WHERE pk_i_id = ' . (int) $modId);
pin('deleting an admin removes their keys', 0, (int) $admin->query("SELECT COUNT(*) FROM $table WHERE fk_i_admin_id = " . (int) $modId)->fetch_row()[0]);
pin('so the key is dead', null, $keys->verify($modKey->token()));
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
pin('delete removes a row', 1, $model->delete($lower));

$zone = date_default_timezone_get();
date_default_timezone_set('America/New_York');
$utcId = $model->insert(new StoredKey(0, CredentialKind::KEY, 'UTC0000000000001', str_repeat('c', 64), 'UTC', ['admin:listings'], KeyOwner::admin($adminId), null, true, 1_800_003_600, null, null, null, 1_800_000_000));
pin('times are written as UTC', ['2027-01-15 08:00:00', '2027-01-15 09:00:00'], $admin->query('SELECT dt_created, dt_expires FROM ' . DB_TABLE_PREFIX . "t_api_credential WHERE pk_i_id = $utcId")->fetch_row());
date_default_timezone_set('Asia/Kolkata');
pin('and read back the same in another zone', [1_800_000_000, 1_800_003_600], [$model->find($utcId)?->createdAt(), $model->find($utcId)?->expiresAt()]);
date_default_timezone_set('America/New_York');
// 2026-11-01 01:45 EDT; half an hour later the clocks have fallen back to 01:15 EST.
$fallBack = 1_793_511_900;
$dstId    = $model->insert(new StoredKey(0, CredentialKind::KEY, 'DST0000000000001', str_repeat('d', 64), 'DST', ['admin:listings'], KeyOwner::admin($adminId), null, true, $fallBack + 1800, null, null, null, $fallBack));
pin('across a DST fall-back a key expiring later is still usable', [$fallBack + 1800, true], [$model->find($dstId)?->expiresAt(), $model->find($dstId)?->isUsableAt($fallBack)]);
date_default_timezone_set($zone);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
