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
 * The message.created and conversation.reported webhooks: ids only, never the text.
 * Usage: php tests/models/api-webhooks-messaging.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\ApiServices;
use mindstellar\api\schema\OpenApi;
use mindstellar\api\serializer\EventData;
use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\event\DispatcherInterface;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\MessagingService;
use mindstellar\moderation\MessageModeration;
use mindstellar\security\AddressGuard;
use mindstellar\utility\SystemClock;
use mindstellar\webhook\Delivery;
use mindstellar\webhook\Dispatcher;
use mindstellar\webhook\Events;
use mindstellar\webhook\WebhookEndpointStore;
use mindstellar\webhook\WebhookService;
use mindstellar\webhook\WebhookServices;

$admin = api_admin_boot('osc_models_api_webhooks_messaging');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;
$_SERVER['REMOTE_ADDR'] = '192.0.2.71';

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

$buyer   = seed_user($admin, 'bea', 'bea@example.test');
$seller  = seed_user($admin, 'sam', 'sam@example.test');
$cat     = seed_category($admin);
$bike    = seed_item($admin, $cat, $seller, 'Bike');
$ola     = seed_user($admin, 'ola', 'ola@example.test');
$asBuyer = Actor::user($buyer, '10.0.0.1');
$asOla   = Actor::user($ola, '10.0.0.3');
$asAdmin = Actor::admin(1, '10.0.0.9');

harness_reset_container();
$c   = Container::root();
$svc = $c->get(MessagingService::class);
$mod = $c->get(MessageModeration::class);
// The reset drops the listeners the API boot added to the old container.
EventData::listen();

$jobs      = static fn (): array => osc_db_stringify_rows(osc_db_table($p . 't_job_queue')->where('s_type', Delivery::TYPE)->orderBy('pk_i_id')->get());
$clearJobs = static function () use ($admin, $p): void {
    $admin->query("DELETE FROM {$p}t_job_queue");
};
$data = static fn (array $row): array => json_decode(json_decode($row['s_payload'], true)['body'], true);
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};

$store   = new WebhookEndpointStore();
$clock   = new SystemClock();
$manager = new WebhookService(
    $store,
    Events::fromHooks(),
    new AddressGuard(static fn (string $host): array => ['93.184.216.34'], false),
    new Dispatcher($store, Events::fromHooks(), $clock, 'osc_job_enqueue'),
    $clock
);
$clearJobs();
WebhookServices::reset();

harness_section('nobody subscribed');
$first = $svc->start($bike, 'Is it still free? Secret text', $asBuyer);
WebhookServices::reset();
pin('a message queues nothing', 0, count($jobs()));
$svc->reply($first->conversationId(), 'Another one', $asBuyer);
pin('nor does a reply', 0, count($jobs()));

harness_section('subscribed to both');
[$ep] = $manager->create('https://hooks.example/msg', ['message.created', 'conversation.reported'], '', true, 1);
WebhookServices::reset();
$clearJobs();
$sent = $svc->reply($first->conversationId(), 'Third. Secret text', $asBuyer);
$rows = $jobs();
pin('a reply queues one job', 1, count($rows));
$body = $data($rows[0]);
pin('of type message.created', 'message.created', $body['type'] ?? null);
pin('the data is the five ids, nothing else', [
    'id'              => $sent->messageId(),
    'conversation_id' => $first->conversationId(),
    'listing_id'      => $bike,
    'sender_id'       => $buyer,
    'recipient_id'    => $seller,
], $body['data'] ?? null);
pin('the text is nowhere in the job', false, str_contains((string) $rows[0]['s_payload'], 'Secret'));

harness_section('held message');
$events = $c->get(DispatcherInterface::class);
$events->listen(MessageSending::class, $hold);
$clearJobs();
$heldMsg = $svc->reply($first->conversationId(), 'Held text', $asBuyer);
$events->remove(MessageSending::class, $hold);
pin('a held message queues nothing', 0, count($jobs()));
$mod->release($heldMsg->messageId(), $asAdmin);
$rows = $jobs();
pin('release queues one', 1, count($rows));
pin('with the held message id', $heldMsg->messageId(), $data($rows[0])['data']['id'] ?? null);

harness_section('report');
$clearJobs();
$svc->report($first->conversationId(), 'scam', $asBuyer);
$rows = $jobs();
pin('a report queues one job', 1, count($rows));
$body = $data($rows[0]);
pin('conversation.reported with ids and reason', [
    'conversation.reported',
    ['id' => $first->conversationId(), 'listing_id' => $bike, 'reporter_id' => $buyer, 'reason' => 'scam'],
], [$body['type'] ?? null, $body['data'] ?? null]);

harness_section('no endpoint wants it');
$manager->update($ep->id(), null, ['listing.created'], null, null);
WebhookServices::reset();
harness_reset_container();
$c    = Container::root();
$svc  = $c->get(MessagingService::class);
$ran  = 0;
EventData::listen(static function () use (&$ran): EventData {
    $ran++;

    return ApiServices::site()->eventData();
});
$clearJobs();
$lamp = seed_item($admin, $cat, $seller, 'Lamp');
$svc->start($lamp, 'Quiet', $asOla);
pin('another subscription gets nothing, and the data is not built', [0, 0], [count($jobs()), $ran]);
$manager->update($ep->id(), null, ['message.created'], null, null);
WebhookServices::reset();
$desk = seed_item($admin, $cat, $seller, 'Desk');
$svc->start($desk, 'Loud', $asOla);
pin('once it subscribes the data is built and queued', [1, 1], [count($jobs()), $ran]);
$store->delete($ep->id());

harness_section('OpenAPI');
$doc = OpenApi::core()->build();
foreach (['message.created' => 'WebhookMessageCreated', 'conversation.reported' => 'WebhookConversationReported'] as $type => $schema) {
    $ref = $doc['webhooks'][$type]['post']['requestBody']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref'] ?? '';
    pin($type . ' is described with its schema', '#/components/schemas/' . $schema, $ref);
    check($schema . ' is a component', isset($doc['components']['schemas'][$schema]));
}
pin('the message schema requires the five ids and no more', ['id', 'conversation_id', 'listing_id', 'sender_id', 'recipient_id'], array_keys($doc['components']['schemas']['WebhookMessageCreated']['properties'] ?? []));

exit(harness_result());
