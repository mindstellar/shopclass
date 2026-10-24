<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The push AJAX actions in-process, on a seeded user: push off is 404, a guest is 401, a refused
 * endpoint is 422 with no row, a subscribe keeps one row per user and browser, status renews it,
 * unsubscribe removes it, a POST without its CSRF token stores nothing. Over real HTTP the
 * worker script is served byte for byte as JavaScript, and is 404 while push is off.
 *
 * Usage:  php tests/models/push-ajax.php      (standalone, own scratch database)
 *         php tests/run-models.php push-ajax  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}
ob_start();

use mindstellar\container\Container;
use mindstellar\database\Connection;
use mindstellar\model\KeyValue;
use mindstellar\push\PushSubscriptions;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;
use mindstellar\utility\Curl;

$GLOBALS['paBase'] = 'https://example.com/';

/** The site address the in-process requests see. */
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return $GLOBALS['paBase'] . ($with_index ? 'index.php' : '');
    }
}

// Registered before the scratch database's own clean-up, so the CSRF section can still read it.
register_shutdown_function(static function (): void {
    if (isset($GLOBALS['paOnExit'])) {
        ($GLOBALS['paOnExit'])();
    }
});
$admin = api_admin_boot('osc_models_push_ajax');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/AjaxResponse.php';
$p = DB_TABLE_PREFIX;

// An installed site, so the HTTP section can serve real requests from the same database.
seed_locale($admin, 'en_US', 'English (US)');
Connection::getInstance()->executeScript(str_replace(
    array('/*TABLE_PREFIX*/', '/*OSCLASS_VERSION*/'),
    array($p, OSCLASS_VERSION),
    file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql') . file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/pages.sql')
));
$ledger = \mindstellar\migration\MigrationRunner::forCore();
$ledger->ensureLedger();
$ledger->baseline();

final class PaClock implements Clock
{
    public function __construct(public int $t)
    {
    }

    public function now(): int
    {
        return $this->t;
    }
}

/** The front base controller, stubbed: each action is called directly. */
class BaseModel
{
    protected $ajax = false;
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php';

$set = static function (string $key, string $value) use ($admin, $p): void {
    if ((int) $admin->query("SELECT COUNT(*) FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '" . $admin->real_escape_string($key) . "'")->fetch_row()[0] === 0) {
        seed_exec($admin, "INSERT INTO {$p}t_preference VALUES ('osclass', ?, ?, 'BOOLEAN')", 'ss', [$key, $value]);
    }
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};

/** Sign a user in for this request, or sign out with null. */
function pa_sign_in(?int $userId): void
{
    Session::getInstance()->_drop('userId');
    Session::getInstance()->_dropEphemeral('userId');
    View::getInstance()->_exportVariableToView('_loggedUser', $userId === null ? false : User::getInstance()->findByPrimaryKey($userId));
    osc_is_web_user_logged_in();
}

/** The CSRF fields the opt-in form posts, for whoever is signed in now. */
function pa_csrf(): array
{
    $csrf = new \mindstellar\security\Csrf();

    return array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue());
}

/** One push action: [status, decoded JSON or the raw body]. */
function pa_call(string $action, string $method, array $fields = array()): array
{
    $map = array('push_worker' => 'pushWorker', 'push_subscribe' => 'pushSubscribe', 'push_unsubscribe' => 'pushUnsubscribe', 'push_status' => 'pushStatus');
    $_GET     = array('page' => 'ajax', 'action' => $action) + ($method === 'GET' ? $fields : array());
    $_POST    = $method === 'POST' ? $fields : array();
    $_REQUEST = $_GET + $_POST;
    $_SERVER  = array('REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130.0 Safari/537.36') + $_SERVER;
    Params::init();
    http_response_code(200);
    $ajax = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $run  = new ReflectionMethod('CWebAjax', $map[$action]);
    $run->setAccessible(true);
    ob_start();
    $run->invoke($ajax);
    $body = (string) ob_get_clean();
    $json = json_decode($body, true);

    return array((int) http_response_code(), is_array($json) ? $json : $body);
}

$me    = seed_user($admin, 'bea', 'bea@example.test');
$other = seed_user($admin, 'sam', 'sam@example.test');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('enabled_push', '0');

