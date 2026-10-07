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
 * API keys and authentication: key lifecycle, scopes, the failure counter, rate limit buckets and request validation.
 * Usage: php tests/api-auth.php
 */

require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\ApiCall;
use mindstellar\api\auth\FailureCounter;
use mindstellar\api\Kernel;
use mindstellar\api\ProblemException;
use mindstellar\api\ratelimit\RateLimiter;
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
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\apiaccess\StoredKey;
use mindstellar\security\AddressBucket;

/** t_api_credential as an array; owners as admin id => moderator flag and enabled user ids. */
final class ArrayStore implements CredentialStore
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public array $admins = [1 => false, 2 => true];
    public array $users = [10 => true];
    public int $touches = 0;
    public int $lookups = 0;

    public function findByTokenId(string $tokenId): ?StoredKey
    {
        $this->lookups++;
        foreach ($this->rows as $id => $row) {
            if ($row['tokenId'] === $tokenId) {
                return $this->key($id);
            }
        }

        return null;
    }

    public function find(int $id): ?StoredKey
    {
        return isset($this->rows[$id]) ? $this->key($id) : null;
    }

    public function insert(StoredKey $key): int
    {
        $id              = count($this->rows) + 1;
        $this->rows[$id] = [
            'kind' => $key->kind(), 'tokenId' => $key->tokenId(), 'hash' => $key->secretHash(), 'name' => $key->name(),
            'scopes' => $key->scopes(), 'adminId' => $key->owner()->adminId(), 'userId' => $key->owner()->userId(),
            'rate' => $key->rateLimit(), 'enabled' => true, 'expires' => $key->expiresAt(), 'revoked' => null,
            'lastUsed' => null, 'ip' => '',
        ];

        return $id;
    }

    public function touch(int $id, string $ip, int $time): void
    {
        $this->touches++;
        $this->rows[$id]['lastUsed'] = $time;
        $this->rows[$id]['ip']       = $ip;
    }

    public function revoke(int $id): bool
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]['revoked'] !== null) {
            return false;
        }
        $this->rows[$id]['revoked'] = time();
        $this->rows[$id]['enabled'] = false;

        return true;
    }

    private function key(int $id): StoredKey
    {
        $r     = $this->rows[$id];
        $owner = null;
        if ($r['adminId'] !== null && isset($this->admins[$r['adminId']])) {
            $owner = KeyOwner::admin($r['adminId'], $this->admins[$r['adminId']]);
        } elseif ($r['userId'] !== null && !empty($this->users[$r['userId']])) {
            $owner = KeyOwner::user($r['userId']);
        }

        return new StoredKey($id, $r['kind'], $r['tokenId'], $r['hash'], $r['name'], $r['scopes'], $owner, $r['rate'], $r['enabled'], $r['expires'], $r['revoked'], $r['lastUsed']);
    }
}

$now   = 1_800_000_000;
$clock = new TestClock(static function () use (&$now): int {
    return $now;
});
$plugin = new Scopes([
    'ext:acme:offers:read'  => ['description' => 'Read offers.', 'audience' => Scopes::AUDIENCE_USER],
    'ext:acme:moderate'     => ['description' => 'Moderate offers.', 'audience' => Scopes::AUDIENCE_MODERATOR],
    'ext:acme:settings'     => 'Change settings.',
    'ext:acme:bad-audience' => ['audience' => 'everyone'],
    'acme:write'            => 'Not namespaced.',
]);
$store = new ArrayStore();
$keys  = new ApiKeys($store, $plugin, $clock);
$admin1 = KeyOwner::admin(1);
$mod2   = KeyOwner::admin(2, true);
$user10 = KeyOwner::user(10);

$threw = static function (callable $fn): bool {
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return true;
    }

    return false;
};

harness_section('making keys');
$admin = $keys->create(CredentialKind::KEY, 'CI', ['admin:listings', 'admin:users', 'account:write', 'nonsense'], $admin1);
check('an admin key is sck_<16>.<64 hex>', preg_match('/^sck_[0-9A-Za-z]{16}\.[0-9a-f]{64}$/D', $admin->token()) === 1);
$secret = explode('.', $admin->token())[1];
pin('the secret is stored as its sha256', hash('sha256', $secret), $store->rows[$admin->id()]['hash']);
check('the token is not stored anywhere', !str_contains((string) json_encode($store->rows), $secret));
pin('scopes the key may not hold are dropped', ['admin:listings', 'admin:users'], $admin->scopes());

