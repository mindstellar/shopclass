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
 * ConversationStore and MessageStore on the real tables: one thread per listing and buyer,
 * unread counters, the inbox union and its paging, held threads and messages, the review
 * lists, the export, and what an account or listing delete takes with it.
 * Usage: php tests/models/messaging-store.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\database\DbException;
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\MessageStore;

$admin = api_admin_boot('osc_models_messaging_store');
$p     = DB_TABLE_PREFIX;

$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$other  = seed_user($admin, 'ola', 'ola@example.test');
$cat    = seed_category($admin);
$item   = seed_item($admin, $cat, $seller, 'Bike');
$item2  = seed_item($admin, $cat, $seller, 'Lamp');
$count  = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_row()[0];
};
$ids = static function (array $rows): array {
    return array_map('intval', array_column($rows, 'pk_i_id'));
};

$messages      = new MessageStore();
$conversations = new ConversationStore($messages);
$row = static function (int $item, int $buyer, int $seller, string $when, string $state = 'open'): array {
    return array(
        'fk_i_item_id' => $item, 'fk_i_buyer_id' => $buyer, 'fk_i_seller_id' => $seller,
        's_state' => $state, 'dt_created' => $when, 'dt_last_message' => $when,
    );
};

harness_section('one thread per listing and buyer');
pin('no thread yet', null, $conversations->findForListing($item, $buyer, $seller));
$c1 = $conversations->create($row($item, $buyer, $seller, '2026-01-01 10:00:00'));
pin('found by listing and buyer', $c1, (int) $conversations->findForListing($item, $buyer, $seller)['pk_i_id']);
pin('found by id', $item, (int) $conversations->find($c1)['fk_i_item_id']);
pin('lock returns the row', $c1, (int) $conversations->lock($c1)['pk_i_id']);
$dup = null;
try {
    $conversations->create($row($item, $buyer, $seller, '2026-01-01 10:05:00'));
} catch (DbException $e) {
    $dup = (int) $e->getCode();
}
pin('a second thread for the same pair is a duplicate-key error', 1062, $dup);
pin('still one thread', 1, $count("SELECT COUNT(*) FROM {$p}t_conversation"));

harness_section('sides');
$c = $conversations->find($c1);
pin('buyer side', 'buyer', ConversationStore::sideOf($c, $buyer));
pin('seller side', 'seller', ConversationStore::sideOf($c, $seller));
pin('stranger has no side', null, ConversationStore::sideOf($c, $other));
pin('other party of the buyer', $seller, ConversationStore::otherParty($c, $buyer));
pin('other party of the seller', $buyer, ConversationStore::otherParty($c, $seller));

