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
 * Sign-in and the user own account end to end through Kernel::handle() on a seeded site.
 * Usage: php tests/models/api-account.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// This file loads page helpers that earlier files in the suite stub, so under the runner it
// runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $aaOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $aaOut, $aaCode);
    $aaOut = implode("\n", $aaOut);
    echo $aaOut, "\n";
    $aaFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $aaOut, $aaM) === 1;
    $aaFail  = $aaFound ? (int)$aaM[2] : 0;
    if (!$aaFound || ($aaCode !== 0 && $aaFail === 0)) {
        $aaFail = max(1, $aaFail);
    }
    $GLOBALS['okCount']   += $aaFound ? (int)$aaM[1] : 0;
    $GLOBALS['failCount'] += $aaFail;
    if ($aaFail > 0) {
        $GLOBALS['failLabels'][] = 'api-account: ' . $aaFail . ' failed (exit ' . $aaCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_api_account');

foreach (array(
    'OSC_CACHE_TTL'   => 60,
    'WEB_PATH'        => 'http://localhost/',
    'REL_WEB_URL'     => '/',
    'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'        => false,
    'OSC_DEBUG'       => false,
    'OSC_CSRF_SECRET' => 'api-account-test-secret',
    'BCRYPT_COST'     => 4,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}
function osc_change_user_email_confirm_url($userId, $code)
{
    $GLOBALS['aa_confirm_code'] = $code;

    return WEB_PATH . 'confirm/' . $userId . '/' . $code;
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\idempotency\Idempotency;
use mindstellar\api\idempotency\KvIdempotencyStore;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\Kernel;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\routing\Router;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\PageTokens;
use mindstellar\apiaccess\Scopes;
use mindstellar\auth\AuthStamp;
use mindstellar\model\ApiCredential;
use mindstellar\security\RememberMe;
use mindstellar\utility\SystemClock;

/** Links without the theme helpers. */
final class AccountLinks implements Links
{
    public function listing(array $item): string
    {
        return 'http://localhost/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'http://localhost/photo';
    }

    public function user(int $id, string $username): string
    {
        return 'http://localhost/user/' . $id;
    }

    public function avatar(int $userId): string
    {
        return 'http://localhost/avatar/' . $userId;
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'http://localhost/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return '';
    }
}

/* ----------------------------------------------------------------------------
 * Fixture: a confirmed user, a suspended one and an unconfirmed one, all with a password.
 * ------------------------------------------------------------------------- */
$p       = DB_TABLE_PREFIX;
$locale  = seed_locale($admin);
$country = seed_country($admin, 'US', 'United States');
$uma     = seed_user($admin, 'uma', 'uma@example.test');
$sam     = seed_user($admin, 'sam', 'sam@example.test', 1, 0);
$una     = seed_user($admin, 'una', 'una@example.test', 0, 1);
$hash    = password_hash('correct horse', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string($hash) . "', s_phone_mobile = '5550100', dt_access_date = NOW()");
foreach (array(
    'enabled_users'             => '1',
    'enabled_user_registration' => '1',
    'enabled_user_validation'   => '1',
    'language'                  => 'en_US',
    // A captcha is switched on and never solved here: sign-up through the API must not need one.
    'captchaProvider'           => 'turnstile',
    'turnstileSiteKey'          => 'site',
    'turnstileSecretKey'        => 'secret',
) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
$_SERVER['REMOTE_ADDR'] = '192.0.2.50';
Params::init();

/** Every hook a test cares about, in firing order. */
$fired = array();
foreach (array(
    'before_validating_login', 'before_login', 'after_login', 'pre_user_post', 'user_edit_completed', 'hook_email_new_email',
    'before_user_register', 'user_register_completed', 'hook_email_user_validation',
) as $hook) {
    osc_add_hook($hook, static function (...$args) use (&$fired, $hook): void {
        $fired[] = $hook;
    });
}
// The core e-mail functions are not loaded here; this one stands in for the activation e-mail.
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
$aaSent = array();
if (!function_exists('osc_sendMail')) {
    function osc_sendMail($params)
    {
        if (\mindstellar\utility\DeferredMail::hold($params)) {
            return true;
        }
        $GLOBALS['aaSent'][] = $params['to'] ?? '';

        return true;
    }
}
$aaCodes = array();
// Stands in for the mail transport when a queued job sends.
$aaMailer = static function (array $params): bool {
    if (!($GLOBALS['aaMailWorks'] ?? true)) {
        throw new \RuntimeException('test transport is down');
    }
    $GLOBALS['aaSent'][] = $params['to'] ?? '';

    return true;
};
osc_add_hook('hook_email_user_validation', static function ($user, $input): void {
    $GLOBALS['aaCodes'][] = $input['s_secret'];
    osc_sendMail(array('to' => $user['s_email'], 'subject' => 'Activate', 'body' => 'validate/' . $user['pk_i_id'] . '/' . $input['s_secret'], 'secret_link' => true));
});
$seen = array();
osc_add_hook('after_login', static function ($user, $redirect) use (&$seen): void {
    $seen['after_login'] = array((int) $user['pk_i_id'], $redirect, osc_logged_user_id());
});
osc_add_hook('user_edit_completed', static function ($userId) use (&$seen): void {
    $seen['edit_as'] = osc_logged_user_id();
});

/* ----------------------------------------------------------------------------
 * The kernel, wired as ApiServices wires the site's, over a test read kit. One per request,
 * as production builds one per request.
 * ------------------------------------------------------------------------- */
$validator = new Validator(Schema::components());
$facts     = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')), true, true, 10, 12, 50, false, false);
$settings  = new ApiSettings(true, userKeys: true);
$call      = static function (string $method, string $path, array|string|null $body = null, ?string $token = null, array $headers = array(), string $ip = '192.0.2.50') use ($validator, $facts, &$settings): Response {
    $users    = new UserRows();
    $services = new ApiServices($settings, new Scopes(), new ApiCredential(), $users, new SystemClock(), $GLOBALS['aa_limiter'] ?? RateLimiter::fromSite(new SystemClock()), $facts, new AccountLinks());
    $kernel   = new Kernel(
        new Router($validator, Router::core(), handlers: $services->handlers()),
        $services->authenticator(),
        api_test_limiter(),
        $validator,
        $settings,
        $users,
        $services->admins(),
        new Idempotency(new KvIdempotencyStore(), $services->clock())
    );
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    if (is_array($body)) {
        $headers['Content-Type'] = 'application/json';
    }
    $_SERVER['REMOTE_ADDR'] = $ip;
    Params::init();
    // index.php forgets the browser's identity before every API request.
    WebIdentity::forget();

    return $kernel->handle(new Request($method, 'v1/' . $path, array(), $headers, $ip, is_array($body) ? (string) json_encode($body) : (string) $body));
};
$login = static fn (string $user, string $password = 'correct horse', array $extra = array(), string $ip = '192.0.2.50'): Response
    => $call('POST', 'auth/token', array('grant_type' => 'password', 'username' => $user, 'password' => $password) + $extra, null, array(), $ip);
$code = static fn (Response $r): string => $r->status() . ' ' . (string) ($r->body()['code'] ?? '');
$schemaErrors = static fn (string $schema, Response $r): array => $validator->check(Schema::ref($schema), $r->body());
$userRow = static fn (int $id): array => $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $id")->fetch_assoc();

harness_section('signing in');
$fired = array();
$r     = $login('uma@example.test', 'correct horse', array('label' => 'Pixel'));
$t     = $r->body() ?? array();
pin('the password grant answers an access and a refresh token', array(200, 'Bearer', 900, true, true), array(
    $r->status(), $t['token_type'] ?? null, $t['expires_in'] ?? null, str_starts_with((string) ($t['access_token'] ?? ''), 'sca_'), str_starts_with((string) ($t['refresh_token'] ?? ''), 'scr_'),
));
pin('with every user scope by default', implode(' ', Scopes::USER), $t['scope'] ?? null);
pin('matches the schema', array(), $schemaErrors('TokenDocument', $r));
pin('the web login\'s hooks fire, in order', array('before_validating_login', 'before_login', 'after_login'), $fired);
pin('after_login gets the user and no redirect, and core already sees the user', array($uma, '', $uma), $seen['after_login'] ?? null);
pin('never cached', ['no-store', 'no-cache'], [$r->header('Cache-Control'), $r->header('Pragma')]);
$access1  = (string) $t['access_token'];
$refresh1 = (string) $t['refresh_token'];
pin('the username works too, with fewer scopes', array(200, 'listings:read account:read'), array($login('uma', 'correct horse', array('scope' => 'listings:read account:read admin:users'))->status(), $login('uma', 'correct horse', array('scope' => 'listings:read account:read admin:users'))->body()['scope']));
pin('a scope no user can hold is 400 invalid_scope', '400 invalid_scope', $code($login('uma', 'correct horse', array('scope' => 'admin:users'))));
pin('a wrong password is 400 invalid_grant', '400 invalid_grant', $code($login('uma@example.test', 'wrong')));
$sameAnswer = static function (string $account, string $password = 'wrong') use ($login): array {
    $body = $login($account, $password)->body();
    unset($body['instance']);

    return $body;
};
pin('an unknown account answers the same as a wrong password', $sameAnswer('uma@example.test'), $sameAnswer('nobody@example.test'));
check('a failed sign-in is counted with the web sign-in form\'s failures, one budget', (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_context = 'web'")->fetch_row()[0] > 0 && (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_context = 'api'")->fetch_row()[0] === 0);
$login('nobody@example.test', 'wrong', array(), '198.51.100.77');
$login('uma', 'correct horse', array(), '198.51.100.77');
pin('a sign-in clears the account\'s failures but keeps the address\'s', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_login_attempt WHERE s_ip = '198.51.100.77'")->fetch_row()[0]);
pin('a suspended account is 400 invalid_grant', '400 invalid_grant', $code($login('sam')));
pin('an unconfirmed one too', '400 invalid_grant', $code($login('una')));
pin('an unconfirmed account with the right password answers exactly as a wrong password', $sameAnswer('uma@example.test'), $sameAnswer('una@example.test', 'correct horse'));
pin('a password grant without a password is 400 invalid_request', '400 invalid_request', $code($call('POST', 'auth/token', array('grant_type' => 'password', 'username' => 'uma'))));
$r = $call('POST', 'auth/token', array('grant_type' => 'client_credentials'));
pin('an unknown grant is 400 unsupported_grant_type, as OAuth names it', array('400 unsupported_grant_type', 'unsupported_grant_type'), array($code($r), $r->body()['error'] ?? null));
$r = $login('nobody@example.test', 'wrong', array(), '198.51.100.78');
pin('a refused sign-in is an OAuth invalid_grant, with error_description as RFC 6749 names it', array(400, 'invalid_grant', true), array($r->status(), $r->body()['error'] ?? null, ($r->body()['error_description'] ?? '') !== '' && $r->body()['error_description'] === $r->body()['detail']));
pin('an unknown member is 400 invalid_request', '400 invalid_request', $code($call('POST', 'auth/token', array('grant_type' => 'password', 'username' => 'uma', 'password' => 'x', 'admin' => true))));
$r = $call('POST', 'auth/token', http_build_query(array('grant_type' => 'password', 'username' => 'uma', 'password' => 'correct horse', 'scope' => 'listings:read')), null, array('Content-Type' => 'application/x-www-form-urlencoded'));
pin('a form-encoded token request works too (RFC 6749)', array(200, 'listings:read', true, false), array($r->status(), $r->body()['scope'] ?? null, isset($r->body()['access_token']), isset($r->body()['data'])));
$r = $call('POST', 'auth/token', 'grant_type=password&username=uma', null, array('Content-Type' => 'application/x-www-form-urlencoded'));
pin('a form-encoded token refusal is an OAuth error and never cached', array('400 invalid_request', 'invalid_request', 'no-store'), array($code($r), $r->body()['error'] ?? null, $r->header('Cache-Control')));
$r = $call('POST', 'auth/token', 'grant_type=password&username=%FF&password=x', null, array('Content-Type' => 'application/x-www-form-urlencoded'));
pin('a form-encoded token request that is not UTF-8 is 400 invalid_request', array('400 invalid_request', 'invalid_request'), array($code($r), $r->body()['error'] ?? null));
pin('a body that is not JSON is invalid_request', '400 invalid_request', $code($call('POST', 'auth/token', '{nope', null, array('Content-Type' => 'application/json'))));

harness_section('the throttle');
for ($i = 0; $i < osc_login_throttle_max_account(); $i++) {
    $login('una@example.test', 'guess ' . $i, array(), '198.51.100.' . ($i + 1));
}
$r = $login('una@example.test', 'correct horse', array(), '198.51.100.200');
pin('past the account limit even the right password is 429 login_blocked', '429 login_blocked', $code($r));
check('with a Retry-After', (int) $r->header('Retry-After') > 0);
$admin->query("DELETE FROM {$p}t_login_attempt");

harness_section('using the access token');
$me = $call('GET', 'account', null, $access1);
pin('GET /account is the owner view', array(200, $uma, 'uma@example.test', '5550100'), array($me->status(), $me->body()['data']['id'] ?? null, $me->body()['data']['email'] ?? null, $me->body()['data']['phone_mobile'] ?? null));
pin('matches the schema', array(), $schemaErrors('UserDocument', $me));
pin('without a token it is 401', 401, $call('GET', 'account')->status());

harness_section('refreshing');
$r        = $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $refresh1));
$refresh2 = (string) ($r->body()['refresh_token'] ?? '');
pin('a refresh answers new tokens', array(200, true, true), array($r->status(), $refresh2 !== '' && $refresh2 !== $refresh1, str_starts_with((string) ($r->body()['access_token'] ?? ''), 'sca_')));
$access2 = (string) $r->body()['access_token'];
pin('the new access token works', 200, $call('GET', 'account', null, $access2)->status());
$retry = $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $refresh1));
pin('the old refresh token sent again at once, as after a lost answer, gets the same new one', array(200, $refresh2), array($retry->status(), $retry->body()['refresh_token'] ?? null));
$refresh3 = (string) ($call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $refresh2))->body()['refresh_token'] ?? '');
pin('once that one was used, the old one again is 400 invalid_grant', '400 invalid_grant', $code($call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $refresh1))));
pin('which ended the family: the newest one is refused too', '400 invalid_grant', $code($call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $refresh3))));