$public = $keys->create(CredentialKind::PUBLIC, 'App', ['listings:read', 'listings:write', 'admin:users', 'ext:acme:offers:read'], $admin1);
check('a public key is scp_...', str_starts_with($public->token(), 'scp_'));
pin('a public key holds only the public read scope, whatever it asks for', [Scopes::PUBLIC_READ], $public->scopes());

$user = $keys->create(CredentialKind::KEY, 'Laptop', ['listings:write', 'account:write', 'admin:users'], $user10);
pin('a user key never holds account:write or admin scopes', ['listings:write'], $user->scopes());
$mod = $keys->create(CredentialKind::KEY, 'Mod', ['admin:listings', 'admin:users'], $mod2);
pin('a moderator key holds only the moderator set', ['admin:listings'], $mod->scopes());

check('a user cannot make a public key', $threw(static fn () => $keys->create(CredentialKind::PUBLIC, 'x', ['listings:read'], $user10)));
check('a key with no scope it may hold is refused', $threw(static fn () => $keys->create(CredentialKind::KEY, 'x', ['account:write'], $user10)));
check('an unknown kind is refused', $threw(static fn () => $keys->create(CredentialKind::REFRESH, 'x', ['listings:read'], $admin1)));

harness_section('plugin scopes and their audience');
$all = $plugin->all();
check('a declared ext:<slug>: scope is listed', isset($all['ext:acme:offers:read'], $all['ext:acme:settings']));
check('a scope outside ext: or with an unknown audience is dropped', !isset($all['acme:write']) && !isset($all['ext:acme:bad-audience']));
pin('a full admin may hold every plugin scope', ['ext:acme:offers:read', 'ext:acme:moderate', 'ext:acme:settings'], array_values(array_intersect(
    ['ext:acme:offers:read', 'ext:acme:moderate', 'ext:acme:settings'],
    $plugin->allowedFor(CredentialKind::KEY, $admin1)
)));
pin('a moderator only moderator and user ones', ['ext:acme:offers:read', 'ext:acme:moderate'], $keys->create(CredentialKind::KEY, 'M', ['ext:acme:offers:read', 'ext:acme:moderate', 'ext:acme:settings'], $mod2)->scopes());
pin('a user only user ones', ['ext:acme:offers:read'], $keys->create(CredentialKind::KEY, 'U', ['ext:acme:offers:read', 'ext:acme:moderate', 'ext:acme:settings'], $user10)->scopes());
pin('an undeclared audience defaults to admin only', [], array_values(array_intersect(['ext:acme:settings'], $plugin->allowedFor(CredentialKind::KEY, $mod2))));
$withExt = $keys->create(CredentialKind::KEY, 'Ext', ['ext:acme:settings', 'admin:listings'], KeyOwner::admin(3));
$store->admins[3] = true;
pin('demoting the admin takes the admin-only plugin scope away at once', ['admin:listings'], $keys->verify($withExt->token())->scopes());
check('Scopes::fromHooks reads api_scopes', (bool) api_with_filter('api_scopes', static fn (array $s): array => $s + ['ext:hook:x' => 'From a hook.'], static fn () => isset(Scopes::fromHooks()->all()['ext:hook:x'])));
check('implies: write reads, any admin scope reads public data, nothing else', Scopes::implies(['listings:write'], 'listings:read')
    && Scopes::implies(['admin:keys'], 'listings:read') && !Scopes::implies(['listings:read'], 'listings:write')
    && !Scopes::implies(['admin:listings'], 'admin:users'));

