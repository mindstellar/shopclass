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
 * CurlHttpClient: HTTPS only, no redirects, certificate checks, timeouts and the answer size cap.
 * DB-free and offline. Set HTTP_CLIENT_NETWORK_TEST=1 to also fetch a real page.
 * Usage:  php tests/http-client.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\http\CurlHttpClient;
use mindstellar\http\HttpException;
use mindstellar\http\ResponseBuffer;

$refused = static function (callable $call): string {
    try {
        $call();
    } catch (\InvalidArgumentException $e) {
        return 'refused';
    }

    return 'accepted';
};

harness_section('options');
$buffer = new ResponseBuffer(10);
$o      = CurlHttpClient::options('get', 'https://example.com/a?key=1', ['Authorization' => 'Bearer x'], null, [], $buffer);
pin('method is upper-cased', 'GET', $o[CURLOPT_CUSTOMREQUEST]);
pin('HTTPS only, redirects too', [CURLPROTO_HTTPS, CURLPROTO_HTTPS], [$o[CURLOPT_PROTOCOLS], $o[CURLOPT_REDIR_PROTOCOLS]]);
pin('no redirects followed', [false, 0], [$o[CURLOPT_FOLLOWLOCATION], $o[CURLOPT_MAXREDIRS]]);
pin('peer and host verified', [true, 2], [$o[CURLOPT_SSL_VERIFYPEER], $o[CURLOPT_SSL_VERIFYHOST]]);
pin('default timeouts are 5 and 15 seconds', [5000, 15000], [$o[CURLOPT_CONNECTTIMEOUT_MS], $o[CURLOPT_TIMEOUT_MS]]);
check('no cookie jar', !isset($o[CURLOPT_COOKIEFILE]) && !isset($o[CURLOPT_COOKIEJAR]));
check('caller headers are sent', in_array('Authorization: Bearer x', $o[CURLOPT_HTTPHEADER], true));
check('a default user agent is added', (bool) preg_grep('/^User-Agent: Shopclass\//', $o[CURLOPT_HTTPHEADER]));
check('no body for a GET with none', !isset($o[CURLOPT_POSTFIELDS]));

$o = CurlHttpClient::options('POST', 'https://example.com/', ['user-agent' => 'Acme/1'], 'a=1', ['connect_timeout' => 2, 'timeout' => 0.5], $buffer);
pin('timeouts can be set, also below a second', [2000, 500], [$o[CURLOPT_CONNECTTIMEOUT_MS], $o[CURLOPT_TIMEOUT_MS]]);
pin('the body is posted', 'a=1', $o[CURLOPT_POSTFIELDS]);
pin('a caller user agent is kept, not doubled', 1, count(preg_grep('/^user-agent:/i', $o[CURLOPT_HTTPHEADER])));
pin('HEAD asks for no body', true, CurlHttpClient::options('HEAD', 'https://example.com/', [], null, [], $buffer)[CURLOPT_NOBODY]);

pin('http:// is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'http://example.com/', [], null, [], $buffer)));
pin('file:// is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'file:///etc/passwd', [], null, [], $buffer)));
pin('a URL with no host is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'https:///x', [], null, [], $buffer)));
pin('an unknown method is refused', 'refused', $refused(fn () => CurlHttpClient::options('TRACE', 'https://example.com/', [], null, [], $buffer)));
pin('a header value with a line break is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'https://example.com/', ['X-A' => "1\r\nX-B: 2"], null, [], $buffer)));
pin('a bad header name is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'https://example.com/', ['X A' => '1'], null, [], $buffer)));
pin('a zero timeout is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'https://example.com/', [], null, ['timeout' => 0], $buffer)));
pin('a string max_bytes is refused', 'refused', $refused(fn () => (new CurlHttpClient())->send('GET', 'https://example.com/', [], null, ['max_bytes' => '10'])));
pin('a refused call never connects', 'refused', $refused(fn () => (new CurlHttpClient())->send('GET', 'http://127.0.0.1:1/')));

harness_section('the size cap');
$buffer = new ResponseBuffer(10);
$o      = CurlHttpClient::options('GET', 'https://example.com/', [], null, [], $buffer);
pin('a chunk under the cap is taken', 6, $o[CURLOPT_WRITEFUNCTION](null, 'abcdef'));
pin('a chunk reaching the cap is taken', 4, $o[CURLOPT_WRITEFUNCTION](null, 'ghij'));
check('not overflowed at the cap', !$buffer->overflowed());
pin('one byte more stops the transfer', 0, $o[CURLOPT_WRITEFUNCTION](null, 'k'));
pin('and is marked', [true, 'abcdefghij'], [$buffer->overflowed(), $buffer->body()]);

harness_section('response headers');
$buffer = new ResponseBuffer(10);
foreach (["HTTP/1.1 100 Continue\r\n", "X-Early: 1\r\n", "\r\n", "HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "Set-Cookie: a=1\r\n", "set-cookie: b=2\r\n", "\r\n"] as $line) {
    $buffer->header($line);
}
pin('lower-cased, repeated ones joined, a 100 Continue dropped', ['content-type' => 'application/json', 'set-cookie' => 'a=1, b=2'], $buffer->headers());

harness_section('errors');
$message = '';
try {
    (new CurlHttpClient())->send('GET', 'https://127.0.0.1:1/v1/secret-path?api_key=SECRET', ['Authorization' => 'Bearer TOKEN'], null, ['connect_timeout' => 2, 'timeout' => 2]);
} catch (HttpException $e) {
    $message = $e->getMessage();
}
check('a closed port throws HttpException', $message !== '');
check('naming the host only', str_contains($message, '127.0.0.1') && !preg_match('/SECRET|TOKEN|secret-path|api_key/', $message));

if (getenv('HTTP_CLIENT_NETWORK_TEST')) {
    harness_section('network');
    $r = (new CurlHttpClient())->send('GET', 'https://example.com/');
    pin('example.com answers 200', 200, $r['status']);
    check('with a lower-cased content-type', str_contains($r['headers']['content-type'] ?? '', 'text/html'));
    $capped = '';
    try {
        (new CurlHttpClient())->send('GET', 'https://example.com/', [], null, ['max_bytes' => 100]);
    } catch (HttpException $e) {
        $capped = $e->getMessage();
    }
    check('a larger answer than max_bytes throws', str_contains($capped, 'larger than 100 bytes'));
}

exit(harness_result());