harness_section('sessions');
$umaKey  = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create('key', 'sessions-key', array('listings:read', 'account:read'), \mindstellar\apiaccess\KeyOwner::user($uma));
$phone   = $login('uma', 'correct horse', array('label' => 'Phone'))->body();
$laptop  = $login('uma', 'correct horse', array('label' => 'Laptop'))->body();
$list    = $call('GET', 'account/sessions', null, $phone['access_token']);
$labels  = array_column($list->body()['data'], 'name');
$current = array_values(array_filter($list->body()['data'], static fn (array $s): bool => $s['current']));
check('the sign-ins are listed with their names, and no key among them', in_array('Phone', $labels, true) && in_array('Laptop', $labels, true));
pin('the one asking is marked current', array('Phone'), array_column($current, 'name'));
pin('matches the schema', array(), $schemaErrors('SessionList', $list));
pin('a whole list has the page envelope', array(count($list->body()['data']), null, true), array(
    $list->body()['meta']['total'],
    $list->body()['links']['next'],
    str_ends_with($list->body()['links']['self'], 'account/sessions'),
));
check('with the address it was last used from', $current[0]['last_ip'] === '192.0.2.50');
$laptopId = array_values(array_filter($list->body()['data'], static fn (array $s): bool => $s['name'] === 'Laptop'))[0]['id'];
pin('one can be ended', 204, $call('DELETE', 'account/sessions/' . $laptopId, null, $phone['access_token'])->status());
pin('its refresh token then fails', 400, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $laptop['refresh_token']))->status());
pin('another user\'s or an unknown session is 404', 404, $call('DELETE', 'account/sessions/AAAAAAAAAAAAAAAA', null, $phone['access_token'])->status());
pin('a key is not a session: it is not listed, and its id cannot be ended here', array(false, 404, 200), array(
    in_array('sessions-key', $labels, true),
    $call('DELETE', 'account/sessions/key-' . $umaKey->id(), null, $phone['access_token'])->status(),
    $call('GET', 'account', null, $umaKey->token())->status(),
));

