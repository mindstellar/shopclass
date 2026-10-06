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
 * Webhooks without a database: Standard Webhooks signatures against the spec's own test
 * vector, two signatures while a rotated-out secret is valid, the receiver's check, message
 * ids, an endpoint's failure count pausing it on the eighth failure in a row, the event
 * catalogue with plugin events, and AddressGuard's private-network switch.
 *
 * DB-free.  Usage: php tests/api-webhooks.php
 */

define('OSC_CSRF_SECRET', 'api-webhooks-test-secret');
require_once __DIR__ . '/lib/api-boot.php';

use mindstellar\api\write\ImageFetcher;
use mindstellar\security\AddressGuard;
use mindstellar\webhook\CurlTransport;
use mindstellar\webhook\Delivery;
use mindstellar\webhook\Endpoint;
use mindstellar\webhook\Events;
use mindstellar\webhook\Signer;
use mindstellar\webhook\TransportResult;

harness_section('Standard Webhooks test vector');
// From the Standard Webhooks reference libraries' tests.
$vector = [
    'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
    'id'     => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
    'ts'     => 1614265330,
    'body'   => '{"test": 2432232314}',
    'sig'    => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
];
pin('the signature is the spec\'s', $vector['sig'], Signer::sign($vector['id'], $vector['ts'], $vector['body'], [$vector['secret']]));
check('the receiver\'s check accepts it', Signer::verify($vector['secret'], $vector['id'], (string) $vector['ts'], $vector['body'], $vector['sig'], $vector['ts'] + 10));
check('a changed body is refused', !Signer::verify($vector['secret'], $vector['id'], (string) $vector['ts'], '{"test": 2432232315}', $vector['sig'], $vector['ts']));
check('a changed id is refused', !Signer::verify($vector['secret'], 'msg_other', (string) $vector['ts'], $vector['body'], $vector['sig'], $vector['ts']));
check('a timestamp over five minutes old is refused', !Signer::verify($vector['secret'], $vector['id'], (string) $vector['ts'], $vector['body'], $vector['sig'], $vector['ts'] + 301));
check('so is one from the future', !Signer::verify($vector['secret'], $vector['id'], (string) $vector['ts'], $vector['body'], $vector['sig'], $vector['ts'] - 301));
check('another secret\'s signature is refused', !Signer::verify('whsec_' . base64_encode(random_bytes(24)), $vector['id'], (string) $vector['ts'], $vector['body'], $vector['sig'], $vector['ts']));

harness_section('rotation: two signatures');
$old    = $vector['secret'];
$new    = Signer::newSecret();
$header = Signer::sign($vector['id'], $vector['ts'], $vector['body'], [$new, $old]);
pin('two v1 signatures, space separated', 2, count(explode(' ', $header)));
check('a receiver on the old secret accepts it', Signer::verify($old, $vector['id'], (string) $vector['ts'], $vector['body'], $header, $vector['ts']));
check('a receiver on the new secret accepts it', Signer::verify($new, $vector['id'], (string) $vector['ts'], $vector['body'], $header, $vector['ts']));
check('a new secret is whsec_ and 24 bytes of base64', preg_match('~^whsec_[A-Za-z0-9+/]{32}$~', $new) === 1 && strlen(base64_decode(substr($new, 6))) === 24);
$refused = false;
try {
    Signer::sign('a', 1, 'b', ['whsec_!!!']);
} catch (InvalidArgumentException $e) {
    $refused = true;
}
check('a secret that is not base64 is refused', $refused);

harness_section('message ids');
$a = Signer::messageId(1_700_000_000_000);
$b = Signer::messageId(1_700_000_000_001);
check('msg_ and a 26-character ULID', preg_match('/^msg_[0-9A-HJKMNP-TV-Z]{26}$/', $a) === 1);
check('ids made later sort later', strcmp(substr($a, 4, 10), substr($b, 4, 10)) < 0);
pin('the time part of a known instant', '01HF7YAT00', substr(Signer::messageId(1_700_000_000_000), 4, 10));

