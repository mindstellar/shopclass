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
 * If-Match through the kernel: a PATCH or DELETE whose ETag no longer matches the path's GET
 * is 412 precondition_failed and runs nothing; a matching tag and `*` go through; no header,
 * and a path with no GET, are never checked.
 *
 * DB-free.  Usage: php tests/api-if-match.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\auth\CredentialStore;
use mindstellar\api\auth\KeyOwner;
use mindstellar\api\auth\Scopes;
use mindstellar\api\auth\StoredKey;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\utility\SystemClock;

final class Things
{
    public static string $title = 'one';

    public static int $writes = 0;

    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok(['id' => (int) $args['id'], 'title' => self::$title]);
    }

    public function update(Request $request, Credential $credential, array $args): Response
    {
        self::$writes++;
        self::$title = (string) ($request->input()['title'] ?? self::$title);

        return $this->show($request, $credential, $args);
    }

    public function delete(Request $request, Credential $credential, array $args): Response
    {
        self::$writes++;

        return new Response(204, null);
    }
}

final class Keys implements CredentialStore
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

$keys  = new ApiKeys(new Keys(), new Scopes(), new SystemClock());
$token = $keys->create(CredentialKind::KEY, 'a', ['listings:read', 'listings:write'], KeyOwner::user(10))->token();
$write = ['auth' => 'user', 'scope' => 'listings:write'];

$kernel = api_test_kernel(
    new Router(new Validator(), [
        'GET things/{id}'      => ['handler' => [Things::class, 'show'], 'auth' => 'user', 'scope' => 'listings:read'],
        'PATCH things/{id}'    => ['handler' => [Things::class, 'update'], 'body' => ['type' => 'object']] + $write,
        'DELETE things/{id}'   => ['handler' => [Things::class, 'delete']] + $write,
        'DELETE lonely/{id}'   => ['handler' => [Things::class, 'delete']] + $write,
    ]),
    api_test_authenticator($keys)
);
$call = static function (string $method, string $path, string $ifMatch = '', array $body = []) use ($kernel, $token): Response {
    $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
    if ($ifMatch !== '') {
        $headers['If-Match'] = $ifMatch;
    }

    return $kernel->handle(new Request($method, 'v1/' . $path, [], $headers, '192.0.2.1', $body === [] ? '' : (string) json_encode($body)));
};

$etag = $call('GET', 'things/5')->prepare('GET')['headers']['ETag'];

harness_section('PATCH');
$r = $call('PATCH', 'things/5', $etag, ['title' => 'two']);
pin('the current ETag lets it through', [200, 1, 'two'], [$r->status(), Things::$writes, Things::$title]);
$r = $call('PATCH', 'things/5', $etag, ['title' => 'three']);
pin('the old ETag is 412 precondition_failed and changes nothing', [412, 'precondition_failed', 1, 'two'], [$r->status(), $r->body()['code'], Things::$writes, Things::$title]);
$r = $call('PATCH', 'things/5', 'W/' . $call('GET', 'things/5')->prepare('GET')['headers']['ETag'], ['title' => 'three']);
pin('a weak copy of the current ETag is accepted', [200, 'three'], [$r->status(), Things::$title]);
$r = $call('PATCH', 'things/5', '*', ['title' => 'four']);
pin('* matches any existing resource', [200, 'four'], [$r->status(), Things::$title]);
$r = $call('PATCH', 'things/5', '', ['title' => 'five']);
pin('no If-Match is never refused', [200, 'five'], [$r->status(), Things::$title]);
$r = $call('PATCH', 'things/5', '"nope", ' . $call('GET', 'things/5')->prepare('GET')['headers']['ETag'], ['title' => 'six']);
pin('a list matches when any tag does', [200, 'six'], [$r->status(), Things::$title]);

harness_section('DELETE');
$writes = Things::$writes;
$r      = $call('DELETE', 'things/5', '"stale"');
pin('a stale ETag is 412 and deletes nothing', [412, $writes], [$r->status(), Things::$writes]);
$r = $call('DELETE', 'things/5', $call('GET', 'things/5')->prepare('GET')['headers']['ETag']);
pin('the current ETag deletes', [204, $writes + 1], [$r->status(), Things::$writes]);
$r = $call('DELETE', 'lonely/5', '"anything"');
pin('a path with no GET ignores If-Match', [204, $writes + 2], [$r->status(), Things::$writes]);

exit(harness_result());
