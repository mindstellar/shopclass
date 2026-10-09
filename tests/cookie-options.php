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
 * Cookie::options() and the cookies written with it, read from real Set-Cookie headers.
 * The CLI keeps no headers, so the writes run in a PHP built-in server and the test reads
 * its responses: the flash cookie lives 300 s, the form cookie 1800 s, and every write
 * carries Secure on HTTPS and the COOKIE_DOMAIN domain.
 *
 * DB-free. Usage:  php tests/cookie-options.php
 */

require_once __DIR__ . '/lib/harness.php';

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSC_CSRF_SECRET', 'cookie-options-test-secret');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

harness_section('options()');

$_SERVER['HTTPS'] = 'off';
check('no Secure flag over plain HTTP', !isset(Cookie::options(5)['secure']));
check('no domain without COOKIE_DOMAIN', !isset(Cookie::options(5)['domain']));
if (!function_exists('osc_is_ssl')) {
    function osc_is_ssl()
    {
        return \mindstellar\utility\Utils::isSsl();
    }
}
$_SERVER['HTTPS'] = 'on';
pin('Secure on HTTPS', true, Cookie::options(5)['secure'] ?? null);
define('COOKIE_DOMAIN', '.shop.example.test');
pin('the COOKIE_DOMAIN domain', '.shop.example.test', Cookie::options(5)['domain'] ?? null);

// One request per scenario, each a fresh PHP run of this script in the built-in server.
$routerBase = (string) tempnam(sys_get_temp_dir(), 'osccookie_');
$router     = $routerBase . '.php';
file_put_contents($router, <<<'PHP'
<?php
define('ABS_PATH', getenv('OSC_TEST_ABS'));
define('OSC_CSRF_SECRET', 'cookie-options-test-secret');
define('REL_WEB_URL', '/shop/');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
function osc_is_ssl()
{
    return \mindstellar\utility\Utils::isSsl();
}
if (isset($_GET['domain'])) {
    define('COOKIE_DOMAIN', '.shop.example.test');
}
if (isset($_GET['https'])) {
    $_SERVER['HTTPS'] = 'on';
}
$s = Session::getInstance();
switch ($_GET['do'] ?? '') {
    case 'write':
        Cookie::write('oc_probe', 'v', time() + 60);
        break;
    case 'flash':
        $s->_setMessage('pubMessages', 'saved', 'ok');
        $s->_flushFlashMessages();
        break;
    case 'readflash':
        $s->_loadFlashMessages();
        echo json_encode($s->_getMessage('pubMessages'));
        break;
    case 'form':
        $s->_setForm('title', 'Bike');
        $s->_flushFormData();
        break;
    case 'keepform':
        $s->_loadFormData();
        echo json_encode($s->_getForm());
        break;
}
PHP);

$probe = stream_socket_server('tcp://127.0.0.1:0');
$port  = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$server = proc_open(
    array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $router),
    array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
    $pipes,
    null,
    array('OSC_TEST_ABS' => ABS_PATH, 'PATH' => (string) getenv('PATH'))
);
register_shutdown_function(static function () use ($server, $router, $routerBase): void {
    proc_terminate($server);
    proc_close($server);
    @unlink($router);
    @unlink($routerBase);
});

/**
 * One GET; returns the body, the Set-Cookie lines keyed by name and the request time.
 *
 * @return array{body:string,cookies:array<string,string>,time:int}
 */
$get = static function (string $query, string $cookie = '') use ($port): array {
    $ctx  = stream_context_create(array('http' => array('ignore_errors' => true, 'header' => $cookie === '' ? '' : 'Cookie: ' . $cookie)));
    $time = time();
    $body = (string) @file_get_contents('http://127.0.0.1:' . $port . '/?' . $query, false, $ctx);
    $out  = array();
    foreach ($http_response_header ?? array() as $h) {
        if (stripos($h, 'Set-Cookie:') === 0) {
            $line = trim(substr($h, 11));
            $out[strtok($line, '=')] = $line;
        }
    }

    return array('body' => $body, 'cookies' => $out, 'time' => $time);
};
$attr = static function (string $line, string $name): ?string {
    return preg_match('/;\s*' . preg_quote($name, '/') . '(?:=([^;]*))?(?:;|$)/i', $line, $m) === 1 ? ($m[1] ?? '') : null;
};
/** Seconds between the cookie's Expires and the expiry signed inside its value. */
$signedLead = static function (string $line): ?int {
    $value = urldecode((string) strtok(substr($line, (int) strpos($line, '=') + 1), ';'));
    $data  = json_decode((string) base64_decode(strtr(explode('.', $value)[0], '-_', '+/')), true);
    $exp   = preg_match('/expires=([^;]+)/i', $line, $m) === 1 ? strtotime($m[1]) : false;

    return is_array($data) && isset($data['x']) && $exp !== false ? (int) $data['x'] - $exp : null;
};

$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100000);
    $up = @fsockopen('127.0.0.1', $port) !== false;
}
check('the built-in server starts', $up);

harness_section('Cookie::write() sends options()');

$plain = $get('do=write')['cookies']['oc_probe'] ?? '';
check('a write over HTTP is HttpOnly, SameSite=Lax, on the site path', $attr($plain, 'HttpOnly') !== null && $attr($plain, 'SameSite') === 'Lax' && $attr($plain, 'path') === '/shop/');
check('...with no Secure flag and no domain', $attr($plain, 'secure') === null && $attr($plain, 'domain') === null);
$tls = $get('do=write&https=1&domain=1')['cookies']['oc_probe'] ?? '';
check('a write over HTTPS is Secure', $attr($tls, 'secure') !== null);
pin('...on the COOKIE_DOMAIN domain', '.shop.example.test', $attr($tls, 'domain'));

harness_section('the flash cookie lives 300 s');

$r     = $get('do=flash');
$flash = $r['cookies']['oc_flash'] ?? '';
check('a pending flash is written to oc_flash', $flash !== '');
$age = (int) $attr($flash, 'Max-Age');
check('...for 300 s', $age >= 298 && $age <= 300, 'Max-Age ' . $age);
check('...through Cookie::write(), so with the site options', $attr($flash, 'HttpOnly') !== null && $attr($flash, 'path') === '/shop/');
pin('...and its signed value expires with it', 0, $signedLead($flash));
$back = $get('do=readflash', 'oc_flash=' . (string) strtok(substr($flash, 9), ';'));
pin('the next request reads it back', array(array('msg' => 'saved', 'type' => 'ok')), json_decode($back['body'], true));
check('...and deletes it', isset($back['cookies']['oc_flash']) && (int) $attr($back['cookies']['oc_flash'], 'Max-Age') === 0);

harness_section('the form cookie lives 1800 s');

$form = $get('do=form')['cookies']['oc_form'] ?? '';
check('stashed form input is written to oc_form', $form !== '');
$age = (int) $attr($form, 'Max-Age');
check('...for 1800 s', $age >= 1798 && $age <= 1800, 'Max-Age ' . $age);
pin('...and its signed value expires with it', 0, $signedLead($form));
$back = $get('do=keepform', 'oc_form=' . (string) strtok(substr($form, 8), ';'));
pin('the next request refills the form from it', array('title' => 'Bike'), json_decode($back['body'], true));

exit(harness_result());

/* file end: ./tests/cookie-options.php */