harness_section('delivery headers');
$endpoint = new Endpoint('ep_0123456789abcdef', 'https://example.com/hook', ['listing.created'], '', true, $new, $old, 2_000_000_000, created: 1, updated: 1);
$headers  = Delivery::headers('msg_x', 1_900_000_000, '{}', $endpoint->signingSecrets(1_900_000_000));
pin('the header names', ['Content-Type', 'User-Agent', 'webhook-id', 'webhook-timestamp', 'webhook-signature'], array_keys($headers));
pin('id and timestamp', ['msg_x', '1900000000'], [$headers['webhook-id'], $headers['webhook-timestamp']]);
pin('signed with both secrets while the old one is valid', 2, count(explode(' ', $headers['webhook-signature'])));
pin('with the current one only after that', 1, count($endpoint->signingSecrets(2_000_000_000)));
check('a User-Agent naming the sender', str_starts_with($headers['User-Agent'], 'Shopclass-Webhooks/'));

harness_section('failures pause an endpoint once');
$e = new Endpoint('ep_0123456789abcdef', 'https://example.com/hook', ['listing.created'], '', true, $new, created: 1, updated: 1);
for ($i = 1; $i < Endpoint::PAUSE_AFTER; $i++) {
    $e = $e->withFailure('HTTP 500', 100 + $i);
}
pin('seven failures in a row: still on', [true, 7, false], [$e->enabled(), $e->failures(), $e->paused()]);
$paused = $e->withFailure('HTTP 503', 200);
pin('the eighth pauses it', [false, true, 200, 'HTTP 503'], [$paused->enabled(), $paused->paused(), $paused->pausedAt(), $paused->lastStatus()]);
check('...and says why', str_contains((string) $paused->pausedReason(), '8 deliveries failed'));
pin('a ninth does not pause it again', 200, $paused->withFailure('HTTP 503', 300)->pausedAt());
$ok = $e->withSuccess('HTTP 204', 150);
pin('a success clears the count', [0, 'HTTP 204', 150], [$ok->failures(), $ok->lastStatus(), $ok->lastSuccess()]);
$tested = $e->withTestResult('HTTP 500', 160);
pin('a test result neither counts nor clears', [7, 'HTTP 500'], [$tested->failures(), $tested->lastStatus()]);
$on = $paused->withEnabled(true, 400);
pin('switching it on clears the pause and the count', [true, false, 0, null], [$on->enabled(), $on->paused(), $on->failures(), $on->pausedReason()]);
pin('switched off by hand is off, not paused', [false, false], [$on->withEnabled(false, 500)->enabled(), $on->withEnabled(false, 500)->paused()]);
$back = Endpoint::fromStored($paused->id(), json_decode((string) json_encode($paused->toStored()), true), 3);
pin('stored and read back, it is the same', [$paused->toArray(500), $paused->signingSecrets(500)], [$back->toArray(500), $back->signingSecrets(500)]);
check('the stored secret is encrypted', !str_contains((string) json_encode($paused->toStored()), $paused->secret()));
pin('a secret sealed under another signing key cannot be read, so nothing is signed with it', [], Endpoint::fromStored('x', ['secret' => 'enc1:' . base64_encode(str_repeat('x', 40))], 1)->signingSecrets(500));
pin('a secret stored before encryption still reads', ['whsec_old'], Endpoint::fromStored('x', ['secret' => 'whsec_old'], 1)->signingSecrets(500));

harness_section('serializer');
$out = $paused->toArray(1_900_000_000);
pin('status paused', 'paused', $out['status']);
check('never the secret', !str_contains((string) json_encode($out), $new) && !array_key_exists('secret', $out));
pin('the secret only when handed one', $new, $paused->toArray(null, $new)['secret']);
$rotated = $endpoint->withSecret(Signer::newSecret(), 1_900_086_400, 1_900_000_000);
pin('a rotation shows until when the old secret signs', '2030-03-18T17:46:40Z', $rotated->toArray(1_900_000_000)['previous_secret_until']);

harness_section('events');
$core = Events::fromHooks();
pin('core events, ping not subscribable', [
    'listing.created', 'listing.updated', 'listing.deleted', 'listing.activated', 'listing.deactivated', 'listing.spam',
    'comment.created', 'user.registered', 'user.updated', 'user.deleted',
], $core->subscribable());
check('ping is in the catalogue', $core->has(Events::PING));
$warnings = [];
set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
}, E_USER_WARNING);
$plugin = api_with_filter('api_webhook_events', static fn (array $events): array => $events + [
    'ext.acme.offer_created' => ['description' => 'An offer was made.', 'schema' => 'AcmeOffer'],
    'offer_created'          => ['description' => 'Not namespaced.'],
    'listing.created'        => ['description' => 'Overridden?'],
], static fn (): Events => Events::fromHooks());
restore_error_handler();
check('a plugin event under ext.<slug>. is added', $plugin->has('ext.acme.offer_created') && in_array('ext.acme.offer_created', $plugin->subscribable(), true));
pin('with its description and schema', ['description' => 'An offer was made.', 'schema' => 'AcmeOffer'], $plugin->all()['ext.acme.offer_created']);
check('a name outside ext. is dropped with a warning', !$plugin->has('offer_created') && count($warnings) === 1 && str_contains($warnings[0], 'offer_created'));
pin('a core event cannot be redefined', 'A listing was posted.', $plugin->description('listing.created'));