harness_section('editing the profile');
$fired = array();
$r     = $call('PATCH', 'account', array('name' => 'Uma Updated', 'is_company' => true, 'country' => 'US'), $phone['access_token']);
pin('PATCH /account saves through UserActions', array(200, 'Uma Updated', true), array($r->status(), $r->body()['data']['name'] ?? null, $r->body()['data']['is_company'] ?? null));
pin('the profile form\'s hooks fire', array('pre_user_post', 'user_edit_completed'), $fired);
pin('acting as the token\'s user', $uma, $seen['edit_as'] ?? null);
pin('a member not sent keeps its value', '5550100', $userRow($uma)['s_phone_mobile']);
pin('the country is stored', array('US', 'United States'), array($userRow($uma)['fk_c_country_code'], $userRow($uma)['s_country']));
pin('an unknown country is 422', '422 validation_failed', $code($call('PATCH', 'account', array('country' => 'ZZ'), $phone['access_token'])));
$placeError   = static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null, $r->body()['errors'][0]['code'] ?? null);
$homeRegion   = seed_region($admin, $country, 'Alpha');
$homeCity     = seed_city($admin, $homeRegion, 'Aville', $country);
$otherCountry = seed_country($admin, 'CA', 'Canada');
$otherRegion  = seed_region($admin, $otherCountry, 'Gamma');
$otherCity    = seed_city($admin, $otherRegion, 'Gtown', $otherCountry);
pin('an unknown place answers unknown', array(array(422, '/country', 'unknown'), array(422, '/region_id', 'unknown'), array(422, '/city_id', 'unknown')), array(
    $placeError($call('PATCH', 'account', array('country' => 'ZZ'), $phone['access_token'])),
    $placeError($call('PATCH', 'account', array('region_id' => 99999), $phone['access_token'])),
    $placeError($call('PATCH', 'account', array('city_id' => 99999), $phone['access_token'])),
));
pin('a region of another country is refused, against the stored country too', array(array(422, '/region_id', 'mismatch'), array(422, '/region_id', 'mismatch')), array(
    $placeError($call('PATCH', 'account', array('country' => 'US', 'region_id' => $otherRegion), $phone['access_token'])),
    $placeError($call('PATCH', 'account', array('region_id' => $otherRegion), $phone['access_token'])),
));
pin('a city of another region is refused', array(422, '/city_id', 'mismatch'), $placeError($call('PATCH', 'account', array('region_id' => $homeRegion, 'city_id' => $otherCity), $phone['access_token'])));
$r = $call('PATCH', 'account', array('region_id' => $homeRegion, 'city_id' => $homeCity), $phone['access_token']);
pin('a matching region and city are stored', array(200, (string) $homeRegion, (string) $homeCity), array($r->status(), $userRow($uma)['fk_i_region_id'], $userRow($uma)['fk_i_city_id']));
$call('PATCH', 'account', array('website' => 'https://uma.example.test'), $phone['access_token']);
$r = $call('PATCH', 'account', array('website' => null), $phone['access_token']);
pin('null clears an optional member of the account', array(200, null), array($r->status(), $r->body()['data']['website'] ?? null));
pin('UserActions\' own refusal is 422 with its message', array(422, 'The name cannot be empty'), (static function (Response $r): array {
    return array($r->status(), $r->body()['errors'][0]['message'] ?? null);
})($call('PATCH', 'account', array('name' => '<b></b>'), $phone['access_token'])));
$fired = array();
$r     = $call('PATCH', 'account', array('email' => 'uma.new@example.test'), $phone['access_token']);
pin('a new e-mail is not applied yet', array(200, 'uma@example.test', 'uma@example.test'), array($r->status(), $r->body()['data']['email'], $userRow($uma)['s_email']));
pin('a warning says a link went out', 'email_confirmation_sent', $r->body()['warnings'][0]['code'] ?? null);
pin('through the web\'s confirmation e-mail hook', array('hook_email_new_email'), $fired);
pin('matches the schema', array(), $schemaErrors('AccountDocument', $r));
$confirm = UserActions::confirmEmailChange($uma, (string) ($GLOBALS['aa_confirm_code'] ?? ''));
pin('the link applies it as on the web', array('ok', 'uma.new@example.test'), array($confirm['status'], $userRow($uma)['s_email']));
$fired = array();
$r     = $call('PATCH', 'account', array('email' => 'sam@example.test'), $phone['access_token']);
pin('an e-mail another account holds answers the same, so nobody learns it is taken', array(200, 'email_confirmation_sent'), array($r->status(), $r->body()['warnings'][0]['code'] ?? null));
pin('a sign-up with a taken e-mail sends no link', array(), $fired);
for ($i = 0; $i < \mindstellar\user\AccountService::EMAIL_CHANGES - 2; $i++) {
    $call('PATCH', 'account', array('email' => 'try' . $i . '@example.test'), $phone['access_token']);
}
pin('a user gets a few e-mail changes an hour, then 429', '429 rate_limited', $code($call('PATCH', 'account', array('email' => 'one-more@example.test'), $phone['access_token'])));
$userKey = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create('key', 'script', array('listings:read', 'account:read'), \mindstellar\apiaccess\KeyOwner::user($uma))->token();
pin('a key can read the account', 200, $call('GET', 'account', null, $userKey)->status());
pin('a personal key cannot edit the account: account:write is access-token only', '403 insufficient_scope', $code($call('PATCH', 'account', array('name' => 'X'), $userKey)));

