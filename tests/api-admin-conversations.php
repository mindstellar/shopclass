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
 * The admin/conversations and admin/messages routes: who may call them, the lists and their
 * filters and cursor, the thread with its held messages, and the four review actions.
 * Usage: php tests/api-admin-conversations.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\container\Container;
use mindstellar\event\DispatcherInterface;
use mindstellar\messaging\event\MessageSending;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$admin = api_admin_boot('osc_api_admin_conversations');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
foreach (['enabled_users' => '1', 'language' => 'en_US', 'rewriteEnabled' => '0', 'enabled_messaging' => '1', 'throttle_message_send' => '0', 'throttle_message_start' => '0'] as $k => $v) {
    $set($k, $v);
}
$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

$cat      = seed_category($admin);
$buyer    = seed_user($admin, 'bea', 'bea@example.test');
$seller   = seed_user($admin, 'sam', 'sam@example.test');
$stranger = seed_user($admin, 'ola', 'ola@example.test');
$bike     = seed_item($admin, $cat, $seller, 'Bike');
$lamp     = seed_item($admin, $cat, $seller, 'Lamp');
$desk     = seed_item($admin, $cat, $seller, 'Desk');
$bossId   = api_admin_seed_admin($admin, 'boss');
$modId    = api_admin_seed_admin($admin, 'mod', true);

harness_reset_container();
$events = Container::root()->get(DispatcherInterface::class);

$keys      = new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
$userKey   = static fn (int $user): string => $keys->create('key', 'k' . $user, ['conversations:write'], KeyOwner::user($user))->token();
$buyerKey  = $userKey($buyer);
$sellerKey = $userKey($seller);
$otherKey  = $userKey($stranger);
$bossKey   = api_admin_key($bossId);
$modKey    = api_admin_key($modId, true);
$noScope   = api_admin_key($bossId, false, ['admin:comments']);
$call      = api_admin_caller();
$ids       = static fn ($r): array => array_column($r->body()['data'] ?? [], 'id');

// Thread A is open (bike); B is a held thread (bike, stranger); C is reported (lamp, stranger).
$a = (int) $call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'Is it here?'], $buyerKey)->body()['data']['conversation_id'];
$call('POST', 'account/conversations/' . $a . '/messages', ['body' => 'Yes.'], $sellerKey);
$c = (int) $call('POST', 'account/conversations', ['listing' => $lamp, 'body' => 'Lamp?'], $otherKey)->body()['data']['conversation_id'];
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};
$events->listen(MessageSending::class, $hold);
$heldReply = $call('POST', 'account/conversations/' . $a . '/messages', ['body' => 'Held reply'], $buyerKey);
$heldStart = $call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'Held start'], $otherKey);
$events->remove(MessageSending::class, $hold);
$h1 = (int) $heldReply->body()['data']['id'];
$h2 = (int) $heldStart->body()['data']['id'];
$b  = (int) $heldStart->body()['data']['conversation_id'];
$call('POST', 'account/conversations/' . $c . '/report', ['reason' => 'scam'], $otherKey);

harness_section('who may call');
foreach (['GET admin/conversations', 'GET admin/messages', 'GET admin/conversations/' . $a, 'POST admin/messages/' . $h1 . '/remove'] as $route) {
    [$method, $path] = explode(' ', $route);
    pin($route . ': a user key is wrong_credential', '403 wrong_credential', api_admin_code($call($method, $path, null, $buyerKey)));
    pin($route . ': an admin key without the scope', '403 insufficient_scope', api_admin_code($call($method, $path, null, $noScope)));
    pin($route . ': no key', '401 unauthorized', api_admin_code($call($method, $path, null, null)));
}
pin('a moderator key with the scope lists', 200, $call('GET', 'admin/conversations', null, $modKey)->status());
check('the moderator scope is granted', in_array('admin:conversations', (new Scopes())->allowedFor(CredentialKind::KEY, KeyOwner::admin($modId, true)), true));

harness_section('the conversation list');
$r = $call('GET', 'admin/conversations', null, $bossKey);
pin('every thread, newest first', [$b, $c, $a], $ids($r));
pin('the page matches the schema', [], api_admin_schema_errors('AdminConversationPage', $r));
$byId = array_column($r->body()['data'], null, 'id');
pin('names, title and counters', ['bea', 'sam', 'Bike', 'open', 1, 1], [$byId[$a]['buyer']['name'], $byId[$a]['seller']['name'], $byId[$a]['listing_title'], $byId[$a]['state'], $byId[$a]['buyer_unread'], $byId[$a]['seller_unread']]);
pin('the report names who and why', [null, ['user_id' => $stranger, 'reason' => 'scam']], [$byId[$a]['report'], $byId[$c]['report']]);
pin('status filter', [$c], $ids($call('GET', 'admin/conversations', null, $bossKey, [], ['status' => 'reported'])));
pin('held filter', [$b], $ids($call('GET', 'admin/conversations', null, $bossKey, [], ['status' => 'held'])));
pin('user filter matches buyer or seller', [$b, $c], $ids($call('GET', 'admin/conversations', null, $bossKey, [], ['user' => (string) $stranger])));
pin('seller matches too', [$b, $c, $a], $ids($call('GET', 'admin/conversations', null, $bossKey, [], ['user' => (string) $seller])));
pin('listing filter', [$c], $ids($call('GET', 'admin/conversations', null, $bossKey, [], ['listing' => (string) $lamp])));
pin('a bad status is refused', '422 validation_failed', api_admin_code($call('GET', 'admin/conversations', null, $bossKey, [], ['status' => 'nope'])));
$r = $call('GET', 'admin/conversations', null, $bossKey, [], ['limit' => '2']);
parse_str((string) parse_url((string) ($r->body()['links']['next'] ?? ''), PHP_URL_QUERY), $next);
pin('a page of two, then the rest by cursor', [[$b, $c], [$a]], [$ids($r), $ids($call('GET', 'admin/conversations', null, $bossKey, [], ['limit' => '2', 'cursor' => (string) ($next['cursor'] ?? '')]))]);

