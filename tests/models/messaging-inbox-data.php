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
 * The inbox reads: the last visible message per thread (MessageStore::lastVisible), the listing
 * cards (ListingStore::cards) and the photos for those listings (PhotoStore::ofItems).
 * Usage: php tests/models/messaging-inbox-data.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\listing\ListingStore;
use mindstellar\listing\PhotoStore;
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\MessageStore;

$admin = api_admin_boot('osc_models_messaging_inbox_data');
$p     = DB_TABLE_PREFIX;

$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$cat    = seed_category($admin);
$item   = seed_item($admin, $cat, $seller, 'Bike', 20.0);
$item2  = seed_item($admin, $cat, $seller, 'Lamp', 5.0);

$messages      = new MessageStore();
$conversations = new ConversationStore($messages);
$thread        = static fn (int $item, int $buyer, int $seller): int => $conversations->create(array(
    'fk_i_item_id' => $item, 'fk_i_buyer_id' => $buyer, 'fk_i_seller_id' => $seller,
    's_state' => 'open', 'dt_created' => '2026-01-01 10:00:00', 'dt_last_message' => '2026-01-01 10:00:00',
));
$say = static fn (int $c, int $sender, string $body, string $state = 'sent'): int => $messages->create(array(
    'fk_i_conversation_id' => $c, 'fk_i_sender_id' => $sender, 's_body' => $body, 's_state' => $state, 'dt_created' => '2026-01-01 10:00:00',
));

harness_section('last visible message');
$c1 = $thread($item, $buyer, $seller);
$say($c1, $buyer, 'first');
$mine = $say($c1, $seller, 'second');
$theirHeld = $say($c1, $buyer, 'held by them', 'held');
$say($c1, $buyer, 'removed', 'removed');
$last = $messages->lastVisible(array($c1), $seller);
pin('the other side\'s held and removed messages are skipped', $mine, (int) ($last[$c1]['pk_i_id'] ?? 0));
pin('the preview is the body', 'second', $last[$c1]['s_preview'] ?? null);
$heldMine = $say($c1, $seller, 'held by me', 'held');
pin('my own held message shows', $heldMine, (int) ($messages->lastVisible(array($c1), $seller)[$c1]['pk_i_id'] ?? 0));
pin('the other side does not see it, only their own held one', $theirHeld, (int) ($messages->lastVisible(array($c1), $buyer)[$c1]['pk_i_id'] ?? 0));

$c2 = $thread($item2, $buyer, $seller);
$say($c2, $buyer, 'only a removed one', 'removed');
pin('a thread with nothing visible is left out', array($c1), array_keys($messages->lastVisible(array($c1, $c2), $seller)));
pin('no ids, no rows', array(), $messages->lastVisible(array(), $seller));

$long = str_repeat('é', 200) . str_repeat('😀', 10);
$c3   = $thread($item2, $seller, $buyer);
$say($c3, $seller, $long);
$cut = (string) ($messages->lastVisible(array($c3), $seller)[$c3]['s_preview'] ?? '');
pin('the preview is cut to 160 characters', 160, mb_strlen($cut, 'UTF-8'));
check('the cut does not split a character', mb_check_encoding($cut, 'UTF-8') && $cut === str_repeat('é', 160));
$emoji = $say($thread($item, $seller, $buyer), $seller, str_repeat('a', 159) . '😀😀');
pin('a four-byte character at the cut stays whole', str_repeat('a', 159) . '😀', (string) ($messages->lastVisible(array((int) $messages->find($emoji)['fk_i_conversation_id']), $seller)[(int) $messages->find($emoji)['fk_i_conversation_id']]['s_preview'] ?? ''));

harness_section('query count');
$many = array();
$manyItems = array();
for ($i = 0; $i < 20; $i++) {
    $it     = seed_item($admin, $cat, $seller, 'Thing ' . $i);
    $c      = $thread($it, $buyer, $seller);
    $many[]      = $c;
    $manyItems[] = $it;
    $say($c, $buyer, 'hi ' . $i);
}
pin('one query for 1 id', 1, harness_query_count(static fn () => $messages->lastVisible(array($many[0]), $seller)));
pin('one query for 20 ids', 1, harness_query_count(static fn () => $messages->lastVisible($many, $seller)));
pin('twenty rows come back', 20, count($messages->lastVisible($many, $seller)));

harness_section('listing cards');
$gone  = 999999;
$cards = ListingStore::cards(array($item, $item2, $gone, $item));
pin('a missing listing is absent', array($item, $item2), array_keys($cards));
pin('price and currency', array('price' => 20000000, 'currency' => 'USD'), array('price' => $cards[$item]['price'], 'currency' => $cards[$item]['currency']));
pin('a published listing is live', true, $cards[$item]['live']);
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = $item2");
pin('a disabled listing is not live', false, ListingStore::cards(array($item2))[$item2]['live']);
$admin->query("UPDATE {$p}t_item SET b_enabled = 1, b_spam = 1 WHERE pk_i_id = $item2");
pin('a spam listing is not live', false, ListingStore::cards(array($item2))[$item2]['live']);
$admin->query("UPDATE {$p}t_item SET b_spam = 0, i_price = NULL, fk_c_currency_code = NULL, dt_expiration = '2000-01-01 00:00:00' WHERE pk_i_id = $item2");
$free = ListingStore::cards(array($item2))[$item2];
pin('no price is null', array(null, null), array($free['price'], $free['currency']));
pin('an expired listing is not live', false, $free['live']);
pin('no ids, no rows', array(), ListingStore::cards(array()));
pin('one query for 1 id', 1, harness_query_count(static fn () => ListingStore::cards(array($item))));
pin('one query for 21 ids', 1, harness_query_count(static fn () => ListingStore::cards(array_merge(array($item), $manyItems))));

harness_section('photos');
seed_photo($admin, $item, array('s_name' => 'a'));
seed_photo($admin, $item, array('s_name' => 'b'));
pin('one query for photos of 21 listings', 1, harness_query_count(static fn () => PhotoStore::ofItems(array($item, $item2, 5, 6, 7))));
$photos = PhotoStore::ofItems(array($item, $item2));
pin('only the listing with photos has rows', 2, count($photos));
pin('upload order, first is the cover', 'a', $photos[0]['s_name']);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
