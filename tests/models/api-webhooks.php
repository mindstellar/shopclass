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
 * Webhooks end to end on t_key_value and t_job_queue: endpoint writes never lose a counter, even
 * from several processes at once; an event queues one job per enabled subscribed endpoint;
 * a delivery is signed, retried with backoff, dead-lettered, and pauses its endpoint on the
 * eighth failure in a row with one e-mail; the address is checked again at send time; and
 * `/admin/webhooks` shows a secret once, keeps the old one signing for 24 hours after a
 * rotation and refuses private addresses unless the site allows them.
 *
 * Usage:  php tests/models/api-webhooks.php        (standalone, own scratch database)
 *         php tests/run-models.php api-webhooks    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\read\ListingReader;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\serializer\EventData;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Scopes;
use mindstellar\job\JobQueue;
use mindstellar\model\ApiCredential;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;
use mindstellar\utility\SystemClock;
use mindstellar\webhook\Delivery;
use mindstellar\webhook\Dispatcher;
use mindstellar\webhook\Endpoint;
use mindstellar\webhook\Events;
use mindstellar\webhook\Signer;
use mindstellar\webhook\Transport;
use mindstellar\webhook\TransportResult;
use mindstellar\webhook\WebhookEndpointStore;
use mindstellar\webhook\WebhookService;

$admin = api_admin_boot('osc_models_api_webhooks');
$p     = DB_TABLE_PREFIX;
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

if (!function_exists('api_with_filter')) {
    /**
     * Run $body with $callback added to $hook, then remove it.
     *
     * @return mixed what $body returns
     */
    function api_with_filter(string $hook, callable $callback, callable $body)
    {
        osc_add_filter($hook, $callback);
        try {
            return $body();
        } finally {
            osc_remove_filter($hook, $callback);
        }
    }
}

/** A clock the test moves. */
final class WebhookTestClock implements Clock
{
    public function __construct(public int $now)
    {
    }

    public function now(): int
    {
        return $this->now;
    }
}

/** Records each POST and answers with the next queued status. */
final class StubTransport implements Transport
{
    /** @var array<int,array{url:string, ip:string, headers:array<string,string>, body:string}> */
    public array $sent = [];

    /** @var TransportResult[] */
    public array $answers = [];

    public function post(string $url, string $ip, array $headers, string $body): TransportResult
    {
        $this->sent[] = ['url' => $url, 'ip' => $ip, 'headers' => $headers, 'body' => $body];

        return array_shift($this->answers) ?? TransportResult::answered(200);
    }
}

$store     = new WebhookEndpointStore();
$clock     = new WebhookTestClock(1_900_000_000);
$resolve   = static fn (string $host): array => ['hooks.example' => ['93.184.216.34'], 'lan.example' => ['192.168.1.20']][$host] ?? [];
$queued    = [];
$enqueue   = static function (string $type, array $payload, array $options) use (&$queued): int {
    $queued[] = ['type' => $type, 'payload' => $payload, 'options' => $options];

    return osc_job_enqueue($type, $payload, $options);
};
$manager   = static fn (bool $private = false): WebhookService => new WebhookService(
    $store,
    Events::fromHooks(),
    new AddressGuard($resolve, $private),
    new Dispatcher($store, Events::fromHooks(), $clock, $enqueue),
    $clock
);
$jobs      = static fn (): array => osc_db_stringify_rows(osc_db_table($p . 't_job_queue')->where('s_type', Delivery::TYPE)->orderBy('pk_i_id')->get());
$clearJobs = static function () use ($admin, $p, &$queued): void {
    $admin->query("DELETE FROM {$p}t_job_queue");
    $queued = [];
};

harness_section('store: compare-and-swap on the state column');
[$ep] = $manager()->create('https://hooks.example/a', ['listing.created'], 'A', true, 1);
$raw  = static fn (string $id): array => $admin->query("SELECT s_state, s_value FROM {$p}t_key_value WHERE s_group = 'api_webhook' AND s_key = '$id'")->fetch_assoc();
pin('stored in t_key_value under api_webhook at version 1', 'v1', $raw($ep->id())['s_state']);
check('the secret is stored encrypted, not as written', !str_contains($raw($ep->id())['s_value'], $ep->secret()) && str_contains($raw($ep->id())['s_value'], '"secret":"enc1:'));
pin('and reads back as written, so it still signs', $ep->secret(), $store->find($ep->id())?->secret());
$calls = 0;
$store->change($ep->id(), static function (Endpoint $e) use (&$calls, $store, $clock): Endpoint {
    if (++$calls === 1) {
        // Another writer gets in between this read and its write.
        $store->change($e->id(), static fn (Endpoint $x): Endpoint => $x->withFailure('HTTP 500', $clock->now));
    }

    return $e->withFailure('HTTP 502', $clock->now);
});
pin('a write that lost the race reads again and keeps both counts', [2, 2, 'v3'], [$calls, $store->find($ep->id())?->failures(), $raw($ep->id())['s_state']]);

