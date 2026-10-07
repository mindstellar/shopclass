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
 * The same-site session mode: theme JavaScript calls the API as the signed-in web user with
 * the sign-in cookie plus a page token in X-Shopclass-Token. Pins each guard: the page token
 * (bound to the user and password, expiring, header only), the site's own origin, the user
 * checks, user scopes without account:write or admin, and no CORS grant or stored answer.
 *
 * DB-free: users live in an array.  Usage: php tests/api-session.php
 */

define('OSC_CSRF_SECRET', 'api-session-test-secret');
define('WEB_PATH', 'https://shop.example.test/sub/');

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';

use mindstellar\api\ApiCall;
use mindstellar\api\auth\PageTokenAuth;
use mindstellar\api\controller\PageTokenController;
use mindstellar\api\http\Cors;
use mindstellar\api\http\SiteOrigin;
use mindstellar\api\identity\SignInCookie;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\Kernel;
use mindstellar\api\ratelimit\RatePolicy;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\RouteSpec;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Validator;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\apiaccess\CredentialStore;
use mindstellar\apiaccess\PageTokens;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\StoredKey;
use mindstellar\security\RememberMe;
use mindstellar\utility\SystemClock;

/** No stored keys at all. */
final class NoKeys implements CredentialStore
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
        return 0;
    }

    public function touch(int $id, string $ip, int $time): void
    {
    }

    public function revoke(int $id): bool
    {
        return false;
    }
}

$user  = static fn (int $id, string $email, int $enabled = 1, int $active = 1): array => [
    'pk_i_id' => (string) $id, 's_name' => 'User ' . $id, 's_email' => $email, 's_password' => '$2y$12$hash' . $id,
    's_phone_mobile' => '', 's_phone_land' => '', 'b_enabled' => (string) $enabled, 'b_active' => (string) $active,
];
$rows = [
    10 => $user(10, 'uma@x.test'),
    11 => $user(11, 'otto@x.test'),
    12 => $user(12, 'sue@x.test', 0),
    13 => $user(13, 'banned@x.test'),
];
$accounts = static function () use (&$rows) {
    return api_test_users($rows);
};

$scopes  = new Scopes(['ext:acme:offers:write' => ['description' => 'Make offers.', 'audience' => 'user']]);
$tokens  = new PageTokens();
$cookie  = static fn (int $id): SignInCookie => new SignInCookie((string) $id, RememberMe::issue('web', $id, $rows[$id]['s_password'], 3600));
$page    = static fn (int $id): string => $tokens->issue($rows[$id])->token();
$site    = 'https://shop.example.test';
$session = static fn (array $headers = [], ?SignInCookie $signIn = null, string $method = 'GET', string $path = 'v1/me', array $query = [], string $body = ''): Request
    => new Request($method, $path, $query, $headers, '192.0.2.10', $body, signIn: $signIn);

$calls  = 0;
$routes = [
    'GET me'       => ['handler' => static fn (ApiCall $call): Response => Response::ok([
        'user' => osc_logged_user_id(), 'kind' => $call->credential()->kind(), 'scopes' => $call->credential()->scopes(),
    ]), 'auth' => 'public', 'scope' => 'listings:read'],
    'POST notes'   => ['handler' => static function (ApiCall $call) use (&$calls): Response {
        $calls++;

        return Response::ok(['calls' => $calls, 'user' => $call->credential()->userId()], 201);
    }, 'auth' => 'user', 'scope' => 'listings:write'],
    'POST secret'  => ['handler' => static fn (): Response => Response::ok(['done' => true]), 'auth' => 'user', 'scope' => 'account:write'],
    'GET admin/x'  => ['handler' => static fn (): Response => Response::ok(['admin' => true]), 'auth' => 'admin', 'scope' => 'admin:users'],
    'GET auth/session' => [
        'handler' => static fn (ApiCall $call): Response => (new PageTokenController(new \mindstellar\api\ApiServices(new ApiSettings(true), $scopes, new \mindstellar\model\ApiCredential(), $accounts(), new SystemClock(), api_test_limiter())))->show($call),
        'auth'    => 'user',
        'scope'   => 'account:read',
    ],
];

