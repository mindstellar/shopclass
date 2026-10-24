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
 * MessageModeration through the container: release and remove held messages, close and clear
 * reported threads, the review lists and counts, and the demo refusal.
 *
 * Usage:  php tests/models/messaging-moderation.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-moderation  (as part of the suite)
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
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\NotFoundException;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\MessagingService;
use mindstellar\moderation\MessageModeration;

$admin = api_admin_boot('osc_models_messaging_moderation');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;

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
    } catch (\Throwable $e) {
        return 'error:' . get_class($e) . ': ' . $e->getMessage();
    }
};
$ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'pk_i_id'));

$buyer   = seed_user($admin, 'bea', 'bea@example.test');
$seller  = seed_user($admin, 'sam', 'sam@example.test');
$third   = seed_user($admin, 'ola', 'ola@example.test');
$cat     = seed_category($admin);
$bike    = seed_item($admin, $cat, $seller, 'Bike');
$lamp    = seed_item($admin, $cat, $seller, 'Lamp');
$desk    = seed_item($admin, $cat, $seller, 'Desk');
$asBuyer  = Actor::user($buyer, '10.0.0.1');
$asSeller = Actor::user($seller, '10.0.0.2');
$asThird  = Actor::user($third, '10.0.0.3');
$asAdmin  = Actor::admin(1, '10.0.0.9');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

harness_reset_container();
$c   = Container::root();
$svc = $c->get(MessagingService::class);
$mod = $c->get(MessageModeration::class);
$log = array();
osc_add_hook('conversation_started', static function () use (&$log): void {
    $log[] = 'conversation_started' . (Db::inTransaction() ? ' (in transaction)' : '');
});
osc_add_hook('message_sent', static function () use (&$log): void {
    $log[] = 'message_sent' . (Db::inTransaction() ? ' (in transaction)' : '');
});
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};
$held = static function (int $item, string $body, Actor $actor) use ($c, $svc, $hold) {
    $events = $c->get(DispatcherInterface::class);
    $events->listen(MessageSending::class, $hold);
    try {
        return $svc->start($item, $body, $actor);
    } finally {
        $events->remove(MessageSending::class, $hold);
    }
};
$state = static fn (string $table, int $id): string => (string) $col("SELECT s_state FROM {$p}t_$table WHERE pk_i_id = $id");

harness_section('release');
$first = $held($bike, 'Cheap bike?', $asBuyer);
$conv  = $first->conversationId();
pin('the thread and message wait for review', array('held', 'held'), array($state('conversation', $conv), $state('message', $first->messageId())));
pin('counts() sees the held message', array('held' => 1, 'reported' => 0), $mod->counts());
$log  = array();
$jobs = $count("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.notify'");
$mod->release($first->messageId(), $asAdmin);
pin('the message is sent and the thread open', array('sent', 'open'), array($state('message', $first->messageId()), $state('conversation', $conv)));
pin('the seller has it unread', 1, $svc->unreadCount($seller));
pin('conversation_started, then message_sent, both after commit', array('conversation_started', 'message_sent'), $log);
pin('the new-message mail is queued', $jobs + 1, $count("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.notify'"));
pin("the thread is in the seller's inbox", array($conv), $ids($svc->inbox($asSeller)));
pin('release again', 'conflict:not_held', $outcome(fn () => $mod->release($first->messageId(), $asAdmin)));
pin('no such message', 'not_found', $outcome(fn () => $mod->release(999999, $asAdmin)));
pin('a user cannot release', 'forbidden:not_owner', $outcome(fn () => $mod->release($first->messageId(), $asSeller)));
$later = $held($bike, 'And a lock?', $asBuyer);
$log   = array();
$mod->release($later->messageId(), $asAdmin);
pin('a held message in an open thread fires message_sent only', array('message_sent'), $log);
pin('and counts for the seller', 2, $svc->unreadCount($seller));