harness_section('AddressGuard: private addresses');
$resolve = static fn (string $host): array => ['localhost' => ['127.0.0.1'], 'lan.test' => ['192.168.1.20'], 'public.test' => ['93.184.216.34']][$host] ?? [];
$strict  = new AddressGuard($resolve);
$lan     = new AddressGuard($resolve, true);
pin('a LAN host is refused by default', false, $strict->check('http://lan.test/hook')['ok']);
pin('...and passed when private addresses are allowed', [true, '192.168.1.20'], [$lan->check('http://lan.test/hook')['ok'], $lan->check('http://lan.test/hook')['ip'] ?? null]);
pin('a port of its own is refused even then', [false, false], [$lan->check('http://localhost:8080/hook')['ok'], $lan->check('http://lan.test:8443/hook')['ok']]);
pin('...the standard port passes', [true, true], [$lan->check('http://localhost/hook')['ok'], $lan->check('http://lan.test:80/hook')['ok']]);
pin('but not on the strict guard', false, $strict->check('http://public.test:8080/hook')['ok']);
pin('a user name in the address is refused either way', false, $lan->check('http://user:pass@lan.test/hook')['ok']);
pin('a public host passes both', [true, true], [$strict->check('https://public.test/hook')['ok'], $lan->check('https://public.test/hook')['ok']]);
pin('a scheme other than http(s) is refused either way', false, $lan->check('ftp://lan.test/hook')['ok']);

harness_section('cURL pinning');
pin('a host name is pinned to the checked IPv4', ['public.test:443:93.184.216.34'], AddressGuard::curlOptions('https://public.test/hook', '93.184.216.34')[CURLOPT_RESOLVE]);
pin('a host name resolving to IPv6 is pinned with the address bracketed', ['v6.test:8443:[2606:2800:220:1::1]'], AddressGuard::curlOptions('https://v6.test:8443/hook', '2606:2800:220:1::1')[CURLOPT_RESOLVE]);
pin('an IPv6 literal host needs no pin', [], AddressGuard::curlOptions('http://[2606:2800:220:1::1]/hook', '2606:2800:220:1::1')[CURLOPT_RESOLVE]);
pin('nor does an IPv4 literal', [], AddressGuard::curlOptions('http://93.184.216.34/hook', '93.184.216.34')[CURLOPT_RESOLVE]);
pin('the webhook transport uses them', AddressGuard::curlOptions('https://[::1]/hook', '::1'), array_intersect_key(CurlTransport::options('https://[::1]/hook', '::1'), AddressGuard::curlOptions('https://[::1]/hook', '::1')));
pin('...as does the photo download', AddressGuard::curlOptions('http://public.test/a.jpg', '93.184.216.34'), array_intersect_key(ImageFetcher::curlOptions('http://public.test/a.jpg', '93.184.216.34', 10), AddressGuard::curlOptions('http://public.test/a.jpg', '93.184.216.34')));

harness_section('transport status');
pin('a timeout is "Timed out"', 'Timed out', CurlTransport::failure(28));
pin('any other cURL error is "Could not connect", never cURL\'s text', ['Could not connect', 'Could not connect'], [CurlTransport::failure(7), CurlTransport::failure(6)]);
pin('an answer is "HTTP <code>"', 'HTTP 502', TransportResult::answered(502)->describe());

harness_section('the pause e-mail names the endpoint, not its URL');
$secretUrl = new Endpoint('ep_0123456789abcdef', 'https://hooks.example/t/SECRET-TOKEN?key=abc', ['listing.created'], '', true, $new, created: 1, updated: 1);
pin('id and host', 'ep_0123456789abcdef (hooks.example)', Delivery::label($secretUrl));

exit(harness_result());