harness_section('moderators follow moderator_access');
pin('a moderator holds the moderator scopes by default', ['admin:listings', 'admin:comments'], array_values(array_intersect(Scopes::ADMIN, (new Scopes())->allowedFor(CredentialKind::KEY, $mod2))));
pin('a page closed to moderators takes its scope away', ['admin:comments'], array_values(array_intersect(Scopes::ADMIN, (new Scopes([], ['comments']))->allowedFor(CredentialKind::KEY, $mod2))));
pin('a page opened to moderators does not add an admin scope', ['admin:listings', 'admin:comments'], array_values(array_intersect(Scopes::ADMIN, (new Scopes([], ['items', 'comments', 'users', 'settings']))->allowedFor(CredentialKind::KEY, $mod2))));
pin('a full admin is not affected', Scopes::ADMIN, array_values(array_intersect(Scopes::ADMIN, (new Scopes([], []))->allowedFor(CredentialKind::KEY, $admin1))));
pin('Scopes::fromHooks reads moderator_access', ['admin:listings'], api_with_filter(
    'moderator_access',
    static fn (array $pages): array => array_values(array_diff($pages, ['comments'])),
    static fn (): array => array_values(array_intersect(Scopes::ADMIN, Scopes::fromHooks()->allowedFor(CredentialKind::KEY, $mod2)))
));

harness_section('verifying keys');
$c = $keys->verify($admin->token(), '10.0.0.9');
pin('a good admin key gives an admin key credential', [CredentialKind::KEY, 1, null, ['admin:listings', 'admin:users']], [$c->kind(), $c->adminId(), $c->userId(), $c->scopes()]);
check('an admin key reads public data', $c->has('listings:read'));
pin('its last use is written', ['10.0.0.9', $now], [$store->rows[$admin->id()]['ip'], $store->rows[$admin->id()]['lastUsed']]);
$touches = $store->touches;
$keys->verify($admin->token(), '10.0.0.9');
pin('a key used again within five minutes is not touched again', $touches, $store->touches);
$now += 301;
$keys->verify($admin->token(), '10.0.0.9');
pin('a key used after five minutes is touched again', $touches + 1, $store->touches);

$pc = $keys->verify($public->token());
pin('a public key gives a public credential, never an admin one', [CredentialKind::PUBLIC, ['listings:read'], false], [$pc->kind(), $pc->scopes(), $pc->isAdmin()]);
$uc = $keys->verify($user->token());
pin('a user key acts for its user', [CredentialKind::KEY, 10, null, true], [$uc->kind(), $uc->userId(), $uc->adminId(), $uc->isUser()]);

[$id, $sec] = explode('.', substr($admin->token(), 4));
pin('a wrong secret is refused', null, $keys->verify('sck_' . $id . '.' . str_repeat('0', 64)));
pin('an unknown key id is refused', null, $keys->verify('sck_' . str_repeat('A', 16) . '.' . $sec));
pin('the public prefix on an admin key is refused', null, $keys->verify('scp_' . $id . '.' . $sec));
pin('a malformed token is refused', null, $keys->verify('sck_' . $id . '.' . strtoupper($sec)));
pin('a trailing newline is refused', null, $keys->verify($admin->token() . "\n"));

$exp = $keys->create(CredentialKind::KEY, 'Short', ['admin:listings'], $admin1, $now + 60);
check('a key works until it expires', $keys->verify($exp->token()) !== null);
$now += 61;
pin('a key is refused after it expires', null, $keys->verify($exp->token()));

$store->admins[2] = false;
pin('a moderator promoted to admin keeps the scopes the key was made with', ['admin:listings'], $keys->verify($mod->token())->scopes());
$demote = $keys->create(CredentialKind::KEY, 'Full', ['admin:listings', 'admin:users'], KeyOwner::admin(2));
$store->admins[2] = true;
pin('an admin demoted to moderator loses the rest at once', ['admin:listings'], $keys->verify($demote->token())->scopes());
unset($store->admins[2]);
pin('a deleted admin\'s key is dead', null, $keys->verify($demote->token()));
$store->users[10] = false;
pin('a disabled user\'s key is dead', null, $keys->verify($user->token()));
$store->users[10] = true;
$store->admins[2] = true;

