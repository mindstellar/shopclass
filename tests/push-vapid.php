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
 * Web push keys and sender: the VAPID key pair, the signed header (checked with openssl against
 * the public point), the DER-to-raw signature step, and the one empty POST the sender makes and
 * how it reads the answer. The key-value table and preferences are in-memory stand-ins.
 *
 * DB-free.  Usage: php tests/push-vapid.php
 */

declare(strict_types=1);

namespace mindstellar\model {
    /** An in-memory stand-in for the key-value table: only what Vapid uses. */
    class KeyValue
    {
        /** @var array<string,array{value:?string, expires:?int}> */
        public array $rows = [];

        public function claim(string $group, string $key, int $expiresAt, string $state = 'locked', ?string $value = null, ?int $now = null): bool
        {
            $id = $group . '|' . $key;
            if (isset($this->rows[$id]) && ($this->rows[$id]['expires'] ?? PHP_INT_MAX) > ($now ?? time())) {
                return false;
            }
            $this->rows[$id] = ['value' => $value, 'expires' => $expiresAt];

            return true;
        }

        public function delete(string $group, string $key): int
        {
            $id = $group . '|' . $key;
            $had = isset($this->rows[$id]);
            unset($this->rows[$id]);

            return $had ? 1 : 0;
        }
    }
}

namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);

    define('ABS_PATH', dirname(__DIR__) . '/');

    require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
    require_once __DIR__ . '/lib/harness.php';

    use mindstellar\http\HttpClient;
    use mindstellar\http\HttpException;
    use mindstellar\live\LiveStatus;
    use mindstellar\model\KeyValue;
    use mindstellar\push\PushSubscriptions;
    use mindstellar\push\Vapid;
    use mindstellar\push\WebPushSender;
    use mindstellar\security\AddressGuard;
    use mindstellar\utility\Clock;

    $GLOBALS['prefs'] = [];

    function osc_get_preference($key, $section = 'osclass')
    {
        return $GLOBALS['prefs'][$key] ?? '';
    }

    function osc_set_preference($key, $value = '', $section = 'osclass', $type = 'STRING')
    {
        $GLOBALS['prefs'][$key] = $value;

        return true;
    }

    function osc_reset_preferences()
    {
        return true;
    }

    function osc_contact_email()
    {
        return 'admin@example.test';
    }

    final class PvClock implements Clock
    {
        public function __construct(public int $t = 1700000000)
        {
        }

        public function now(): int
        {
            return $this->t;
        }
    }

    /** Records calls instead of writing the table. */
    final class PvStatus extends LiveStatus
    {
        /** @var list<string> */
        public array $calls = [];

        public function __construct()
        {
        }

        public function ok(): void
        {
            $this->calls[] = 'ok';
        }

        public function failed(string $error): void
        {
            $this->calls[] = 'failed: ' . $error;
        }
    }

    /** Records each request and answers with the next scripted status, or throws. */
    final class PvHttp implements HttpClient
    {
        /** @var list<array{method:string, url:string, headers:array<string,string>, body:?string, options:array<string,mixed>}> */
        public array $requests = [];

        public int|\Throwable $answer = 201;

        public function send(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array
        {
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
            if ($this->answer instanceof \Throwable) {
                throw $this->answer;
            }

            return ['status' => $this->answer, 'headers' => [], 'body' => ''];
        }
    }

    $b64d = static fn (string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);

    /** The public point as a PEM openssl can verify with. */
    $pointPem = static function (string $point): string {
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    };

    /** A 64-byte r||s back to DER, written here independently of the class. */
    $toDer = static function (string $raw): string {
        $int = static function (string $n): string {
            $n = ltrim($n, "\x00");
            if ($n === '' || ord($n[0]) & 0x80) {
                $n = "\x00" . $n;
            }

            return "\x02" . chr(strlen($n)) . $n;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));

        return "\x30" . chr(strlen($body)) . $body;
    };

    $clock = new PvClock();
    $kv    = new KeyValue();
    $vapid = new Vapid($kv, $clock);

    harness_section('keys');
    $public = $vapid->publicKey();
    $point  = $b64d($public);
    pin('public key is base64url, no padding', 1, preg_match('/^[A-Za-z0-9_-]+$/', $public));
    pin('a 65-byte point', 65, strlen($point));
    pin('uncompressed (0x04 first)', "\x04", $point[0]);
    pin('both preferences written', [Vapid::PUBLIC_PREF, Vapid::PRIVATE_PREF], array_values(array_intersect([Vapid::PUBLIC_PREF, Vapid::PRIVATE_PREF], array_keys($GLOBALS['prefs']))));
    pin('the lock is let go', [], $kv->rows);
    pin('a second instance reads the same pair', $public, (new Vapid($kv, $clock))->publicKey());
    $details = openssl_pkey_get_details(openssl_pkey_get_private($vapid->privatePem()));
    pin('the private PEM belongs to the public point', $point, "\x04" . str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT));

    harness_section('rawSignature');
    $key = openssl_pkey_get_private($vapid->privatePem());
    $der = '';
    for ($i = 0; $i < 500; $i++) {
        openssl_sign('m' . $i, $der, $key, OPENSSL_ALGO_SHA256);
        if (ord($der[3]) === 33) {
            break;
        }
    }
    pin('found a signature whose r DER pads with a zero byte', 33, ord($der[3]));
    $raw = Vapid::rawSignature($der);
    pin('64 bytes', 64, strlen($raw));
    pin('rebuilt DER verifies against the public point', 1, openssl_verify('m' . $i, $toDer($raw), $pointPem($point), OPENSSL_ALGO_SHA256));
    pin('the zero pad is gone: r has its high bit set', true, (ord($raw[0]) & 0x80) !== 0);
    // A short r: DER drops leading zero bytes, raw keeps 32.
    $shortR = "\x30\x26\x02\x01\x05\x02\x21\x00" . str_repeat("\xAA", 32);
    pin('a 1-byte r is left-padded', str_repeat("\x00", 31) . "\x05" . str_repeat("\xAA", 32), Vapid::rawSignature($shortR));
    foreach (['empty' => '', 'not a sequence' => "\x31\x06\x02\x01\x01\x02\x01\x01", 'length lies' => "\x30\x07\x02\x01\x01\x02\x01\x01", 'r too long' => "\x30\x27\x02\x22" . str_repeat("\x01", 34) . "\x02\x01\x01"] as $label => $bad) {
        $threw = false;
        try {
            Vapid::rawSignature($bad);
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        pin('malformed DER throws: ' . $label, true, $threw);
    }

    harness_section('authorization');
    $auth = $vapid->authorization('https://fcm.googleapis.com/fcm/send/x');
    pin('vapid t=<jwt>, k=<key>', 1, preg_match('/^vapid t=([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+), k=([A-Za-z0-9_-]+)$/', $auth, $m));
    [$h, $c, $s] = explode('.', $m[1]);
    pin('header', ['typ' => 'JWT', 'alg' => 'ES256'], json_decode($b64d($h), true));
    $claims = json_decode($b64d($c), true);
    pin('aud is the origin only', 'https://fcm.googleapis.com', $claims['aud'] ?? null);
    pin('exp within 12 h', true, $claims['exp'] > $clock->t && $claims['exp'] <= $clock->t + 43200);
    pin('sub is mailto:', 'mailto:admin@example.test', $claims['sub'] ?? null);
    pin('k= is the public key', $public, $m[2]);
    pin('raw signature is 64 bytes', 64, strlen($b64d($s)));
    pin('the JWT verifies against the public point', 1, openssl_verify($h . '.' . $c, $toDer($b64d($s)), $pointPem($point), OPENSSL_ALGO_SHA256));
    $aud = static function (string $url) use ($vapid, $b64d): ?string {
        preg_match('/t=[^.]+\.([^.]+)\./', $vapid->authorization($url), $m);

        return json_decode($b64d($m[1]), true)['aud'] ?? null;
    };
    pin('aud keeps an odd port', 'https://push.example.com:8443', $aud('https://push.example.com:8443/a?b=c'));
    pin('aud drops :443', 'https://push.example.com', $aud('https://push.example.com:443/a'));

    harness_section('sender');
    $http   = new PvHttp();
    $status = new PvStatus();
    $guard  = new AddressGuard(static fn (string $host): array => ['142.250.1.1']);
    $sender = new WebPushSender($http, $guard, $vapid, $status);
    $ep     = 'https://fcm.googleapis.com/fcm/send/abc';

    pin('201 -> ok', 'ok', $sender->send($ep));
    $req = $http->requests[0];
    pin('POST to the endpoint', ['POST', $ep], [$req['method'], $req['url']]);
    pin('no body', null, $req['body']);
    pin('Content-Length: 0', '0', $req['headers']['Content-Length'] ?? null);
    pin('TTL', '86400', $req['headers']['TTL'] ?? null);
    pin('Urgency', 'normal', $req['headers']['Urgency'] ?? null);
    pin('Topic', 'shopclass-messages', $req['headers']['Topic'] ?? null);
    pin('Authorization: vapid', 1, preg_match('/^vapid t=.+, k=' . preg_quote($public, '/') . '$/', $req['headers']['Authorization'] ?? ''));
    pin('no other headers', ['Content-Length', 'TTL', 'Urgency', 'Topic', 'Authorization'], array_keys($req['headers']));
    pin('timeouts, cap and the checked IP pinned', ['connect_timeout' => 2, 'timeout' => 5, 'max_bytes' => 4096, 'resolve' => ['fcm.googleapis.com:443:142.250.1.1']], $req['options']);
    pin('ok recorded', ['ok'], $status->calls);

    $status->calls = [];
    $http->answer  = 200;
    pin('200 -> ok', 'ok', $sender->send($ep));
    $http->answer = 404;
    pin('404 -> gone', 'gone', $sender->send($ep));
    $http->answer = 410;
    pin('410 -> gone', 'gone', $sender->send($ep));
    $http->answer = 429;
    pin('429 -> retry', 'retry', $sender->send($ep));
    pin('gone and 429 leave the status alone', ['ok'], $status->calls);
    $status->calls = [];
    $http->answer  = 503;
    pin('503 -> retry', 'retry', $sender->send($ep));
    pin('a failing push service marks the status failed', ['failed: HTTP 503'], $status->calls);

    foreach ([400, 413] as $code) {
        $status->calls = [];
        $http->answer  = $code;
        pin("$code -> failed", 'failed', $sender->send($ep));
        pin("$code is about one browser: the status is left alone", [], $status->calls);
    }
    foreach ([401, 403] as $code) {
        $status->calls = [];
        $http->answer  = $code;
        pin("$code -> failed", 'failed', $sender->send($ep));
        pin("$code points at the site's VAPID key: recorded", ["failed: HTTP $code: the push service refused the site's VAPID key"], $status->calls);
    }

    $status->calls = [];
    $http->answer  = new HttpException('fcm.googleapis.com could not be reached');
    pin('a thrown HttpException -> failed', 'failed', $sender->send($ep));
    pin('and recorded', 1, count($status->calls));
    $http->answer = new \RuntimeException('anything');
    pin('any throwable -> failed, never thrown', 'failed', $sender->send($ep));

    $status->calls = [];
    $before = count($http->requests);
    pin('http:// refused', 'failed', $sender->send('http://fcm.googleapis.com/fcm/send/abc'));
    pin('https://127.0.0.1/x refused', 'failed', $sender->send('https://127.0.0.1/x'));
    pin('an odd port refused', 'failed', $sender->send('https://fcm.googleapis.com:8443/x'));
    pin('over 1024 bytes refused', 'failed', $sender->send('https://fcm.googleapis.com/' . str_repeat('a', 1024)));
    pin('no request for a refused address', $before, count($http->requests));
    pin('a refused address is that browser\'s problem: the status is left alone', [], $status->calls);

    harness_section('browser id and family');
    $id = PushSubscriptions::browserId($ep);
    pin('22 base64url characters', 1, preg_match('/^[A-Za-z0-9_-]{22}$/', $id));
    pin('sha256 of the endpoint', substr(rtrim(strtr(base64_encode(hash('sha256', $ep, true)), '+/', '-_'), '='), 0, 22), $id);
    pin('Chrome UA -> Chrome', 'Chrome', PushSubscriptions::browserFamily('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'));
    pin('Edge UA -> Edge', 'Edge', PushSubscriptions::browserFamily('Mozilla/5.0 Chrome/130.0 Safari/537.36 Edg/130.0'));
    pin('Firefox UA -> Firefox', 'Firefox', PushSubscriptions::browserFamily('Mozilla/5.0 (X11; Linux; rv:131.0) Gecko/20100101 Firefox/131.0'));
    pin('unknown -> Other', 'Other', PushSubscriptions::browserFamily('curl/8.0'));

    exit(harness_result());
}
