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
 * MessagingService through the container: who may write, the spam and rate rules, held and
 * refused messages, blocks and reports, the events and when they fire, and two first
 * messages sent at once.
 *
 * Usage:  php tests/models/messaging-service.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-service  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';
require_once __DIR__ . '/../lib/race.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\event\DispatcherInterface;
use mindstellar\exception\BlockedException;
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\event\MessageSent;
use mindstellar\messaging\MessageNotifier;
use mindstellar\messaging\MessagingService;
use mindstellar\security\BanRuleStore;

$admin = api_admin_boot('osc_models_messaging_service');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p      = DB_TABLE_PREFIX;
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$count = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_row()[0];
};
$col = static function (string $sql) use ($admin) {
    return $admin->query($sql)->fetch_row()[0];
};
/** The exception a call throws, as 'class:reason', or 'ok'. */
$outcome = static function (callable $fn): string {
    try {
        $fn();

        return 'ok';
    } catch (ForbiddenException $e) {
        return 'forbidden:' . $e->reason();
    } catch (ConflictException $e) {
        return 'conflict:' . $e->reason();
    } catch (NotFoundException $e) {
        return 'not_found';
    } catch (InvalidException $e) {
        return 'invalid:' . $e->pointer() . ':' . $e->reason();
    } catch (BlockedException $e) {
        return 'rate_limited';
    }
};
$log = array();
/** A fresh container with the service, recording each messaging hook and whether a transaction was open. */
$fresh = static function () use (&$log): MessagingService {
    harness_reset_container();
    $c = Container::root();
    $c->get(DispatcherInterface::class)->remove(MessageSent::class, [MessageNotifier::class, 'onMessageSent']);
    foreach (array('conversation_started', 'message_sent', 'message_held', 'conversation_blocked', 'conversation_reported', 'message_read') as $name) {
        osc_add_hook($name, static function () use (&$log, $name): void {
            $log[] = $name . (Db::inTransaction() ? ' (in transaction)' : '');
        });
    }
    $log = array();

    return $c->get(MessagingService::class);
};