harness_section('rotate and revoke');
$new = $keys->rotate($admin->id());
check('rotation makes a new token', $new !== null && $new->token() !== $admin->token());
pin('with the same name and scopes', ['CI', ['admin:listings', 'admin:users']], [$store->rows[$new->id()]['name'], $store->rows[$new->id()]['scopes']]);
check('the old key still works until revoked', $keys->verify($admin->token()) !== null && $keys->verify($new->token()) !== null);
check('revoking a live key reports a change', $keys->revoke($admin->id()));
check('revoking an already revoked key reports no change', !$keys->revoke($admin->id()));
pin('a revoked key is refused', null, $keys->verify($admin->token()));
pin('a revoked key cannot be rotated', null, $keys->rotate($admin->id()));
pin('nor can an expired one', null, $keys->rotate($exp->id()));
$store->rows[$withExt->id()]['enabled'] = false;
pin('nor a disabled one', null, $keys->rotate($withExt->id()));
pin('a disabled key is refused', null, $keys->verify($withExt->token()));
unset($store->admins[2]);
pin('nor one whose owner is gone', null, $keys->rotate($mod->id()));
$store->admins[2] = true;
pin('rotating a missing key gives null', null, $keys->rotate(999));
check('the new one still works', $keys->verify($new->token()) !== null);

harness_section('the Authenticator');
$fails    = [];
$failures = new FailureCounter(
    static function (string $c, array $keys, int $w) use (&$fails): ?array {
        return array_combine($keys, array_map(static fn (string $k): int => $fails[$k] ?? 0, $keys));
    },
    static function (string $c, string $k, int $w) use (&$fails): ?int {
        return $fails[$k] = ($fails[$k] ?? 0) + 1;
    }
);
$auth = api_test_authenticator($keys, $failures);
$req  = static fn (string $authz, array $query = [], string $method = 'GET', array $headers = [], string $ip = '203.0.113.5'): Request
    => new Request($method, 'v1/x', $query, ($authz === '' ? [] : ['Authorization' => $authz]) + $headers, $ip);
$problem = static function (callable $fn): ?Response {
    try {
        $fn();
    } catch (ProblemException $e) {
        return $e->response();
    }

    return null;
};