harness_section('remove');
$lone = $held($lamp, 'Pills', $asBuyer);
$lc   = $lone->conversationId();
$log  = array();
pin('a user cannot remove', 'forbidden:not_owner', $outcome(fn () => $mod->remove($lone->messageId(), $asBuyer)));
$mod->remove($lone->messageId(), $asAdmin);
pin('the message is removed', 'removed', $state('message', $lone->messageId()));
pin('a lone held thread is closed', 'closed', $state('conversation', $lc));
pin('nothing fired', array(), $log);
pin("the seller's inbox still lacks it", array($conv), $ids($svc->inbox($asSeller)));
pin('remove again', 'conflict:not_held', $outcome(fn () => $mod->remove($lone->messageId(), $asAdmin)));
$a  = $held($desk, 'One', $asThird);
$b  = $held($desk, 'Two', $asThird);
$mod->remove($a->messageId(), $asAdmin);
pin('a held thread with another held message stays held', 'held', $state('conversation', $a->conversationId()));

harness_section('held() and reported() lists');
$rows = $mod->held($asAdmin);
pin('held() lists the waiting message', array($b->messageId()), $ids($rows));
pin('with its thread and both names', array($a->conversationId(), 'ola', 'ola@example.test', $seller, 'sam'), array((int) $rows[0]['conversation']['pk_i_id'], $rows[0]['sender_name'], $rows[0]['sender_email'], $rows[0]['recipient_id'], $rows[0]['recipient_name']));
$c2 = $held($lamp, 'Again', $asThird);
pin('held() pages by before', array($b->messageId()), $ids($mod->held($asAdmin, 1, $c2->messageId())));
pin('held() limit', array($c2->messageId()), $ids($mod->held($asAdmin, 1)));
$svc->report($conv, 'spam', $asBuyer);
$rows = $mod->reported($asAdmin);
pin('reported() lists the thread', array($conv), $ids($rows));
pin('with buyer, seller and reporter names', array('bea', 'sam', 'bea'), array($rows[0]['buyer_name'], $rows[0]['seller_name'], $rows[0]['reporter_name']));
pin('reported() pages by before', array(), $mod->reported($asAdmin, 5, $conv));
pin('counts()', array('held' => 2, 'reported' => 1), $mod->counts());

harness_section('conversations() and message()');
$one = $mod->message($b->messageId(), $asAdmin);
pin('message() has the row, its thread and the names', array($b->messageId(), $a->conversationId(), 'ola', 'sam', $seller), array((int) $one['pk_i_id'], (int) $one['conversation']['pk_i_id'], $one['sender_name'], $one['recipient_name'], $one['recipient_id']));
pin('message() of nothing', null, $mod->message(999999, $asAdmin));
pin('a user cannot read a message', 'forbidden:not_owner', $outcome(fn () => $mod->message($b->messageId(), $asBuyer)));
$rows = $mod->conversations(array('status' => 'reported'), 20, null, $asAdmin);
pin('conversations() filters by status', array($conv), $ids($rows));
pin('and carries both names and the reporter', array('bea', 'sam', 'bea'), array($rows[0]['buyer_name'], $rows[0]['seller_name'], $rows[0]['reporter_name']));
pin('filters by user, newest first', array($c2->conversationId(), $a->conversationId()), array_slice($ids($mod->conversations(array('user' => array($third)), 20, null, $asAdmin)), 0, 2));
pin('and pages on', array($a->conversationId()), $ids($mod->conversations(array('user' => array($third), 'listing' => array($desk)), 20, $conv + 100, $asAdmin)));
pin('before skips the newer ones', array(), $ids($mod->conversations(array('status' => 'reported'), 20, $conv, $asAdmin)));
pin('a user cannot list threads', 'forbidden:not_owner', $outcome(fn () => $mod->conversations(array(), 20, null, $asBuyer)));

