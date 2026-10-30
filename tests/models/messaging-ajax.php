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
 * What the messages AJAX actions read from the service: MessagingService::activity() for the
 * poll check and since() for the thread poll, and how the refusals map to statuses.
 *
 * Usage:  php tests/models/messaging-ajax.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-ajax  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\event\DispatcherInterface;
use mindstellar\exception\BlockedException;
use mindstellar\exception\ConflictException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\MessagingService;

$admin = api_admin_boot('osc_models_messaging_ajax');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
$p = DB_TABLE_PREFIX;

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$outcome = static function (callable $fn): string {
    try {
        $fn();

        return 'ok';
    } catch (ForbiddenException $e) {
        return 'forbidden:' . $e->reason();
    } catch (NotFoundException $e) {
        return 'not_found';
    } catch (\Throwable $e) {
        return 'error:' . get_class($e);
    }
};
/** The ETag value the messages_check action sends for this activity. */
$etag = static function (array $activity): string {
    return \mindstellar\messaging\MessagingService::activityTag($activity);
};

$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$third  = seed_user($admin, 'ola', 'ola@example.test');
$cat    = seed_category($admin);
$bike   = seed_item($admin, $cat, $seller, 'Bike');
$lamp   = seed_item($admin, $cat, $seller, 'Lamp');
$asBuyer  = Actor::user($buyer, '10.0.0.1');
$asSeller = Actor::user($seller, '10.0.0.2');
$asThird  = Actor::user($third, '10.0.0.3');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

harness_reset_container();
$c   = Container::root();
$svc = $c->get(MessagingService::class);

harness_section('activity');
pin('nothing yet', array('unread' => 0, 'latest' => null), $svc->activity($seller));
$first = $svc->start($bike, 'Is it for sale?', $asBuyer);
$conv  = $first->conversationId();
$svc->start($bike, 'Hello?', $asBuyer);
$latest = (string) $admin->query("SELECT dt_last_message FROM {$p}t_conversation WHERE pk_i_id = $conv")->fetch_row()[0];
pin('the seller has 2 unread and the thread time', array('unread' => 2, 'latest' => $latest), $svc->activity($seller));
pin('it matches unreadCount', $svc->unreadCount($seller), $svc->activity($seller)['unread']);
pin('the buyer has none but sees the time', array('unread' => 0, 'latest' => $latest), $svc->activity($buyer));
pin('a stranger has nothing', array('unread' => 0, 'latest' => null), $svc->activity($third));
pin('no user, nothing', array('unread' => 0, 'latest' => null), $svc->activity(0));
$before = $etag($svc->activity($seller));
pin('the tag is stable for the same activity', $before, $etag($svc->activity($seller)));
$svc->reply($conv, 'Yes.', $asSeller);
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = DATE_ADD(dt_last_message, INTERVAL 5 SECOND) WHERE pk_i_id = $conv");
pin('the tag changes when a message lands for the buyer', true, $etag($svc->activity($buyer)) !== $etag(array('unread' => 0, 'latest' => $latest)));
pin('and for the seller when the thread time moves', true, $etag($svc->activity($seller)) !== $before);

harness_section('activity and a held thread');
$c->get(DispatcherInterface::class)->listen(MessageSending::class, static function (MessageSending $e): void {
    if ($e->body() === 'hold me') {
        $e->hold('test');
    }
});
$held = $svc->start($lamp, 'hold me', $asBuyer);
pin('the message is held', true, $held->held());
pin('the held message adds no unread for the seller', 2, $svc->activity($seller)['unread']);
$lampLatest = (string) $admin->query("SELECT dt_last_message FROM {$p}t_conversation WHERE pk_i_id = " . $held->conversationId())->fetch_row()[0];
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = '2099-01-01 00:00:00' WHERE pk_i_id = " . $held->conversationId());
pin('a held thread with a later time does not move the seller', true, $svc->activity($seller)['latest'] !== '2099-01-01 00:00:00');
pin('the buyer does see it', '2099-01-01 00:00:00', $svc->activity($buyer)['latest']);
$admin->query("UPDATE {$p}t_conversation SET dt_last_message = '$lampLatest' WHERE pk_i_id = " . $held->conversationId());

harness_section('the sent message');
$reply = $svc->reply($conv, 'Still here?', $asBuyer);
pin('message() is the stored row in the scripts\' shape', \mindstellar\messaging\MessageStore::shape((array) $c->get(\mindstellar\messaging\MessageStore::class)->find($reply->messageId())), $reply->message());
$admin->query("DELETE FROM {$p}t_message WHERE pk_i_id = " . $reply->messageId());
$svc->markRead($conv, $asSeller);

