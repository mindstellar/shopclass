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
 * The typing signal (messages_typing) and the seen note. Typing: POST, CSRF, a signed-in party,
 * live on, an open thread neither side blocked, one note per 3 s, ids and time only, always 204
 * except 404 for a thread the visitor is not in. Seen: markRead also tells the other party.
 *
 * Usage:  php tests/models/messaging-typing.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-typing  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}
ob_start();

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\NotFoundException;
use mindstellar\live\LivePublisher;
use mindstellar\live\NullPublisher;
use mindstellar\messaging\MessagingService;
use mindstellar\utility\Clock;

$admin = api_admin_boot('osc_models_messaging_typing');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/AjaxResponse.php';
$p = DB_TABLE_PREFIX;

/** Records every publish. */
final class MtRecorder implements LivePublisher
{
    /** @var list<array{channel:string, note:array}> */
    public array $sent = [];

    public function __construct(private string $mode = 'nchan')
    {
    }

    public function publish(string $channel, array $note): bool
    {
        $this->sent[] = ['channel' => $channel, 'note' => $note];

        return true;
    }

    public function subscribeUrl(string $channel): ?string
    {
        return $this->mode === 'off' ? null : '/live/sub/' . $channel;
    }

    public function mode(): string
    {
        return $this->mode;
    }
}

/** A clock the test sets. */
final class MtClock implements Clock
{
    public int $t = 3000;

    public function now(): int
    {
        return $this->t;
    }
}

/** The front base controller, stubbed: the action is called directly. */
class BaseModel
{
    protected $ajax = false;

