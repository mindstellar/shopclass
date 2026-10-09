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
 * Signed-in user tokens: access and refresh tokens, the authenticator and the kernel acting for the user.
 * Usage: php tests/api-tokens.php
 */

define('OSC_CSRF_SECRET', 'api-tokens-test-secret');
define('WEB_PATH', 'http://example.test/');

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';

use mindstellar\api\ApiCall;
use mindstellar\api\auth\Authenticator;
use mindstellar\api\auth\FailureCounter;
use mindstellar\api\auth\RefreshRetries;
use mindstellar\api\auth\RefreshTokens;
use mindstellar\api\auth\UserRows;
use mindstellar\api\Kernel;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\SignInStore;
use mindstellar\apiaccess\StoredKey;
use mindstellar\utility\SystemClock;

/** t_api_credential as an array, with a user table beside it. */
final class ArraySessions implements SignInStore
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    /** @var array<int,array<string,mixed>> user id => t_user row */
    public array $users = [];

    /** @var string[] store calls, in order, while $trace is on */
    public array $calls = [];

    public bool $trace = false;

    public function atomically(callable $fn): mixed
    {
        $this->note('begin');
        $result = $fn();
        $this->note('commit');

        return $result;
    }

    public function lockFamily(string $family): void
    {
        $this->note('lock');
    }

    private function note(string $call): void
    {
        if ($this->trace) {
            $this->calls[] = $call;
        }
    }

    public function findByTokenId(string $tokenId): ?StoredKey
    {
        foreach ($this->rows as $id => $row) {
            if ($row['tokenId'] === $tokenId) {
                return $this->key($id);
            }
        }

        return null;
    }

    public function find(int $id): ?StoredKey
    {
        $this->note('find');

        return isset($this->rows[$id]) ? $this->key($id) : null;
    }

    public function insert(StoredKey $key): int
    {
        $this->note('insert');
        $id              = count($this->rows) + 1;
        $this->rows[$id] = [
            'kind' => $key->kind(), 'tokenId' => $key->tokenId(), 'hash' => $key->secretHash(), 'name' => $key->name(),
            'scopes' => $key->scopes(), 'userId' => $key->owner()->userId(), 'family' => $key->family(),
            'expires' => $key->expiresAt(), 'revoked' => null, 'created' => $key->createdAt(),
            'lastUsed' => null, 'ip' => '',
        ];

        return $id;
    }

    public function touch(int $id, string $ip, int $time): void
    {
        $this->rows[$id]['lastUsed'] = $time;
        $this->rows[$id]['ip']       = $ip;
    }

    public function revoke(int $id): bool
    {
        $this->note('revoke');
        if (!isset($this->rows[$id]) || $this->rows[$id]['revoked'] !== null) {
            return false;
        }
        $this->rows[$id]['revoked'] = 1;

        return true;
    }

    public function revokeFamily(string $family): int
    {
        $n = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['family'] === $family && $row['revoked'] === null) {
                $this->rows[$id]['revoked'] = 1;
                $n++;
            }
        }

        return $n;
    }

    public function revokeRefreshFor(int $userId, ?string $keepFamily = null): int
    {
        $n = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['kind'] === CredentialKind::REFRESH && $row['userId'] === $userId && $row['revoked'] === null && $row['family'] !== $keepFamily) {
                $this->rows[$id]['revoked'] = 1;
                $n++;
            }
        }

        return $n;
    }

    public function listBy(?string $kind = null, ?int $userId = null, ?int $adminId = null, bool $liveOnly = false): array
    {
        $out = [];
        foreach (array_reverse(array_keys($this->rows)) as $id) {
            $row = $this->rows[$id];
            if (($kind === null || $row['kind'] === $kind) && ($userId === null || $row['userId'] === $userId) && (!$liveOnly || $row['revoked'] === null)) {
                $out[] = $this->key($id);
            }
        }

        return $out;
    }

    public function hasLiveFor(int $userId): bool
    {
        foreach ($this->rows as $row) {
            if ($row['userId'] === $userId && $row['revoked'] === null && in_array($row['kind'], [CredentialKind::REFRESH, CredentialKind::KEY], true)) {
                return true;
            }
        }

        return false;
    }

    /** Live rows of a family. */
    public function live(string $family): int
    {
        return count(array_filter($this->rows, static fn (array $r): bool => $r['family'] === $family && $r['revoked'] === null));
    }

    private function key(int $id): StoredKey
    {
        $r     = $this->rows[$id];
        $user  = $this->users[$r['userId']] ?? null;
        $owner = $user !== null && \mindstellar\user\UserStore::isLive($user) ? KeyOwner::user($r['userId']) : null;

        return new StoredKey($id, $r['kind'], $r['tokenId'], $r['hash'], $r['name'], $r['scopes'], $owner, null, true, $r['expires'], $r['revoked'], $r['lastUsed'], $r['family'], $r['created'], $r['ip']);
    }
}

