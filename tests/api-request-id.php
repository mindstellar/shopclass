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
 * Every API answer carries a `Request-Id` header and an error's `instance` is
 * `urn:request:<id>`. A valid id from the client is reused; anything else is replaced.
 * Also: the OAuth password grant answers `unsupported_grant_type` while its switch is off.
 *
 * DB-free.  Usage: php tests/api-request-id.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ApiServices;
use mindstellar\api\ApiSettings;
use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialStore;
use mindstellar\api\auth\Scopes;
use mindstellar\api\auth\StoredKey;
use mindstellar\api\controller\AuthController;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\RequestId;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\utility\SystemClock;

if (!function_exists('osc_users_enabled')) {
    function osc_users_enabled()
    {
        return true;
    }
}

final class NoStoredKeys implements CredentialStore
{
    public function findByTokenId(string $tokenId): ?StoredKey
    {
        return null;
    }

    public function find(int $id): ?StoredKey
    {
        return null;
    }

    public function insert(StoredKey $key): int
    {
        return 1;
    }

    public function touch(int $id, string $ip, int $time): void
    {
    }

    public function revoke(int $id): bool
    {
        return false;
    }
}

$kernel = api_test_kernel(
    new Router(new Validator(), [
        'GET ping' => ['handler' => static fn (): Response => Response::ok(['pong' => true]), 'auth' => 'none'],
        'GET boom' => ['handler' => static function (): Response {
            throw new RuntimeException('secret detail');
        }, 'auth' => 'none'],
    ]),
    api_test_authenticator(new ApiKeys(new NoStoredKeys(), new Scopes(), new SystemClock()))
);
$call = static fn (string $path, array $headers = []): Response => $kernel->handle(new Request('GET', 'v1/' . $path, [], $headers, '192.0.2.1'));

harness_section('Request-Id on every answer');
$ok = $call('ping');
pin('a success has one', 1, preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $ok->header('Request-Id')));
check('a success has no problem instance', !isset($ok->body()['instance']));
check('each request gets its own', $call('ping')->header('Request-Id') !== $ok->header('Request-Id'));

$missing = $call('nothing');
pin('a 404 has one', 404, $missing->status());
pin('...and its instance is urn:request:<id>', 'urn:request:' . $missing->header('Request-Id'), $missing->body()['instance']);

$logged = [];
$prev   = ini_set('error_log', $file = tempnam(sys_get_temp_dir(), 'apilog'));
$boom   = $call('boom');
ini_set('error_log', (string) $prev);
$line = (string) file_get_contents($file);
unlink($file);
pin('a 500 is a problem with an instance and a header', [500, 'urn:request:' . $boom->header('Request-Id')], [$boom->status(), $boom->body()['instance']]);
check('...and its log line names the request id', str_contains($line, '(request ' . $boom->header('Request-Id') . ')'));
check('...but the response does not leak the exception', !str_contains((string) json_encode($boom->body()), 'secret detail'));
pin('an OPTIONS preflight has one too', true, $kernel->handle(new Request('OPTIONS', 'v1/ping', [], [], '192.0.2.1'))->header('Request-Id') !== null);

harness_section('A client\'s id is reused when it is valid');
pin('Request-Id', 'abc-12345_x.y', $call('ping', ['Request-Id' => 'abc-12345_x.y'])->header('Request-Id'));
pin('X-Request-Id', 'abc-12345', $call('ping', ['X-Request-Id' => 'abc-12345'])->header('Request-Id'));
pin('Request-Id wins over X-Request-Id', 'first-one-1', $call('ping', ['Request-Id' => 'first-one-1', 'X-Request-Id' => 'second-one-2'])->header('Request-Id'));
$bad = ['short', str_repeat('a', 65), "has space1", "new\nline-123", 'semi;colon1', 'slash/slash1', ''];
foreach ($bad as $value) {
    $got = $call('ping', ['Request-Id' => $value])->header('Request-Id');
    check('"' . addcslashes(substr($value, 0, 20), "\n") . '" is replaced', $got !== $value && preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $got) === 1);
}
pin('a bad Request-Id falls back to a good X-Request-Id', 'good-id-123', $call('ping', ['Request-Id' => 'bad id', 'X-Request-Id' => 'good-id-123'])->header('Request-Id'));
pin('the error instance uses the client\'s id', 'urn:request:client-id-9', $call('nothing', ['Request-Id' => 'client-id-9'])->body()['instance']);
check('a generated id is short and URL-safe', strlen(RequestId::for(new Request('GET', 'v1/ping'))) <= 16);

harness_section('The password grant switch');
$services = static fn (ApiSettings $s): ApiServices => new ApiServices(
    $s,
    new Scopes(),
    new \mindstellar\model\ApiCredential(),
    api_test_users(),
    new SystemClock(),
    api_test_limiter()
);
$token = static function (ApiSettings $s, string $grant) use ($services): ?array {
    $controller = new AuthController($services($s));
    try {
        $controller->token(new Request('POST', 'v1/auth/token', [], ['Content-Type' => 'application/json'], '192.0.2.1', json_encode(['grant_type' => $grant])), Credential::anonymous(), []);
    } catch (ProblemException $e) {
        return [$e->response()->status(), $e->response()->body()['error'] ?? $e->response()->body()['code'] ?? null];
    }

    return null;
};
pin('the switch is on by default', true, (new ApiSettings())->passwordGrant());
pin('off: the password grant is unsupported_grant_type', [400, 'unsupported_grant_type'], $token(new ApiSettings(true, passwordGrant: false), 'password'));
$on = $token(new ApiSettings(true), 'password');
pin('on: it gets past the grant check and asks for the credentials', 'invalid_request', $on[1] ?? null);
pin('an unknown grant is unsupported_grant_type either way', [400, 'unsupported_grant_type'], $token(new ApiSettings(true), 'implicit'));

exit(harness_result());
