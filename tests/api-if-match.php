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
 * If-Match through the kernel. A path whose GET keeps a stored version: the ETag carries it,
 * whatever `fields` asked for; a stale one is 412 and runs nothing; the check and the write
 * run in one transaction with the rows locked; the write's answer carries the new ETag.
 * Other paths compare the GET's ETag, and a credential that cannot read the GET is refused,
 * not let through. A stale tag on a resource the caller cannot see gets the GET's 404, so a
 * 412 never reveals that it exists. No header, and a path with no GET, are never checked.
 *
 * DB-free.  Usage: php tests/api-if-match.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ApiCall;
use mindstellar\api\auth\AdminRows;
use mindstellar\api\http\ResourceVersions;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\Kernel;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\CredentialStore;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\StoredKey;
use mindstellar\utility\SystemClock;

final class Things
{
    public static string $title = 'one';

    public static int $writes = 0;

    public function show(ApiCall $call): Response
    {
        $doc = ['id' => (int) $call->arg('id'), 'title' => self::$title];

        return Response::ok(isset($call->request()->query()['fields']) ? ['id' => $doc['id']] : $doc);
    }

    /** Only id 5 is the caller's; any other is 404, as if it did not exist. */
    public function own(ApiCall $call): Response
    {
        if ($call->arg('id') !== '5') {
            throw ProblemException::of('not_found', 'No such thing.');
        }

        return $call->request()->method() === 'GET' ? $this->show($call) : $this->update($call);
    }

    public function fail(ApiCall $call): Response
    {
        self::$writes++;
        Versions::$stored = 'v-broken';

        throw new RuntimeException('handler failed');
    }

    public function update(ApiCall $call): Response
    {
        self::$writes++;
        self::$title = (string) ($call->request()->input()['title'] ?? self::$title);

        return $this->show($call);
    }

    public function delete(ApiCall $call): Response
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

/** The stored version is the title, read under a lock inside a transaction it records. */
final class Versions implements ResourceVersions
{
    public static ?string $stored = null;

    public array $log = [];

    private int $depth = 0;

    public function supports(string $path): bool
    {
        return str_starts_with($path, 'versioned/') || str_starts_with($path, 'owned/') || str_starts_with($path, 'secret/');
    }

    public function version(string $path, array $args, Credential $credential, bool $lock = false): ?string
    {
        $this->log[] = ($lock ? 'lock' : 'read') . '@' . $this->depth;

        return $args['id'] === '404' ? null : (self::$stored ?? 'v-' . md5(Things::$title));
    }

