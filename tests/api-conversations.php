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
 * The account/conversations routes: their scopes, the list and its keyset, starting and
 * replying, message paging and `after`, the read marker, held messages, block, report, the
 * switch, the send limit, Idempotency-Key, and the schema of every answer.
 * Usage: php tests/api-conversations.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\api\serializer\ConversationSerializer;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\container\Container;
use mindstellar\event\DispatcherInterface;
use mindstellar\messaging\event\MessageSending;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$admin = api_admin_boot('osc_api_conversations');
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
$member = static fn ($parent, string $name) => is_array($parent) && array_key_exists($name, $parent) ? $parent[$name] : 'missing';

$cat      = seed_category($admin);
$buyer    = seed_user($admin, 'bea', 'bea@example.test');
$seller   = seed_user($admin, 'sam', 'sam@example.test');
$stranger = seed_user($admin, 'ola', 'ola@example.test');
$bike     = seed_item($admin, $cat, $seller, 'Bike');
$lamp     = seed_item($admin, $cat, $seller, 'Lamp');
$desk     = seed_item($admin, $cat, $seller, 'Desk');
$own      = seed_item($admin, $cat, $buyer, 'My chair');

harness_reset_container();
$events = Container::root()->get(DispatcherInterface::class);

harness_section('scopes');
$scopes = new Scopes();
foreach ([CredentialKind::KEY => 'a user key', CredentialKind::SESSION => 'a page-token session', CredentialKind::USER => 'an access token'] as $kind => $label) {
    $allowed = $scopes->allowedFor($kind, KeyOwner::user($buyer));
    check($label . ' may hold conversations:read and conversations:write', in_array('conversations:read', $allowed, true) && in_array('conversations:write', $allowed, true));
}
check('conversations:write grants conversations:read', Scopes::implies(['conversations:write'], 'conversations:read'));
check('a public key holds neither', array_intersect(['conversations:read', 'conversations:write'], $scopes->allowedFor(CredentialKind::PUBLIC, KeyOwner::user($buyer))) === []);
check('an admin key may hold admin:conversations', in_array('admin:conversations', $scopes->allowedFor(CredentialKind::KEY, KeyOwner::admin(1)), true));
check('a moderator key may hold it while moderators open the messages page', in_array('admin:conversations', $scopes->allowedFor(CredentialKind::KEY, KeyOwner::admin(2, true)), true));
check('but not when the messages page is closed to them', !in_array('admin:conversations', (new Scopes([], ['items']))->allowedFor(CredentialKind::KEY, KeyOwner::admin(2, true)), true));

$keys      = new ApiKeys(new ApiCredential(), $scopes, new SystemClock());
$key       = static fn (int $user, array $grant): string => $keys->create('key', 'k' . $user . implode('', $grant) . mt_rand(), $grant, KeyOwner::user($user))->token();
$buyerKey  = $key($buyer, ['conversations:write']);
$sellerKey = $key($seller, ['conversations:write']);
$otherKey  = $key($stranger, ['conversations:write']);
$readKey   = $key($buyer, ['conversations:read']);
$call      = api_admin_caller();

harness_section('the list, empty');
$r = $call('GET', 'account/conversations', null, $buyerKey);
pin('an empty list with the unread total', [200, [], 0, null], [$r->status(), $r->body()['data'] ?? null, $r->body()['meta']['unread'] ?? null, $member($r->body()['meta'] ?? null, 'latest_at')]);
pin('the page matches the schema', [], api_admin_schema_errors('ConversationPage', $r));

harness_section('starting a conversation');
$r    = $call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'Is the bike still here?'], $buyerKey);
$conv = (int) ($r->body()['data']['conversation_id'] ?? 0);
$m1   = (int) ($r->body()['data']['id'] ?? 0);
pin('201 with the message', [201, 'Is the bike still here?', $buyer, false], [$r->status(), $r->body()['data']['body'] ?? null, $r->body()['data']['sender_id'] ?? null, $r->body()['data']['held'] ?? null]);
pin('Location names the conversation', 'http://localhost/api/v1/account/conversations/' . $conv, $r->header('Location'));
pin('no warning when it is sent', false, isset($r->body()['warnings']));
pin('the document matches the schema', [], api_admin_schema_errors('MessageDocument', $r));
$r = $call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'Hello again'], $buyerKey);
pin('a second start on the listing goes to the same thread', [201, $conv, 'http://localhost/api/v1/account/conversations/' . $conv], [$r->status(), $r->body()['data']['conversation_id'] ?? null, $r->header('Location')]);
$m2 = (int) ($r->body()['data']['id'] ?? 0);

