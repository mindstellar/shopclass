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
 * Idempotency-Key through the kernel: the same key and request replays the first answer
 * with Idempotency-Replayed and runs nothing again; another request under the key is 422;
 * a key still running is 409; a key past its day runs again; a 5xx or a crash is not kept;
 * keys belong to their sender; a write whose answer holds a secret and an anonymous call
 * ignore the header; a malformed key is 422.
 *
 * DB-free: keys live in an array.  Usage: php tests/api-idempotency.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\auth\CredentialStore;
use mindstellar\api\auth\KeyOwner;
use mindstellar\api\auth\Scopes;
use mindstellar\api\auth\StoredKey;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\idempotency\IdempotencyRecord;
use mindstellar\api\Kernel;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\utility\SystemClock;

/** Two users' keys and an admin's. */
final class KeyRows implements CredentialStore
{
    public array $rows = [];

    public function findByTokenId(string $tokenId): ?StoredKey
    {
        foreach ($this->rows as $id => $r) {
            if ($r[0] === $tokenId) {
                return new StoredKey($id, CredentialKind::KEY, $r[0], $r[1], 'k', $r[2], $r[3]);
            }
        }

        return null;
    }

    public function find(int $id): ?StoredKey
    {
        return null;
    }

    public function insert(StoredKey $key): int
    {
        $id              = count($this->rows) + 1;
        $this->rows[$id] = [$key->tokenId(), $key->secretHash(), $key->scopes(), $key->owner()];

        return $id;
    }

    public function touch(int $id, string $ip, int $time): void
    {
    }

    public function revoke(int $id): bool
    {
        return false;
    }
}

/** Counts its runs; answers 201, or a problem, or crashes, as the body asks. */
final class Writes
{
    public static int $runs = 0;

    public function create(Request $request, Credential $credential, array $args): Response
    {
        self::$runs++;
        $input = $request->input();

        return match ($input['do'] ?? '') {
            'fail'  => throw ProblemException::of('validation_failed', 'No.'),
            'crash' => throw new \RuntimeException('boom'),
            '500'   => new Response(500, ['code' => 'server_error']),
            default => Response::ok(['run' => self::$runs, 'by' => $credential->userId()], 201)->withHeader('Location', '/api/v1/things/' . self::$runs),
        };
    }
}

$store = new KeyRows();
$keys  = new ApiKeys($store, new Scopes(), new SystemClock());
$alice  = $keys->create(CredentialKind::KEY, 'a', ['listings:write'], KeyOwner::user(10))->token();
$bob    = $keys->create(CredentialKind::KEY, 'b', ['listings:write'], KeyOwner::user(11))->token();
$alice2 = $keys->create(CredentialKind::KEY, 'a2', ['listings:write'], KeyOwner::user(10))->token();
$now   = 1_800_000_000;
$kept  = new MemoryIdempotencyStore();
$spec  = ['handler' => [Writes::class, 'create'], 'auth' => 'user', 'scope' => 'listings:write', 'body' => ['type' => 'object']];

$kernel = api_test_kernel(
    new Router(new Validator(), [
        'POST things'  => $spec,
        'POST secrets' => $spec + ['replayable' => false],
        'POST open'    => ['auth' => RouteSpec::AUTH_NONE] + $spec,
    ]),
    api_test_authenticator($keys),
    idempotency: new Idempotency($kept, new TestClock(static function () use (&$now): int {
        return $now;
    }))
);
$post = static function (string $path, array $body, string $key = '', ?string $token = null) use ($kernel, $alice): Response {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== '') {
        $headers['Authorization'] = 'Bearer ' . ($token ?? $alice);
    }
    if ($key !== '') {
        $headers['Idempotency-Key'] = $key;
    }

    return $kernel->handle(new Request('POST', 'v1/' . $path, [], $headers, '192.0.2.1', (string) json_encode($body)));
};