$id      = $store->find($ep->id())->id();
$store->change($id, static fn (Endpoint $e): Endpoint => $e->withEnabled(true, 1));
$procs   = [];
$workers = 4;
$each    = 15;
for ($i = 0; $i < $workers; $i++) {
    $procs[] = proc_open([PHP_BINARY, "-d", "display_errors=stderr", __DIR__ . '/../lib/webhook-counter-child.php', DB_NAME, $id, (string) $each], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outs[$i] = $pipes;
}
$printed = '';
foreach ($procs as $i => $proc) {
    $printed .= stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]);
    proc_close($proc);
}
$after = $store->find($id);
if (substr_count($printed, "done") !== $workers) {
    echo $printed;
}
pin($workers . ' processes counting ' . $each . ' failures each lose none', $workers * $each, $after?->failures());
pin('every process finished', $workers, substr_count($printed, 'done'));
pin('exactly one write saw the pause happen', 1, substr_count($printed, 'paused'));
pin('...and the endpoint is paused', [false, true], [$after?->enabled(), $after?->paused()]);
$store->delete($id);

harness_section('the "any endpoint on" flag');
foreach ($store->all() as $e) {
    $store->delete($e->id());
}
$flag = static fn (): string => (string) osc_get_preference(WebhookEndpointStore::FLAG, ApiSettings::SECTION);
pin('with no endpoint the flag is off', '0', $flag());
pin('so an event reads nothing from t_key_value', 0, harness_query_count(static fn () => (new Dispatcher($store, Events::fromHooks(), $clock, $enqueue))->dispatch('listing.created', static fn (): array => ['id' => 1])));
[$flagged] = $manager()->create('https://hooks.example/flag', ['listing.created'], '', false, 1);
pin('a switched-off endpoint leaves it off', '0', $flag());
$manager()->update($flagged->id(), null, null, null, true);
pin('switching one on turns it on', '1', $flag());
$warm = new Dispatcher($store, Events::fromHooks(), $clock, $enqueue);
$warm->dispatch('user.deleted', static fn (): array => ['id' => 1]);
pin('a dispatcher reads the endpoints once per request', 0, harness_query_count(static fn () => $warm->dispatch('user.deleted', static fn (): array => ['id' => 2])));
Preference::getInstance()->replace(WebhookEndpointStore::FLAG, '', ApiSettings::SECTION, 'BOOLEAN');
pin('an unknown flag is worked out on first use', [1, '1'], [count($store->enabled()), $flag()]);
$store->delete($flagged->id());
pin('deleting the last one turns it off', '0', $flag());