$r    = $call('GET', 'account/conversations', null, $sellerKey);
$item = $r->body()['data'][0] ?? [];
pin("the seller's list shows it", [[$conv], 'seller', 2, 'Bike', $bike, ['id' => $buyer, 'name' => 'bea'], 2], [array_column($r->body()['data'] ?? [], 'id'), $item['side'] ?? null, $item['unread'] ?? null, $item['listing_title'] ?? null, $item['listing_id'] ?? null, $item['buyer'] ?? null, $r->body()['meta']['unread'] ?? null]);
pin('with the latest time in meta', true, is_string($r->body()['meta']['latest_at'] ?? null) && str_ends_with($r->body()['meta']['latest_at'], 'Z'));
pin('the page matches the schema', [], api_admin_schema_errors('ConversationPage', $r));
$r = $call('GET', 'account/conversations/' . $conv, null, $buyerKey);
pin("the buyer's view", ['buyer', 0, 'open', false, false, false], [$r->body()['data']['side'] ?? null, $r->body()['data']['unread'] ?? null, $r->body()['data']['state'] ?? null, $r->body()['data']['blocked_by_me'] ?? null, $r->body()['data']['blocked_by_them'] ?? null, $r->body()['data']['reported_by_me'] ?? null]);
pin('the document matches the schema', [], api_admin_schema_errors('ConversationDocument', $r));
pin('a stranger gets a 404', '404 not_found', api_admin_code($call('GET', 'account/conversations/' . $conv, null, $otherKey)));
pin("and cannot read its messages", '404 not_found', api_admin_code($call('GET', 'account/conversations/' . $conv . '/messages', null, $otherKey)));
pin('or poll them', '404 not_found', api_admin_code($call('GET', 'account/conversations/' . $conv . '/messages', null, $otherKey, [], ['after' => '1'])));
pin('or write in it', '404 not_found', api_admin_code($call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'hi'], $otherKey)));
pin('no such conversation', '404 not_found', api_admin_code($call('GET', 'account/conversations/999999', null, $buyerKey)));
pin('no such listing', '404 not_found', api_admin_code($call('POST', 'account/conversations', ['listing' => 999999, 'body' => 'x'], $buyerKey)));
pin('your own listing is a conflict', '409 conflict', api_admin_code($call('POST', 'account/conversations', ['listing' => $own, 'body' => 'x'], $buyerKey)));
pin('an empty body is refused', '422 validation_failed', api_admin_code($call('POST', 'account/conversations', ['listing' => $bike, 'body' => ''], $buyerKey)));
pin('an unknown member is refused', '422 validation_failed', api_admin_code($call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'x', 'to' => $stranger], $buyerKey)));

harness_section('messages and the read marker');
$r  = $call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'Yes, it is.'], $sellerKey);
$m3 = (int) ($r->body()['data']['id'] ?? 0);
pin('a reply is 201', [201, $seller], [$r->status(), $r->body()['data']['sender_id'] ?? null]);
$r = $call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey, [], ['limit' => '2']);
pin('newest first, two to a page', [[$m3, $m2], true], [array_column($r->body()['data'] ?? [], 'id'), is_string($r->body()['links']['next'] ?? null)]);
pin('the page matches the schema', [], api_admin_schema_errors('MessagePage', $r));
parse_str((string) parse_url((string) ($r->body()['links']['next'] ?? ''), PHP_URL_QUERY), $next);
$r = $call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey, [], ['limit' => '2', 'cursor' => (string) ($next['cursor'] ?? '')]);
pin('the cursor gives the rest', [[$m1], null], [array_column($r->body()['data'] ?? [], 'id'), $member($r->body()['links'] ?? null, 'next')]);
$r = $call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey, [], ['after' => (string) $m1, 'limit' => '2', 'cursor' => (string) ($next['cursor'] ?? '')]);
pin('after answers only the newer ones, oldest first, and ignores the cursor', [[$m2, $m3], null], [array_column($r->body()['data'] ?? [], 'id'), $member($r->body()['links'] ?? null, 'next')]);
pin('that page matches the schema', [], api_admin_schema_errors('MessagePage', $r));
pin('after the newest there is nothing', [], $call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey, [], ['after' => (string) $m3])->body()['data'] ?? null);
pin('after must be a positive id', '422 validation_failed', api_admin_code($call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey, [], ['after' => '0'])));
$unread = static fn (string $token) => $call('GET', 'account/conversations/' . $conv, null, $token)->body()['data']['unread'] ?? null;
pin('reading does not mark it read', 1, $unread($buyerKey));
$r = $call('POST', 'account/conversations/' . $conv . '/read', null, $buyerKey);
pin('POST read does, and answers the conversation', [200, 0, 0], [$r->status(), $r->body()['data']['unread'] ?? null, $unread($buyerKey)]);
pin('the read answer matches the schema', [], api_admin_schema_errors('ConversationDocument', $r));