$store        = new ArraySessions();
$store->users = [
    10 => ['pk_i_id' => '10', 's_name' => 'Uma', 's_email' => 'uma@x.test', 's_phone_mobile' => '', 's_phone_land' => '', 's_password' => '$2y$12$first', 'b_enabled' => '1', 'b_active' => '1'],
    11 => ['pk_i_id' => '11', 's_name' => 'Cookie', 's_email' => 'c@x.test', 's_phone_mobile' => '', 's_phone_land' => '', 's_password' => '$2y$12$other', 'b_enabled' => '1', 'b_active' => '1'],
];
$loads    = 0;
$accounts = static function () use ($store, &$loads): UserRows {
    return new UserRows(static function (int $id) use ($store, &$loads): ?array {
        $loads++;

        return $store->users[$id] ?? null;
    });
};
$scopes = new Scopes();
$now    = 1_800_000_000;
$clock  = new TestClock(static function () use (&$now): int {
    return $now;
});
$problem = static function (callable $fn): ?string {
    try {
        $result = $fn();
    } catch (ProblemException $e) {
        $result = $e;
    }

    return $result instanceof ProblemException ? (string) $result->response()->body()['code'] : null;
};

harness_section('access tokens');
$access = api_test_access_tokens($scopes, $accounts(), 900);
$token  = $access->issue($store->users[10], ['listings:read', 'account:write', 'admin:users'], 'FAMILY0000000001');
check('an access token is sca_<signed payload>', preg_match('/^sca_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) === 1);
$c = $access->check($token)->credential();
pin('an access token stands for its user, from its sign-in', [CredentialKind::USER, 10, 'FAMILY0000000001', true, false], [$c->kind(), $c->userId(), $c->family(), $c->isUser(), $c->isAdmin()]);
pin('scopes a user may not hold are cut on every use', ['listings:read', 'account:write'], $c->scopes());
check('the payload holds no password hash, only the sign-out stamp\'s fingerprint', !str_contains(base64_decode(strtr(explode('.', substr($token, 4))[0], '-_', '+/')), '$2y$'));
pin('an expired token is refused', null, $access->check($access->issue($store->users[10], ['listings:read'], 'F', -1))->credential());
pin('a changed signature is refused', null, $access->check($token . 'x')->credential());
pin('a token signed for another purpose is refused', null, $access->check('sca_' . \mindstellar\security\SignedPayload::pack('report-sender', ['sub' => 10, 'kind' => 'user', 'scopes' => '', 'pw' => '', 'fam' => 'F'], 60))->credential());
pin('a key-looking token is not an access token', null, $access->check('sck_AAAAAAAAAAAAAAAA.' . str_repeat('a', 64))->credential());

$store->users[10]['s_password'] = '$2y$12$rehashed';
pin('a rehash alone leaves the token working', 10, (api_test_access_tokens($scopes, $accounts(), 900))->check($token)->credential()?->userId());
$store->users[10]['s_password']   = '$2y$12$first';
$store->users[10]['i_auth_stamp'] = '1';
pin('a raised sign-out stamp (a password change, or signing out everywhere) ends the token at once', null, (api_test_access_tokens($scopes, $accounts(), 900))->check($token)->credential());
unset($store->users[10]['i_auth_stamp']);
check('another account\'s stamp has a different fingerprint', \mindstellar\auth\AuthStamp::fingerprint($store->users[10]) !== \mindstellar\auth\AuthStamp::fingerprint($store->users[11]));
$store->users[10]['b_enabled']  = '0';
pin('a suspended user\'s token is refused on the next call', null, (api_test_access_tokens($scopes, $accounts(), 900))->check($token)->credential());
$store->users[10]['b_enabled'] = '1';
$store->users[10]['b_active']  = '0';
pin('an unconfirmed user\'s token is refused on the next call', null, (api_test_access_tokens($scopes, $accounts(), 900))->check($token)->credential());
$store->users[10]['b_active'] = '1';
pin('a revoked sign-in\'s token is refused on the next call', null, api_test_access_tokens($scopes, $accounts(), 900, static fn (string $f): bool => $f !== 'FAMILY0000000001')->check($token)->credential());
pin('a deleted user\'s too', null, (api_test_access_tokens($scopes, $accounts(), 900))->check($access->issue(['pk_i_id' => 99, 's_password' => 'x'], [], 'F'))->credential());
$memo  = $accounts();
$loads = 0;
$memo->find(10);
$memo->find(10);
pin('a user row is read once per request', 1, $loads);

harness_section('refresh tokens');
$refresh = new RefreshTokens($store, $scopes, $accounts(), 30, $clock);
$first   = $refresh->start($store->users[10], ['listings:read', 'account:read'], '  Phone  ', '192.0.2.1');
check('a refresh token is scr_<16>.<64 hex>', preg_match('/^scr_[0-9A-Za-z]{16}\.[0-9a-f]{64}$/D', $first->token()) === 1);
$row = $store->rows[1];
pin('stored as a hash with its family, label, address and a 30-day expiry', [
    CredentialKind::REFRESH, hash('sha256', explode('.', $first->token())[1]), $first->family(), 'Phone', '192.0.2.1', $now + 30 * 86400,
], [$row['kind'], $row['hash'], $row['family'], $row['name'], $row['ip'], $row['expires']]);
check('the token itself is stored nowhere', !str_contains((string) json_encode($store->rows), explode('.', $first->token())[1]));

harness_section('one mint and check for keys and refresh tokens');
[$mintId, $mintSecret, $mintHash] = ApiKeys::mint();
pin('mint() gives a 16-character id, a 64-hex secret and its sha256', [1, 1, hash('sha256', $mintSecret)],
    [preg_match('/^[0-9A-Za-z]{16}$/D', $mintId), preg_match('/^[0-9a-f]{64}$/D', $mintSecret), $mintHash]);
pin('parse() splits a refresh token', ['scr_', $row['tokenId'], explode('.', $first->token())[1]], ApiKeys::parse($first->token(), [RefreshTokens::PREFIX]));
pin('and refuses a prefix it was not given', null, ApiKeys::parse($first->token(), [ApiKeys::KEY_PREFIX, ApiKeys::PUBLIC_PREFIX]));
pin('or a short secret', null, ApiKeys::parse('scr_' . str_repeat('A', 16) . '.abc', [RefreshTokens::PREFIX]));
$stored = new StoredKey(1, CredentialKind::REFRESH, $row['tokenId'], $row['hash'], '', [], null);
pin('secretMatches() checks the stored hash', [true, false], [ApiKeys::secretMatches($stored, explode('.', $first->token())[1]), ApiKeys::secretMatches($stored, str_repeat('0', 64))]);
$code = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/apiaccess/ApiKeys.php')
    . file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/api/auth/RefreshTokens.php');
pin('both make secrets and compare hashes in one place', [1, 1, 2], [substr_count($code, 'random_bytes(32)'), substr_count($code, 'hash_equals('), substr_count($code, '::mint()')]);

harness_section('a Clock reaches SignedPayload');
$pages = new \mindstellar\apiaccess\PageTokens(600, $clock);
$page  = $pages->issue($store->users[10]);
pin('a page token expires from the clock\'s time', $now + 600, $page->expiresAt());
pin('and is checked at the clock\'s time', \mindstellar\apiaccess\PageTokens::VALID, $pages->check($page->token(), $store->users[10]));
$now += 601;
pin('so moving the clock past it expires it', \mindstellar\apiaccess\PageTokens::EXPIRED, $pages->check($page->token(), $store->users[10]));
$now -= 601;

$now          += 3600;
$store->trace  = true;
$second        = $refresh->rotate($first->token(), '192.0.2.2');
$store->trace  = false;
pin('a swap runs in one transaction, the family locked before the row is read again, claimed and replaced', ['begin', 'lock', 'find', 'revoke', 'insert', 'commit'], $store->calls);
pin('a use swaps it for a new token in the same family, with the same scopes', [$first->family(), ['listings:read', 'account:read']], [$second->family(), $second->scopes()]);
check('the new token differs', $second->token() !== $first->token());
pin('the expiry slides from this use', $now + 30 * 86400, $second->expiresAt());
pin('the old one is revoked, the new one live', [1, 1], [(int) $store->rows[1]['revoked'], $store->live($first->family())]);

try {
    $refresh->rotate($first->token(), '203.0.113.9');
    $reused = null;
} catch (ProblemException $e) {
    $reused = $e->response()->body();
}
pin('the old token coming back is thrown as an OAuth invalid_grant that says so', ['invalid_grant', 'invalid_grant', true], [$reused['code'] ?? null, $reused['error'] ?? null, str_contains((string) ($reused['detail'] ?? ''), 'already used')]);
pin('a reused refresh token leaves no live token in its family', 0, $store->live($first->family()));
pin('a reused refresh token also refuses the newest token', 'invalid_grant', $problem(static fn () => $refresh->rotate($second->token(), '192.0.2.2')));

$other = $refresh->start($store->users[10], ['listings:read'], 'Laptop', '192.0.2.3');
pin('a wrong secret is refused and revokes nothing', ['invalid_grant', 1], [$problem(static fn () => $refresh->rotate(substr($other->token(), 0, -1) . (str_ends_with($other->token(), 'f') ? 'e' : 'f'), '1.1.1.1')), $store->live($other->family())]);
pin('garbage is refused', 'invalid_grant', $problem(static fn () => $refresh->rotate('scr_nope', '1.1.1.1')));
$now += 31 * 86400;
pin('a token unused past its life is refused', 'invalid_grant', $problem(static fn () => $refresh->rotate($other->token(), '1.1.1.1')));

$third = $refresh->start($store->users[10], ['listings:read'], 'Tablet', '192.0.2.4');
$store->users[10]['s_password'] = '$2y$12$rehashed';
$fresh = new RefreshTokens($store, $scopes, $accounts(), 30, $clock);
check('a new hash alone (a rehash) does not end the family', $fresh->rotate($third->token(), '1.1.1.1')->family() === $third->family());
$store->users[10]['s_password'] = '$2y$12$first';

$fifth = $fresh->start($store->users[10], ['listings:read'], 'Five', '');
$store->users[10]['b_enabled'] = '0';
pin('a suspended user cannot refresh', 'invalid_grant', $problem(static fn () => (new RefreshTokens($store, $scopes, $accounts(), 30, $clock))->rotate($fifth->token(), '')));
$store->users[10]['b_enabled'] = '1';

harness_section('refresh retries');
$kept     = [];
$retries  = new RefreshRetries(
    static function (string $key) use (&$kept, &$now): ?string {
        return isset($kept[$key]) && $kept[$key][1] > $now ? $kept[$key][0] : null;
    },
    static function (string $key, string $value, int $expiresAt) use (&$kept): void {
        $kept[$key] = [$value, $expiresAt];
    },
    static function (string $key) use (&$kept): void {
        unset($kept[$key]);
    }
);
$retrying = new RefreshTokens($store, $scopes, $accounts(), 30, $clock, $retries);
$begun    = $retrying->start($store->users[10], ['listings:read'], 'Retry', '');
$next     = $retrying->rotate($begun->token(), '');
$again    = $retrying->rotate($begun->token(), '');
pin('the old token sent again within the window gets the same new token, and the sign-in lives', [$next->token(), 1], [$again->token(), $store->live($begun->family())]);
pin('one row per sign-in, keyed by the sign-in', [$begun->family()], array_keys($kept));
check('the kept token is encrypted', !str_contains((string) json_encode($kept), explode('.', $next->token())[1]));
$hashOnly = (string) $store->rows[array_key_first(array_filter($store->rows, static fn (array $r): bool => ($r['family'] ?? null) === $begun->family()))]['hash'];
pin('nothing the site stores opens it: not the stored hash of the old token', null, $retries->recall($begun->family(), 0, $hashOnly));
$retrying->rotate($next->token(), '');
pin('once the new token was used, the old one coming back ends the sign-in', ['invalid_grant', 0, []], [$problem(static fn () => $retrying->rotate($begun->token(), '')), $store->live($begun->family()), $kept]);
$late = $retrying->start($store->users[10], ['listings:read'], 'Late', '');
$retrying->rotate($late->token(), '');
$now += RefreshRetries::WINDOW;
pin('after the window, it ends the sign-in too', ['invalid_grant', 0], [$problem(static fn () => $retrying->rotate($late->token(), '')), $store->live($late->family())]);
$held = $retrying->start($store->users[10], ['listings:read'], 'Held', '');
$retrying->rotate($held->token(), '');
$store->users[10]['b_enabled'] = '0';
pin('a user blocked in the window gets no token back', 'invalid_grant', $problem(static fn () => (new RefreshTokens($store, $scopes, $accounts(), 30, $clock, $retries))->rotate($held->token(), '')));
$store->users[10]['b_enabled'] = '1';
$kept[$held->family()] = ['{"old":1,"id":1,"token":"scr_plain"}', $now + 60];
pin('a value that is not sealed is never handed out', null, $retries->recall($held->family(), 1, 'x'));
$oldIv  = random_bytes(12);
$oldTag = '';
$oldBox = openssl_encrypt('{"old":7,"id":8,"token":"scr_old.row"}', 'aes-256-gcm', hash_hmac('sha256', 'api-refresh-retry', 'old-secret', true), OPENSSL_RAW_DATA, $oldIv, $oldTag, '', 16);
$kept[$held->family()] = ['rr1:' . base64_encode($oldIv . $oldTag . $oldBox), $now + 60];
pin('a row kept before the move to SecretBox still opens', 'scr_old.row', $retries->recall($held->family(), 7, 'old-secret')?->token());

$a = $fresh->start($store->users[10], ['listings:read'], 'A', '');
$b = $fresh->start($store->users[10], ['listings:read'], 'B', '');
pin('revoking all but one sign-in', [0, 1], [$fresh->end(10, null, $b->family()) >= 1 ? $store->live($a->family()) : -1, $store->live($b->family())]);
pin('another user\'s sign-in is not ended', [0, 1], [$fresh->end(11, $b->family()), $store->live($b->family())]);
pin('the user\'s own is', [1, 0], [$fresh->end(10, $b->family()), $store->live($b->family())]);

harness_section('the authenticator takes access tokens');
$authenticator = new Authenticator(new ApiKeys($store, $scopes, new SystemClock()), new FailureCounter(static fn () => [], static function () use (&$failures): int {
    return ++$failures;
}), api_test_access_tokens($scopes, $accounts(), 900));
$failures = 0;
$good     = (api_test_access_tokens($scopes, $accounts(), 900))->issue($store->users[10], ['listings:read'], 'FAM');
$c        = $authenticator->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $good], '192.0.2.10'));
pin('a Bearer access token authenticates', [CredentialKind::USER, 10], [$c->kind(), $c->userId()]);
pin('a bad one is 401 and counted', ['unauthorized', 1], [$problem(static fn () => $authenticator->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer sca_x.y'], '192.0.2.10'))), $failures]);
pin('a refresh token never authenticates a call', 'unauthorized', $problem(static fn () => $authenticator->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $b->token()], '192.0.2.10'))));
$failures = 0;
pin('an expired but genuine token is 401 token_expired, and not counted', ['token_expired', 0], [$problem(static fn () => $authenticator->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . (api_test_access_tokens($scopes, $accounts(), 900))->issue($store->users[10], ['listings:read'], 'FAM', -1)], '192.0.2.10'))), $failures]);
$staleToken = (api_test_access_tokens($scopes, $accounts(), 900))->issue(['pk_i_id' => 10, 'i_auth_stamp' => 7], ['listings:read'], 'FAM');
pin('a genuine token whose user changed since is 401 unauthorized, and not counted', ['unauthorized', 0], [$problem(static fn () => $authenticator->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $staleToken], '192.0.2.10'))), $failures]);
pin('a forged one is counted', 1, ($problem(static fn () => $authenticator->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . substr($good, 0, -2) . 'xx'], '192.0.2.10'))) !== null) ? $failures : -1);
$noTokens = api_test_authenticator(new ApiKeys($store, $scopes, new SystemClock()));
pin('an access token for a user the checker does not know is refused', 'unauthorized', $problem(static fn () => $noTokens->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $good], '192.0.2.10'))));