harness_section('unread counters');
$conversations->touch($c1, 'seller', '2026-01-01 10:10:00');
$conversations->touch($c1, 'seller', '2026-01-01 10:11:00');
$conversations->touch($c1, 'buyer', '2026-01-01 10:12:00');
$c = $conversations->find($c1);
pin('seller unread bumped twice', 2, (int) $c['i_seller_unread']);
pin('buyer unread bumped once', 1, (int) $c['i_buyer_unread']);
pin('last message time moved', '2026-01-01 10:12:00', $c['dt_last_message']);
pin('unread sums the seller side', 2, $conversations->unread($seller));
pin('unread sums the buyer side', 1, $conversations->unread($buyer));
pin('a stranger has none', 0, $conversations->unread($other));
pin('clearUnread returns the count', 2, $conversations->clearUnread($c1, 'seller'));
pin('clearUnread again returns 0', 0, $conversations->clearUnread($c1, 'seller'));
pin('the other side is untouched', 1, (int) $conversations->find($c1)['i_buyer_unread']);
$admin->query("UPDATE {$p}t_conversation SET i_seller_unread = 65535 WHERE pk_i_id = $c1");
$conversations->touch($c1, 'seller', '2026-01-01 10:13:00');
pin('the counter stops at 65535', 65535, (int) $conversations->find($c1)['i_seller_unread']);
$conversations->clearUnread($c1, 'seller');
pin('latest is the newest activity', '2026-01-01 10:13:00', $conversations->latest($buyer));
pin('latest of a stranger is null', null, $conversations->latest($other));
$threw = false;
try {
    $conversations->touch($c1, 'admin', '2026-01-01 10:14:00');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('an unknown side is refused', $threw);

harness_section('inbox');
$c2 = $conversations->create($row($item2, $buyer, $seller, '2026-01-02 10:00:00'));
$c3 = $conversations->create($row($item2, $other, $seller, '2026-01-03 10:00:00', 'held'));
$c4 = $conversations->create($row($item, $seller, $other, '2026-01-04 10:00:00'));
$delivered = static function (int $conversation) use ($messages): void {
    $messages->create(array('fk_i_conversation_id' => $conversation, 'fk_i_sender_id' => 0, 's_body' => 'x', 's_state' => 'sent', 'dt_created' => '2026-01-01 00:00:00'));
};
foreach (array($c1, $c2, $c3, $c4) as $c) {
    $delivered($c);
}
pin('buyer inbox, newest first', array($c2, $c1), $ids($conversations->inbox($buyer)));
pin('seller inbox mixes both sides and hides the held thread', array($c4, $c2, $c1), $ids($conversations->inbox($seller)));
pin('the held thread shows for its buyer', array($c4, $c3), $ids($conversations->inbox($other)));
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = '2026-01-05 10:00:00' WHERE pk_i_id = $c3");
pin('latest() for the seller ignores a held thread, even the newest', '2026-01-04 10:00:00', $conversations->latest($seller));
pin('latest() for its buyer counts it', '2026-01-05 10:00:00', $conversations->latest($other));
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = '2026-01-03 10:00:00' WHERE pk_i_id = $c3");
pin('byIds keys rows by id and skips missing ids', array($c1, $c3), array_keys($conversations->byIds(array($c3, $c1, 999999, $c1))));
pin('byIds of nothing', array(), $conversations->byIds(array()));
pin('limit', array($c4, $c2), $ids($conversations->inbox($seller, 2)));
pin('next page by date and id', array($c1), $ids($conversations->inbox($seller, 2, '2026-01-02 10:00:00', $c2)));
$tie = $conversations->create($row($item2, $seller, $buyer, '2026-01-02 10:00:00'));
$delivered($tie);
pin('same-second threads page by id', array($tie, $c2), $ids($conversations->inbox($seller, 2, '2026-01-03 10:00:00', $c3 + 1)));
pin('same-second paging does not repeat a row', array($c2, $c1), $ids($conversations->inbox($seller, 5, '2026-01-02 10:00:00', $tie)));
$unseen = $conversations->create($row($item2, $buyer, $other, '2026-01-06 10:00:00', 'closed'));
pin('the seller never sees a thread with nothing delivered in it', array($c4, $c3), $ids($conversations->inbox($other)));
pin('its buyer does', $unseen, $ids($conversations->inbox($buyer))[0]);
$admin->query("DELETE FROM {$p}t_conversation WHERE pk_i_id = $unseen");
$admin->query("DELETE FROM {$p}t_message");

harness_section('messages');
$m1 = $messages->create(array('fk_i_conversation_id' => $c1, 'fk_i_sender_id' => $buyer, 's_body' => 'Still for sale?', 's_state' => 'sent', 'dt_created' => '2026-01-01 10:00:00'));
$m2 = $messages->create(array('fk_i_conversation_id' => $c1, 'fk_i_sender_id' => $seller, 's_body' => 'Yes', 's_state' => 'sent', 'dt_created' => '2026-01-01 10:01:00'));
$m3 = $messages->create(array('fk_i_conversation_id' => $c1, 'fk_i_sender_id' => $buyer, 's_body' => 'Held text', 's_state' => 'held', 'dt_created' => '2026-01-01 10:02:00'));
$m4 = $messages->create(array('fk_i_conversation_id' => $c2, 'fk_i_sender_id' => $seller, 's_body' => 'Lamp text', 's_state' => 'sent', 'dt_created' => '2026-01-02 10:00:00'));
pin('find', 'Yes', $messages->find($m2)['s_body']);
pin('the seller sees sent messages only, newest first', array($m2, $m1), $ids($messages->ofConversation($c1, $seller)));
pin('the sender also sees their held message', array($m3, $m2, $m1), $ids($messages->ofConversation($c1, $buyer)));
pin('paging by message id', array($m1), $ids($messages->ofConversation($c1, $seller, 50, $m2)));
pin('limit', array($m3), $ids($messages->ofConversation($c1, $buyer, 1)));
pin('another thread is kept apart', array($m4), $ids($messages->ofConversation($c2, $buyer)));
pin('includeHeld shows every held message to the admin view', array($m3, $m2, $m1), $ids($messages->ofConversation($c1, 0, 50, null, true)));
pin('since() is oldest first after the cursor', array($m2, $m3), $ids($messages->since($c1, $buyer, $m1, 50)));
pin("since() hides the other side's held message", array($m2), $ids($messages->since($c1, $seller, $m1, 50)));
pin('since() after the last id is empty', array(), $messages->since($c1, $buyer, $m3, 50));
pin('since() keeps its limit', array($m1), $ids($messages->since($c1, $buyer, 0, 1)));
pin('liveCount counts sent and held', 3, $messages->liveCount($c1));
pin('countHeld', 1, $messages->countHeld());
pin('held() lists held messages', array($m3), $ids($messages->held()));
$messages->setState($m3, 'removed');
pin('removed leaves the held list', array(), $ids($messages->held()));
pin('removed is shown to nobody', array($m2, $m1), $ids($messages->ofConversation($c1, $buyer)));
pin('nor to the admin view', array($m2, $m1), $ids($messages->ofConversation($c1, 0, 50, null, true)));
pin('removed leaves liveCount', 2, $messages->liveCount($c1));

harness_section('block, report and review lists');
$conversations->setBlocked($c1, 'seller', true);
pin('seller block set', array(1, 0), array((int) $conversations->find($c1)['b_seller_blocked'], (int) $conversations->find($c1)['b_buyer_blocked']));
$conversations->setBlocked($c1, 'seller', false);
pin('seller block cleared', 0, (int) $conversations->find($c1)['b_seller_blocked']);
pin('held thread is in the state list', 'held', $conversations->find($c3)['s_state']);
$conversations->setReport($c2, $buyer, 'spam');
$r = $conversations->find($c2);
pin('report state, reporter and reason', array('reported', $buyer, 'spam'), array($r['s_state'], (int) $r['fk_i_reporter_id'], $r['s_report_reason']));
pin('reported() lists it', array($c2), $ids($conversations->reported()));
pin('countReported', 1, $conversations->countReported());
$conversations->clearReport($c2);
$r = $conversations->find($c2);
pin('clearReport opens it and drops reporter and reason', array('open', null, null), array($r['s_state'], $r['fk_i_reporter_id'], $r['s_report_reason']));
pin('countReported after clearReport', 0, $conversations->countReported());
$conversations->setReport($c2, $buyer, 'spam');
$conversations->setState($c2, 'closed');
pin('closed leaves the reported list', array(), $ids($conversations->reported()));
$conversations->setState($c2, 'open');

harness_section('export');
$export = $conversations->export($buyer);
$bySide = array();
foreach ($export['conversations'] as $conversation) {
    $bySide[(int) $conversation['pk_i_id']] = $conversation['side'];
}
ksort($bySide);
pin('threads with the side the person was on', array($c1 => 'buyer', $c2 => 'buyer', $tie => 'seller'), $bySide);
pin('only their own message text', array($m1, $m3), $ids($export['messages']));
pin('a stranger exports nothing', array('conversations' => array(), 'messages' => array()), $conversations->export(0));

harness_section('deletes');
// The scratch session runs with foreign key checks off; the cascades need them on.
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = $item");
pin('deleting a listing keeps its threads', 1, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE pk_i_id = $c1"));
pin('and their messages', 3, $count("SELECT COUNT(*) FROM {$p}t_message WHERE fk_i_conversation_id = $c1"));
$admin->query("DELETE FROM {$p}t_user WHERE pk_i_id = $buyer");
pin('deleting the buyer removes their threads', 0, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE fk_i_buyer_id = $buyer OR fk_i_seller_id = $buyer"));
pin('and their messages', 0, $count("SELECT COUNT(*) FROM {$p}t_message WHERE fk_i_conversation_id IN ($c1, $c2, $tie)"));
pin('another account\'s thread survives', 1, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE pk_i_id = $c3"));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