harness_section('thread()');
$view = $mod->thread($a->conversationId(), $asAdmin);
pin('the admin view shows held messages, oldest first, not removed ones', array($b->messageId()), $ids($view['messages']));
pin('and the parties by id, buyer first', array($third => 'ola', $seller => 'sam'), $view['names']);
pin('thread() of nothing', 'not_found', $outcome(fn () => $mod->thread(999999, $asAdmin)));
pin('a user cannot read the admin lists or a thread', array_fill(0, 3, 'forbidden:not_owner'), array($outcome(fn () => $mod->held($asBuyer)), $outcome(fn () => $mod->reported($asBuyer)), $outcome(fn () => $mod->thread($a->conversationId(), $asBuyer))));

harness_section('close');
pin('a thread that is not reported', 'conflict:not_reported', $outcome(fn () => $mod->close($a->conversationId(), $asAdmin)));
pin('a user cannot close', 'forbidden:not_owner', $outcome(fn () => $mod->close($conv, $asBuyer)));
$mod->close($conv, $asAdmin);
pin('the thread is closed', 'closed', $state('conversation', $conv));
pin('the buyer cannot reply', 'conflict:closed', $outcome(fn () => $svc->reply($conv, 'Hello?', $asBuyer)));
pin('nor the seller', 'conflict:closed', $outcome(fn () => $svc->reply($conv, 'Hello?', $asSeller)));
pin('both still read it', array(true, true), array(count($svc->thread($conv, $asBuyer)['messages']) > 0, count($svc->thread($conv, $asSeller)['messages']) > 0));
pin('close again', 'conflict:not_reported', $outcome(fn () => $mod->close($conv, $asAdmin)));
pin('clear a closed thread', 'conflict:not_reported', $outcome(fn () => $mod->clearReport($conv, $asAdmin)));

harness_section('clearReport');
$sid   = seed_user($admin, 'sid', 'sid@example.test');
$asSid = Actor::user($sid, '10.0.0.4');
$vase  = seed_item($admin, $cat, $sid, 'Vase');
$open  = $svc->start($vase, 'Vase still free?', $asBuyer)->conversationId();
$svc->report($open, 'scam', $asBuyer);
pin('a user cannot clear', 'forbidden:not_owner', $outcome(fn () => $mod->clearReport($open, $asSid)));
$mod->clearReport($open, $asAdmin);
pin('state open, reporter and reason gone, the block stays', array('open', null, null, '1'), array_values($admin->query("SELECT s_state, fk_i_reporter_id, s_report_reason, b_buyer_blocked FROM {$p}t_conversation WHERE pk_i_id = $open")->fetch_row()));
pin('the reporter can unblock', 'ok', $outcome(fn () => $svc->unblock($open, $asBuyer)));
pin('and write again', 'ok', $outcome(fn () => $svc->reply($open, 'Back again', $asBuyer)));

harness_section('a demo site');
$svc->report($open, 'spam', $asSid);
$snapshot = static fn (): array => array(
    $admin->query("SELECT GROUP_CONCAT(pk_i_id, s_state ORDER BY pk_i_id) FROM {$p}t_message")->fetch_row()[0],
    $admin->query("SELECT GROUP_CONCAT(pk_i_id, s_state, i_seller_unread, i_buyer_unread ORDER BY pk_i_id) FROM {$p}t_conversation")->fetch_row()[0],
);
$before = $snapshot();
define('DEMO', true);
pin('release is refused', 'forbidden:demo', $outcome(fn () => $mod->release($b->messageId(), $asAdmin)));
pin('remove is refused', 'forbidden:demo', $outcome(fn () => $mod->remove($b->messageId(), $asAdmin)));
pin('close is refused', 'forbidden:demo', $outcome(fn () => $mod->close($open, $asAdmin)));
pin('clearReport is refused', 'forbidden:demo', $outcome(fn () => $mod->clearReport($open, $asAdmin)));
pin('no row changed', $before, $snapshot());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