harness_section('a user\'s key does not hang on the password hash');
$userKeys = new ApiKeys($store, $scopes, new SystemClock());
$made     = $userKeys->create(CredentialKind::KEY, 'Script', ['listings:read'], KeyOwner::user(10));
pin('the key works', 10, $userKeys->check($made->token())->credential()?->userId());
$keptHash                       = $store->users[10]['s_password'];
$store->users[10]['s_password'] = '$2y$12$anotherone';
pin('a rehash leaves it working; a password change revokes it through SignOut', 10, $userKeys->check($made->token())->credential()?->userId());
$store->users[10]['s_password'] = $keptHash;

harness_section('a banned user\'s key and access token are refused');
$bannedIds = [];
$banning   = new Authenticator(
    $userKeys,
    new FailureCounter(static fn () => [], static fn (): int => 1),
    api_test_access_tokens($scopes, $accounts(), 900),
    null,
    static function (int $id, string $ip) use (&$bannedIds): bool {
        return in_array($id, $bannedIds, true) || $ip === '203.0.113.9';
    }
);
$asKey   = new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $made->token()], '192.0.2.10');
$asToken = new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $good], '192.0.2.10');
pin('not banned, both work', [10, 10], [$banning->authenticate($asKey)?->userId(), $banning->authenticate($asToken)?->userId()]);
$bannedIds = [10];
pin('a banned user\'s key is 403', 'banned', $problem(static fn () => $banning->authenticate($asKey)));
pin('a banned user\'s access token is 403 banned', 'banned', $problem(static fn () => $banning->authenticate($asToken)));
$bannedIds = [];
pin('a banned address is 403 too', 'banned', $problem(static fn () => $banning->authenticate(new Request('GET', 'v1/x', [], ['Authorization' => 'Bearer ' . $good], '203.0.113.9'))));

