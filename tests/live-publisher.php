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
 * The live publishers: NullPublisher does nothing, NchanPublisher sends one exact POST and
 * never throws, whatever the server does (refuses, errors, stalls). The status flip rules run
 * on the real table in tests/models/messaging-live.php.
 *
 * DB-free.  Usage: php tests/live-publisher.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\live\LiveChannel;
use mindstellar\live\LiveStatus;
use mindstellar\live\NchanPublisher;
use mindstellar\live\NullPublisher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** A status that records calls instead of writing the table. */
final class LpStatus extends LiveStatus
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
        $this->calls[] = 'failed';
    }
}

harness_section('LiveChannel');
pin('forUser', 'user-7', LiveChannel::forUser(7));
pin('userId round-trips', 7, LiveChannel::userId(LiveChannel::forUser(7)));
foreach (['user-', 'user-0', 'user-07', 'user-7 ', "user-7\n", 'conv-7', '../user-7'] as $bad) {
    pin('not a user channel: ' . json_encode($bad), null, LiveChannel::userId($bad));
}
$entry = (string) file_get_contents(ABS_PATH . '.docker/prod/entrypoint.sh');
preg_match_all('#location ~ \^/live/(?:pub|sub)/\((?:\?<\w+>)?([^)]+)\)\$#', $entry, $m);
pin('the nginx publisher and subscriber match one channel shape each', 2, count($m[1]));
foreach ($m[1] as $i => $nginx) {
    $same = true;
    foreach (['user-7', 'user-123', 'conv-7', 'user-', 'user-x'] as $name) {
        $same = $same && (preg_match('#^' . $nginx . '$#D', $name) === 1) === (LiveChannel::userId($name) !== null);
    }
    pin('nginx channel regex ' . ($i + 1) . ' agrees with LiveChannel', true, $same);
}

harness_section('NullPublisher');
$null = new NullPublisher();
pin('publish says true', true, $null->publish('user-7', ['kind' => 'sent']));
pin('no subscribe URL', null, $null->subscribeUrl('user-7'));
pin('mode is off', 'off', $null->mode());

harness_section('NchanPublisher request');
$seen   = [];
$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
    $seen[] = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? null, 'headers' => $options['headers'] ?? []];

    return new MockResponse('', ['http_code' => 202]);
});
$status = new LpStatus();
$pub    = new NchanPublisher('http://127.0.0.1:8090/live/pub/', '/live/sub/', $status, $client);
$note   = ['kind' => 'sent', 'conversation' => 7, 'message' => 12];
pin('a 2xx answer is a success', true, $pub->publish('user-7', $note));
pin('one POST to the channel', ['POST', 'http://127.0.0.1:8090/live/pub/user-7'], [$seen[0]['method'] ?? '', $seen[0]['url'] ?? '']);
pin('the whole body is the note, nothing else', '{"kind":"sent","conversation":7,"message":12}', $seen[0]['body'] ?? '');
pin('sent as JSON', true, in_array('Content-Type: application/json', $seen[0]['headers'] ?? [], true));
pin('status told ok', ['ok'], $status->calls);
pin('mode is nchan', 'nchan', $pub->mode());
pin('subscribe URL', '/live/sub/user-7', $pub->subscribeUrl('user-7'));

harness_section('bad channels');
$seen = [];
foreach (['user-', 'user-7/../x', 'conv-7', 'user-7 ', "user-7\n", '../user-7', ''] as $bad) {
    pin('refused: ' . json_encode($bad), [false, null], [$pub->publish($bad, $note), $pub->subscribeUrl($bad)]);
}
pin('and no request was made', [], $seen);

harness_section('failures');
$status = new LpStatus();
$pub    = new NchanPublisher('http://127.0.0.1:8090/live/pub', '/live/sub', $status, new MockHttpClient(new MockResponse('', ['http_code' => 500])));
pin('a 500 is false', false, $pub->publish('user-7', $note));
pin('and recorded as failed', ['failed'], $status->calls);
pin('a trailing slash is optional', '/live/sub/user-7', $pub->subscribeUrl('user-7'));

$status = new LpStatus();
$pub    = new NchanPublisher('http://127.0.0.1:8090/live/pub/', '/live/sub/', $status, new MockHttpClient(static function (): MockResponse {
    throw new \RuntimeException('boom');
}));
pin('a throwing client is false, not an exception', false, $pub->publish('user-7', $note));
pin('and recorded as failed', ['failed'], $status->calls);

harness_section('real client: dead port');
$status = new LpStatus();
$pub    = new NchanPublisher('http://127.0.0.1:9/live/pub/', '/live/sub/', $status);
$t      = microtime(true);
$result = $pub->publish('user-7', $note);
$took   = microtime(true) - $t;
pin('false', false, $result);
pin('under 0.5 s (took ' . round($took, 3) . ')', true, $took < 0.5);
pin('recorded as failed', ['failed'], $status->calls);

harness_section('real client: server that never answers');
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$name   = (string) stream_socket_get_name($server, false);
$port   = (int) substr($name, strrpos($name, ':') + 1);
$status = new LpStatus();
$pub    = new NchanPublisher('http://127.0.0.1:' . $port . '/live/pub/', '/live/sub/', $status);
// Accept in the background of the client call: the backlog holds the connection, nobody reads it.
$t      = microtime(true);
$result = $pub->publish('user-7', $note);
$took   = microtime(true) - $t;
pin('false', false, $result);
pin('under 1.5 s (took ' . round($took, 3) . ')', true, $took < 1.5);
pin('recorded as failed', ['failed'], $status->calls);
fclose($server);

exit(harness_result());