harness_section('changing the password');
$tablet = $login('uma', 'correct horse', array('label' => 'Tablet'))->body();
pin('the current password must match', '422 validation_failed', $code($call('POST', 'account/password', array('current_password' => 'nope', 'new_password' => 'battery staple'), $phone['access_token'])));
$r = $call('POST', 'account/password', array('current_password' => 'correct horse', 'new_password' => 'battery staple'), $phone['access_token']);
pin('a change answers a new sign-in for this client: an access and a refresh token', array(200, true, true), array($r->status(), str_starts_with((string) ($r->body()['access_token'] ?? ''), 'sca_'), str_starts_with((string) ($r->body()['refresh_token'] ?? ''), 'scr_')));
$newTokenId = substr(explode('.', (string) ($r->body()['refresh_token'] ?? ''))[0], 4);
pin('under the same label', 'Phone', $admin->query("SELECT s_name FROM {$p}t_api_credential WHERE s_token_id = '" . $admin->real_escape_string($newTokenId) . "'")->fetch_row()[0] ?? null);
check('the password is changed', password_verify('battery staple', $userRow($uma)['s_password']));
pin('the old access token is dead at once', 401, $call('GET', 'account', null, $phone['access_token'])->status());
pin('the new one works', 200, $call('GET', 'account', null, $r->body()['access_token'])->status());
pin('another sign-in\'s access token is dead', 401, $call('GET', 'account', null, $tablet['access_token'])->status());
pin('the other sign-in refresh token is dead too', 400, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $tablet['refresh_token']))->status());
pin('this sign-in\'s old refresh token ended with the rest', 400, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $phone['refresh_token']))->status());
pin('the client carries on with the new one', 200, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $r->body()['refresh_token']))->status());
pin('the old password no longer signs in', 400, $login('uma', 'correct horse')->status());