$kernelFor = static function (array $settings = [], ?PageTokens $with = null, ?callable $banned = null) use ($routes, $scopes, $tokens, $accounts): Kernel {
    $users = $accounts();
    $auth  = new PageTokenAuth(
        $with ?? $tokens,
        $users,
        $scopes,
        new SiteOrigin(WEB_PATH),
        $banned ?? static fn (array $u, string $ip): bool => $u['s_email'] === 'banned@x.test'
    );

    return api_test_kernel(
        new Router(new Validator(), $routes),
        api_test_authenticator(new ApiKeys(new NoKeys(), $scopes, new SystemClock()), tokens: api_test_access_tokens($scopes, $users), session: $auth),
        new ApiSettings(...($settings + ['enabled' => true, 'publicReads' => true])),
        users: $users
    );
};
$kernel = $kernelFor();
$same   = ['Origin' => $site];
$ok     = static fn (int $id) => ['Origin' => $site, 'X-Shopclass-Token' => $page($id)];
$code   = static fn (Response $r): array => [$r->status(), $r->body()['code'] ?? null];
$reset  = static function (): void {
    WebIdentity::forget();
};

harness_section('guard 1: the page token');
$reset();
$r = $kernel->handle($session($same, $cookie(10)));
pin('the sign-in cookie alone stays anonymous', [200, 0, CredentialKind::ANONYMOUS], [$r->status(), $r->body()['data']['user'], $r->body()['data']['kind']]);
$reset();
$r = $kernel->handle($session($ok(10), $cookie(10)));
pin('cookie plus a valid page token is that user', [200, 10, CredentialKind::SESSION], [$r->status(), $r->body()['data']['user'], $r->body()['data']['kind']]);
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'X-Shopclass-Token' => $page(11)], $cookie(10)));
pin('another user\'s page token is refused', [401, 'session_required'], $code($r));
$reset();
$r = $kernel->handle($session($ok(10)));
pin('a page token with no sign-in cookie is refused', [401, 'session_required'], $code($r));
$reset();
$r = $kernel->handle($session($ok(10), new SignInCookie('10', 'forged.' . str_repeat('0', 64))));
pin('a forged sign-in cookie is refused', [401, 'session_required'], $code($r));
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'X-Shopclass-Token' => 'scs_garbage.sig'], $cookie(10)));
pin('a forged page token is refused', [401, 'session_required'], $code($r));
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'X-Shopclass-Token' => (new PageTokens(-10))->issue($rows[10])->token()], $cookie(10)));
pin('an expired page token answers token_expired', [401, 'token_expired'], $code($r));
$accessToken = (api_test_access_tokens($scopes, $accounts()))->issue($rows[10], ['listings:read'], 'fam');
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'X-Shopclass-Token' => substr_replace($accessToken, 'scs_', 0, 4)], $cookie(10)));
pin('an access token\'s payload is not a page token', [401, 'session_required'], $code($r));

$before = $page(10);
$kept   = $rows[10]['s_password'];
$rows[10]['s_password'] = '$2y$12$rehashed';
pin('a new hash alone (a rehash) leaves the page token working', PageTokens::VALID, $tokens->check($before, $rows[10]));
$rows[10]['s_password']   = $kept;
$rows[10]['i_auth_stamp'] = 1;
pin('a raised sign-out stamp (a password change, or signing out everywhere) ends the page token', PageTokens::REFUSED, $tokens->check($before, $rows[10]));
$reset();
$r = $kernelFor()->handle($session(['Origin' => $site, 'X-Shopclass-Token' => $before], $cookie(10)));
pin('and the call made with it', [401, 'session_required'], $code($r));
unset($rows[10]['i_auth_stamp']);
$twin                = $rows[10];
$twin['pk_i_id']     = '11';
pin('a token names its user, not only a stamp fingerprint', PageTokens::REFUSED, $tokens->check($before, $twin));
pin('the token checks the right user and password', [PageTokens::VALID, PageTokens::REFUSED], [$tokens->check($before, $rows[10]), $tokens->check($before, $rows[11])]);