harness_section('limits and settings');
check('the API is off by default', !(new ApiSettings())->enabled());
$strict = api_test_limiter(static fn () => null);
check('a limit fails open by default when the counter is unreachable', $strict->hit(new \mindstellar\api\ratelimit\RateBucket('x', 'k', 5))->allowed());
check('a limit fails closed when asked to when the counter is unreachable', !$strict->hit(new \mindstellar\api\ratelimit\RateBucket('x', 'k', 5), false)->allowed());
pin('an access token lives 15 minutes, a refresh token 30 days unused', [900, 900, 30], [\mindstellar\api\auth\AccessTokens::TTL, (api_test_access_tokens($scopes, $accounts()))->ttl(), \mindstellar\api\auth\RefreshTokens::TTL_DAYS]);
$tuned   = new ApiSettings(true, signUpsPerHour: 7, photoFetchesPerHour: 3, refreshDays: 9);
$policy  = new \mindstellar\api\ratelimit\RatePolicy($tuned);
$refresh = (new \mindstellar\api\ApiServices($tuned, $scopes, $store, $accounts(), new SystemClock(), api_test_limiter()))->refreshTokens();
pin('the site sign-up cap, photo fetches and refresh-token days are settings', [7, 3, 9], [
    $policy->signUpSite()->max(), $policy->photoFetch(1)->max(), (new ReflectionProperty($refresh, 'ttlDays'))->getValue($refresh),
]);
$plain = new \mindstellar\api\ratelimit\RatePolicy(new ApiSettings());
pin('...whose defaults are the old fixed numbers', [100, 30, 30, 5], [$plain->signUpSite()->max(), $plain->photoFetch(1)->max(), (new ApiSettings())->refreshDays(), $plain->signUp('1.2.3.4')->max()]);