harness_section('bans');
$s = $login('uma', 'battery staple')->body();
$admin->query("INSERT INTO {$p}t_ban_rule (s_name, s_email) VALUES ('test', 'uma.new@example.test')");
\mindstellar\security\BanRuleStore::forget();
pin('a ban stops a refresh', '400 invalid_grant', $code($call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']))));
$admin->query("DELETE FROM {$p}t_ban_rule");
\mindstellar\security\BanRuleStore::forget();
pin('a banned user cannot refresh, which ends that sign-in', 400, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']))->status());

harness_section('suspension and sign-out');
$s = $login('uma', 'battery staple')->body();
$admin->query("UPDATE {$p}t_user SET b_enabled = 0 WHERE pk_i_id = $uma");
pin('a suspended user\'s token is 401 on the next call', 401, $call('GET', 'account', null, $s['access_token'])->status());
pin('a suspended user cannot refresh', 400, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']))->status());
$admin->query("UPDATE {$p}t_user SET b_enabled = 1 WHERE pk_i_id = $uma");
$s = $login('uma', 'battery staple')->body();
pin('sign-out answers 204', 204, $call('POST', 'auth/sign-out', null, $s['access_token'])->status());
pin('sign-out ends the refresh token', 400, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']))->status());
$a = $login('uma', 'battery staple')->body();
$b = $login('uma', 'battery staple')->body();
pin('sign-out ends only this sign-in', array(204, 400, 200), array(
    $call('POST', 'auth/sign-out', null, $a['access_token'])->status(),
    $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $a['refresh_token']))->status(),
    $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $b['refresh_token']))->status(),
));
pin('the key made before the password change is dead', 401, $call('GET', 'account', null, $userKey)->status());
// A key made on the admin screen or the CLI works the same.
$userKey = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create('key', 'script', array('listings:read', 'account:read'), \mindstellar\apiaccess\KeyOwner::user($uma))->token();
pin('a key made now works', 200, $call('GET', 'account', null, $userKey)->status());
pin('a key cannot sign out', '403 wrong_credential', $code($call('POST', 'auth/sign-out', null, $userKey)));

harness_section('personal keys');
$s       = $login('uma', 'battery staple')->body();
$keyBody = array('name' => 'Backup script', 'scopes' => array('listings:read', 'account:read'), 'expires_at' => date('Y-m-d', time() + 30 * 86400), 'current_password' => 'battery staple');
$settings = new ApiSettings(true);
pin('off by default: 403', '403 feature_disabled', $code($call('POST', 'account/keys', $keyBody, $s['access_token'])));
pin('a personal key stops working while personal keys are off', '403 feature_disabled', $code($call('GET', 'account', null, $userKey)));
$settings = new ApiSettings(true, userKeys: true);
$r        = $call('POST', 'account/keys', $keyBody, $s['access_token']);
pin('when on, a key is made and its token shown once', array(201, 'Backup script', array('listings:read', 'account:read'), true), array($r->status(), $r->body()['data']['name'] ?? null, $r->body()['data']['scopes'] ?? null, str_starts_with((string) ($r->body()['data']['token'] ?? ''), 'sck_')));
pin('matches the schema', array(), $schemaErrors('PersonalKeyDocument', $r));
$madeId = (int) $r->body()['data']['id'];
$keyMe = $call('GET', 'account', null, (string) $r->body()['data']['token']);
pin('the key works for its user', array(200, $uma), array($keyMe->status(), $keyMe->body()['data']['id'] ?? null));
pin('account:write is refused', '422 validation_failed', $code($call('POST', 'account/keys', array('scopes' => array('account:write')) + $keyBody, $s['access_token'])));
pin('an expiry is required', '422 validation_failed', $code($call('POST', 'account/keys', array('name' => 'x', 'scopes' => array('listings:read'), 'current_password' => 'battery staple'), $s['access_token'])));
pin('the password is required', '422 validation_failed', $code($call('POST', 'account/keys', array_diff_key($keyBody, array('current_password' => 1)), $s['access_token'])));
pin('the current password must be right', array(422, '/current_password'), (static function (Response $r): array {
    return array($r->status(), $r->body()['errors'][0]['pointer'] ?? null);
})($call('POST', 'account/keys', array('current_password' => 'wrong') + $keyBody, $s['access_token'])));
pin('a personal key takes a number of days too, as an admin key does', 201, $call('POST', 'account/keys', array('expires_at' => '30d', 'name' => 'Days') + $keyBody, $s['access_token'])->status());
pin('an expiry past a year is refused', '422 validation_failed', $code($call('POST', 'account/keys', array('expires_at' => date('Y-m-d', time() + 400 * 86400)) + $keyBody, $s['access_token'])));
pin('a key cannot make keys', '403 insufficient_scope', $code($call('POST', 'account/keys', $keyBody, $userKey)));
$narrow = (string) ($login('uma', 'battery staple', array('scope' => 'account:read account:write'))->body()['access_token'] ?? '');
pin('a token cannot make a key with a scope it does not hold', '403 forbidden', $code($call('POST', 'account/keys', $keyBody, $narrow)));
pin('but can with the scopes it holds', 201, $call('POST', 'account/keys', array('scopes' => array('account:read')) + $keyBody, $narrow)->status());
$list = $call('GET', 'account/keys', null, $s['access_token']);
check('the list shows the key with its prefix and no secret', in_array('Backup script', array_column($list->body()['data'], 'name'), true) && !str_contains((string) json_encode($list->body()), (string) $r->body()['data']['token']));
$one = $call('GET', substr((string) $r->header('Location'), strlen('http://localhost/api/v1/')), null, $s['access_token']);
pin('Location is a key that can be read, never with its secret', array(200, $madeId, false), array($one->status(), $one->body()['data']['id'] ?? null, isset($one->body()['data']['token'])));
pin('matches the schema', array(), $schemaErrors('PersonalKeyDocument', $one));
pin('revoking it answers 204', 204, $call('DELETE', 'account/keys/' . $madeId, null, $s['access_token'])->status());
pin('revoking it again is 409, as for an admin key', '409 conflict', $code($call('DELETE', 'account/keys/' . $madeId, null, $s['access_token'])));
pin('another user\'s key, or an unknown one, is 404 to read', 404, $call('GET', 'account/keys/999999', null, $s['access_token'])->status());
pin('another user\'s key is 404', 404, $call('DELETE', 'account/keys/999999', null, $s['access_token'])->status());
$bound = (string) $call('POST', 'account/keys', $keyBody, $s['access_token'])->body()['data']['token'];
pin('a fresh key works', 200, $call('GET', 'account', null, $bound)->status());
$s = $call('POST', 'account/password', array('current_password' => 'battery staple', 'new_password' => 'third pass'), $s['access_token'])->body();
pin('a password change through the API ends the user\'s keys', 401, $call('GET', 'account', null, $bound)->status());
$bound = (string) $call('POST', 'account/keys', array('current_password' => 'third pass') + $keyBody, $s['access_token'])->body()['data']['token'];
\mindstellar\user\AccountService::setPassword($uma, 'reset pass');
pin('a password change anywhere else, such as a reset or an admin edit, ends the keys too', 401, $call('GET', 'account', null, $bound)->status());

// A password stored at another cost is stored again at sign-in: that is not a change.
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string(password_hash('battery staple', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST + 1))) . "' WHERE pk_i_id = $uma");
$old   = $login('uma', 'battery staple')->body();
$bound = (string) $call('POST', 'account/keys', $keyBody, $old['access_token'])->body()['data']['token'];
$fresh = $login('uma', 'battery staple');
check('the next sign-in rehashed the password at the current cost', str_starts_with($userRow($uma)['s_password'], sprintf('$2y$%02d$', BCRYPT_COST)) && $fresh->status() === 200);
pin('the key survives a rehash', 200, $call('GET', 'account', null, $bound)->status());
pin('the earlier sign-in refresh token survives a rehash', 200, $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $old['refresh_token']))->status());
$settings = new ApiSettings(true, userKeys: true);

