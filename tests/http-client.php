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
check('a default user agent is added, with no made-up version', in_array('User-Agent: Shopclass', $o[CURLOPT_HTTPHEADER], true));
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

pin('https:// with no host is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'https://', [], null, [], $buffer)));
pin('an https: URL that parses with no host is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', 'https:example.com/x', [], null, [], $buffer)));
pin('resolve pins the host to checked addresses', ['example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'], CurlHttpClient::options('GET', 'https://example.com/', [], null, ['resolve' => ['example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]']], $buffer)[CURLOPT_RESOLVE] ?? null);
pin('no resolve, no pinning', false, array_key_exists(CURLOPT_RESOLVE, $o));
foreach (array(
    'another host'           => array('https://example.com/', ['other.test:443:1.2.3.4']),
    'another port'           => array('https://example.com:8443/', ['example.com:443:1.2.3.4']),
    'no address'             => array('https://example.com/', ['example.com:443:not-an-ip']),
    'an empty list'          => array('https://example.com/', []),
    'two entries'            => array('https://example.com/', ['example.com:443:1.2.3.4', 'example.com:443:5.6.7.8']),
    'a leading hyphen'       => array('https://-example.com/', ['-example.com:443:1.2.3.4']),
    'an IPv6 without brackets' => array('https://example.com/', ['example.com:443:2606:2800::1']),
) as $what => [$url, $resolve]) {
    pin('resolve with ' . $what . ' is refused', 'refused', $refused(fn () => CurlHttpClient::options('GET', $url, [], null, ['resolve' => $resolve], $buffer)));
}
pin('cURL is told not to use signals, so short timeouts work', true, $o[CURLOPT_NOSIGNAL] ?? null);
pin('the default cap is 1 MB', 1048576, CurlHttpClient::MAX_BYTES);

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
pin('header bytes are capped apart from the body', [ResponseBuffer::MAX_HEADER_BYTES - 1, 0, true], [$buffer->header(str_repeat('h', ResponseBuffer::MAX_HEADER_BYTES - 1)), $buffer->header('hh'), $buffer->headersOverflowed()]);
$buffer = new ResponseBuffer(10);
foreach (["HTTP/1.1 100 Continue\r\n", "X-Early: 1\r\n", "\r\n", "HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "Set-Cookie: a=1\r\n", "set-cookie: b=2\r\n", "\r\n"] as $line) {
    $buffer->header($line);
}
pin('lower-cased, repeated ones joined, a 100 Continue dropped', ['content-type' => 'application/json', 'set-cookie' => 'a=1, b=2'], $buffer->headers());

harness_section('the request, with a fake transport');
$fake = static fn (array $chunks, int $status, array $headers = []) => static function (array $o) use ($chunks, $status, $headers): array {
    foreach ($headers as $line) {
        $o[CURLOPT_HEADERFUNCTION](null, $line);
    }
    foreach ($chunks as $chunk) {
        if ($o[CURLOPT_WRITEFUNCTION](null, $chunk) !== strlen($chunk)) {
            return ['ok' => false, 'status' => $status, 'errno' => 23];
        }
    }

    return ['ok' => true, 'status' => $status, 'errno' => 0];
};
$r = (new CurlHttpClient($fake(['{"a":', '1}'], 201, ["HTTP/1.1 201 Created\r\n", "Content-Type: application/json\r\n"])))->send('POST', 'https://api.example.com/x', [], '{}');
pin('status, lower-cased headers and body come back', [201, ['content-type' => 'application/json'], '{"a":1}'], [$r['status'], $r['headers'], $r['body']]);

$caught = static function (callable $call): string {
    try {
        $call();
    } catch (HttpException $e) {
        return $e->getMessage();
    }

    return '';
};
$message = $caught(fn () => (new CurlHttpClient($fake([str_repeat('x', 60), str_repeat('x', 60)], 200)))->send('GET', 'https://api.example.com/v1?key=SECRET', [], null, ['max_bytes' => 100]));
check('an answer past max_bytes throws, saying the limit', str_contains($message, 'larger than 100 bytes'));
check('without the query string', $message !== '' && !str_contains($message, 'SECRET'));
$message = $caught(fn () => (new CurlHttpClient($fake([str_repeat('x', CurlHttpClient::MAX_BYTES), 'x'], 200)))->send('GET', 'https://api.example.com/'));
check('the default cap applies with no option', str_contains($message, 'larger than 1048576 bytes'));
$message = $caught(fn () => (new CurlHttpClient($fake([], 200)))->send('GET', 'https://api.example.com/'));
$r       = null;
try {
    $r = (new CurlHttpClient(static fn (): array => ['ok' => false, 'status' => 200, 'errno' => 28]))->send('GET', 'https://api.example.com/');
} catch (HttpException $e) {
    $message = $e->getMessage();
}
check('a transfer cut short after a 200 (a timeout) is an HttpException, not an answer', $r === null && str_contains($message, 'cURL error 28'));
$big     = array_fill(0, 70, 'X-Pad: ' . str_repeat('p', 1000) . "\r\n");
$message = $caught(fn () => (new CurlHttpClient($fake(['ok'], 200, $big)))->send('GET', 'https://api.example.com/'));
check('headers past 64 KB stop the transfer', str_contains($message, 'headers from api.example.com were larger than 65536 bytes'));
$message = $caught(fn () => (new CurlHttpClient($fake([], 0)))->send('GET', 'https://api.example.com/v1?key=SECRET'));
check('no status is an HttpException', $message !== '' && !str_contains($message, 'SECRET'));

harness_section('no cURL');
$noCurl = 'none';
try {
    (new CurlHttpClient(null, static fn (): bool => false))->send('GET', 'https://api.example.com/', [], null, ['timeout' => 0]);
} catch (HttpException $e) {
    $noCurl = $e->getMessage();
} catch (\Throwable $e) {
    $noCurl = get_class($e);
}
pin('without cURL send() throws HttpException before building any option', 'This server cannot make HTTP requests (no cURL)', $noCurl);
check('a fake transport works without asking', (new CurlHttpClient(static fn (): array => ['ok' => true, 'status' => 204, 'errno' => 0], static fn (): bool => false))->send('GET', 'https://api.example.com/')['status'] === 204);

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