    public function atomically(callable $fn): mixed
    {
        $this->depth++;
        $this->log[] = 'begin';
        $before      = [Things::$title, self::$stored];
        try {
            $result      = $fn();
            $this->log[] = 'commit';

            return $result;
        } catch (Throwable $e) {
            [Things::$title, self::$stored] = $before;
            $this->log[]                    = 'rollback';

            throw $e;
        } finally {
            $this->depth--;
        }
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

$versions = new Versions();
$vkernel  = new Kernel(
    new Router(new Validator(), [
        'GET versioned/{id}'    => ['handler' => [Things::class, 'show'], 'auth' => 'user', 'scope' => 'listings:read', 'query' => ['type' => 'object', 'properties' => ['fields' => ['type' => 'string']]]],
        'PATCH versioned/{id}'  => ['handler' => [Things::class, 'update'], 'body' => ['type' => 'object']] + $write,
        'DELETE versioned/{id}' => ['handler' => [Things::class, 'fail']] + $write,
        'GET hidden/{id}'       => ['handler' => [Things::class, 'show'], 'auth' => 'user', 'scope' => 'listings:moderate'],
        'DELETE hidden/{id}'    => ['handler' => [Things::class, 'delete']] + $write,
        'GET owned/{id}'        => ['handler' => [Things::class, 'own'], 'auth' => 'user', 'scope' => 'listings:read'],
        'PATCH owned/{id}'      => ['handler' => [Things::class, 'own'], 'body' => ['type' => 'object']] + $write,
        'GET secret/{id}'       => ['handler' => [Things::class, 'show'], 'auth' => 'user', 'scope' => 'listings:moderate'],
        'DELETE secret/{id}'    => ['handler' => [Things::class, 'delete']] + $write,
    ]),
    api_test_authenticator($keys),
    api_test_limiter(),
    new Validator(),
    new ApiSettings(true),
    api_test_users(),
    new AdminRows(static fn (): ?array => null),
    new Idempotency(new MemoryIdempotencyStore(), new SystemClock()),
    $versions
);
$vcall = static function (string $method, string $path, string $ifMatch = '', array $body = []) use ($vkernel, $token): Response {
    $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
    if ($ifMatch !== '') {
        $headers['If-Match'] = $ifMatch;
    }
    [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
    parse_str($query, $q);

    return $vkernel->handle(new Request($method, 'v1/' . $path, $q, $headers, '192.0.2.1', $body === [] ? '' : (string) json_encode($body)));
};
$tag = static fn (Response $r): string => (string) $r->prepare('GET')['headers']['ETag'];

harness_section('stored version');
Things::$title = 'one';
$writes        = Things::$writes;
$full          = $tag($vcall('GET', 'versioned/5'));
$slim          = $tag($vcall('GET', 'versioned/5?fields=id'));
$version       = 'v-' . md5('one');
pin('a GET\'s ETag starts with the stored version', true, str_starts_with($full, '"' . $version . '.'));
pin('fields changes the ETag but not its version', [true, true], [$full !== $slim, str_starts_with($slim, '"' . $version . '.')]);
$r = $vkernel->handle(new Request('GET', 'v1/versioned/5', [], ['Authorization' => 'Bearer ' . $token, 'If-None-Match' => $full], '192.0.2.1', ''));
pin('If-None-Match with that ETag is 304', 304, $r->prepare('GET', $full)['status']);

$versions->log = [];
$r             = $vcall('PATCH', 'versioned/5', $slim, ['title' => 'two']);
pin('a PATCH with the ETag of a fields= GET goes through', [200, 'two', $writes + 1], [$r->status(), Things::$title, Things::$writes]);
pin('the version is read locked inside the transaction, then again after the write', ['begin', 'lock@1', 'read@1', 'commit'], $versions->log);
pin('the answer carries the new version', true, str_starts_with((string) $r->header('ETag'), '"v-' . md5('two') . '.'));
$r = $vcall('PATCH', 'versioned/5', (string) $r->header('ETag'), ['title' => 'three']);
pin('which the next PATCH can send', [200, 'three'], [$r->status(), Things::$title]);

$versions->log = [];
$r             = $vcall('PATCH', 'versioned/5', $full, ['title' => 'four']);
pin('a stale version is 412 and runs nothing', [412, 'precondition_failed', 'three', $writes + 2], [$r->status(), $r->body()['code'], Things::$title, Things::$writes]);
pin('and its transaction is rolled back', ['begin', 'lock@1', 'rollback'], $versions->log);
$r = $vcall('PATCH', 'versioned/5', 'W/"v-' . md5('three') . '"', ['title' => 'five']);
pin('a bare or weak tag of the current version matches', [200, 'five'], [$r->status(), Things::$title]);
$r = $vcall('PATCH', 'versioned/5', '*', ['title' => 'six']);
pin('* goes through', [200, 'six'], [$r->status(), Things::$title]);
$r = $vcall('PATCH', 'versioned/404', '"v-gone"', ['title' => 'seven']);
pin('a missing resource is left to the handler', [200, 'seven'], [$r->status(), Things::$title]);

$versions->log = [];
$logged        = ini_set('error_log', '/dev/null');
$r             = $vcall('DELETE', 'versioned/5', '*');
ini_set('error_log', (string) $logged);
pin('a write that throws is rolled back with its transaction', [500, null, ['begin', 'lock@1', 'rollback']], [$r->status(), Versions::$stored, $versions->log]);
$versions->log = [];
$vcall('PATCH', 'versioned/5', '', ['title' => 'eight']);
pin('no If-Match opens no transaction', [], $versions->log);

harness_section('no stored version');
$writes = Things::$writes;
$r      = $vcall('DELETE', 'hidden/5', '"anything"');
pin('a credential that cannot read the GET is 412, not let through', [412, $writes], [$r->status(), Things::$writes]);
$r = $vcall('DELETE', 'hidden/5');
pin('without If-Match it is not checked', [204, $writes + 1], [$r->status(), Things::$writes]);

harness_section('no existence leak');
Things::$title = 'one';
$writes        = Things::$writes;
$versions->log = [];
$r             = $vcall('PATCH', 'owned/7', '"stale"', ['title' => 'two']);
pin('a stale tag on a resource the caller cannot see answers as the GET does, not 412', [404, 'not_found', $writes, 'one'], [$r->status(), $r->body()['code'], Things::$writes, Things::$title]);
pin('and its transaction is rolled back', ['begin', 'lock@1', 'rollback'], $versions->log);
$r = $vcall('PATCH', 'owned/7', '', ['title' => 'two']);
pin('which is what the write answers without If-Match', [404, $writes], [$r->status(), Things::$writes]);
$r = $vcall('PATCH', 'owned/5', '"stale"', ['title' => 'two']);
pin('a stale tag on the caller\'s own resource is still 412', [412, 'one'], [$r->status(), Things::$title]);
$versions->log = [];
$r             = $vcall('DELETE', 'secret/5', '"stale"');
$star          = $vcall('DELETE', 'secret/5', '*');
pin('a credential that cannot read the GET is 412 before any version is read', [412, 412, $writes, []], [$r->status(), $star->status(), Things::$writes, $versions->log]);

exit(harness_result());