    protected function isXhrRequest(): bool
    {
        return strtolower((string) Params::getServerParam('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php';

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$count = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];
$rates = static fn (): int => $count("SELECT COUNT(*) FROM {$p}t_rate_counter WHERE s_bucket LIKE 'message_typing%'");

function mt_sign_in(?int $userId): void
{
    Session::getInstance()->_drop('userId');
    Session::getInstance()->_dropEphemeral('userId');
    View::getInstance()->_exportVariableToView('_loggedUser', $userId === null ? false : User::getInstance()->findByPrimaryKey($userId));
    osc_is_web_user_logged_in();
}

function mt_csrf(): array
{
    $csrf = new \mindstellar\security\Csrf();

    return array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue());
}

/** One messages_typing call. Returns [status, body, Cache-Control sent]. */
function mt_ajax(string $method, array $post): array
{
    $_GET     = array('page' => 'ajax', 'action' => 'messages_typing');
    $_POST    = $method === 'POST' ? $post : array();
    $_GET    += $method === 'POST' ? array() : $post;
    $_REQUEST = $_GET + $_POST;
    $_SERVER  = array('REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.9') + $_SERVER;
    Params::init();
    http_response_code(200);
    $ajax = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $run  = new ReflectionMethod('CWebAjax', 'messagesTyping');
    $run->setAccessible(true);
    ob_start();
    $run->invoke($ajax);
    $body = (string) ob_get_clean();

    return array((int) http_response_code(), $body);
}

$buyer    = seed_user($admin, 'bea', 'bea@example.test');
$seller   = seed_user($admin, 'sam', 'sam@example.test');
$stranger = seed_user($admin, 'tom', 'tom@example.test');
$cat      = seed_category($admin);
$asBuyer  = Actor::user($buyer, '10.0.0.1');
$asSeller = Actor::user($seller, '10.0.0.2');
$asOther  = Actor::user($stranger, '10.0.0.3');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

/** A fresh container with the recorder and the test clock. */
$fresh = static function (string $mode = 'nchan') use (&$recorder, &$clock): Container {
    harness_reset_container();
    $c        = Container::root();
    $recorder = new MtRecorder($mode);
    $clock    = new MtClock();
    $c->instance(LivePublisher::class, $recorder);
    $c->instance(Clock::class, $clock);

    return $c;
};

$c       = $fresh();
$threads = [];
foreach (['open', 'held', 'reported', 'closed', 'buyer_blocked', 'seller_blocked', 'ajax', 'seen'] as $name) {
    $item           = seed_item($admin, $cat, $seller, 'Item ' . $name);
    $threads[$name] = $c->get(MessagingService::class)->start($item, 'Hello about ' . $name, $asBuyer)->conversationId();
}
$admin->query("UPDATE {$p}t_conversation SET s_state = 'held' WHERE pk_i_id = " . $threads['held']);
$admin->query("UPDATE {$p}t_conversation SET s_state = 'reported' WHERE pk_i_id = " . $threads['reported']);
$admin->query("UPDATE {$p}t_conversation SET s_state = 'closed' WHERE pk_i_id = " . $threads['closed']);
$admin->query("UPDATE {$p}t_conversation SET b_buyer_blocked = 1 WHERE pk_i_id = " . $threads['buyer_blocked']);
$admin->query("UPDATE {$p}t_conversation SET b_seller_blocked = 1 WHERE pk_i_id = " . $threads['seller_blocked']);
scratchdb_forget_cache();

harness_section('live off');
$c   = $fresh('off');
$svc = $c->get(MessagingService::class);
$got = null;
pin('no query at all', 0, harness_query_count(static function () use ($svc, $threads, $asBuyer, &$got): void {
    $got = $svc->typing($threads['open'], $asBuyer);
}));
pin('nothing sent', [false, 0], [$got, count($recorder->sent)]);
pin('no rate count written', 0, $rates());
harness_reset_container();
pin('the real NullPublisher is off too', true, Container::root()->get(LivePublisher::class) instanceof NullPublisher);
pin('and sends nothing', false, Container::root()->get(MessagingService::class)->typing($threads['open'], $asBuyer));
pin('still no rate count', 0, $rates());

harness_section('an open thread');
$c   = $fresh();
$svc = $c->get(MessagingService::class);
pin('the buyer types: true', true, $svc->typing($threads['open'], $asBuyer));
pin('one note, to the seller, ids and time only', [['channel' => 'user-' . $seller, 'note' => ['kind' => 'typing', 'conversation' => $threads['open'], 'at' => 3000]]], $recorder->sent);
$clock->t = 3002;
pin('again within 3 s: false', false, $svc->typing($threads['open'], $asBuyer));
pin('and no second note', 1, count($recorder->sent));
$clock->t = 3003;
pin('after 3 s: true', true, $svc->typing($threads['open'], $asBuyer));
pin('one more note', 2, count($recorder->sent));
pin('the seller types: the note goes to the buyer', true, $svc->typing($threads['open'], $asSeller));
pin('on the buyer\'s channel', 'user-' . $buyer, $recorder->sent[2]['channel'] ?? '');
pin('no message text or names in any note', false, (bool) preg_match('/Hello|bea|sam/', (string) json_encode($recorder->sent)));

harness_section('threads that take no typing');
$recorder->sent = [];
$before         = $rates();
foreach (['held', 'reported', 'closed', 'buyer_blocked', 'seller_blocked'] as $name) {
    pin("$name: false for the buyer", false, $svc->typing($threads[$name], $asBuyer));
}
foreach (['reported', 'closed', 'buyer_blocked', 'seller_blocked'] as $name) {
    pin("$name: false for the seller", false, $svc->typing($threads[$name], $asSeller));
}
pin('no note for any of them', 0, count($recorder->sent));
pin('and no rate count', $before, $rates());
$refused = static function (callable $call): string {
    try {
        $call();

        return 'allowed';
    } catch (NotFoundException) {
        return 'not found';
    } catch (ForbiddenException) {
        return 'forbidden';
    }
};
pin('a held thread is not found for the seller', 'not found', $refused(static fn () => $svc->typing($threads['held'], $asSeller)));
pin('another user\'s thread is not found', 'not found', $refused(static fn () => $svc->typing($threads['open'], $asOther)));
pin('a missing thread is not found', 'not found', $refused(static fn () => $svc->typing(999999, $asBuyer)));
pin('an admin cannot type', 'forbidden', $refused(static fn () => $svc->typing($threads['open'], Actor::admin(1, '10.0.0.9'))));
pin('still no note', 0, count($recorder->sent));

harness_section('the ajax action');
$c = $fresh();
mt_sign_in($buyer);
pin('GET is 405', 405, mt_ajax('GET', ['id' => $threads['ajax']] + mt_csrf())[0]);
pin('and sends nothing', 0, count($recorder->sent));
mt_sign_in(null);
pin('a guest is 401', 401, mt_ajax('POST', ['id' => $threads['ajax']] + mt_csrf())[0]);
mt_sign_in($stranger);
pin('another user\'s thread is 404', 404, mt_ajax('POST', ['id' => $threads['ajax']] + mt_csrf())[0]);
mt_sign_in($buyer);
pin('a closed thread is 204, empty', [204, ''], mt_ajax('POST', ['id' => $threads['closed']] + mt_csrf()));
pin('the open thread is 204, empty', [204, ''], mt_ajax('POST', ['id' => $threads['ajax']] + mt_csrf()));
pin('one note, to the seller', ['user-' . $seller], array_column($recorder->sent, 'channel'));
pin('limited is 204 too', [204, ''], mt_ajax('POST', ['id' => $threads['ajax']] + mt_csrf()));
pin('no second note', 1, count($recorder->sent));
$c = $fresh('off');
pin('live off is 204', [204, ''], mt_ajax('POST', ['id' => $threads['ajax']] + mt_csrf()));
pin('no note', 0, count($recorder->sent));
$set('enabled_messaging', '0');
pin('messaging off is 403', 403, mt_ajax('POST', ['id' => $threads['ajax']] + mt_csrf())[0]);
$set('enabled_messaging', '1');

harness_section('seen');
$c   = $fresh();
$svc = $c->get(MessagingService::class);
$recorder->sent = [];
pin('the seller reads the thread', 1, $svc->markRead($threads['seen'], $asSeller));
pin('read to the reader, seen to the buyer', [
    ['channel' => 'user-' . $seller, 'note' => ['kind' => 'read', 'conversation' => $threads['seen']]],
    ['channel' => 'user-' . $buyer, 'note' => ['kind' => 'seen', 'conversation' => $threads['seen']]],
], $recorder->sent);
$svc->reply($threads['seen'], 'Thanks', $asSeller);
$recorder->sent = [];
$svc->markRead($threads['seen'], $asBuyer);
pin('the buyer reads: seen goes to the seller', ['user-' . $buyer, 'user-' . $seller], array_column($recorder->sent, 'channel'));
pin('the seen note is the second', 'seen', $recorder->sent[1]['note']['kind'] ?? '');
$c   = $fresh('off');
$svc = $c->get(MessagingService::class);
$svc->reply($threads['seen'], 'Again', $asSeller);
$recorder->sent = [];
$svc->markRead($threads['seen'], $asBuyer);
pin('live off: no seen note', [], array_values(array_filter(array_column(array_column($recorder->sent, 'note'), 'kind'), static fn ($k) => $k === 'seen')));

harness_section('no CSRF token');
$c = $fresh();
mt_sign_in($buyer);
$clock->t = 9000;
defined('IS_AJAX') || define('IS_AJAX', true);
register_shutdown_function(static function () use (&$recorder): void {
    $out = (string) ob_get_clean();
    pin('refused with the CSRF error', true, str_contains($out, '"error":1'));
    pin('and no note', 0, count($recorder->sent));
    exit(harness_result());
});
mt_ajax('POST', ['id' => $threads['ajax']]);
pin('the call returned instead of stopping', true, false);