harness_reset_container();
$clock = new PaClock(time());
$kv    = new KeyValue();
$subs  = new PushSubscriptions($kv, $clock, new AddressGuard(static fn (string $host): array => array('142.250.1.1')));
Container::root()->instance(PushSubscriptions::class, $subs);
$ep     = 'https://fcm.googleapis.com/fcm/send/abc';
$rows   = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'push'")->fetch_row()[0];
$status = static fn (array $r): int => $r[0];
$worker = (string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/push-worker.js');

harness_section('push off: 404');
pa_sign_in($me);
foreach (array('push_worker' => 'GET', 'push_subscribe' => 'POST', 'push_unsubscribe' => 'POST', 'push_status' => 'GET') as $action => $method) {
    pin("$action signed in is 404", 404, $status(pa_call($action, $method, array('endpoint' => $ep) + pa_csrf())));
}
pa_sign_in(null);
pin('the worker for a guest is 404 too', array(404, ''), pa_call('push_worker', 'GET'));
pin('a guest subscribe is still 401 (the visitor comes first)', 401, $status(pa_call('push_subscribe', 'POST', array('endpoint' => $ep))));
pin('nothing stored', 0, $rows());

harness_section('push on: the gate');
$set('enabled_push', '1');
pin('a guest status is 401 sign_in', array(401, 'sign_in'), (static function () use ($ep): array {
    $r = pa_call('push_status', 'GET', array('endpoint' => $ep));

    return array($r[0], $r[1]['error'] ?? null);
})());
pin('a guest subscribe is 401', 401, $status(pa_call('push_subscribe', 'POST', array('endpoint' => $ep))));
pa_sign_in($me);
$r = pa_call('push_subscribe', 'GET', array('endpoint' => $ep));
pin('subscribe by GET is 405', 405, $r[0]);
$set('enabled_messaging', '0');
pin('messaging off is 403 disabled', array(403, 'disabled'), (static function () use ($ep): array {
    $r = pa_call('push_status', 'GET', array('endpoint' => $ep));

    return array($r[0], $r[1]['error'] ?? null);
})());
$set('enabled_messaging', '1');
$GLOBALS['paBase'] = 'http://example.com/';
pin('an http:// site is off: 404', 404, $status(pa_call('push_status', 'GET', array('endpoint' => $ep))));
$GLOBALS['paBase'] = 'https://example.com/';

harness_section('subscribe');
foreach (array('http://' => 'http://fcm.googleapis.com/fcm/send/abc', 'loopback' => 'https://127.0.0.1/x', 'empty' => '') as $label => $bad) {
    $r = pa_call('push_subscribe', 'POST', array('endpoint' => $bad) + pa_csrf());
    pin("$label is 422 invalid", array(422, 'invalid', true), array($r[0], $r[1]['error'] ?? null, ($r[1]['msg'] ?? '') !== ''));
}
pin('no row for a refused endpoint', 0, $rows());
$r = pa_call('push_subscribe', 'POST', array('endpoint' => $ep) + pa_csrf());
pin('a valid endpoint: ok and the browser id', array(200, array('ok' => true, 'id' => PushSubscriptions::browserId($ep))), $r);
pin('one row, under the user and browser', 1, $rows());
$row = $kv->get('push', $me . ':' . PushSubscriptions::browserId($ep));
pin('the row keeps the endpoint and the browser family only', array($ep, 'Chrome'), array(json_decode((string) $row['value'], true)['endpoint'] ?? null, json_decode((string) $row['value'], true)['ua'] ?? null));

harness_section('status');
$before = (int) $row['expires'];
$clock->t += 3600;
pin('active for its user', array(200, array('active' => true)), pa_call('push_status', 'GET', array('endpoint' => $ep)));
pin('and the expiry moved on', $before + 3600, (int) $kv->get('push', $me . ':' . PushSubscriptions::browserId($ep))['expires']);
pin('another endpoint is not active', array(200, array('active' => false)), pa_call('push_status', 'GET', array('endpoint' => $ep . 'x')));
pa_sign_in($other);
pin('another user with the same endpoint: not active (rows are per user)', array(200, array('active' => false)), pa_call('push_status', 'GET', array('endpoint' => $ep)));
pa_call('push_unsubscribe', 'POST', array('endpoint' => $ep) + pa_csrf());
pin('their unsubscribe leaves the first user\'s row', 1, $rows());

harness_section('unsubscribe');
pa_sign_in($me);
pin('ok', array(200, array('ok' => true)), pa_call('push_unsubscribe', 'POST', array('endpoint' => $ep) + pa_csrf()));
pin('the row is gone', 0, $rows());
pin('status is now off', array(200, array('active' => false)), pa_call('push_status', 'GET', array('endpoint' => $ep)));

harness_section('the worker script');
pa_sign_in(null);
pin('a guest gets it, byte for byte', array(200, $worker), pa_call('push_worker', 'GET'));

harness_section('the worker over HTTP');
$port  = 0;
$probe = @stream_socket_server('tcp://127.0.0.1:0');
if ($probe !== false) {
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
}
if (!Curl::available() || $port === 0) {
    echo "SKIP  no curl or no free port\n";
    check('skipped: no curl or no free port', true);
} else {
    foreach (array('osclass_installed' => '1', 'enabled_users' => '1', 'enabled_messaging' => '1', 'enabled_push' => '1') as $name => $value) {
        $set($name, $value);
    }
    $dir = sys_get_temp_dir() . '/osc-push-ajax-' . getmypid() . '/';
    @mkdir($dir, 0700, true);
    $server = null;
    register_shutdown_function(static function () use (&$server, $dir): void {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        array_map('unlink', glob($dir . '*') ?: array());
        @rmdir($dir);
    });
    $root = rtrim(ABS_PATH, '/');
    file_put_contents($dir . 'router.php', '<?php
$_SERVER["SCRIPT_NAME"] = $_SERVER["PHP_SELF"] = "/index.php";
$_SERVER["SCRIPT_FILENAME"] = ' . var_export($root . '/index.php', true) . ';
chdir(' . var_export($root, true) . ');
require ' . var_export($root . '/index.php', true) . ';
');
    $base   = 'http://127.0.0.1:' . $port;
    $server = proc_open(
        array(PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=' . E_ALL, '-d', 'opcache.jit=disable', '-S', '127.0.0.1:' . $port, '-t', $root, $dir . 'router.php'),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $dir . 'server.log', 'w'), 2 => array('file', $dir . 'server.log', 'a')),
        $pipes,
        $root,
        array(
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
        )
    );
    $up = false;
    for ($i = 0; $i < 100 && !$up; $i++) {
        usleep(30000);
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        $up   = $sock !== false && fclose($sock);
    }
    check('the server started', $up);

    /** One request: [status, lower-cased headers, body]. */
    $get = static function (string $path, string $method = 'GET') use ($base): array {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10));
        $raw  = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $head = array();
        foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $head[strtolower(trim($k))] = trim($v);
            }
        }

        return array($code, $head, substr($raw, $size));
    };
    [$code, $head, $body] = $get('/index.php?page=ajax&action=push_worker&key=x');
    pin('200, JavaScript, no-cache, nosniff', array(200, 'text/javascript; charset=utf-8', 'no-cache', 'nosniff'), array($code, $head['content-type'] ?? null, $head['cache-control'] ?? null, $head['x-content-type-options'] ?? null));
    pin('the body is the asset file, byte for byte', md5($worker), md5($body));
    [$code] = $get('/index.php?page=ajax&action=push_subscribe', 'POST');
    pin('a guest POST to subscribe is 401', 401, $code);
    $set('enabled_push', '0');
    [$code, $head, $body] = $get('/index.php?page=ajax&action=push_worker');
    pin('push off: the worker is 404 with no script', array(404, false), array($code, str_contains($body, 'addEventListener')));
    $log = (string) @file_get_contents($dir . 'server.log');
    check('the server logged no PHP error', preg_match('/PHP (Warning|Notice|Deprecated|Fatal|Parse)/', $log) !== 1, $log);
    $set('enabled_push', '1');
}

// Last, since a refused CSRF token ends the request: the answer is read when the script stops.
harness_section('a POST without its CSRF token');
pa_sign_in($me);
$GLOBALS['paOnExit'] = static function () use ($rows): void {
    $body = '';
    while (ob_get_level() > 1) {
        $body = (string) ob_get_clean() . $body;
    }
    $json = json_decode($body, true);
    pin('the request ends with the CSRF error', array(1, true), array($json['error'] ?? null, is_string($json['msg'] ?? null)));
    pin('and no row is stored', 0, $rows());
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
};
// Runs after the database and server clean-up, which an exit inside a shutdown function would skip.
register_shutdown_function(static function (): void {
    exit(harness_result());
});
pa_call('push_subscribe', 'POST', array('endpoint' => $ep));
pin('the request should have ended at the CSRF check', 'ended', 'went on');
