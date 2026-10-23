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
 * Live notes from the messaging service: one note to the recipient of a sent or released
 * message, none for a held one, one to the reader on markRead, always after commit and ids
 * only. A stalled nchan cannot slow a send past about a second. Off by default; the status
 * row written by LiveStatus flips only when the outcome flips.
 *
 * Usage:  php tests/models/messaging-live.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-live  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\event\DispatcherInterface;
use mindstellar\live\LivePublisher;
use mindstellar\live\LiveStatus;
use mindstellar\live\NchanPublisher;
use mindstellar\live\NullPublisher;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\MessagingService;
use mindstellar\model\KeyValue;
use mindstellar\moderation\MessageModeration;
use mindstellar\utility\Clock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$admin = api_admin_boot('osc_models_messaging_live');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;

/** Records every publish and whether a transaction was open. */
final class MlRecorder implements LivePublisher
{
    /** @var list<array{channel:string, note:array, in_transaction:bool}> */
    public array $sent = [];

    public function publish(string $channel, array $note): bool
    {
        $this->sent[] = ['channel' => $channel, 'note' => $note, 'in_transaction' => Db::inTransaction()];

        return true;
    }

    public function subscribeUrl(string $channel): ?string
    {
        return '/live/sub/' . $channel;
    }

    public function mode(): string
    {
        return 'nchan';
    }
}

/** A clock that moves one second on every read, so a changed value shows a write. */
final class MlClock implements Clock
{
    private int $t = 1000;

    public function now(): int
    {
        return ++$this->t;
    }
}

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$count = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_row()[0];
};

$buyer    = seed_user($admin, 'bea', 'bea@example.test');
$seller   = seed_user($admin, 'sam', 'sam@example.test');
$cat      = seed_category($admin);
$bike     = seed_item($admin, $cat, $seller, 'Bike');
$asBuyer  = Actor::user($buyer, '10.0.0.1');
$asSeller = Actor::user($seller, '10.0.0.2');
$asAdmin  = Actor::admin(1, '10.0.0.9');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

/** A fresh container whose publisher is the recorder. */
$fresh = static function () use (&$recorder): Container {
    harness_reset_container();
    $c        = Container::root();
    $recorder = new MlRecorder();
    $c->instance(LivePublisher::class, $recorder);

    return $c;
};
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};

harness_section('off by default');
putenv('OSC_LIVE');
putenv('OSC_LIVE_PUBLISH_URL');
putenv('OSC_LIVE_SUBSCRIBE_URL');
harness_reset_container();
$c = Container::root();
pin('no env: NullPublisher', NullPublisher::class, get_class($c->get(LivePublisher::class)));
pin('and no live URL', '', osc_messaging_live_url());
putenv('OSC_LIVE=off');
harness_reset_container();
pin('OSC_LIVE=off: NullPublisher', NullPublisher::class, get_class(Container::root()->get(LivePublisher::class)));
putenv('OSC_LIVE=nchan');
harness_reset_container();
pin('OSC_LIVE=nchan: NchanPublisher', NchanPublisher::class, get_class(Container::root()->get(LivePublisher::class)));
pin('no one signed in: no live URL', '', osc_messaging_live_url());
putenv('OSC_LIVE');

harness_section('notes from the service');
$c   = $fresh();
$svc = $c->get(MessagingService::class);
$res = $svc->start($bike, 'Is it still for sale?', $asBuyer);
pin('one note, to the seller', 1, count($recorder->sent));
pin('the channel is the recipient', 'user-' . $seller, $recorder->sent[0]['channel'] ?? '');
pin('the note is ids only', ['kind' => 'sent', 'conversation' => $res->conversationId(), 'message' => $res->messageId()], $recorder->sent[0]['note'] ?? []);
pin('published after commit', false, $recorder->sent[0]['in_transaction'] ?? true);
pin('no message text anywhere in the notes', false, str_contains(json_encode($recorder->sent), 'for sale'));

$recorder->sent = [];
$svc->reply($res->conversationId(), 'Yes.', $asSeller);
pin('a reply notes the buyer', ['user-' . $buyer], array_column($recorder->sent, 'channel'));

$recorder->sent = [];
pin('markRead clears the seller side', 1, $svc->markRead($res->conversationId(), $asSeller));
pin('one read note, to the reader', [['channel' => 'user-' . $seller, 'note' => ['kind' => 'read', 'conversation' => $res->conversationId()]]], array_map(
    static fn (array $r): array => ['channel' => $r['channel'], 'note' => $r['note']],
    $recorder->sent
));
$recorder->sent = [];
$svc->markRead($res->conversationId(), $asSeller);
pin('nothing to read: no note', 0, count($recorder->sent));