harness_section('since');
$rows = $svc->since($conv, 0, $asBuyer);
pin('after 0 gives the whole short thread, oldest first', array('Is it for sale?', 'Hello?', 'Yes.'), array_column($rows['messages'], 's_body'));
pin('the buyer is now read', 0, $svc->unreadCount($buyer));
$ids = array_map('intval', array_column($rows['messages'], 'pk_i_id'));
pin('after the last id gives nothing', array(), $svc->since($conv, max($ids), $asBuyer)['messages']);
pin('after the first id gives the rest', array_slice($ids, 1), array_map('intval', array_column($svc->since($conv, $ids[0], $asBuyer)['messages'], 'pk_i_id')));
pin('the seller is read by polling', 0, ($svc->since($conv, 0, $asSeller) ? $svc->unreadCount($seller) : -1));
pin('the thread row comes back with its state', 'open', $rows['conversation']['s_state']);
pin('a stranger gets not_found', 'not_found', $outcome(fn () => $svc->since($conv, 0, $asThird)));
pin('an unknown thread gets not_found', 'not_found', $outcome(fn () => $svc->since(999999, 0, $asBuyer)));
pin('a guest must sign in', 'forbidden:sign_in', $outcome(fn () => $svc->since($conv, 0, Actor::guest('10.0.0.4'))));
pin('an admin is not a party', 'not_found', $outcome(fn () => $svc->since($conv, 0, new Actor($third, 1))));
$lampId = $held->conversationId();
pin('the seller cannot poll a held thread', 'not_found', $outcome(fn () => $svc->since($lampId, 0, $asSeller)));
pin('the sender sees their own held message', array('hold me'), array_column($svc->since($lampId, 0, $asBuyer)['messages'], 's_body'));
$set('enabled_messaging', '0');
pin('off, since is refused', 'forbidden:disabled', $outcome(fn () => $svc->since($conv, 0, $asBuyer)));
pin('off, activity is empty', array('unread' => 0, 'latest' => null), $svc->activity($seller));
$set('enabled_messaging', '1');

harness_section('how the controller answers a refusal');
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/AjaxResponse.php';
$ajax    = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
$refusal = new ReflectionMethod('CWebAjax', 'messagingRefusal');
$refusal->setAccessible(true);
$answer = static function (\Throwable $e) use ($ajax, $refusal): array {
    ob_start();
    $refusal->invoke($ajax, $e);
    $body = json_decode((string) ob_get_clean(), true);

    return array($body['error'] ?? null, $body['retry_after'] ?? null);
};
$cases = array(
    array(new ForbiddenException('x', ForbiddenException::SIGN_IN), 'sign_in', null),
    array(new ForbiddenException('x', ForbiddenException::DISABLED), 'disabled', null),
    array(new ForbiddenException('x', 'blocked'), 'blocked', null),
    array(new ForbiddenException('x', ForbiddenException::BANNED), 'banned', null),
    array(new ForbiddenException('x', ForbiddenException::DEMO), 'demo', null),
    array(new NotFoundException('x'), 'not_found', null),
    array(new ConflictException('x', 'closed'), 'closed', null),
    array(new InvalidException('/body', 'minLength', 'x'), 'body', null),
    array(BlockedException::rateLimit('x', 90), 'rate_limited', 90),
);
foreach ($cases as [$e, $code, $retry]) {
    pin(get_class($e) . ' answers ' . $code, array($code, $retry), $answer($e));
}

harness_section('activity with the open thread');
$set('enabled_messaging', '1');
$desk  = seed_item($admin, $cat, $seller, 'Desk');
$deskC = $svc->start($desk, 'Is the desk free?', $asBuyer)->conversationId();
pin('the seller has not read the buyer\'s message', 1, $svc->activity($buyer, $deskC)['their_unread'] ?? null);
$unseen = $etag($svc->activity($buyer, $deskC));
$svc->markRead($deskC, $asSeller);
pin('once the seller reads it, the buyer\'s poll says so', 0, $svc->activity($buyer, $deskC)['their_unread'] ?? null);
pin('and its tag changes', true, $etag($svc->activity($buyer, $deskC)) !== $unseen);
pin('no thread asked: the same keys as before', array('unread', 'latest'), array_keys($svc->activity($buyer)));
pin('a thread the user is not in adds nothing', array('unread', 'latest'), array_keys($svc->activity($third, $deskC)));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
