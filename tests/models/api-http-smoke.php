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
 * /api/v1 over real HTTP: a `php -S` server runs the real index.php on an installed scratch site,
 * with a router that sends what is not a file to index.php as the .htaccess rewrite does.
 * Usage: php tests/models/api-http-smoke.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\database\Connection;
use mindstellar\model\ApiCredential;
use mindstellar\utility\Curl;
use mindstellar\utility\SystemClock;

$db = api_admin_boot('osc_models_api_http_smoke');
$p  = DB_TABLE_PREFIX;

$smokeSkip = static function (string $why): void {
    echo "SKIP  $why\n";
    harness_section('skipped');
    check('skipped: ' . $why, true);
};

$smokePort = 0;
$probe     = @stream_socket_server('tcp://127.0.0.1:0');
if ($probe !== false) {
    $smokePort = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
}
if (!Curl::available()) {
    $smokeSkip('the curl extension is missing');
} elseif ($smokePort === 0) {
    $smokeSkip('no free local port');
} else {
    harness_section('an installed site');
    seed_locale($db, 'en_US', 'English (US)');
    Connection::getInstance()->executeScript(str_replace(
        ['/*TABLE_PREFIX*/', '/*OSCLASS_VERSION*/'],
        [$p, OSCLASS_VERSION],
        file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql') . file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/pages.sql')
    ));
    foreach ([
        ['osclass', 'osclass_installed', '1'],
        ['osclass', 'rewriteEnabled', '1'],
        ['api', 'api_enabled', '1'],
        ['api', 'api_cors_origins', 'https://app.example'],
    ] as [$section, $name, $value]) {
        $db->query("UPDATE {$p}t_preference SET s_value = '$value' WHERE s_section = '$section' AND s_name = '$name'");
        if ($db->affected_rows === 0) {
            seed_exec($db, "INSERT INTO {$p}t_preference VALUES (?, ?, ?, 'BOOLEAN')", 'sss', [$section, $name, $value]);
        }
    }
    $adminId   = api_admin_seed_admin($db, 'smoke');
    $publicKey = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))
        ->create('public', 'smoke app', ['listings:read'], KeyOwner::admin($adminId))->token();
    check('the site is seeded', (int) $db->query("SELECT COUNT(*) FROM {$p}t_preference")->fetch_row()[0] > 20);

    $dir = sys_get_temp_dir() . '/osc-api-http-' . getmypid() . '/';
    @mkdir($dir, 0700, true);
    $server = null;
    register_shutdown_function(static function () use (&$server, $dir): void {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        array_map('unlink', glob($dir . '*') ?: []);
        @rmdir($dir);
    });
    $root = rtrim(ABS_PATH, '/');
    file_put_contents($dir . 'router.php', '<?php
$path = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path !== "/" && is_file(' . var_export($root, true) . ' . $path)) {
    return false;
}
$_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = "/index.php";
$_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/index.php', true) . ';
chdir(' . var_export($root, true) . ');
require ' . var_export($root . '/index.php', true) . ';
');
    $base = 'http://127.0.0.1:' . $smokePort;
    $env  = [
        'PATH'                   => (string) getenv('PATH'),
        'OSC_IGNORE_CONFIG_FILE' => '1',
        'DB_HOST'                => DB_HOST,
        'DB_PORT'                => (string) ini_get('mysqli.default_port'),
        'DB_NAME'                => DB_NAME,
        'DB_USER'                => DB_USER,
        'DB_PASSWORD'            => DB_PASSWORD,
        'DB_TABLE_PREFIX'        => $p,
        'WEB_PATH'               => $base . '/',
        'REL_WEB_URL'            => '/',
    ];
    $server = proc_open(
        [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-S', '127.0.0.1:' . $smokePort, '-t', $root, $dir . 'router.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir . 'server.log', 'w'], 2 => ['file', $dir . 'server.log', 'a']],
        $pipes,
        $root,
        $env
    );
    $up = false;
    for ($i = 0; $i < 100 && !$up; $i++) {
        usleep(30000);
        $sock = @fsockopen('127.0.0.1', $smokePort, $errno, $errstr, 0.1);
        $up   = $sock !== false && fclose($sock);
    }
    check('the server started', $up);

    /** One request: [status, lower-cased headers, body]. */
    $call = static function (string $method, string $path, array $headers = [], ?string $body = null) use ($base): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]));
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = [];
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $head[strtolower(trim($k))] = trim($v);
            }
        }

        return [$code, $head, substr($raw, $size)];
    };
    $clean = static fn (string $body): bool => $body === '' || (json_decode($body) !== null && preg_match('/<br|<b>|Warning:|Notice:|Deprecated:|Fatal error/', $body) !== 1);
    $bearer = ['Authorization: Bearer ' . $publicKey];

    harness_section('GET /api/v1/ through the friendly URL');
    [$code, $head, $body] = $call('GET', '/api/v1/', $bearer);
    pin('200 JSON', [200, 'application/json'], [$code, explode(';', $head['content-type'] ?? '')[0]]);
    check('a Request-Id header', ($head['request-id'] ?? '') !== '', describe($head));
    check('a Cache-Control header', ($head['cache-control'] ?? '') !== '', describe($head));
    check('the body is clean JSON naming the API', $clean($body) && isset(json_decode($body, true)['data']), substr($body, 0, 300));

    harness_section('the ?page=api form');
    [$code, $head, $body] = $call('GET', '/index.php?page=api&path=v1/openapi.json', $bearer);
    check('the OpenAPI document is served', $code === 200, $code . ' ' . substr($body, 0, 300));
    check('...as clean JSON', $clean($body) && isset(json_decode($body, true)['openapi']), substr($body, 0, 300));

    harness_section('listings with a public key');
    [$code, $head, $body] = $call('GET', '/api/v1/listings', $bearer);
    check('200', $code === 200, $code . ' ' . substr($body, 0, 300));
    check('a clean JSON list', $clean($body) && is_array(json_decode($body, true)['data'] ?? null), substr($body, 0, 300));

    harness_section('errors are problem JSON');
    [$code, $head, $body] = $call('GET', '/api/v1/no-such-thing', $bearer);
    pin('an unknown path is 404 problem JSON', [404, 'application/problem+json'], [$code, explode(';', $head['content-type'] ?? '')[0]]);
    check('...with a clean body', $clean($body), substr($body, 0, 300));
    [$code, $head, $body] = $call('POST', '/api/v1/listings', ['Content-Type: application/json'], '{}');
    pin('a write without a key is 401 problem JSON', [401, 'application/problem+json'], [$code, explode(';', $head['content-type'] ?? '')[0]]);
    check('...with a clean body', $clean($body), substr($body, 0, 300));

    harness_section('CORS preflight');
    [$code, $head, $body] = $call('OPTIONS', '/api/v1/listings', ['Origin: https://app.example', 'Access-Control-Request-Method: GET']);
    pin('204 for an allowed origin', [204, 'https://app.example'], [$code, $head['access-control-allow-origin'] ?? null]);
    check('...naming the methods', str_contains($head['access-control-allow-methods'] ?? '', 'GET'), describe($head));

    $log = (string) @file_get_contents($dir . 'server.log');
    check('the server logged no PHP error', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log) !== 1, $log);
}

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