harness_section('held and released');
$events = $c->get(DispatcherInterface::class);
$recorder->sent = [];
$events->listen(MessageSending::class, $hold);
$heldMsg = $svc->reply($res->conversationId(), 'Held one', $asBuyer);
$events->remove(MessageSending::class, $hold);
pin('the message is held', true, $heldMsg->held());
pin('a held message publishes nothing', 0, count($recorder->sent));
$c->get(MessageModeration::class)->release($heldMsg->messageId(), $asAdmin);
pin('release publishes one note, to the seller', ['user-' . $seller], array_column($recorder->sent, 'channel'));
pin('with the released message id', $heldMsg->messageId(), $recorder->sent[0]['note']['message'] ?? 0);

harness_section('helper');
$c = $fresh();
pin('signed out: empty', '', osc_messaging_live_url());
Session::getInstance()->_setEphemeral('userId', $buyer);
pin('signed in: the channel URL', '/live/sub/user-' . $buyer, osc_messaging_live_url());
$set('enabled_messaging', '0');
pin('messaging off: empty', '', osc_messaging_live_url());
$set('enabled_messaging', '1');
Session::getInstance()->_dropEphemeral('userId');
putenv('OSC_LIVE=nchan');
harness_reset_container();
Session::getInstance()->_setEphemeral('userId', $buyer);
pin('real publisher: default subscribe path', '/live/sub/user-' . $buyer, osc_messaging_live_url());
putenv('OSC_LIVE_SUBSCRIBE_URL=/stream/');
harness_reset_container();
pin('override', '/stream/user-' . $buyer, osc_messaging_live_url());
putenv('OSC_LIVE_SUBSCRIBE_URL');
putenv('OSC_LIVE');
Session::getInstance()->_dropEphemeral('userId');

harness_section('a stalled nchan cannot hold up a send');
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$name   = (string) stream_socket_get_name($server, false);
$port   = (int) substr($name, strrpos($name, ':') + 1);
putenv('OSC_LIVE=nchan');
putenv('OSC_LIVE_PUBLISH_URL=http://127.0.0.1:' . $port . '/live/pub/');
harness_reset_container();
$c = Container::root();
pin('the real publisher is wired', NchanPublisher::class, get_class($c->get(LivePublisher::class)));
$seen = [];
osc_add_hook('message_sent', static function () use (&$seen): void {
    $seen[] = 'message_sent';
});
$t    = microtime(true);
$sent = $c->get(MessagingService::class)->reply($res->conversationId(), 'Stall test', $asBuyer);
$took = microtime(true) - $t;
pin('the message row exists', 1, $count("SELECT COUNT(*) FROM {$p}t_message WHERE pk_i_id = " . $sent->messageId()));
pin('MessageSent fired', ['message_sent'], $seen);
pin('under 2 s (took ' . round($took, 2) . ')', true, $took < 2.0);
pin('the failure was recorded', false, $c->get(LiveStatus::class)->last()['ok'] ?? null);
fclose($server);
putenv('OSC_LIVE');
putenv('OSC_LIVE_PUBLISH_URL');

harness_section('status flips');
$log = tempnam(sys_get_temp_dir(), 'livelog');
$old = ini_set('error_log', $log);
$kv  = new KeyValue();
$kv->delete(LiveStatus::GROUP, LiveStatus::KEY);
$status = new LiveStatus($kv, new MlClock());
$bad    = new NchanPublisher('http://x/live/pub/', '/live/sub/', $status, new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 500])));
$good   = new NchanPublisher('http://x/live/pub/', '/live/sub/', $status, new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 200])));
$lines  = static fn (): int => count(array_filter(explode("\n", (string) file_get_contents($log)), static fn ($l) => $l !== ''));
pin('nothing published yet', null, $status->last());
$bad->publish('user-1', ['kind' => 'read', 'conversation' => 1]);
$first = $status->last();
pin('first failure is stored', [false, 'HTTP 500'], [$first['ok'] ?? null, $first['error'] ?? null]);
pin('and logged once', 1, $lines());
$bad->publish('user-1', ['kind' => 'read', 'conversation' => 1]);
$bad->publish('user-1', ['kind' => 'read', 'conversation' => 1]);
pin('more failures write nothing', $first, $status->last());
pin('and log nothing', 1, $lines());
$good->publish('user-1', ['kind' => 'read', 'conversation' => 1]);
$ok = $status->last();
pin('a success after failures is stored', true, $ok['ok'] ?? null);
pin('at the time of the flip', true, ($ok['at'] ?? 0) > ($first['at'] ?? 0));
$good->publish('user-1', ['kind' => 'read', 'conversation' => 1]);
pin('more successes write nothing', $ok, $status->last());
pin('a success does not log', 1, $lines());
$kv->delete(LiveStatus::GROUP, LiveStatus::KEY);
$good->publish('user-1', ['kind' => 'read', 'conversation' => 1]);
pin('the very first success is stored too', true, $status->last()['ok'] ?? null);
ini_set('error_log', $old === false ? '' : $old);
@unlink($log);

exit(harness_result());