harness_section('sign-up');
$signup = array('name' => 'Neo', 'email' => 'neo@example.test', 'password' => 'red pill', 'username' => 'neo');
pin('off by default: 403', '403 feature_disabled', $code($call('POST', 'users', $signup)));
$settings = new ApiSettings(true, userKeys: true, registration: true);
$fired    = array();
$r        = $call('POST', 'users', $signup, null, array(), '203.0.113.7');
pin('when on, an account is made: 201, waiting for activation', array(201, false), array($r->status(), $r->body()['data']['confirmed'] ?? null));
pin('no Location, as the account cannot be read until it is confirmed', null, $r->header('Location'));
pin('the body says only whether the account is confirmed, no id', array('confirmed' => false), $r->body()['data'] ?? null);
pin('matches the schema', array(), $schemaErrors('NewAccountDocument', $r));
check('the site asks for a captcha on its own form', osc_captcha_enabled());
pin('the sign-up form\'s hooks fire; the activation e-mail waits for its job', array('before_user_register', 'pre_user_post', 'user_register_completed'), $fired);
$neo = (int) $admin->query("SELECT pk_i_id FROM {$p}t_user WHERE s_email = 'neo@example.test'")->fetch_row()[0];
pin('without the captcha the form would ask for', array('0', 'neo'), array($userRow($neo)['b_active'], $userRow($neo)['s_username']));
$taken = $call('POST', 'users', array('username' => '') + $signup, null, array(), '203.0.113.7');
pin('an e-mail in use answers as a new one: 201, the same body, no Location', array(201, array('confirmed' => false), null), array($taken->status(), $taken->body()['data'] ?? null, $taken->header('Location')));
pin('signing in to the new, unconfirmed account answers as a wrong password does for a taken one', $sameAnswer('uma@example.test'), $sameAnswer('neo@example.test', 'red pill'));
Preference::getInstance()->set('enabled_user_validation', '0');
osc_reset_preferences();
$open = $call('POST', 'users', array('username' => '') + $signup, null, array(), '203.0.113.8');
pin('without activation a taken e-mail cannot be hidden, so it is refused', array(422, 'The specified e-mail is already in use'), array($open->status(), $open->body()['errors'][0]['message'] ?? null));
Preference::getInstance()->set('enabled_user_validation', '1');
osc_reset_preferences();
pin('a sign-up with a taken e-mail makes no second account', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE s_email = 'neo@example.test'")->fetch_row()[0]);
$queued = $admin->query("SELECT s_type, s_payload FROM {$p}t_job_queue WHERE s_type LIKE 'user.%'")->fetch_all();
pin('the activation e-mail waits on the job queue, which holds only the user id', array(array(array('user.activation_mail', array('user' => $neo))), array(), array()), array(
    array_map(static fn ($r) => array($r[0], json_decode($r[1], true)), array_filter($queued, static fn ($r) => $r[0] === 'user.activation_mail')),
    $aaSent,
    $aaCodes,
));
check('no queued e-mail carries an activation link', !str_contains((string) json_encode($queued), 'validate/'));
$neoRow = $userRow($neo);
pin('the job mails a fresh code to the account', array(true, array('neo@example.test'), 1), array(\mindstellar\user\SignUpMail::sendActivation($neo, $aaMailer), $aaSent, count($aaCodes)));
$firstCode = (string) $aaCodes[0];
check('the user row keeps only the code\'s hash', $userRow($neo)['s_secret'] === \mindstellar\security\ActionToken::hash($firstCode) && $neoRow['s_secret'] !== $userRow($neo)['s_secret']);
\mindstellar\user\SignUpMail::sendActivation($neo, $aaMailer);
$secondCode = (string) $aaCodes[1];
$refused    = static function (int $id, string $code): string {
    try {
        (new \mindstellar\user\AccountService())->confirm($id, $code);
    } catch (\mindstellar\validation\RefusedException $e) {
        return get_class($e);
    }

    return 'confirmed';
};
pin('a retried job makes a new code, and the old one no longer works', \mindstellar\validation\NotFoundException::class, $refused($neo, $firstCode));
pin('the code in the sent link activates the account', array('confirmed', '1'), array($refused($neo, $secondCode), $userRow($neo)['b_active']));
$admin->query("UPDATE {$p}t_user SET b_active = 0 WHERE pk_i_id = $neo");
scratchdb_forget_cache();
$GLOBALS['aaMailWorks'] = false;
pin('a failed send throws, so the job is tried again', 'RuntimeException', (static function (int $id) use ($aaMailer): string {
    try {
        \mindstellar\user\SignUpMail::sendActivation($id, $aaMailer);
    } catch (\RuntimeException $e) {
        return get_class($e);
    }

    return 'no exception';
})($neo));
unset($GLOBALS['aaMailWorks']);
$admin->query("UPDATE {$p}t_user SET b_active = 1 WHERE pk_i_id = $neo");
scratchdb_forget_cache();
$aaSent = array('neo@example.test', 'neo@example.test');
pin('once active, the job sends nothing', array(false, 2), array(\mindstellar\user\SignUpMail::sendActivation($neo, $aaMailer), count($aaSent)));
check('a job for an account that is gone sends nothing', !\mindstellar\user\SignUpMail::sendActivation(999999, $aaMailer));
$aaSent = array();
$nameNew   = $call('POST', 'users', array('email' => 'morpheus@example.test') + $signup, null, array(), '203.0.113.60');
$nameTaken = $call('POST', 'users', $signup, null, array(), '203.0.113.61');
pin('a taken username is refused the same way with a new e-mail and a taken one', array(422, $nameNew->body()['errors'] ?? null), array($nameTaken->status(), $nameTaken->body()['errors'] ?? null));
pin('a taken username is refused as 422 with a new e-mail', 422, $nameNew->status());
// Sign up with a name and an e-mail, then with the same name and a fresh e-mail.
$twoStep = static function (string $firstEmail, string $name, int $n) use ($call): array {
    $first  = $call('POST', 'users', array('email' => $firstEmail, 'username' => $name, 'name' => 'Two', 'password' => 'red pill'), null, array(), '203.0.113.' . (70 + $n));
    $second = $call('POST', 'users', array('email' => 'second' . $n . '@example.test', 'username' => $name, 'name' => 'Two', 'password' => 'red pill'), null, array(), '203.0.113.' . (80 + $n));

    return array($first->status(), $first->body(), $second->status(), $second->body()['errors'] ?? null);
};
$withTaken = $twoStep('sam@example.test', 'cypher', 1);
$withNew   = $twoStep('switch@example.test', 'apoc', 2);
pin('the second sign-up answers the same whether the first e-mail was taken or new', $withNew, $withTaken);
pin('a sign-up with a taken e-mail answers 201 first, then the username refusal', array(201, 422, 'Username is already taken'), array($withTaken[0], $withTaken[2], $withTaken[3][0]['message'] ?? null));
pin('the taken e-mail made no account', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE s_username = 'cypher'")->fetch_row()[0]);
for ($i = 0; $i < 5; $i++) {
    $last = $call('POST', 'users', array('email' => 'n' . $i . '@example.test') + $signup, null, array(), '203.0.113.7');
}
$admin->query("INSERT INTO {$p}t_ban_rule (s_name, s_email) VALUES ('test', 'trinity@example.test')");
\mindstellar\security\BanRuleStore::forget();
pin('a banned e-mail cannot sign up', '403 banned', $code($call('POST', 'users', array('email' => 'trinity@example.test', 'username' => 'trinity') + $signup, null, array(), '203.0.113.50')));
$admin->query("DELETE FROM {$p}t_ban_rule");
\mindstellar\security\BanRuleStore::forget();
pin('each address gets a few tries an hour, then 429', '429 rate_limited', $code($last));
$GLOBALS['aa_limiter'] = api_test_limiter(static fn (string $bucket) => $bucket === 'api_register_site' ? \mindstellar\api\ratelimit\RatePolicy::SIGN_UPS_PER_SITE + 1 : 1);
pin('the whole site has a cap too', '429 rate_limited', $code($call('POST', 'users', array('email' => 'cap@example.test', 'username' => '') + $signup, null, array(), '203.0.113.99')));
$counted = array();
$GLOBALS['aa_limiter'] = api_test_limiter(static function (string $bucket) use (&$counted): int {
    $counted[] = $bucket;

    return 1;
});
$bad = $call('POST', 'users', array('email' => 'bad@example.test') + $signup, null, array(), '203.0.113.97');
pin('a sign-up refused by its checks counts only for its address, not the site', array(422, array('api_register')), array($bad->status(), $counted));
$counted = array();
$call('POST', 'users', array('email' => 'neo@example.test', 'username' => '') + $signup, null, array(), '203.0.113.96');
pin('one that passed counts for the site too, with a taken e-mail as with a new one', array('api_register', 'api_register_site'), $counted);
$GLOBALS['aa_limiter'] = api_test_limiter(static fn (string $bucket) => $bucket === 'api_register_site' ? 101 : 1);
pin('so a full site answers a taken e-mail as it answers a new one', array('429 rate_limited', '429 rate_limited'), array(
    $code($call('POST', 'users', array('email' => 'neo@example.test', 'username' => '') + $signup, null, array(), '203.0.113.95')),
    $code($call('POST', 'users', array('email' => 'fresh@example.test', 'username' => '') + $signup, null, array(), '203.0.113.94')),
));
$GLOBALS['aa_limiter'] = api_test_limiter(static fn () => null);
pin('sign-up fails closed when the counter cannot be reached', '429 rate_limited', $code($call('POST', 'users', array('email' => 'closed@example.test') + $signup, null, array(), '203.0.113.98')));
unset($GLOBALS['aa_limiter']);
pin('no account was made by any', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE s_email IN ('cap@example.test', 'closed@example.test', 'bad@example.test', 'fresh@example.test')")->fetch_row()[0]);
$settings = new ApiSettings(true, userKeys: true);

harness_section('signing out of all devices');
$settings = new ApiSettings(true, userKeys: true);
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string(password_hash('battery staple', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST))) . "' WHERE pk_i_id = $uma");
scratchdb_forget_cache();
$one     = $login('uma', 'battery staple')->body();
$two     = $login('uma', 'battery staple')->body();
$key     = (string) $call('POST', 'account/keys', $keyBody, $one['access_token'])->body()['data']['token'];
$row     = $userRow($uma);
$page    = (new PageTokens())->issue($row)->token();
$cookie  = RememberMe::issue('web', $uma, $row['s_password'], 3600, AuthStamp::of($row));
pin('every credential works before', array(200, 200, 200, PageTokens::VALID, true), array(
    $call('GET', 'account', null, $one['access_token'])->status(), $call('GET', 'account', null, $two['access_token'])->status(),
    $call('GET', 'account', null, $key)->status(), (new PageTokens())->check($page, $row), RememberMe::verify('web', $uma, $cookie, $row['s_password'], AuthStamp::of($row)),
));
pin('a key cannot sign out of all devices', '403 insufficient_scope', $code($call('POST', 'account/sign-out-everywhere', null, $key)));
$stampBefore = AuthStamp::of($row);
pin('an access token can', 204, $call('POST', 'account/sign-out-everywhere', null, $one['access_token'])->status());
$row = $userRow($uma);
pin('the stamp went up by one', $stampBefore + 1, AuthStamp::of($row));
pin('every access token is dead, this one too', array(401, 401), array(
    $call('GET', 'account', null, $one['access_token'])->status(), $call('GET', 'account', null, $two['access_token'])->status(),
));
pin('a password reset kills the refresh tokens', '400 invalid_grant', $code($call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $two['refresh_token']))));
pin('a password reset kills the personal key', 401, $call('GET', 'account', null, $key)->status());
pin('a password reset kills the page token and the web cookie', array(PageTokens::REFUSED, false), array(
    (new PageTokens())->check($page, $row), RememberMe::verify('web', $uma, $cookie, $row['s_password'], AuthStamp::of($row)),
));
pin('the session list holds only a sign-in made after', 1, count($call('GET', 'account/sessions', null, $login('uma', 'battery staple')->body()['access_token'])->body()['data'] ?? array()));
pin('a new sign-in works', 200, $call('GET', 'account', null, $login('uma', 'battery staple')->body()['access_token'])->status());