harness_section('the list keyset');
$r2 = $call('POST', 'account/conversations', ['listing' => $lamp, 'body' => 'Lamp?'], $buyerKey);
$r3 = $call('POST', 'account/conversations', ['listing' => $desk, 'body' => 'Desk?'], $buyerKey);
$c2 = (int) ($r2->body()['data']['conversation_id'] ?? 0);
$c3 = (int) ($r3->body()['data']['conversation_id'] ?? 0);
// Times that put the ids out of order, and two threads on one second so the id breaks the tie.
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = '2026-01-01 10:00:00' WHERE pk_i_id = $c3");
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = '2026-01-03 10:00:00' WHERE pk_i_id IN ($conv, $c2)");
$walk = [];
$cursor = null;
for ($i = 0; $i < 5; $i++) {
    $r      = $call('GET', 'account/conversations', null, $buyerKey, [], ['limit' => '1'] + ($cursor === null ? [] : ['cursor' => $cursor]));
    $walk   = array_merge($walk, array_column($r->body()['data'] ?? [], 'id'));
    $link   = $r->body()['links']['next'] ?? null;
    if (!is_string($link)) {
        break;
    }
    parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
    $cursor = (string) ($q['cursor'] ?? '');
}
pin('one at a time, newest activity first, the id breaking the tie', [$c2, $conv, $c3], $walk);
pin('a cursor made for another list is refused', '400 invalid_cursor', api_admin_code($call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey, [], ['cursor' => (string) $cursor])));

harness_section('held messages');
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};
$events->listen(MessageSending::class, $hold);
$r = $call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'Held one'], $buyerKey);
$heldStart = $call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'Held start'], $otherKey);
$events->remove(MessageSending::class, $hold);
$heldId = (int) ($r->body()['data']['id'] ?? 0);
pin('the sender gets 201, held, and the warning', [201, true, [['code' => 'message_held', 'message' => 'The message shows to the other person once it is reviewed.']]], [$r->status(), $r->body()['data']['held'] ?? null, $r->body()['warnings'] ?? null]);
pin('the held document matches the schema', [], api_admin_schema_errors('MessageDocument', $r));
pin('the sender sees it in the thread', true, in_array($heldId, array_column($call('GET', 'account/conversations/' . $conv . '/messages', null, $buyerKey)->body()['data'] ?? [], 'id'), true));
pin('the recipient does not', false, in_array($heldId, array_column($call('GET', 'account/conversations/' . $conv . '/messages', null, $sellerKey)->body()['data'] ?? [], 'id'), true));
pin('held rows do not eat the recipient\'s page slots', 2, count($call('GET', 'account/conversations/' . $conv . '/messages', null, $sellerKey, [], ['limit' => '2'])->body()['data'] ?? []));
pin('nor when polling', [], $call('GET', 'account/conversations/' . $conv . '/messages', null, $sellerKey, [], ['after' => (string) $m3])->body()['data'] ?? null);
$heldConv = (int) ($heldStart->body()['data']['conversation_id'] ?? 0);
pin('a held thread is 404 for the seller', '404 not_found', api_admin_code($call('GET', 'account/conversations/' . $heldConv, null, $sellerKey)));
pin('and its messages too', '404 not_found', api_admin_code($call('GET', 'account/conversations/' . $heldConv . '/messages', null, $sellerKey, [], ['after' => '1'])));
pin('and it is not in their list', false, in_array($heldConv, array_column($call('GET', 'account/conversations', null, $sellerKey)->body()['data'] ?? [], 'id'), true));
pin('the starter sees it, held', 'held', $call('GET', 'account/conversations/' . $heldConv, null, $otherKey)->body()['data']['state'] ?? null);
$serializer = new ConversationSerializer();
$row        = ['pk_i_id' => 9, 'fk_i_conversation_id' => 1, 'fk_i_sender_id' => $buyer, 's_body' => 'x', 's_state' => 'held', 'dt_created' => '2026-01-01 00:00:00'];
pin('the serializer drops a held row for anyone but its sender', [[], 1], [$serializer->messages([$row], $seller), count($serializer->messages([$row], $buyer))]);
pin('and a removed one for everyone', [], $serializer->messages([['s_state' => 'removed'] + $row], $buyer));