$buyer   = seed_user($admin, 'bea', 'bea@example.test');
$seller  = seed_user($admin, 'sam', 'sam@example.test');
$third   = seed_user($admin, 'ola', 'ola@example.test');
$seller2 = seed_user($admin, 'sid', 'sid@example.test');
$cat     = seed_category($admin);
$bike    = seed_item($admin, $cat, $seller, 'Bike');
$lamp    = seed_item($admin, $cat, $seller, 'Lamp');
$guest   = seed_item($admin, $cat, null, 'Guest sofa');
$old     = seed_item($admin, $cat, $seller, 'Old chair');
$vase    = seed_item($admin, $cat, $seller2, 'Vase');
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE pk_i_id = $old");
$asBuyer  = Actor::user($buyer, '10.0.0.1');
$asSeller = Actor::user($seller, '10.0.0.2');
$asThird  = Actor::user($third, '10.0.0.3');
$set('enabled_users', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

$svc = $fresh();

harness_section('who may write');
$set('enabled_messaging', '0');
pin('messaging off', 'forbidden:disabled', $outcome(fn () => $svc->start($bike, 'Hi', $asBuyer)));
$set('enabled_messaging', '1');
pin('a guest must sign in', 'forbidden:sign_in', $outcome(fn () => $svc->start($bike, 'Hi', Actor::guest('10.0.0.4'))));
pin('the seller cannot write about their own listing', 'conflict:own_listing', $outcome(fn () => $svc->start($bike, 'Hi', $asSeller)));
pin('a guest listing has no one to write to', 'conflict:no_seller', $outcome(fn () => $svc->start($guest, 'Hi', $asBuyer)));
pin('an expired listing takes no new thread', 'conflict:listing_not_live', $outcome(fn () => $svc->start($old, 'Hi', $asBuyer)));
pin('no such listing', 'not_found', $outcome(fn () => $svc->start(999999, 'Hi', $asBuyer)));
$admin->query("UPDATE {$p}t_user SET b_enabled = 0 WHERE pk_i_id = $third");
pin('a blocked account cannot write', 'forbidden:disabled', $outcome(fn () => $svc->start($bike, 'Hi', $asThird)));
$admin->query("UPDATE {$p}t_user SET b_enabled = 1 WHERE pk_i_id = $third");

harness_section('a thread and its counters');
$first  = $svc->start($bike, 'Is it still for sale?', $asBuyer);
$second = $svc->start($bike, '  Still there?  ', $asBuyer);
$conv   = $first->conversationId();
pin('the first message starts the thread', true, $first->started());
pin('the second continues it', array(false, $conv), array($second->started(), $second->conversationId()));
pin('one thread for the pair', 1, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE fk_i_item_id = $bike AND fk_i_buyer_id = $buyer"));
pin('two messages in it', 2, $count("SELECT COUNT(*) FROM {$p}t_message WHERE fk_i_conversation_id = $conv"));
pin('the body is trimmed', 'Still there?', $col("SELECT s_body FROM {$p}t_message WHERE pk_i_id = " . $second->messageId()));
pin('seller unread 2, buyer 0', array(2, 0), array($svc->unreadCount($seller), $svc->unreadCount($buyer)));
pin('a third user cannot reply', 'not_found', $outcome(fn () => $svc->reply($conv, 'Me too', $asThird)));
pin('nor read the thread', null, $svc->find($conv, $asThird));
pin('an admin reads it', $conv, (int) ($svc->find($conv, Actor::admin(1))['pk_i_id'] ?? 0));
pin('an admin does not write in it', 'forbidden:sign_in', $outcome(fn () => $svc->reply($conv, 'Admin here', Actor::admin(1))));
pin('a signed-in admin who is not a party does not write either', 'forbidden:not_owner', $outcome(fn () => $svc->reply($conv, 'Admin here', new Actor($third, 1))));
$svc->reply($conv, 'Yes, it is.', $asSeller);
pin('the reply counts for the buyer', 1, $svc->unreadCount($buyer));
$log = array();
pin('markRead clears 2', 2, $svc->markRead($conv, $asSeller));
pin('and then 0', 0, $svc->markRead($conv, $asSeller));
pin('message_read fired once, after commit', array('message_read'), $log);
pin('seller unread 0', 0, $svc->unreadCount($seller));
$thread = $svc->thread($conv, $asBuyer);
pin('thread() is oldest first', array('Is it still for sale?', 'Still there?', 'Yes, it is.'), array_column($thread['messages'], 's_body'));
pin('thread() pages back from a message id', array('Is it still for sale?'), array_column($svc->thread($conv, $asBuyer, 5, $second->messageId())['messages'], 's_body'));
pin('the buyer inbox holds the thread', array($conv), array_map('intval', array_column($svc->inbox($asBuyer), 'pk_i_id')));
$set('enabled_messaging', '0');
pin('unreadCount is 0 while messaging is off', 0, $svc->unreadCount($buyer));
$set('enabled_messaging', '1');

harness_section('bans and the body');
BanRuleStore::add('t', '', 'bea@example.test', 'messages', null);
pin('a message ban on the buyer', 'forbidden:banned', $outcome(fn () => $svc->reply($conv, 'Hello', $asBuyer)));
$admin->query("DELETE FROM {$p}t_ban_rule");
BanRuleStore::forget();
pin('an empty body', 'invalid:/body:minLength', $outcome(fn () => $svc->reply($conv, "  \n ", $asBuyer)));
$set('message_max_links', '0');
pin('a link when none are allowed', 'invalid:/body:refused', $outcome(fn () => $svc->reply($conv, 'See https://example.com/x', $asBuyer)));
$svc = $fresh();
osc_add_filter('message_body', static fn ($b) => $b . ' example.org/spam');
pin('a link a plugin adds is checked too', 'invalid:/body:refused', $outcome(fn () => $svc->reply($conv, 'Hello', $asBuyer)));
$set('message_max_links', '1');

harness_section('rate limits');
$set('throttle_message_start', '1');
$svc = $fresh();
pin('the first new thread in the hour', 'ok', $outcome(fn () => $svc->start($lamp, 'And the lamp?', $asThird)));
pin('a second new thread is over the limit', 'rate_limited', $outcome(fn () => $svc->start($bike, 'And the bike?', $asThird)));
pin('a reply still goes', 'ok', $outcome(fn () => $svc->start($lamp, 'Any news?', $asThird)));
$set('throttle_message_start', '0');

harness_section('message_body: changed, held, refused');
$svc = $fresh();
osc_add_filter('message_body', static fn ($b) => $b . ' [x]');
$changed = $svc->reply($conv, 'Tagged', $asBuyer);
pin('an old-style filter changes the stored body', 'Tagged [x]', $col("SELECT s_body FROM {$p}t_message WHERE pk_i_id = " . $changed->messageId()));
$svc  = $fresh();
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};
Container::root()->get(DispatcherInterface::class)->listen(MessageSending::class, $hold);
$held = $svc->start($vase, 'Cheap pills here', $asBuyer);
$hc   = $held->conversationId();
pin('a typed listener holds it', true, $held->held());
pin('message_held fires, not message_sent or conversation_started', array('message_held'), $log);
pin('the thread waits for review', 'held', $col("SELECT s_state FROM {$p}t_conversation WHERE pk_i_id = $hc"));
pin('the seller has nothing unread', 0, $svc->unreadCount($seller2));
pin("the seller's inbox is empty", array(), $svc->inbox(Actor::user($seller2)));
pin("the seller cannot open the thread", 'not_found', $outcome(fn () => $svc->thread($hc, Actor::user($seller2))));
pin('the sender sees their own held message', array('held'), array_column($svc->thread($hc, $asBuyer)['messages'], 's_state'));
Container::root()->get(DispatcherInterface::class)->remove(MessageSending::class, $hold);
$more = $svc->start($vase, 'Hello again', $asBuyer);
pin('a later message in a held thread is held too', true, $more->held());
pin('and still counts for no one', 0, $svc->unreadCount($seller2));
$svc = $fresh();
Container::root()->get(DispatcherInterface::class)->listen(MessageSending::class, static function (MessageSending $e): void {
    $e->refuse('No thanks');
});
pin('a typed listener refuses it', 'invalid:/body:refused', $outcome(fn () => $svc->reply($conv, 'Hello', $asBuyer)));

harness_section('block, unblock, report');
$svc = $fresh();
$svc->block($conv, $asSeller);
pin('blocking fires conversation_blocked after commit', array('conversation_blocked'), $log);
pin('the buyer cannot write', 'forbidden:blocked', $outcome(fn () => $svc->reply($conv, 'Hello?', $asBuyer)));
pin('nor can the seller', 'forbidden:blocked', $outcome(fn () => $svc->reply($conv, 'Bye', $asSeller)));
pin('nor through start()', 'forbidden:blocked', $outcome(fn () => $svc->start($bike, 'Hello?', $asBuyer)));
pin('nor a new thread on another of the seller\'s listings', 'forbidden:blocked', $outcome(fn () => $svc->start($lamp, 'And the lamp?', $asBuyer)));
pin('no thread was made', 0, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE fk_i_item_id = $lamp AND fk_i_buyer_id = $buyer"));
pin('the buyer cannot lift the seller\'s block', 'forbidden:not_owner', $outcome(fn () => $svc->unblock($conv, $asBuyer)));
$svc->unblock($conv, $asSeller);
pin('the seller can', 'ok', $outcome(fn () => $svc->reply($conv, 'Sorry, go on', $asSeller)));
pin('a report needs a reason code', 'invalid:/reason:pattern', $outcome(fn () => $svc->report($conv, 'Bad person!', $asBuyer)));
$log = array();
$svc->report($conv, 'spam', $asBuyer);
pin('report stores state, reporter and reason, and blocks for the reporter', array('reported', (string) $buyer, 'spam', '1', '0'), array_values($admin->query("SELECT s_state, fk_i_reporter_id, s_report_reason, b_buyer_blocked, b_seller_blocked FROM {$p}t_conversation WHERE pk_i_id = $conv")->fetch_row()));
pin('conversation_reported, then conversation_blocked', array('conversation_reported', 'conversation_blocked'), $log);
pin('the reported side cannot report it back', 'conflict:reported', $outcome(fn () => $svc->report($conv, 'harassment', $asSeller)));
pin('the first report stands', array((string) $buyer, 'spam', '0'), array_values($admin->query("SELECT fk_i_reporter_id, s_report_reason, b_seller_blocked FROM {$p}t_conversation WHERE pk_i_id = $conv")->fetch_row()));
pin('a held thread cannot be reported', 'conflict:held', $outcome(fn () => $svc->report($hc, 'spam', $asBuyer)));
pin('a third user cannot block', 'not_found', $outcome(fn () => $svc->block($conv, $asThird)));

harness_section('events on a first message');
$svc = $fresh();
$fresh2 = seed_item($admin, $cat, $seller, 'Desk');
$svc->start($fresh2, 'Is the desk free?', $asThird);
pin('conversation_started, then message_sent, both after commit', array('conversation_started', 'message_sent'), $log);
$log = array();
$svc->start($fresh2, 'Hello?', $asThird);
pin('a later message fires message_sent only', array('message_sent'), $log);

harness_section('two first messages at once');
$racer = seed_user($admin, 'rae', 'rae@example.test');
$tent  = seed_item($admin, $cat, $seller, 'Tent');
$child = __DIR__ . '/../lib/message-start-child.php';
[$waiting, $out] = race(
    $admin,
    "SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = $racer FOR UPDATE",
    $dbName,
    array(array($child, array($racer, $tent)), array($child, array($racer, $tent))),
    "SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = % FOR UPDATE"
);
pin('both children waited on the buyer lock', 2, $waiting);
$printed = $out;
sort($printed);
pin('one started the thread, the other continued it', array("continued\n", "started\n"), $printed);
pin('one thread', 1, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE fk_i_item_id = $tent AND fk_i_buyer_id = $racer"));
pin('two messages', 2, $count("SELECT COUNT(*) FROM {$p}t_message m JOIN {$p}t_conversation c ON c.pk_i_id = m.fk_i_conversation_id WHERE c.fk_i_item_id = $tent"));

harness_section('a thread another connection is still writing');
$rope = seed_item($admin, $cat, $seller, 'Rope');
// The scratch session runs with foreign key checks off, so this insert holds no lock on the
// user rows the child takes first.
$admin->begin_transaction();
$admin->query("INSERT INTO {$p}t_conversation (fk_i_item_id, fk_i_buyer_id, fk_i_seller_id, s_state, dt_created, dt_last_message) VALUES ($rope, $racer, $seller, 'open', NOW(), NOW())");
$kid     = race_run($child, $dbName, array($racer, $rope));
$reading = race_wait_for($dbName, "SELECT * FROM {$p}t_conversation WHERE fk_i_item_id = % FOR UPDATE");
$out     = race_release($admin, array($kid));
pin('the child waited on its locking read, not on an insert', 1, $reading);
pin('the child used the thread written first', "continued\n", $out[0]);
pin('still one thread', 1, $count("SELECT COUNT(*) FROM {$p}t_conversation WHERE fk_i_item_id = $rope"));
pin('its message is in that thread', 1, $count("SELECT COUNT(*) FROM {$p}t_message m JOIN {$p}t_conversation c ON c.pk_i_id = m.fk_i_conversation_id WHERE c.fk_i_item_id = $rope"));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