pin('no token is no credential', null, $auth->authenticate($req('')));
pin('a cookie never authenticates', null, $auth->authenticate($req('', [], 'GET', ['Cookie' => 'oc_userId=10; oc_userSecret=x; osclass=abc'])));
check('a good key authenticates', $auth->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
check('the scheme is case-insensitive', $auth->authenticate($req('bearer ' . $new->token())) instanceof Credential);
check('a public key works as ?api_key= on a GET', $auth->authenticate($req('', ['api_key' => $public->token()])) instanceof Credential);
pin('an admin key is refused as ?api_key=', null, $auth->authenticate($req('', ['api_key' => $new->token()])));
pin('nor a public key on a POST', null, $auth->authenticate($req('', ['api_key' => $public->token()], 'POST')));
pin('a Basic header is not a token', null, $auth->authenticate($req('Basic dXNlcjpwYXNz')));
check('with Basic auth in front, ?api_key= still works', $auth->authenticate($req('Basic dXNlcjpwYXNz', ['api_key' => $public->token()])) instanceof Credential);
pin('none of that counted a failure', [], $fails);

$bodies = [];
foreach (['Bearer sck_' . str_repeat('A', 16) . '.' . str_repeat('a', 64), 'Bearer ' . $admin->token(), 'Bearer nonsense', 'Bearer sca_whatever'] as $bad) {
    $r        = $problem(static fn () => $auth->authenticate($req($bad)));
    $bodies[] = [$r->status(), $r->body(), $r->header('WWW-Authenticate')];
}
pin('every bad token answers the same 401', 1, count(array_unique(array_map('serialize', $bodies))));
pin('which is 401 with invalid_token', [401, 'unauthorized', 'Bearer error="invalid_token"'], [$bodies[0][0], $bodies[0][1]['code'], $bodies[0][2]]);

$fails   = [];
$known   = 'Bearer ' . $admin->token();
$last    = null;
for ($i = 0; $i < FailureCounter::MAX; $i++) {
    $last = $problem(static fn () => $auth->authenticate($req($known)));
}
pin('20 failures for one revoked key from one address are each a 401', 401, $last->status());
$lookups = $store->lookups;
$r       = $problem(static fn () => $auth->authenticate($req($known)));
pin('the 21st is a 429', [429, 'too_many_failures', '900'], [$r->status(), $r->body()['code'], $r->header('Retry-After')]);
pin('a key shut out by failures is not looked up', $lookups, $store->lookups);
check('a stale key in a shared app does not lock out other keys behind the same address', $auth->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
$guess = 'Bearer sck_' . $id . '.' . str_repeat('f', 64);
pin('a guessed secret for that key id from that address is shut out too', 429, $problem(static fn () => $auth->authenticate($req($guess)))?->status());
check('the same key from another address still answers normally', $problem(static fn () => $auth->authenticate($req($known, [], 'GET', [], '203.0.113.6')))?->status() === 401);
pin('failures for a real key id never count against the whole address', 0, $fails['addr:203.0.113.5'] ?? 0);

$fails = [];
for ($i = 0; $i < FailureCounter::ADDRESS_MAX - 1; $i++) {
    $problem(static fn () => $auth->authenticate($req('Bearer sck_' . sprintf('%016d', $i) . '.' . str_repeat('b', 64))));
}
check('unknown key ids count per address, with a much higher ceiling', $auth->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
$problem(static fn () => $auth->authenticate($req('Bearer nonsense')));
pin('past it, a bad token from that address is a 429', 429, $problem(static fn () => $auth->authenticate($req('Bearer nonsense')))?->status());
check('a valid token from the same shared address still works past the failure cap', $auth->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
check('other addresses are not', $auth->authenticate($req('Bearer ' . $new->token(), [], 'GET', [], '203.0.113.7')) instanceof Credential);

$fails = ['addr:2001:db8:1:2::/64' => FailureCounter::ADDRESS_MAX];
pin('IPv6 floods count per /64, so stepping through a /64 does not help', 429, $problem(static fn () => $auth->authenticate($req('Bearer nope', [], 'GET', [], '2001:db8:1:2:ffff::1')))?->status());
pin('AddressBucket keys IPv6 by /64 and IPv4 as it is', ['2001:db8:1:2::/64', '192.0.2.1'], [AddressBucket::of('2001:db8:1:2:aaaa:bbbb:cccc:dddd'), AddressBucket::of('192.0.2.1')]);
pin('an IPv4-mapped IPv6 address is its IPv4 client, not ::/64', ['192.0.2.5', '192.0.2.5'], [AddressBucket::of('::ffff:192.0.2.5'), AddressBucket::of('::FFFF:c000:205')]);
$fails = ['addr:192.0.2.5' => FailureCounter::ADDRESS_MAX];
pin('a mapped address shares its IPv4 client\'s failure counter', 429, $problem(static fn () => $auth->authenticate($req('Bearer nope', [], 'GET', [], '::ffff:192.0.2.5')))?->status());
$unreadable = api_test_authenticator($keys, new FailureCounter(static fn () => null, static fn () => null));
check('when the failure counter cannot be read, tokens are checked as usual', $unreadable->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
$fails = [];

harness_section('the failure marker');
$now     = 9000;
$marker  = 0;
$reads   = 0;
$order   = [];
$marked  = new FailureCounter(
    static function (string $c, array $keys, int $w) use (&$fails, &$reads): ?array {
        $reads++;

        return array_combine($keys, array_map(static fn (string $k): int => $fails[$k] ?? 0, $keys));
    },
    static function (string $c, string $k, int $w) use (&$fails, &$order): ?int {
        $order[] = 'count';

        return $fails[$k] = ($fails[$k] ?? 0) + 1;
    },
    static function () use (&$marker): int {
        return $marker;
    },
    static function (int $until) use (&$marker, &$order): bool {
        $order[] = 'mark';
        $marker  = $until;

        return true;
    },
    static function () use (&$now): int {
        return $now;
    }
);
$mauth = api_test_authenticator($keys, $marked);
check('with no marker a good key authenticates', $mauth->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
pin('a good key with no marker reads the failure counter 0 times', 0, $reads);
$problem(static fn () => $mauth->authenticate($req('Bearer nonsense')));
pin('a token with no known key id leaves the marker unset', 0, $marker);
$order = [];
$problem(static fn () => $mauth->authenticate($req($known)));
pin('a failure for a known key id marks the window before it is counted', [['mark', 'count'], 9900], [$order, $marker]);
$order = [];
$reads = 0;
$problem(static fn () => $mauth->authenticate($req($known)));
pin('the marker is written once per window', ['count'], $order);
pin('while it is set, the key check reads the counter (and the refusal its address count)', 2, $reads);
for ($i = 2; $i < FailureCounter::MAX; $i++) {
    $problem(static fn () => $mauth->authenticate($req($known)));
}
$lookups = $store->lookups;
pin('the 21st is still a 429, and the key is not looked up', [429, $lookups], [$problem(static fn () => $mauth->authenticate($req($known)))?->status(), $store->lookups]);
$now   = 9900;
$fails = [];
$reads = 0;
check('when the window ends the marker lapses with the counter', $mauth->authenticate($req('Bearer ' . $new->token())) instanceof Credential);
pin('after the marker lapses the failure counter is not read again', 0, $reads);
$fails = [];

harness_section('the Kernel: auth levels and scopes');
$echo   = static fn (ApiCall $call): Response => Response::ok(['kind' => $call->credential()->kind(), 'query' => $call->request()->query()]);
$routes = [
    'GET open'     => ['handler' => $echo, 'auth' => RouteSpec::AUTH_NONE],
    'GET listings' => ['handler' => $echo, 'auth' => RouteSpec::AUTH_PUBLIC, 'scope' => 'listings:read',
        'query' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]], 'additionalProperties' => false]],
    'GET site'     => ['handler' => $echo, 'auth' => RouteSpec::AUTH_PUBLIC],
    'POST mine'    => ['handler' => $echo, 'auth' => RouteSpec::AUTH_USER, 'scope' => 'listings:write',
        'body' => ['$ref' => '#/components/schemas/Mine']],
    'GET users'    => ['handler' => $echo, 'auth' => RouteSpec::AUTH_ADMIN, 'scope' => 'admin:users'],
    'GET staff'    => ['handler' => $echo, 'auth' => RouteSpec::AUTH_ADMIN],
    'GET boom'     => ['handler' => static function (): Response {
        throw ProblemException::of('conflict', 'Busy.');
    }, 'auth' => RouteSpec::AUTH_NONE],
];
$validator = new Validator(['Mine' => ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string', 'minLength' => 1]]]]);
$counts    = [];
$limiter   = new RateLimiter(
    static function (string $b, string $k, int $w) use (&$counts): ?int {
        return $counts[$b . '|' . $k] = ($counts[$b . '|' . $k] ?? 0) + 1;
    },
    $clock
);
$make = static function (?ApiSettings $settings = null) use ($validator, $routes, $auth, $limiter): Kernel {
    $settings ??= new ApiSettings(true, userKeys: true);

    return api_test_kernel(new Router($validator, $routes), $auth, $settings, $limiter, $validator);
};
$kernel = $make();
$call   = static function (string $method, string $path, string $token = '', array $query = [], ?string $body = null, ?Kernel $k = null, string $ip = '198.51.100.1') use ($kernel): Response {
    $headers = $token === '' ? [] : ['Authorization' => 'Bearer ' . $token];
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
    }

    return ($k ?? $kernel)->handle(new Request($method, 'v1/' . $path, $query, $headers, $ip, $body ?? ''));
};

$r = $call('GET', 'listings');
pin('anonymous reads are off by default: 401 asking for a token', [401, 'Bearer'], [$r->status(), $r->header('WWW-Authenticate')]);
pin('an auth none route needs nothing', 200, $call('GET', 'open')->status());
$anon = $make(new ApiSettings(true, true));
$r    = $call('GET', 'listings', '', [], null, $anon);
pin('with anonymous reads on, public data answers', [200, CredentialKind::ANONYMOUS], [$r->status(), $r->body()['data']['kind']]);
pin('anonymous public data may be cached publicly', 'public, max-age=60, stale-while-revalidate=60', $r->header('Cache-Control'));
pin('with anonymous reads on, a user route still wants a token', 401, $call('POST', 'mine', '', [], '{"title":"x"}', $anon)->status());

$r = $call('GET', 'listings', $public->token());
pin('a public key reads listings', [200, CredentialKind::PUBLIC], [$r->status(), $r->body()['data']['kind']]);
pin('a public key reads the public view, so its answer may be cached publicly', 'public, max-age=60, stale-while-revalidate=60', $r->header('Cache-Control'));
pin('a public answer carries no one caller\'s rate counters', [null, null], [$r->header('RateLimit'), $r->header('X-RateLimit-Remaining')]);
pin('a public answer varies on Authorization and the page token', 'Authorization, X-Shopclass-Token', $r->header('Vary'));
$r = $call('GET', 'users', $new->token());
pin('a keyed answer is private, revalidated by ETag rather than never stored', 'private, no-cache', $r->header('Cache-Control'));
pin('a keyed answer varies on Authorization and the page token', 'Authorization, X-Shopclass-Token', $r->header('Vary'));
check('a keyed answer keeps its rate limit headers', $r->header('RateLimit') !== null);
pin('a write is never stored and varies on nothing', ['private, no-store', null], [
    $call('POST', 'mine', $user->token(), [], '{"title":"x"}')->header('Cache-Control'), $call('POST', 'mine', $user->token(), [], '{"title":"x"}')->header('Vary'),
]);
$r = $call('GET', 'users', $public->token());
pin('a public key on an admin route is 403', [403, 'wrong_credential'], [$r->status(), $r->body()['code']]);
$r = $call('GET', 'users', $mod->token());
pin('a key without the scope is 403 insufficient_scope', [403, 'insufficient_scope', 'Bearer error="insufficient_scope", scope="admin:users"'], [$r->status(), $r->body()['code'], $r->header('WWW-Authenticate')]);
pin('an admin key with the scope passes', 200, $call('GET', 'users', $new->token())->status());
pin('an admin route naming no scope turns a moderator away', [403, 'wrong_credential'], [$call('GET', 'staff', $mod->token())->status(), $call('GET', 'staff', $mod->token())->body()['code']]);
pin('an admin route naming no scope admits a full admin', 200, $call('GET', 'staff', $new->token())->status());
pin('an admin key on a user route is 403', 403, $call('POST', 'mine', $new->token(), [], '{"title":"x"}')->status());
pin('a user key with the scope passes', 200, $call('POST', 'mine', $user->token(), [], '{"title":"x"}')->status());
$r = $call('GET', 'listings', 'sck_' . str_repeat('C', 16) . '.' . str_repeat('c', 64));
pin('a bad token through the kernel is 401 with an instance naming the Request-Id', [401, 'urn:request:' . $r->header('Request-Id')], [$r->status(), $r->body()['instance']]);

harness_section('the Kernel: validation');
$r = $call('GET', 'listings', $public->token(), ['limit' => '20']);
pin('query values arrive typed', ['limit' => 20], $r->body()['data']['query']);
pin('?api_key= is not a query field', 200, $call('GET', 'listings', '', ['api_key' => $public->token()])->status());
$r = $call('GET', 'listings', $public->token(), ['limit' => '500', 'colour' => 'red']);
pin('a bad query is 422 with pointers', [422, ['/limit maximum query', '/colour additionalProperties query']], [
    $r->status(), array_map(static fn ($e) => $e['pointer'] . ' ' . $e['code'] . ' ' . $e['in'], $r->body()['errors']),
]);
$r = $call('POST', 'mine', $user->token(), [], '{"title":""}');
pin('a bad body is 422, through a $ref the kernel\'s validator resolves', [422, '/title'], [$r->status(), $r->body()['errors'][0]['pointer']]);
pin('broken JSON is 400', 400, $call('POST', 'mine', $user->token(), [], '{"title":')->status());
pin('an ProblemException thrown by a handler answers', [409, 'conflict'], [$call('GET', 'boom')->status(), $call('GET', 'boom')->body()['code']]);
$before = static function (Request $r, RouteSpec $route, Credential $c): void {
    if ($route->path() === 'site') {
        throw ProblemException::of('forbidden', 'Not today.');
    }
};
osc_add_hook('api_request_before', $before);
pin('api_request_before can refuse', 403, $call('GET', 'site', $public->token())->status());
osc_remove_filter('api_request_before', $before);
pin('api_response has the last word', '1', api_with_filter('api_response', static fn (Response $r): Response => $r->withHeader('X-Seen', '1'), static fn () => $call('GET', 'open')->header('X-Seen')));
check('a problem after counting keeps the RateLimit header', $call('POST', 'mine', $user->token(), [], '{"title":""}')->header('RateLimit') !== null);

harness_section('the Kernel: rate limits');
$counts = [];
$small  = $make(new ApiSettings(true, false, 3, 2, 1, userKeys: true));
$r      = $call('GET', 'users', $new->token(), [], null, $small);
pin('IETF policy header', '"api_key";q=3;w=60', $r->header('RateLimit-Policy'));
$reset = 60 - ($now % 60);
pin('IETF RateLimit header', '"api_key";r=2;t=' . $reset, $r->header('RateLimit'));
pin('X-RateLimit headers too', ['3', '2', (string) ($now + $reset)], [$r->header('X-RateLimit-Limit'), $r->header('X-RateLimit-Remaining'), $r->header('X-RateLimit-Reset')]);
$call('GET', 'users', $new->token(), [], null, $small);
$call('GET', 'users', $new->token(), [], null, $small);
$r = $call('GET', 'users', $new->token(), [], null, $small);
pin('past the key\'s limit: 429 with Retry-After', [429, 'rate_limited', (string) $reset, '0'], [$r->status(), $r->body()['code'], $r->header('Retry-After'), $r->header('X-RateLimit-Remaining')]);
$other = $keys->create(CredentialKind::KEY, 'Other', ['admin:users'], $admin1);
pin('another key has its own bucket', 200, $call('GET', 'users', $other->token(), [], null, $small)->status());

$counts = [];
for ($i = 0; $i < 3; $i++) {
    $call('GET', 'site', $public->token(), [], null, $small, '198.51.100.1');
}
pin('a public key is counted per client: one app user at the limit', 429, $call('GET', 'site', $public->token(), [], null, $small, '198.51.100.1')->status());
pin('does not stop another', 200, $call('GET', 'site', $public->token(), [], null, $small, '198.51.100.2')->status());
check('the public key bucket names the key and the address', isset($counts['api_key|' . $public->id() . '@198.51.100.2']));
$call('GET', 'site', $public->token(), [], null, $small, '2001:db8:9:9::1');
check('an IPv6 client is counted by its /64 in the public key bucket', isset($counts['api_key|' . $public->id() . '@2001:db8:9:9::/64']));

$store->rows[$new->id()]['rate'] = 1;
$counts = [];
$call('GET', 'users', $new->token(), [], null, $small);
pin('a key\'s own limit overrides the default', 429, $call('GET', 'users', $new->token(), [], null, $small)->status());
$store->rows[$new->id()]['rate'] = null;

$counts    = [];
$anonSmall = $make(new ApiSettings(true, true, 120, 2));
$call('GET', 'site', '', [], null, $anonSmall);
$r = $call('GET', 'site', '', [], null, $anonSmall);
pin('anonymous answers are public, so they carry no rate headers', null, $r->header('RateLimit-Policy'));
check('anonymous calls are counted per address', isset($counts['api_anon|198.51.100.1']));
pin('anonymous calls stop at the anonymous limit', 429, $call('GET', 'site', '', [], null, $anonSmall)->status());

$counts = [];
$r      = $call('POST', 'mine', $user->token(), [], '{"title":"a"}', $small);
pin('a write is counted in the write bucket too', '"api_key";q=3;w=60, "api_write";q=1;w=60', $r->header('RateLimit-Policy'));
pin('the tighter bucket is the one reported', '"api_write";r=0;t=' . $reset, $r->header('RateLimit'));
pin('past the write limit: 429', 429, $call('POST', 'mine', $user->token(), [], '{"title":"a"}', $small)->status());

$counts = [];
pin('api_rate_limit sets a per-route limit', 429, api_with_filter(
    'api_rate_limit',
    static fn (array $limit, Credential $c, RouteSpec $route): array => $route->path() === 'users' ? ['max' => 1, 'window' => 60] : $limit,
    static function () use ($call, $new, $small): int {
        $call('GET', 'users', $new->token(), [], null, $small);

        return $call('GET', 'users', $new->token(), [], null, $small)->status();
    }
));

exit(harness_result());