$reset();
$r = $kernel->handle($session(['Origin' => $site], $cookie(10), 'GET', 'v1/me', ['X-Shopclass-Token' => $page(10), 'token' => $page(10)]));
pin('a page token in the query string is ignored', [200, 0], [$r->status(), $r->body()['data']['user']]);
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'Content-Type' => 'application/json'], $cookie(10), 'POST', 'v1/notes', [], (string) json_encode(['X-Shopclass-Token' => $page(10), 'token' => $page(10)])));
pin('a page token in the body is ignored', [401, 'unauthorized'], $code($r));
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'X-Shopclass-Token' => $page(10), 'Authorization' => 'Bearer ' . $accessToken], $cookie(11)));
pin('a Bearer token wins over a page token', [200, 10, CredentialKind::USER], [$r->status(), $r->body()['data']['user'], $r->body()['data']['kind']]);

harness_section('guard 2: the site\'s own origin');
$call = static function (array $headers, string $method = 'GET') use ($kernel, $session, $cookie, $page, $reset): array {
    $reset();
    $path = $method === 'GET' ? 'v1/me' : 'v1/notes';
    $r    = $kernel->handle($session($headers + ['X-Shopclass-Token' => $page(10)], $cookie(10), $method, $path));

    return [$r->status(), $r->body()['code'] ?? null];
};
pin('another site\'s Origin is refused', [403, 'cross_origin'], $call(['Origin' => 'https://evil.test']));
pin('plain http when the site is https is refused', [403, 'cross_origin'], $call(['Origin' => 'http://shop.example.test']));
pin('another port is refused', [403, 'cross_origin'], $call(['Origin' => 'https://shop.example.test:8443']));
pin('a subdomain is refused', [403, 'cross_origin'], $call(['Origin' => 'https://evil.shop.example.test']));
pin('an opaque origin is refused', [403, 'cross_origin'], $call(['Origin' => 'null']));
pin('an Origin with a path is refused', [403, 'cross_origin'], $call(['Origin' => 'https://shop.example.test/sub']));
pin('the site origin is accepted, whatever case and default port, for a site in a subdirectory', [200, null], $call(['Origin' => 'HTTPS://Shop.Example.Test:443']));
pin('no Origin and Sec-Fetch-Site cross-site is refused', [403, 'cross_origin'], $call(['Sec-Fetch-Site' => 'cross-site']));
pin('no Origin and Sec-Fetch-Site same-site is refused', [403, 'cross_origin'], $call(['Sec-Fetch-Site' => 'same-site']));
pin('a same Origin with Sec-Fetch-Site cross-site is refused', [403, 'cross_origin'], $call(['Origin' => $site, 'Sec-Fetch-Site' => 'cross-site']));
pin('no Origin and Sec-Fetch-Site same-origin is accepted', [200, null], $call(['Sec-Fetch-Site' => 'same-origin']));
pin('a GET with only a Referer from the site is accepted', [200, null], $call(['Referer' => 'https://shop.example.test/sub/item/1']));
pin('a GET with a Referer from another site is refused', [403, 'cross_origin'], $call(['Referer' => 'https://evil.test/page']));
pin('a GET that shows nothing is refused', [403, 'cross_origin'], $call([]));
pin('a write with only a Referer is refused', [403, 'cross_origin'], $call(['Referer' => 'https://shop.example.test/sub/'], 'POST'));
pin('a write from the site is accepted', [201, null], $call(['Origin' => $site, 'Content-Type' => 'application/json'], 'POST'));
pin('the site origin drops the subdirectory and default port', ['https://shop.example.test', 'http://a.test:8080', null], [
    (new SiteOrigin('https://shop.example.test:443/sub/'))->origin(), SiteOrigin::of('http://A.test:8080/x'), SiteOrigin::of('ftp://a.test'),
]);
pin('a site with no usable base URL accepts nobody', false, (new SiteOrigin(''))->matches($session(['Origin' => ''])));