harness_section('the identity core code sees');
final class WhoAmI
{
    public function show(ApiCall $call): Response
    {
        return Response::ok(['user' => osc_logged_user_id(), 'email' => osc_logged_user_email()]);
    }
}
$kernel = api_test_kernel(
    new Router(new Validator(), ['GET me' => ['handler' => [WhoAmI::class, 'show'], 'auth' => 'public', 'scope' => 'listings:read']]),
    api_test_authenticator(new ApiKeys($store, $scopes, new SystemClock()), tokens: api_test_access_tokens($scopes, $accounts(), 900)),
    new ApiSettings(true, true),
    users: $accounts()
);
// The bootstrap resolved user 11 from a signed cookie; index.php forgets it for page=api.
$_COOKIE = ['oc_userId' => '11', 'oc_userSecret' => 'signed'];
osc_web_user_apply_identity($store->users[11]);
\mindstellar\api\identity\WebIdentity::forget();
$r = $kernel->handle(new Request('GET', 'v1/me', [], [], '192.0.2.20'));
pin('a valid oc_userId cookie and no header is anonymous', [200, 0, ''], [$r->status(), $r->body()['data']['user'], $r->body()['data']['email']]);
$r = $kernel->handle(new Request('GET', 'v1/me', [], ['Authorization' => 'Bearer ' . $good], '192.0.2.20'));
pin('with a token, core sees the token\'s user', [10, 'uma@x.test'], [$r->body()['data']['user'], $r->body()['data']['email']]);
check('the cookie user is never the caller when a token is sent', $r->body()['data']['user'] !== 11);
check('nothing started a session', session_status() !== PHP_SESSION_ACTIVE);

exit(harness_result());