harness_section('block and report');
$r = $call('POST', 'account/conversations/' . $conv . '/block', null, $buyerKey);
pin('block answers the conversation, blocked by me', [200, true, false], [$r->status(), $r->body()['data']['blocked_by_me'] ?? null, $r->body()['data']['blocked_by_them'] ?? null]);
pin('the block answer matches the schema', [], api_admin_schema_errors('ConversationDocument', $r));
pin('the other side sees blocked by them', [false, true], (static fn (array $d): array => [$d['blocked_by_me'] ?? null, $d['blocked_by_them'] ?? null])($call('GET', 'account/conversations/' . $conv, null, $sellerKey)->body()['data'] ?? []));
pin('a reply then is forbidden', '403 forbidden', api_admin_code($call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'hi'], $sellerKey)));
pin('the other side cannot lift it', '403 not_owner', api_admin_code($call('DELETE', 'account/conversations/' . $conv . '/block', null, $sellerKey)));
$r = $call('DELETE', 'account/conversations/' . $conv . '/block', null, $buyerKey);
pin('DELETE block lifts it', [200, false], [$r->status(), $r->body()['data']['blocked_by_me'] ?? null]);
pin('and a reply goes through', 201, $call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'Back'], $sellerKey)->status());
pin('a bad reason is refused', '422 validation_failed', api_admin_code($call('POST', 'account/conversations/' . $c2 . '/report', ['reason' => 'rude'], $buyerKey)));
$r = $call('POST', 'account/conversations/' . $c2 . '/report', ['reason' => 'spam'], $buyerKey);
pin('report answers the conversation', [200, 'reported', true, true], [$r->status(), $r->body()['data']['state'] ?? null, $r->body()['data']['reported_by_me'] ?? null, $r->body()['data']['blocked_by_me'] ?? null]);
pin('the report answer matches the schema', [], api_admin_schema_errors('ConversationDocument', $r));
pin('reporting again is a conflict', '409 conflict', api_admin_code($call('POST', 'account/conversations/' . $c2 . '/report', ['reason' => 'spam'], $buyerKey)));
pin('the other side did not report it', false, $call('GET', 'account/conversations/' . $c2, null, $sellerKey)->body()['data']['reported_by_me'] ?? null);

harness_section('who may call');
pin('a read key reads', 200, $call('GET', 'account/conversations', null, $readKey)->status());
pin('but cannot write', '403 insufficient_scope', api_admin_code($call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'x'], $readKey)));
pin('nor mark read', '403 insufficient_scope', api_admin_code($call('POST', 'account/conversations/' . $conv . '/read', null, $readKey)));
pin('anonymous is a 401', [401, 401], [$call('GET', 'account/conversations')->status(), $call('POST', 'account/conversations', ['listing' => $bike, 'body' => 'x'])->status()]);
pin('a key with another scope is refused', '403 insufficient_scope', api_admin_code($call('GET', 'account/conversations', null, $key($buyer, ['wishlist:read']))));

harness_section('Idempotency-Key');
$before = $count("SELECT COUNT(*) FROM {$p}t_message");
$first  = $call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'Once'], $buyerKey, ['Idempotency-Key' => 'conv-once-1']);
$second = $call('POST', 'account/conversations/' . $conv . '/messages', ['body' => 'Once'], $buyerKey, ['Idempotency-Key' => 'conv-once-1']);
pin('the same key twice writes one message and answers the same', [201, 201, true, 1], [$first->status(), $second->status(), $first->body() === $second->body(), $count("SELECT COUNT(*) FROM {$p}t_message") - $before]);

harness_section('the send limit');
$kim    = seed_user($admin, 'kim', 'kim@example.test');
$kimKey = $key($kim, ['conversations:write']);
$set('throttle_message_send', '1');
$r = $call('POST', 'account/conversations', ['listing' => $lamp, 'body' => 'One'], $kimKey);
pin('the first message goes through', 201, $r->status());
$r = $call('POST', 'account/conversations/' . (int) ($r->body()['data']['conversation_id'] ?? 0) . '/messages', ['body' => 'Two'], $kimKey);
pin('the next one in the hour is a 429 with Retry-After', ['429 rate_limited', true], [api_admin_code($r), (int) $r->header('Retry-After') > 0]);
$set('throttle_message_send', '0');

harness_section('the switch');
$set('enabled_messaging', '0');
$routes = [
    ['GET', 'account/conversations', null, []],
    ['POST', 'account/conversations', ['listing' => $lamp, 'body' => 'x'], []],
    ['GET', 'account/conversations/' . $conv, null, []],
    ['GET', 'account/conversations/' . $conv . '/messages', null, []],
    ['GET', 'account/conversations/' . $conv . '/messages', null, ['after' => '1']],
    ['POST', 'account/conversations/' . $conv . '/messages', ['body' => 'x'], []],
    ['POST', 'account/conversations/' . $conv . '/read', null, []],
    ['POST', 'account/conversations/' . $conv . '/block', null, []],
    ['DELETE', 'account/conversations/' . $conv . '/block', null, []],
    ['POST', 'account/conversations/' . $conv . '/report', ['reason' => 'spam'], []],
];
$codes = [];
foreach ($routes as [$method, $path, $body, $query]) {
    $codes[] = api_admin_code($call($method, $path, $body, $buyerKey, [], $query));
}
pin('switched off, every route is 403 feature_disabled', array_fill(0, count($routes), '403 feature_disabled'), $codes);
$set('enabled_messaging', '1');

exit(harness_result());
