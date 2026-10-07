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
 * What the admin endpoint tests (tests/models/api-admin-*.php) share: a process of their
 * own under the models runner, a seeded scratch site, an admin, a moderator and a user, and
 * a kernel wired by ApiServices as the site's is.
 */

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\Kernel;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

/**
 * Under the models runner, run $file in a process of its own and add its counts. The page
 * helpers it loads are stubbed by earlier files in the suite.
 *
 * @return bool true when the caller should return now
 */
function api_admin_isolated(string $file): bool
{
    if (!defined('MODELS_RUNNER')) {
        return false;
    }
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    $out = implode("\n", $out);
    echo $out, "\n";
    $found = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $out, $m) === 1;
    $fail  = $found ? (int) $m[2] : 0;
    if (!$found || ($code !== 0 && $fail === 0)) {
        $fail = max(1, $fail);
    }
    $GLOBALS['okCount']   += $found ? (int) $m[1] : 0;
    $GLOBALS['failCount'] += $fail;
    if ($fail > 0) {
        $GLOBALS['failLabels'][] = basename($file, '.php') . ': ' . $fail . ' failed (exit ' . $code . ')';
    }

    return true;
}

/**
 * A scratch site with the helpers the admin endpoints call.
 */
function api_admin_boot(string $scratch): mysqli
{
    require_once __DIR__ . '/scratchdb.php';
    $admin = scratchdb_session($scratch);
    foreach ([
        'OSC_CACHE_TTL'   => 60,
        'WEB_PATH'        => 'http://localhost/',
        'REL_WEB_URL'     => '/',
        'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
        'OC_ADMIN'        => false,
        'OSC_DEBUG'       => false,
        'OSC_CSRF_SECRET' => 'api-admin-test-secret',
        'BCRYPT_COST'     => 4,
    ] as $const => $value) {
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
    if (!function_exists('osc_item_url')) {
        function osc_item_url($locale = '')
        {
            return WEB_PATH . 'item';
        }
    }
    if (!function_exists('osc_core_url')) {
        function osc_core_url($name, $args = [])
        {
            return WEB_PATH . 'route/' . $name;
        }
    }
    if (!function_exists('osc_register_render_target')) {
        function osc_register_render_target($id, $path)
        {
        }
    }
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
    require_once __DIR__ . '/action-standins.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hResources.php';
    require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';
    require_once __DIR__ . '/api-doubles.php';

    return $admin;
}

/**
 * An admin row.
 */
function api_admin_seed_admin(mysqli $db, string $username, bool $moderator = false): int
{
    return seed_exec(
        $db,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES (?, ?, ?, ?, ?)',
        'ssssi',
        [ucfirst($username), $username, str_repeat('x', 60), $username . '@example.test', $moderator ? 1 : 0]
    );
}

/**
 * A key's token for an admin, with every scope it may hold unless named.
 *
 * @param string[]|null $scopes
 */
function api_admin_key(int $adminId, bool $moderator = false, ?array $scopes = null): string
{
    $owner = KeyOwner::admin($adminId, $moderator);
    $all   = new Scopes();

    return (new ApiKeys(new ApiCredential(), $all, new SystemClock()))->create('key', 'test key', $scopes ?? $all->allowedFor('key', $owner), $owner)->token();
}

/**
 * fn(method, path, ?body, ?token, headers = [], query = []): Response, one kernel per call as
 * one request has one.
 *
 * @param Closure|null $settings fn(): ApiSettings, read for each call; the defaults when null
 */
function api_admin_caller(?Closure $settings = null): Closure
{
    $facts     = new SiteFacts('en_US', ['en_US' => ['name' => 'English', 'direction' => 'ltr']], true, true, 10, 12, 50, false, false);
    $settings ??= static fn (): ApiSettings => new ApiSettings(true, userKeys: true);
    $links     = new class () implements Links {
        public function listing(array $item): string
        {
            return 'http://localhost/item/' . $item['pk_i_id'];
        }

        public function photo(array $resource, string $variant): string
        {
            return 'http://localhost/photo/' . $resource['pk_i_id'];
        }

        public function user(int $id, string $username): string
        {
            return 'http://localhost/user/' . $id;
        }

        public function avatar(int $userId): string
        {
            return osc_user_avatar_url($userId);
        }

        public function api(string $path, ?string $version = null): string
        {
            return 'http://localhost/api/' . ($version ?? 'v1') . '/' . $path;
        }

        public function price(?int $micros, string $symbol): string
        {
            return '';
        }
    };

    return static function (string $method, string $path, ?array $body = null, ?string $token = null, array $headers = [], array $query = []) use ($facts, $settings, $links): Response {
        $users    = new UserRows();
        $services = new ApiServices($settings(), new Scopes(), new ApiCredential(), $users, new SystemClock(), api_test_limiter(), $facts, $links);
        $kernel   = $services->kernel();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $content = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $content                 = (string) json_encode($body);
        }
        $_GET = $query;
        Params::init();
        WebIdentity::forget();

        return $kernel->handle(new Request($method, 'v1/' . $path, $query, $headers, '192.0.2.70', $content));
    };
}

/**
 * Status and problem code, e.g. "403 insufficient_scope".
 */
function api_admin_code(Response $r): string
{
    return $r->status() . ' ' . (string) ($r->body()['code'] ?? '');
}

/**
 * Every way a response body breaks a component schema.
 *
 * @return array<int,mixed>
 */
function api_admin_schema_errors(string $schema, Response $r): array
{
    static $validator = null;
    $validator ??= new Validator(Schema::components());

    return $validator->check(Schema::ref($schema), $r->body());
}