harness_section('guard 3: SameSite on the cookies this mode reads');
$cookieSource  = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/Cookie.php');
$sessionSource = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/Session.php');
check('the sign-in cookie container is SameSite=Lax and HttpOnly', str_contains($cookieSource, "'samesite' => 'Lax'") && str_contains($cookieSource, "'httponly' => true"));
check('the PHP session cookie is SameSite=Lax too', substr_count($sessionSource, "'samesite' => 'Lax'") >= 1);
$_COOKIE = [];
$jar     = Cookie::getInstance();
$jar->clear();
$jar->push('oc_userId', '10');
$jar->push('oc_userSecret', 'signed');
$jar->push('oc_adminId', '1');
$jar->push('oc_adminSecret', 'admin-signed');
WebIdentity::forget();
pin('forget() keeps only the user sign-in cookie aside', ['10', 'signed'], [WebIdentity::signInCookie()?->rawUserId(), WebIdentity::signInCookie()?->secret()]);
pin('and still drops every identity cookie from the request', [[], '', ''], [
    array_values(array_intersect(array_keys($_COOKIE), WebIdentity::COOKIES)), $jar->get_value('oc_userId'), $jar->get_value('oc_adminId'),
]);
$jar->push('oc_adminId', '1');
$jar->push('oc_adminSecret', 'admin-signed');
WebIdentity::forget();
pin('an admin cookie is never kept', null, WebIdentity::signInCookie());
pin('a half cookie or a non-numeric id is no cookie', [null, null], [SignInCookie::from(['oc_userId' => '10']), SignInCookie::from(['oc_userId' => '1 OR 1', 'oc_userSecret' => 's'])]);

harness_section('guard 4: user scopes, users who may sign in');
$reset();
$r = $kernel->handle($session($ok(10), $cookie(10)));
$held = $r->body()['data']['scopes'];
pin('a session holds the user scopes without account:write, plus plugin user scopes', [
    'listings:read', 'listings:write', 'listings:delete', 'comments:write', 'alerts:write', 'account:read', 'ext:acme:offers:write',
], $held);
$reset();
$r = $kernel->handle($session($ok(10) + ['Content-Type' => 'application/json'], $cookie(10), 'POST', 'v1/secret'));
pin('an account:write route is refused', [403, 'insufficient_scope'], $code($r));
$reset();
$r = $kernel->handle($session($ok(10), $cookie(10), 'GET', 'v1/admin/x'));
pin('an admin route is refused', [403, 'wrong_credential'], $code($r));
$reset();
$r = $kernel->handle($session(['Origin' => $site, 'X-Shopclass-Token' => 'scs_x'], null, 'GET', 'v1/admin/x'));
pin('an admin web cookie gives nothing: no user cookie, no session', [401, 'session_required'], $code($r));
$reset();
$r = $kernel->handle($session($ok(12), $cookie(12)));
pin('a suspended user is refused', [401, 'session_required'], $code($r));
$rows[12]['b_enabled'] = '1';
$rows[12]['b_active']  = '0';
$reset();
$r = $kernelFor()->handle($session($ok(12), $cookie(12)));
pin('an unconfirmed user is refused', [401, 'session_required'], $code($r));
$reset();
$r = $kernel->handle($session($ok(13), $cookie(13)));
pin('a banned user is refused', [403, 'banned'], $code($r));
$reset();
$r = $kernel->handle($session($ok(10) + ['Content-Type' => 'application/json'], $cookie(10), 'POST', 'v1/notes'));
pin('core actions run as the session user', [201, 10, 10], [$r->status(), $r->body()['data']['user'], osc_logged_user_id()]);
$bucket = (new RatePolicy(new ApiSettings(true)))->bucketsFor(
    $session(),
    new RouteSpec('GET', 'me', $routes['GET me']),
    new Credential(CredentialKind::SESSION, [], 10)
)[0];
pin('a session counts in the user\'s bucket, shared with their tokens', ['api_user', '10'], [$bucket->name(), $bucket->key()]);