harness_section('replay');
$first  = $post('things', ['a' => 1], 'key-1');
$second = $post('things', ['a' => 1], 'key-1');
pin('the first call runs', [201, 1, 1], [$first->status(), $first->body()['data']['run'], Writes::$runs]);
pin('the same key and request answers the same, and runs nothing', [201, $first->body(), '/api/v1/things/1', 1], [$second->status(), $second->body(), $second->header('Location'), Writes::$runs]);
pin('the replay says so', ['true', null], [$second->header('Idempotency-Replayed'), $first->header('Idempotency-Replayed')]);
pin('only a hash of the key is stored', [64, false], [strlen((string) array_key_first($kept->rows)), str_contains((string) json_encode(array_keys($kept->rows)), 'key-1')]);
$r = $post('things', ['a' => 2], 'key-1');
pin('the same key with another body is 422 idempotency_key_reused', [422, 'idempotency_key_reused', 1], [$r->status(), $r->body()['code'], Writes::$runs]);
pin('without a key every call runs', 3, ($post('things', ['a' => 1]) && $post('things', ['a' => 1])) ? Writes::$runs : 0);

harness_section('owners');
$r = $post('things', ['a' => 1], 'key-1', $bob);
pin('another sender\'s same key is their own', [201, 11], [$r->status(), $r->body()['data']['by']]);
$r = $post('things', ['a' => 1], 'key-1', $alice2);
pin('so is another credential of the same user', [201, null], [$r->status(), $r->header('Idempotency-Replayed')]);

harness_section('in flight and expiry');
$hash               = hash('sha256', 'key:1' . "\n" . 'key-2');
$kept->rows[$hash]  = ['fp' => Idempotency::fingerprint(new Request('POST', 'v1/things', [], [], '', (string) json_encode(['a' => 1]))), 'status' => IdempotencyRecord::LOCKED, 'response' => null, 'created' => $now, 'expires' => $now + 86400];
$runs               = Writes::$runs;
$r                  = $post('things', ['a' => 1], 'key-2');
pin('a key whose first request still runs is 409 idempotency_in_flight', [409, 'idempotency_in_flight', '1', $runs], [$r->status(), $r->body()['code'], $r->header('Retry-After'), Writes::$runs]);
$now += Idempotency::LOCK_TTL + 1;
pin('a lock whose request died is taken over', 201, $post('things', ['a' => 1], 'key-2')->status());
$now += Idempotency::TTL + 1;
$runs = Writes::$runs;
pin('a key past its day runs again', [201, $runs + 1], [$post('things', ['a' => 1], 'key-1')->status(), Writes::$runs]);

harness_section('what is kept');
$runs = Writes::$runs;
$post('things', ['do' => 'fail'], 'key-3');
pin('a refusal is kept and replayed', [422, $runs + 1], [$post('things', ['do' => 'fail'], 'key-3')->status(), Writes::$runs]);
$runs = Writes::$runs;
$post('things', ['do' => '500'], 'key-4');
$post('things', ['do' => '500'], 'key-4');
pin('a 5xx is not kept, so a retry runs', $runs + 2, Writes::$runs);
$runs = Writes::$runs;
$logged = ini_set('error_log', '/dev/null');
$crash  = $post('things', ['do' => 'crash'], 'key-5');
ini_set('error_log', (string) $logged);
pin('a crash answers 500 and leaves no lock behind', [500, $runs + 1, false], [$crash->status(), Writes::$runs, isset($kept->rows[hash('sha256', "key:1\nkey-5")])]);
$runs = Writes::$runs;
$post('secrets', ['a' => 1], 'key-6');
$post('secrets', ['a' => 1], 'key-6');
pin('a write whose answer holds a secret ignores the key', [$runs + 2, false], [Writes::$runs, isset($kept->rows[hash('sha256', "key:1\nkey-6")])]);
$runs = Writes::$runs;
$post('open', ['a' => 1], 'key-7', '');
$post('open', ['a' => 1], 'key-7', '');
pin('so does an anonymous call', $runs + 2, Writes::$runs);

harness_section('the key itself');
pin('a key over 255 characters is 400', [400, 'invalid_header'], [$post('things', [], str_repeat('k', 256))->status(), $post('things', [], str_repeat('k', 256))->body()['code']]);
pin('a key with a space is 400', 400, $post('things', [], 'two words')->status());
pin('a GET never looks at it', 405, $kernel->handle(new Request('GET', 'v1/things', [], ['Authorization' => 'Bearer ' . $alice, 'Idempotency-Key' => 'x'], '192.0.2.1'))->status());

exit(harness_result());