harness_section('dispatcher: one job per enabled endpoint that subscribes');
$clearJobs();
[$one]   = $manager()->create('https://hooks.example/one', ['listing.created', 'user.deleted'], 'One', true, 1);
[$two]   = $manager()->create('https://hooks.example/two', ['listing.created'], 'Two', true, 1);
[$off]   = $manager()->create('https://hooks.example/off', ['listing.created'], 'Off', false, 1);
[$other] = $manager()->create('https://hooks.example/other', ['user.registered'], 'Other', true, 1);
$dispatcher = new Dispatcher($store, Events::fromHooks(), $clock, $enqueue);
$msg        = $dispatcher->emit('listing.created', ['id' => 7, 'url' => 'http://localhost/item/7', 'title' => 'Bike']);
$targets    = array_map(static fn (array $j): string => $j['payload']['endpoint_id'], $queued);
sort($targets);
$expect = [$one->id(), $two->id()];
sort($expect);
pin('two jobs: the disabled and the unsubscribed endpoints get none', $expect, $targets);
$keys = [];
foreach ($queued as $j) {
    $keys[$j['payload']['endpoint_id']] = $j['options']['unique_key'];
}
pin('each keyed by endpoint and message', ['wh:' . $one->id() . ':' . $msg, 'wh:' . $two->id() . ':' . $msg], [$keys[$one->id()] ?? null, $keys[$two->id()] ?? null]);
pin('two rows on the queue', 2, count($jobs()));
$body = json_decode($queued[0]['payload']['body'], true);
pin('the body: type, id, timestamp and data', ['listing.created', $msg, '2030-03-17T17:46:40Z', ['id' => 7, 'url' => 'http://localhost/item/7', 'title' => 'Bike']], [$body['type'], $body['id'], $body['timestamp'], $body['data']]);
check('the message id is msg_ and a ULID', preg_match('/^msg_[0-9A-Z]{26}$/', (string) $msg) === 1);
$ids = [];
for ($i = 0; $i < 25; $i++) {
    $clock->now = time();
    $ids[]      = substr((string) $dispatcher->emit('listing.created', ['id' => 7]), 4, 10);
    usleep(300);
}
$sorted = $ids;
sort($sorted);
pin('ids made one after another sort in that order: their time is the real millisecond', $sorted, $ids);
$clock->now = 1_900_000_000;
$clearJobs();
$queuedBefore = count($queued);
$built        = false;
pin('an event nobody subscribes to queues nothing', null, $dispatcher->dispatch('comment.created', static function () use (&$built): array {
    $built = true;

    return ['id' => 1];
}));
check('...and its data is never built', !$built && count($queued) === $queuedBefore);
$threw = false;
try {
    $dispatcher->emit('listing.renamed', ['id' => 1]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('an unknown event type is refused', $threw);

harness_section('dispatcher: thin events, the payload filter and plugin events');
$clearJobs();
$dispatcher->emit('listing.created', ['id' => 8, 'url' => 'http://localhost/item/8', 'description' => str_repeat('é', 40000)]);
$thin = json_decode($queued[0]['payload']['body'], true);
pin('a body over 64 KB is sent thin: id and url only', [true, ['id' => 8, 'url' => 'http://localhost/item/8']], [$thin['thin'] ?? null, $thin['data']]);
check('...and fits a job', count($jobs()) === 2);
$clearJobs();
api_with_filter('api_webhook_payload', static function (array $payload, string $type, array $endpoint): array {
    $payload['data']['for'] = $endpoint['id'];
    check('the filter never sees the secret', !array_key_exists('secret', $endpoint));

    return $payload;
}, static fn () => $dispatcher->emit('listing.created', ['id' => 9]));
$fors = array_map(static fn (array $j): string => json_decode($j['payload']['body'], true)['data']['for'], $queued);
sort($fors);
pin('api_webhook_payload shapes the body per endpoint', $expect, $fors);
$clearJobs();
api_with_filter('api_webhook_events', static fn (array $events): array => $events + ['ext.acme.offer_created' => ['description' => 'An offer.', 'schema' => '']], static function () use ($store, $clock, $enqueue, $manager, &$plugin): void {
    $plugin = $manager()->create('https://hooks.example/acme', ['ext.acme.offer_created'], '', true, 1)[0];
    (new Dispatcher($store, Events::fromHooks(), $clock, $enqueue))->emit('ext.acme.offer_created', ['id' => 3]);
});
pin('a plugin event reaches the endpoint that subscribes to it', [$plugin->id()], array_map(static fn (array $j): string => $j['payload']['endpoint_id'], $queued));
$threw = '';
try {
    $manager()->create('https://hooks.example/acme', ['ext.acme.offer_created'], '', true, 1);
} catch (InvalidArgumentException $e) {
    $threw = $e->getMessage();
}
pin('without the plugin, its event cannot be chosen', 'Unknown events: ext.acme.offer_created', $threw);
$store->delete($plugin->id());

harness_section('core hooks become events');
$clearJobs();
ApiServices::reset();
\mindstellar\webhook\WebhookServices::reset();
osc_run_hook('after_delete_user', 42);
$rows = $jobs();
pin('after_delete_user queues user.deleted for the endpoint that wants it', [1, $one->id()], [count($rows), json_decode($rows[0]['s_payload'] ?? '{}', true)['endpoint_id'] ?? null]);
pin('...with the id only', ['id' => 42], json_decode(json_decode($rows[0]['s_payload'] ?? '{}', true)['body'] ?? '{}', true)['data'] ?? null);
$clearJobs();
osc_run_hook('after_delete_item', 5, []);
pin('after_delete_item queues listing.deleted? Nobody subscribes, so nothing', 0, count($jobs()));

harness_section('events built inside a write are queued after it commits');
$clearJobs();
$bodyOf = static fn (array $row): array => json_decode(json_decode($row['s_payload'], true)['body'], true);
osc_run_hook('after_delete_user', 43);
$plain = $bodyOf($jobs()[0]);
$clearJobs();
$seenInside = null;
osc_db_transaction(static function () use ($jobs, &$seenInside): void {
    osc_run_hook('after_delete_user', 43);
    $seenInside = count($jobs());
});
$after = $jobs();
pin('nothing is queued while the write is open', 0, $seenInside);
pin('one job once it committed', 1, count($after));
pin('with the same type and data as one fired outside a write', [$plain['type'], $plain['data']], [$bodyOf($after[0])['type'], $bodyOf($after[0])['data']]);
$clearJobs();
try {
    osc_db_transaction(static function (): void {
        osc_run_hook('after_delete_user', 44);

        throw new RuntimeException('rolled back');
    });
} catch (RuntimeException $e) {
}
pin('a write that rolls back sends no event', 0, count($jobs()));
$locale = seed_locale($admin);
seed_currency($admin);
$cat    = seed_category($admin);
$userId = seed_user($admin);
$item   = seed_item($admin, $cat, $userId, 'Red bike');
$kit    = new ApiServices(
    new ApiSettings(true),
    new Scopes(),
    new ApiCredential(),
    new UserRows(),
    new SystemClock(),
    RateLimiter::fromSite(new SystemClock()),
    new SiteFacts('en_US', ['en_US' => ['name' => 'English', 'direction' => 'ltr']], true, true, 10, 12, 50, false, false),
    new class () implements mindstellar\api\serializer\Links {
        public function listing(array $item): string
        {
            return 'http://localhost/item/' . $item['pk_i_id'];
        }

        public function photo(array $resource, string $variant): string
        {
            return '';
        }

        public function user(int $id, string $username): string
        {
            return 'http://localhost/user/' . $id;
        }

        public function avatar(int $userId): string
        {
            return '';
        }

        public function api(string $path): string
        {
            return 'http://localhost/api/v1/' . $path;
        }

        public function price(?int $micros, string $symbol): string
        {
            return '';
        }
    }
);
$eventData = static fn (ApiServices $kit): EventData => new EventData(new ListingReader(CategoryCatalog::fromSite(), $kit->listingSerializer()), $kit, new SystemClock());
$data = $eventData($kit)->listing($item);
pin('a listing event carries the public listing', [$item, 'Red bike', 'http://localhost/item/' . $item], [$data['id'] ?? null, $data['title'] ?? null, $data['url'] ?? null]);
check('...never its IP or the seller e-mail', !array_key_exists('ip', (array) $data) && !str_contains((string) json_encode($data), '@'));
$user = $eventData($kit)->user($userId);
check('a user event leaves the e-mail out', is_array($user) && !array_key_exists('email', $user) && ($user['id'] ?? null) === $userId);
pin('a gone resource gives no event', [null, null], [$eventData($kit)->listing(999999), $eventData($kit)->comment(999999)]);
$notLive = [
    'pending'  => 'b_active = 0',
    'disabled' => 'b_enabled = 0',
    'spam'     => 'b_spam = 1',
    'expired'  => 'dt_expiration = DATE_SUB(NOW(), INTERVAL 1 DAY)',
];
foreach ($notLive as $label => $set) {
    $hidden = seed_item($admin, $cat, $userId, 'Hidden ' . $label);
    $admin->query("UPDATE {$p}t_item SET $set WHERE pk_i_id = $hidden");
    pin('a ' . $label . ' listing sends its id and url only, marked not live', ['id' => $hidden, 'url' => 'http://localhost/item/' . $hidden, 'live' => false], $eventData($kit)->listing($hidden));
}
$commentRow = static function (int $active, int $enabled, int $spam) use ($admin, $p, $item): int {
    $admin->query("INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam)
        VALUES ($item, NOW(), 'Hi', 'Ann', 'ann@example.com', 'Pending text', $enabled, $active, $spam)");

    return (int) $admin->insert_id;
};
$live = $commentRow(1, 1, 0);
pin('a live comment sends the public comment', [$live, 'Pending text'], [$eventData($kit)->comment($live)['id'] ?? null, $eventData($kit)->comment($live)['body'] ?? null]);
foreach (['pending' => [0, 1, 0], 'disabled' => [1, 0, 0], 'spam' => [1, 1, 1]] as $label => [$a, $e, $sp]) {
    $cid = $commentRow($a, $e, $sp);
    pin('a ' . $label . ' comment sends its id only, marked not live', ['id' => $cid, 'url' => null, 'live' => false, 'listing_id' => $item], $eventData($kit)->comment($cid));
}
$clearJobs();
$dispatcher->emit('listing.created', EventData::notLive(5, 'http://localhost/item/5'));
$hiddenBody = $queued === [] ? [] : json_decode($queued[0]['payload']['body'], true);
pin('a not-live event goes out thin', [true, ['id' => 5, 'url' => 'http://localhost/item/5', 'live' => false]], [$hiddenBody['thin'] ?? null, $hiddenBody['data'] ?? null]);
$clearJobs();

harness_section('delivery: signed POST');
$clearJobs();
$transport = new StubTransport();
$mails     = [];
$delivery  = new Delivery($store, $transport, new AddressGuard($resolve), $clock, static function (Endpoint $e) use (&$mails): void {
    $mails[] = $e->id();
});
$delivered = [];
$watch     = static function ($endpoint, $event, $status, $attempt) use (&$delivered): void {
    $delivered[] = [$endpoint['id'], $event['type'], $status, $attempt];
};
osc_add_hook('api_webhook_delivered', $watch);
$job = ['endpoint_id' => $two->id(), 'msg_id' => 'msg_01TEST', 'type' => 'listing.created', 'body' => '{"type":"listing.created"}', 'ts' => $clock->now];
$result = $delivery->run($job);
$sent   = $transport->sent[0];
pin('POSTs the body to the endpoint, pinned to the checked IP', ['https://hooks.example/two', '93.184.216.34', '{"type":"listing.created"}'], [$sent['url'], $sent['ip'], $sent['body']]);
check('the signature verifies with the endpoint\'s secret', Signer::verify($two->secret(), 'msg_01TEST', $sent['headers']['webhook-timestamp'], $sent['body'], $sent['headers']['webhook-signature'], $clock->now));
pin('signed at send time', (string) $clock->now, $sent['headers']['webhook-timestamp']);
pin('a 2xx: done, last status kept', [true, 'HTTP 200', 0], [$result?->ok(), $store->find($two->id())?->lastStatus(), $store->find($two->id())?->failures()]);
pin('api_webhook_delivered is told', [[$two->id(), 'listing.created', 200, 1]], $delivered);

harness_section('delivery: retries, dead letter, pause and one e-mail');
osc_job_register_handler(Delivery::TYPE, static function ($job) use ($delivery): void {
    $delivery->run($job->payload(), $job->attempts() + 1);
});
$queue = JobQueue::getInstance();
$run   = static function () use ($queue): array {
    $outcomes = [];
    foreach ($queue->claim(20, Delivery::TYPE) as $row) {
        try {
            mindstellar\job\JobRegistry::handler(Delivery::TYPE)(new mindstellar\job\Job($row, json_decode((string) $row['s_payload'], true)));
            $queue->complete((int) $row['pk_i_id']);
            $outcomes[] = 'done';
        } catch (Throwable $e) {
            $retry      = $e instanceof mindstellar\job\JobRetry ? $e : null;
            $outcomes[] = $queue->fail((int) $row['pk_i_id'], $e->getMessage(), $retry?->delay(), $retry?->maxAttempts()) ? 'dead' : 'retry';
        }
    }

    return $outcomes;
};
$jobId = osc_job_enqueue(Delivery::TYPE, ['endpoint_id' => $one->id()] + $job, ['unique_key' => 'wh:' . $one->id() . ':msg_01TEST']);
$transport->answers = [TransportResult::answered(500)];
$started = time();
pin('a 500 is retried', ['retry'], $run());
$row = $queue->page(null, Delivery::TYPE)[0];
pin('...after about a minute, with the error kept', [1, 'pending', true], [(int) $row['i_attempts'], $row['s_status'], abs(strtotime((string) $row['dt_next_run']) - ($started + 60)) <= 10]);
check('...the error names the answer', str_contains((string) $row['s_last_error'], 'HTTP 500'));
check('...and the endpoint by id, never its URL', str_contains((string) $row['s_last_error'], $one->id()) && !str_contains((string) $row['s_last_error'], 'hooks.example'));
pin('the endpoint counts one failure', 1, $store->find($one->id())?->failures());
$admin->query("UPDATE {$p}t_job_queue SET i_attempts = 6, dt_next_run = NOW() WHERE pk_i_id = $jobId");
$transport->answers = [TransportResult::failed('Connection refused')];
$run();
$row = $queue->page(null, Delivery::TYPE)[0];
pin('the seventh failure waits about 10 hours, not 64 minutes', true, abs(strtotime((string) $row['dt_next_run']) - (time() + 36000)) <= 3700);
$admin->query("UPDATE {$p}t_job_queue SET i_attempts = 11, dt_next_run = NOW() WHERE pk_i_id = $jobId");
$transport->answers = [TransportResult::answered(503)];
pin('the twelfth failure dead-letters the job', ['dead'], $run());
pin('...visible as a dead letter', ['error', 'HTTP 503'], [$queue->page('error', Delivery::TYPE)[0]['s_status'] ?? null, substr((string) ($queue->page('error', Delivery::TYPE)[0]['s_last_error'] ?? ''), -8)]);
$clearJobs();
for ($i = 0; $i < 5; $i++) {
    $transport->answers[] = TransportResult::answered(500);
    try {
        $delivery->run(['msg_id' => 'msg_' . $i, 'endpoint_id' => $one->id()] + $job);
    } catch (RuntimeException $e) {
    }
}
$paused = $store->find($one->id());
pin('the eighth failure in a row pauses the endpoint', [8, false, true], [$paused?->failures(), $paused?->enabled(), $paused?->paused()]);
pin('...and e-mails once', [$one->id()], $mails);
$before = count($transport->sent);
pin('a delivery to a paused endpoint is dropped, not sent', [null, $before], [$delivery->run(['endpoint_id' => $one->id()] + $job), count($transport->sent)]);
pin('no second e-mail', 1, count($mails));
$test = $delivery->run(['endpoint_id' => $one->id(), 'test' => true] + $job);
pin('a test is sent even while paused, and does not count', [true, 8], [$test?->ok(), $store->find($one->id())?->failures()]);
$manager()->update($one->id(), null, null, null, true);
pin('switching it on clears the pause and the count', [true, 0], [$store->find($one->id())?->enabled(), $store->find($one->id())?->failures()]);

harness_section('delivery: schedule, Retry-After and 410 Gone');
$span = array_sum(\mindstellar\webhook\RetrySchedule::WAITS);
check('the schedule spans about 72 hours', $span >= 70 * 3600 && $span <= 74 * 3600);
pin('and gives one try more than it has waits', count(\mindstellar\webhook\RetrySchedule::WAITS) + 1, \mindstellar\webhook\RetrySchedule::MAX_ATTEMPTS);
$jitterOk = true;
for ($i = 0; $i < 50; $i++) {
    $d = \mindstellar\webhook\RetrySchedule::delay(3);
    $jitterOk = $jitterOk && $d >= 1620 && $d <= 1980;
}
check('each wait is jittered by no more than 10%', $jitterOk);
pin('Retry-After replaces the wait', 120, \mindstellar\webhook\RetrySchedule::delay(1, 120));
pin('...up to a day', 86400, \mindstellar\webhook\RetrySchedule::delay(1, 999999));
pin('Retry-After as seconds or as a date', [30, 90, 0, 0], [
    \mindstellar\webhook\CurlTransport::seconds('30'),
    \mindstellar\webhook\CurlTransport::seconds(gmdate('D, d M Y H:i:s', 1000090) . ' GMT', 1000000),
    \mindstellar\webhook\CurlTransport::seconds('soon'),
    \mindstellar\webhook\CurlTransport::seconds(''),
]);
$store->change($two->id(), static fn (Endpoint $e): Endpoint => $e->withEnabled(true, $clock->now));
$transport->answers = [TransportResult::answered(429, 300)];
$retry = null;
try {
    $delivery->run(['msg_id' => 'msg_ra', 'endpoint_id' => $two->id()] + $job, 3);
} catch (\mindstellar\job\JobRetry $e) {
    $retry = $e;
}
pin('a receiver\'s Retry-After sets the wait of the retry', [300, \mindstellar\webhook\RetrySchedule::MAX_ATTEMPTS], [$retry?->delay(), $retry?->maxAttempts()]);
$mails     = [];
$transport->answers = [TransportResult::answered(410)];
$threw = false;
try {
    $gone = $delivery->run(['msg_id' => 'msg_410', 'endpoint_id' => $two->id()] + $job);
} catch (Throwable $e) {
    $threw = true;
}
$after = $store->find($two->id());
pin('410 Gone ends the job without a retry and pauses the endpoint', [false, false, true, 'HTTP 410'], [$threw, $after?->enabled(), $after?->paused(), $after?->lastStatus()]);
pin('...with one e-mail', [$two->id()], $mails);
pin('...whose reason says why', 'The receiver answered 410 Gone', $after?->pausedReason());
$manager()->update($two->id(), null, null, null, true);
$transport->answers = [TransportResult::answered(410)];
$delivery->run(['endpoint_id' => $two->id(), 'test' => true] + $job);
pin('a test that gets 410 changes nothing', true, $store->find($two->id())?->enabled());

harness_section('delivery: the address is checked again at send time');
$store->change($two->id(), static fn (Endpoint $e): Endpoint => $e->withSettings('http://lan.example/hook', $e->events(), '', 1));
$before = count($transport->sent);
$threw  = '';
try {
    $delivery->run($job);
} catch (RuntimeException $e) {
    $threw = $e->getMessage();
}
pin('a host that now resolves to a private address is not called', $before, count($transport->sent));
check('...and the failure says why', str_contains($threw, 'Address refused') && str_contains((string) $store->find($two->id())?->lastStatus(), 'private'));
$lanDelivery = new Delivery($store, $transport, new AddressGuard($resolve, true), $clock, static fn () => null);
pin('with private addresses allowed, it is sent', [true, '192.168.1.20'], [$lanDelivery->run($job)?->ok(), end($transport->sent)['ip']]);
$store->change($two->id(), static fn (Endpoint $e): Endpoint => $e->withSettings('https://hooks.example/two', $e->events(), '', 1));

harness_section('rotation: two signatures for 24 hours');
[$rotated, $newSecret] = $manager()->rotate($two->id());
$oldSecret = $two->secret();
$delivery->run($job);
$sig = end($transport->sent)['headers']['webhook-signature'];
$ts  = end($transport->sent)['headers']['webhook-timestamp'];
pin('the new secret is not the old one', true, $newSecret !== $oldSecret && $rotated->secret() === $newSecret);
pin('two signatures', 2, count(explode(' ', $sig)));
check('a receiver still on the old secret accepts it', Signer::verify($oldSecret, $job['msg_id'], $ts, $job['body'], $sig, $clock->now));
check('so does one on the new secret', Signer::verify($newSecret, $job['msg_id'], $ts, $job['body'], $sig, $clock->now));
$clock->now += WebhookService::ROTATION_OVERLAP;
$delivery->run($job);
$sig = end($transport->sent)['headers']['webhook-signature'];
$ts  = end($transport->sent)['headers']['webhook-timestamp'];
pin('a day later, one signature', 1, count(explode(' ', $sig)));
check('...that the old secret no longer matches', !Signer::verify($oldSecret, $job['msg_id'], $ts, $job['body'], $sig, $clock->now) && Signer::verify($newSecret, $job['msg_id'], $ts, $job['body'], $sig, $clock->now));
osc_remove_hook('api_webhook_delivered', $watch);
$clock->now = 1_900_000_000;

harness_section('/admin/webhooks: who may call');
$clearJobs();
foreach ($store->all() as $e) {
    $store->delete($e->id());
}
$bossId = api_admin_seed_admin($admin, 'boss');
$modId  = api_admin_seed_admin($admin, 'mod', true);
$boss   = api_admin_key($bossId);
$mod    = api_admin_key($modId, true);
$keys   = api_admin_key($bossId, false, ['admin:keys']);
Preference::getInstance()->replace('api_enabled', '1', ApiSettings::SECTION, 'BOOLEAN');
$call   = api_admin_caller(static fn (): ApiSettings => ApiSettings::fromPreferences());
pin('an admin key: 200', 200, $call('GET', 'admin/webhooks', null, $boss)->status());
pin('a moderator: 403', '403 insufficient_scope', api_admin_code($call('GET', 'admin/webhooks', null, $mod)));
pin('a key without admin:webhooks: 403', '403 insufficient_scope', api_admin_code($call('POST', 'admin/webhooks', ['url' => 'https://93.184.216.34/h', 'events' => ['listing.created']], $keys)));

harness_section('/admin/webhooks: the secret once');
$r      = $call('POST', 'admin/webhooks', ['url' => 'https://93.184.216.34/hook', 'events' => ['listing.created', 'user.registered'], 'description' => 'CRM'], $boss);
$hookId = (string) ($r->body()['data']['id'] ?? '');
$secret = (string) ($r->body()['data']['secret'] ?? '');
pin('POST: 201 with the secret', [201, true, 'active', ['listing.created', 'user.registered']], [$r->status(), str_starts_with($secret, 'whsec_'), $r->body()['data']['status'] ?? null, $r->body()['data']['events'] ?? null]);
pin('Location names it; it belongs to the caller\'s admin', ['http://localhost/api/v1/admin/webhooks/' . $hookId, $bossId], [$r->header('Location'), $r->body()['data']['created_by'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('WebhookDocument', $r));
check('GET one never shows it', !str_contains((string) json_encode($call('GET', 'admin/webhooks/' . $hookId, null, $boss)->body()), $secret));
$list = $call('GET', 'admin/webhooks', null, $boss);
check('nor does the list', !str_contains((string) json_encode($list->body()), $secret) && count($list->body()['data'] ?? []) === 1);
pin('the list matches the schema', [], api_admin_schema_errors('WebhookList', $list));
pin('an unknown id is 404', [404, 404], [$call('GET', 'admin/webhooks/ep_0000000000000000', null, $boss)->status(), $call('GET', 'admin/webhooks/nonsense', null, $boss)->status()]);

harness_section('/admin/webhooks: addresses');
foreach (['http://127.0.0.1/hook' => 'loopback', 'http://10.0.0.5/hook' => 'a private range', 'http://169.254.169.254/latest' => 'cloud metadata', 'https://93.184.216.34:8443/hook' => 'another port'] as $url => $label) {
    $r = $call('POST', 'admin/webhooks', ['url' => $url, 'events' => ['listing.created']], $boss);
    pin($label . ' is refused at save: 422', [422, 'rejected'], [$r->status(), $r->body()['errors'][0]['code'] ?? null]);
}
pin('an unknown event is 422', 422, $call('POST', 'admin/webhooks', ['url' => 'https://93.184.216.34/h', 'events' => ['listing.exploded']], $boss)->status());
pin('ping cannot be subscribed to', 422, $call('POST', 'admin/webhooks', ['url' => 'https://93.184.216.34/h', 'events' => ['ping']], $boss)->status());
pin('no events is 422', 422, $call('POST', 'admin/webhooks', ['url' => 'https://93.184.216.34/h', 'events' => []], $boss)->status());
Preference::getInstance()->replace('api_webhooks_allow_private', '1', ApiSettings::SECTION, 'BOOLEAN');
scratchdb_forget_cache();
osc_reset_preferences();
pin('with private addresses allowed, a port of its own is still refused', 422, $call('POST', 'admin/webhooks', ['url' => 'http://127.0.0.1:8080/hook', 'events' => ['listing.created']], $boss)->status());
$r = $call('POST', 'admin/webhooks', ['url' => 'http://127.0.0.1/hook', 'events' => ['listing.created'], 'enabled' => false], $boss);
pin('...a LAN receiver on port 80 is accepted', [201, 'disabled'], [$r->status(), $r->body()['data']['status'] ?? null]);
$lanId = (string) ($r->body()['data']['id'] ?? '');
Preference::getInstance()->replace('api_webhooks_allow_private', '0', ApiSettings::SECTION, 'BOOLEAN');
scratchdb_forget_cache();
osc_reset_preferences();
pin('switched back off, it cannot be saved again', 422, $call('PATCH', 'admin/webhooks/' . $lanId, ['url' => 'http://127.0.0.1/other'], $boss)->status());

harness_section('update: a write in between is kept');
[$raced] = $manager()->create('https://hooks.example/race', ['listing.created'], 'Before', true, 1);
$racing  = new WebhookService(
    $store,
    Events::fromHooks(),
    new AddressGuard(static function (string $host) use ($store, $raced, $resolve): array {
        // Another admin edits the description while this request checks its new address.
        $store->change($raced->id(), static fn (Endpoint $e): Endpoint => $e->withSettings($e->url(), $e->events(), 'Edited elsewhere', 1));

        return $resolve($host);
    }),
    new Dispatcher($store, Events::fromHooks(), $clock, $enqueue),
    $clock
);
$racing->update($raced->id(), 'https://hooks.example/race2');
pin('a PATCH of the url keeps the description another writer saved meanwhile', ['https://hooks.example/race2', 'Edited elsewhere'], [$store->find($raced->id())?->url(), $store->find($raced->id())?->description()]);
$store->delete($raced->id());

harness_section('/admin/webhooks: changes');
$r = $call('PATCH', 'admin/webhooks/' . $hookId, ['events' => ['comment.created'], 'description' => 'Comments'], $boss);
pin('PATCH changes only what is sent', [200, ['comment.created'], 'Comments', 'https://93.184.216.34/hook'], [$r->status(), $r->body()['data']['events'] ?? null, $r->body()['data']['description'] ?? null, $r->body()['data']['url'] ?? null]);
pin('switched off', 'disabled', $call('PATCH', 'admin/webhooks/' . $hookId, ['enabled' => false], $boss)->body()['data']['status'] ?? null);
$r = $call('POST', 'admin/webhooks/' . $hookId . '/rotate-secret', null, $boss);
$fresh = (string) ($r->body()['data']['secret'] ?? '');
pin('rotate: a new secret, shown once, the old one valid for 24 hours', [200, true, true], [
    $r->status(), $fresh !== '' && $fresh !== $secret,
    abs(strtotime((string) ($r->body()['data']['previous_secret_until'] ?? '')) - (time() + 86400)) <= 5,
]);
pin('...and stored to sign with both', 2, count($store->find($hookId)?->signingSecrets(time()) ?? []));
$clearJobs();
$r = $call('POST', 'admin/webhooks/' . $hookId . '/test', null, $boss);
pin('test: 202 with the message id', [202, 'ping', true], [$r->status(), $r->body()['data']['type'] ?? null, str_starts_with((string) ($r->body()['data']['message_id'] ?? ''), 'msg_')]);
pin('...a ping queued for a switched-off endpoint, marked as a test', [1, true, 'ping'], (static function () use ($jobs): array {
    $rows    = $jobs();
    $payload = json_decode($rows[0]['s_payload'] ?? '{}', true);

    return [count($rows), $payload['test'] ?? null, $payload['type'] ?? null];
})());
pin('matches the schema', [], api_admin_schema_errors('WebhookTestDocument', $r));
$r = $call('GET', 'admin/webhooks/' . $hookId . '/deliveries', null, $boss);
pin('deliveries: the waiting ping', [200, 1, 'ping', 'pending', true], [$r->status(), count($r->body()['data']['deliveries'] ?? []), $r->body()['data']['deliveries'][0]['type'] ?? null, $r->body()['data']['deliveries'][0]['status'] ?? null, $r->body()['data']['deliveries'][0]['test'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('WebhookDeliveriesDocument', $r));
check('no secret in the deliveries', !str_contains((string) json_encode($r->body()), $fresh));
pin('a limit outside 1 to 100 is 422', [422, 422], [
    $call('GET', 'admin/webhooks/' . $hookId . '/deliveries', null, $boss, [], ['limit' => '0'])->status(),
    $call('GET', 'admin/webhooks/' . $hookId . '/deliveries', null, $boss, [], ['limit' => '-5'])->status(),
]);
pin('another endpoint\'s deliveries are not shown', 0, count($call('GET', 'admin/webhooks/' . $lanId . '/deliveries', null, $boss)->body()['data']['deliveries'] ?? [1]));
$r = api_with_filter('api_webhook_events', static fn (array $e): array => $e + ['ext.acme.offer_created' => ['description' => 'An offer.', 'schema' => 'AcmeOffer']], static fn () => $call('GET', 'admin/webhook-events', null, $boss));
$types = array_column($r->body()['data'] ?? [], 'type');
check('the catalogue lists core and plugin events, and ping', in_array('listing.created', $types, true) && in_array('ext.acme.offer_created', $types, true) && in_array('ping', $types, true));
pin('matches the schema', [], api_admin_schema_errors('WebhookEventList', $r));
pin('DELETE: 204, and its waiting deliveries go too', [204, 0, 404], [$call('DELETE', 'admin/webhooks/' . $hookId, null, $boss)->status(), count($jobs()), $call('GET', 'admin/webhooks/' . $hookId, null, $boss)->status()]);

exit(harness_result());