$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string(password_hash('battery staple', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST + 1))) . "' WHERE pk_i_id = $uma");
scratchdb_forget_cache();
// Made while the hash is at the old cost, before any sign-in stores it again.
$bound = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create('key', 'rehash', array('listings:read', 'account:read'), \mindstellar\apiaccess\KeyOwner::user($uma))->token();
$grant = (new \mindstellar\api\auth\RefreshTokens(new ApiCredential(), new Scopes(), new UserRows(), 30, new SystemClock()))->start($userRow($uma), array('listings:read'), 'Rehash', '192.0.2.1');
pin('fixture: the key works on the old hash', 200, $call('GET', 'account', null, $bound)->status());
$login('uma', 'battery staple');
check('the sign-in stored the password again at the current cost', str_starts_with($userRow($uma)['s_password'], sprintf('$2y$%02d$', BCRYPT_COST)));
pin('with a stamp set, a rehash still keeps the key and the refresh token', array($stampBefore + 1, 200, 200), array(
    AuthStamp::of($userRow($uma)), $call('GET', 'account', null, $bound)->status(),
    $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $grant->token()))->status(),
));
$settings = new ApiSettings(true, userKeys: true);

harness_section('an Idempotency-Key, kept in the table');
$s   = $login('uma', 'battery staple')->body();
$one = $call('PATCH', 'account', array('website' => 'https://uma.example.test'), $s['access_token'], array('Idempotency-Key' => 'edit-1'));
$two = $call('PATCH', 'account', array('website' => 'https://uma.example.test'), $s['access_token'], array('Idempotency-Key' => 'edit-1'));
pin('the same write again is replayed from the table', array(200, null, 'true', $one->body()), array($two->status(), $one->header('Idempotency-Replayed'), $two->header('Idempotency-Replayed'), $two->body()));
pin('another body under the key is 422', '422 idempotency_key_reused', $code($call('PATCH', 'account', array('website' => ''), $s['access_token'], array('Idempotency-Key' => 'edit-1'))));
$r = $call('POST', 'auth/token', array('grant_type' => 'refresh_token', 'refresh_token' => $s['refresh_token']));
pin('the same sign-in\'s next access token shares its keys', 'true', $call('PATCH', 'account', array('website' => 'https://uma.example.test'), $r->body()['access_token'], array('Idempotency-Key' => 'edit-1'))->header('Idempotency-Replayed'));
pin('another sign-in of the same user does not', null, $call('PATCH', 'account', array('website' => 'https://uma.example.test'), $login('uma', 'battery staple')->body()['access_token'], array('Idempotency-Key' => 'edit-1'))->header('Idempotency-Replayed'));
check('no session was started', session_status() !== PHP_SESSION_ACTIVE);

Preference::getInstance()->set('enabled_users', '0');
osc_reset_preferences();
pin('with user accounts off there is no sign-in: 403', '403 feature_disabled', $code($login('uma', 'battery staple')));
Preference::getInstance()->set('enabled_users', '1');
osc_reset_preferences();

exit(harness_result());