harness_section('guard 5: no CORS, never stored');
$cors = $kernelFor(['corsOrigins' => "*\n" . $site]);
$reset();
$r = $cors->handle($session($ok(10), $cookie(10)));
pin('a session answer has no CORS grant, though * and the site are listed', [200, null, null], [
    $r->status(), $r->header('Access-Control-Allow-Origin'), $r->header('Access-Control-Expose-Headers'),
]);
pin('it is never stored', 'private, no-store', $r->header('Cache-Control'));
pin('it varies on the cookie', 'Authorization, X-Shopclass-Token, Cookie, Origin', $r->header('Vary'));
pin('no rate headers are dropped from it', true, $r->header('RateLimit-Policy') !== null);
$reset();
$r = $cors->handle($session(['Origin' => 'https://evil.test', 'X-Shopclass-Token' => $page(10)], $cookie(10)));
pin('a refused cross-origin session call gets no CORS grant either', [403, null], [$r->status(), $r->header('Access-Control-Allow-Origin')]);
$reset();
$r = $cors->handle($session(['Origin' => 'https://evil.test'], $cookie(10)));
pin('while a cookie-only anonymous read still gets *', '*', $r->header('Access-Control-Allow-Origin'));
$preflight = $cors->handle(new Request('OPTIONS', 'v1/me', [], [
    'Origin' => 'https://evil.test', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'x-shopclass-token',
]));
check('a preflight never allows the page token header', !str_contains(strtolower((string) $preflight->header('Access-Control-Allow-Headers')), 'x-shopclass-token'));
check('nor does the allowed header list', !in_array(PageTokens::HEADER, Cors::ALLOW_HEADERS, true));
$headers = array_change_key_case($r->headers(), CASE_LOWER);
check('no answer sets a cookie', !isset($headers['set-cookie']));
pin('and a handler cannot add one', null, Response::ok([])->withHeader('Set-Cookie', 'a=b')->header('Set-Cookie'));

harness_section('Idempotency-Key per user and session');
$calls = 0;
$write = static fn (array $headers): Request => $session($headers + ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k-1'], $cookie(10), 'POST', 'v1/notes');
$reset();
$first = $kernel->handle($write($ok(10)));
$reset();
$again = $kernel->handle($write($ok(10)));
pin('the same key from the same session replays', [1, 1, 'true'], [$first->body()['data']['calls'], $again->body()['data']['calls'], $again->header('Idempotency-Replayed')]);
$reset();
$token = $kernel->handle($write(['Authorization' => 'Bearer ' . (api_test_access_tokens($scopes, $accounts()))->issue($rows[10], ['listings:write'], 'fam')]));
$reset();
$other = $kernel->handle($session($ok(11) + ['Content-Type' => 'application/json', 'Idempotency-Key' => 'k-1'], $cookie(11), 'POST', 'v1/notes'));
pin('the same key from another user\'s session is its own', [11, null], [$other->body()['data']['user'], $other->header('Idempotency-Replayed')]);
pin('the same key from the same user\'s access token is its own', [2, null], [$token->body()['data']['calls'], $token->header('Idempotency-Replayed')]);

harness_section('GET /auth/session');
$reset();
$r = $kernel->handle($session($ok(10), $cookie(10), 'GET', 'v1/auth/session'));
$fresh = $r->body()['data']['token'] ?? '';
pin('a session gets a fresh page token', [200, PageTokens::HEADER, true, 'private, no-store'], [
    $r->status(), $r->body()['data']['header'] ?? null, str_starts_with($fresh, PageTokens::PREFIX), $r->header('Cache-Control'),
]);
pin('which works', PageTokens::VALID, $tokens->check($fresh, $rows[10]));
$reset();
$r = $kernel->handle($session(['Authorization' => 'Bearer ' . (api_test_access_tokens($scopes, $accounts()))->issue($rows[10], ['account:read'], 'fam')], null, 'GET', 'v1/auth/session'));
pin('an access token cannot get one', [403, 'wrong_credential'], $code($r));

exit(harness_result());