harness_section('the held list');
$r = $call('GET', 'admin/messages', null, $bossKey);
pin('held messages, newest first', [$h2, $h1], $ids($r));
pin('the page matches the schema', [], api_admin_schema_errors('AdminMessagePage', $r));
$first = $r->body()['data'][0];
pin('the body, sender and state are there', ['Held start', ['id' => $stranger, 'name' => 'ola'], 'held', true, $bike, $b], [$first['body'], $first['sender'], $first['state'], $first['held'], $first['listing_id'], $first['conversation_id']]);
pin('only held is a status', '422 validation_failed', api_admin_code($call('GET', 'admin/messages', null, $bossKey, [], ['status' => 'sent'])));
$r = $call('GET', 'admin/messages/' . $h1, null, $bossKey);
pin('one message', [200, 'Held reply', 'bea'], [$r->status(), $r->body()['data']['body'] ?? null, $r->body()['data']['sender']['name'] ?? null]);
pin('it matches the schema', [], api_admin_schema_errors('AdminMessageDocument', $r));
pin('no such message', '404 not_found', api_admin_code($call('GET', 'admin/messages/999999', null, $bossKey)));

harness_section('one thread');
$r = $call('GET', 'admin/conversations/' . $a, null, $bossKey);
pin('every message, held included, oldest first', [3, 'held', ['Is it here?', 'Yes.', 'Held reply']], [count($r->body()['data']['messages'] ?? []), $r->body()['data']['messages'][2]['state'] ?? null, array_column($r->body()['data']['messages'] ?? [], 'body')]);
pin('the document matches the schema', [], api_admin_schema_errors('AdminConversationDocument', $r));
pin('no such conversation', '404 not_found', api_admin_code($call('GET', 'admin/conversations/999999', null, $bossKey)));

harness_section('release and remove');
$jobs = $count("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.notify'");
$r    = $call('POST', 'admin/messages/' . $h2 . '/release', null, $modKey);
pin('release answers the message as sent', [200, 'sent', false], [$r->status(), $r->body()['data']['state'] ?? null, $r->body()['data']['held'] ?? null]);
pin('it matches the schema', [], api_admin_schema_errors('AdminMessageDocument', $r));
pin('the seller now sees the thread', true, in_array($b, $ids($call('GET', 'account/conversations', null, $sellerKey)), true));
pin('and is mailed', $jobs + 1, $count("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.notify'"));
pin('the thread opened with it', 'open', $call('GET', 'admin/conversations/' . $b, null, $bossKey)->body()['data']['state'] ?? null);
pin('releasing again is a conflict', '409 conflict', api_admin_code($call('POST', 'admin/messages/' . $h2 . '/release', null, $bossKey)));
$r = $call('POST', 'admin/messages/' . $h1 . '/remove', null, $bossKey);
pin('remove answers the message as removed', [200, 'removed'], [$r->status(), $r->body()['data']['state'] ?? null]);
pin('the other person never sees it', false, in_array($h1, $ids($call('GET', 'account/conversations/' . $a . '/messages', null, $sellerKey)), true));
pin('removing again is a conflict', '409 conflict', api_admin_code($call('POST', 'admin/messages/' . $h1 . '/remove', null, $bossKey)));
pin('releasing a removed one is a conflict', '409 conflict', api_admin_code($call('POST', 'admin/messages/' . $h1 . '/release', null, $bossKey)));
pin('no held message is left', [], $ids($call('GET', 'admin/messages', null, $bossKey)));

harness_section('close and clear the report');
pin('clearing an unreported thread is a conflict', '409 conflict', api_admin_code($call('POST', 'admin/conversations/' . $a . '/clear-report', null, $bossKey)));
$r = $call('POST', 'admin/conversations/' . $c . '/clear-report', null, $bossKey);
pin('clearing the report opens it', [200, 'open', null], [$r->status(), $r->body()['data']['state'] ?? null, array_key_exists('report', $r->body()['data'] ?? []) ? $r->body()['data']['report'] : 'missing']);
pin('it matches the schema', [], api_admin_schema_errors('AdminConversationDocument', $r));
$call('POST', 'account/conversations/' . $c . '/report', ['reason' => 'spam'], $sellerKey);
$r = $call('POST', 'admin/conversations/' . $c . '/close', null, $modKey);
pin('close answers the thread as closed', [200, 'closed'], [$r->status(), $r->body()['data']['state'] ?? null]);
pin('both sides can no longer write', ['409 conflict', '409 conflict'], [
    api_admin_code($call('POST', 'account/conversations/' . $c . '/messages', ['body' => 'x'], $otherKey)),
    api_admin_code($call('POST', 'account/conversations/' . $c . '/messages', ['body' => 'x'], $sellerKey)),
]);
pin('closing a closed thread is a conflict', '409 conflict', api_admin_code($call('POST', 'admin/conversations/' . $c . '/close', null, $bossKey)));
pin('no such conversation', '404 not_found', api_admin_code($call('POST', 'admin/conversations/999999/close', null, $bossKey)));

harness_section('the switch off');
$set('enabled_messaging', '0');
pin('the review routes still answer, as the admin screen does', [200, 200], [$call('GET', 'admin/conversations', null, $bossKey)->status(), $call('GET', 'admin/messages', null, $bossKey)->status()]);
pin('and the user routes are refused', '403 feature_disabled', api_admin_code($call('GET', 'account/conversations', null, $buyerKey)));
$set('enabled_messaging', '1');

exit(harness_result());
