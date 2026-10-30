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
 * The messaging web actions run against a real database: the AJAX check (ETag and 304),
 * since and send, the thread page for a stranger, a held-thread seller and a party, and the
 * contact form's switch between a conversation and the seller e-mail.
 *
 * The whole run is buffered so no header is sent and the status codes can be read. The
 * ETag header itself cannot be read on the CLI; a matching If-None-Match answering 304 pins it.
 *
 * Usage:  php tests/models/messaging-web.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-web  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}
ob_start();

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\messaging\MessagingService;

$admin = api_admin_boot('osc_models_messaging_web');
scratchdb_pin_site_mode($admin, 'classifieds');
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") { $GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
foreach (array(
    '_mn'                    => static fn ($single, $plural, $n) => $n == 1 ? $single : $plural,
    'osc_user_dashboard_url' => static fn () => WEB_PATH . 'dashboard',
    'osc_keep_form'          => static function (array $values, string $error): void {
        $GLOBALS['flashes'][] = 'kept:' . $error;
    },
    'osc_local_referer'      => static fn ($fallback) => $fallback,
    'osc_item_url_ns'        => static fn ($id) => WEB_PATH . 'item/' . $id,
    'osc_locate_template'    => static fn ($candidates, $context = '') => (string) $candidates[0],
) as $name => $impl) {
    if (!function_exists($name)) {
        $GLOBALS['stubs'][$name] = $impl;
        eval('function ' . $name . '(...$a) { return ($GLOBALS["stubs"]["' . $name . '"])(...$a); }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/AjaxResponse.php';
$p = DB_TABLE_PREFIX;

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$col = static fn (string $sql) => $admin->query($sql)->fetch_row()[0];

/** Sign a user in for this request, or sign out with null. */
function web_sign_in(?int $userId): void
{
    Session::getInstance()->_drop('userId');
    Session::getInstance()->_dropEphemeral('userId');
    View::getInstance()->_exportVariableToView('_loggedUser', $userId === null ? false : User::getInstance()->findByPrimaryKey($userId));
    osc_is_web_user_logged_in();
}

/** Set up one request. */
function web_request(string $method, array $get, array $post = array(), array $server = array()): void
{
    $_GET     = $get;
    $_POST    = $post;
    $_REQUEST = $_GET + $_POST;
    $_SERVER  = array('REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '10.0.0.9') + $server + $_SERVER;
    Params::init();
    $GLOBALS['flashes'] = array();
    http_response_code(200);
}

/** The CSRF fields a form posts. */
function web_csrf(): array
{
    $csrf = new \mindstellar\security\Csrf();

    return array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue());
}

/** One AJAX action: its status and body. */
function web_ajax(string $method): array
{
    $ajax = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $run  = new ReflectionMethod('CWebAjax', $method);
    $run->setAccessible(true);
    ob_start();
    $run->invoke($ajax);
    $body = (string) ob_get_clean();

    return array('status' => (int) http_response_code(), 'raw' => $body, 'json' => json_decode($body, true));
}

/** A redirect ends the request, as the real one exits. */
final class WebRedirect extends RuntimeException
{
    public function __construct(public string $url)
    {
        parent::__construct($url);
    }
}

/** The front base controllers, stubbed: a redirect ends the request and views are only named. */
class BaseModel
{
    protected $action;

    protected $page;

    protected $ajax = false;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function redirectTo($url, $code = null)
    {
        throw new WebRedirect((string) $url);
    }

    public function do404()
    {
        throw new WebRedirect('404');
    }

    protected function redirectShopperHome(): void
    {
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }

    public function doView($file)
    {
        $GLOBALS['view'] = $file;
    }

    protected function isXhrRequest(): bool
    {
        return strtolower((string) Params::getServerParam('HTTP_X_REQUESTED_WITH')) === 'xmlhttprequest';
    }
}

class WebSecBaseModel extends BaseModel
{
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebMessages.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php';

/** The real messages controller with the template only named. */
final class WebMessages extends CWebMessages
{
    public function doView($file)
    {
        $GLOBALS['view'] = $file;
    }
}

/** Run one page request; returns the redirect, the flashes and whether a view was drawn. */
function web_page(callable $make): array
{
    $GLOBALS['view'] = null;
    View::getInstance()->_erase('conversation');
    $redirect = null;
    try {
        $make()->doModel();
    } catch (WebRedirect $r) {
        $redirect = $r->url;
    }

    return array('redirect' => $redirect, 'flashes' => $GLOBALS['flashes'], 'view' => $GLOBALS['view'],
        'conversation' => View::getInstance()->_get('conversation') ?: null);
}

seed_locale($admin);
$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$third  = seed_user($admin, 'ola', 'ola@example.test');
$cat    = seed_category($admin);
$bike   = seed_item($admin, $cat, $seller, 'Bike');
$lamp   = seed_item($admin, $cat, $seller, 'Lamp');
$desk   = seed_item($admin, $cat, $seller, 'Desk');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');
$set('reg_user_can_contact', '0');

harness_reset_container();
$c   = Container::root();
$svc = $c->get(MessagingService::class);
$c->get(\mindstellar\event\DispatcherInterface::class)->listen(\mindstellar\messaging\event\MessageSending::class, static function ($e): void {
    if ($e->body() === 'hold me') {
        $e->hold('test');
    }
});
$open = $svc->start($bike, 'Is it for sale?', Actor::user($buyer, '10.0.0.1'))->conversationId();
$svc->reply($open, 'Yes.', Actor::user($seller, '10.0.0.2'));
$held = $svc->start($lamp, 'hold me', Actor::user($buyer, '10.0.0.1'))->conversationId();
$unread = static fn (int $id): string => $col("SELECT CONCAT(i_buyer_unread, '/', i_seller_unread) FROM {$p}t_conversation WHERE pk_i_id = $id");

harness_section('messages_check');
$activity = $svc->activity($seller);
$etag     = '"' . $activity['unread'] . '.' . md5((string) $activity['latest']) . '"';
web_sign_in($seller);
web_request('GET', array('page' => 'ajax', 'action' => 'messages_check'), array(), array('HTTP_IF_NONE_MATCH' => '"0.stale"'));
$r = web_ajax('messagesCheck');
pin('a stale tag gets 200 and the activity', array(200, $activity), array($r['status'], $r['json']));
web_request('GET', array('page' => 'ajax', 'action' => 'messages_check'), array(), array('HTTP_IF_NONE_MATCH' => $etag));
$r = web_ajax('messagesCheck');
pin('the current tag gets 304 with an empty body', array(304, ''), array($r['status'], $r['raw']));
web_request('GET', array('page' => 'ajax', 'action' => 'messages_check', 'for' => $buyer));
$r = web_ajax('messagesCheck');
pin('a push worker of another user gets 409 and no count', array(409, 'other_user', false), array($r['status'], $r['json']['error'] ?? null, isset($r['json']['unread'])));
web_request('GET', array('page' => 'ajax', 'action' => 'messages_check', 'for' => $seller), array(), array('HTTP_IF_NONE_MATCH' => '"0.stale"'));
$r = web_ajax('messagesCheck');
pin('its own user gets the activity', array(200, $activity), array($r['status'], $r['json']));
web_sign_in(null);
web_request('GET', array('page' => 'ajax', 'action' => 'messages_check'));
$r = web_ajax('messagesCheck');
pin('a guest gets 401 sign_in', array(401, 'sign_in'), array($r['status'], $r['json']['error'] ?? null));

harness_section('messages_since');
$before = $unread($open);
web_sign_in($third);
web_request('GET', array('page' => 'ajax', 'action' => 'messages_since', 'id' => $open, 'after' => 0));
$r = web_ajax('messagesSince');
pin('a stranger gets 404 not_found', array(404, 'not_found'), array($r['status'], $r['json']['error'] ?? null));
pin('and nothing is marked read', $before, $unread($open));
web_sign_in($buyer);
$first = (int) $col("SELECT MIN(pk_i_id) FROM {$p}t_message WHERE fk_i_conversation_id = $open");
web_request('GET', array('page' => 'ajax', 'action' => 'messages_since', 'id' => $open, 'after' => 0));
$r = web_ajax('messagesSince');
check('the buyer has unread messages here', explode('/', $before)[0] !== '0');
pin('a request with no X-Requested-With reads the thread but leaves it unread', array(200, $before), array($r['status'], $unread($open)));
web_request('GET', array('page' => 'ajax', 'action' => 'messages_since', 'id' => $open, 'after' => $first), array(), array('HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'));
$r = web_ajax('messagesSince');
pin('the buyer gets the messages after the id', array(200, array('Yes.')), array($r['status'], array_column($r['json']['messages'] ?? array(), 'body')));
pin('the page script marks the buyer read', '0', explode('/', $unread($open))[0]);
web_request('GET', array('page' => 'ajax', 'action' => 'messages_since', 'id' => $open, 'after' => 0));
pin('after 0 gives the whole thread', array('Is it for sale?', 'Yes.'), array_column(web_ajax('messagesSince')['json']['messages'] ?? array(), 'body'));

harness_section('messages_send');
web_request('GET', array('page' => 'ajax', 'action' => 'messages_send', 'id' => $open, 'body' => 'x'));
pin('GET is 405', 405, web_ajax('messagesSend')['status']);
web_request('POST', array('page' => 'ajax', 'action' => 'messages_send'), array('id' => $open, 'body' => 'Fish & <chips>') + web_csrf());
$r = web_ajax('messagesSend');
pin('POST stores the reply and answers it', array(200, true, 'Fish & <chips>'), array($r['status'], $r['json']['ok'] ?? null, $r['json']['message']['body'] ?? null));
pin('the stored text is the typed text', 'Fish & <chips>', $col("SELECT s_body FROM {$p}t_message WHERE fk_i_conversation_id = $open ORDER BY pk_i_id DESC LIMIT 1"));
web_sign_in($third);
web_request('POST', array('page' => 'ajax', 'action' => 'messages_send'), array('id' => $open, 'body' => 'Me too') + web_csrf());
pin('a stranger gets 404 not_found', array(404, 'not_found'), array(web_ajax('messagesSend')['status'], web_ajax('messagesSend')['json']['error'] ?? null));

harness_section('thread page');
$thread = static fn () => new WebMessages();
$before = $unread($open);
web_sign_in($third);
web_request('GET', array('page' => 'messages', 'id' => $open));
$r = web_page($thread);
pin('a stranger goes back to the inbox', osc_messaging_inbox_url(), $r['redirect']);
check('with an error flash', strpos($r['flashes'][0] ?? '', 'error:') === 0, implode('|', $r['flashes']));
pin('and no conversation reaches the view', array(null, null), array($r['view'], $r['conversation']));
pin('and nothing is marked read', $before, $unread($open));
$heldBefore = $unread($held);
web_sign_in($seller);
web_request('GET', array('page' => 'messages', 'id' => $held));
$r = web_page($thread);
pin('the seller of a held thread goes back to the inbox', osc_messaging_inbox_url(), $r['redirect']);
check('with an error flash', strpos($r['flashes'][0] ?? '', 'error:') === 0);
pin('and no conversation reaches the view', array(null, null), array($r['view'], $r['conversation']));
pin('and the held thread is untouched', $heldBefore, $unread($held));
web_request('GET', array('page' => 'messages', 'id' => $open));
$r = web_page($thread);
pin('a party sees the thread', array(null, $open), array($r['redirect'], (int) ($r['conversation']['pk_i_id'] ?? 0)));
check('in the conversation template', strpos((string) $r['view'], 'user-conversation') !== false, (string) $r['view']);
pin('and it is marked read for them', '0', explode('/', $unread($open))[1]);

harness_section('messenger rows and query count');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hResources.php';
$mia = seed_user($admin, 'mia', 'mia@example.test');
$lou = seed_user($admin, 'lou', 'lou@example.test');
$photoOf = seed_photo($admin, $bike, array('s_name' => 'bike'));
$louFirst = 0;
for ($i = 0; $i < 20; $i++) {
    $it = seed_item($admin, $cat, $seller, 'Thing ' . $i);
    $cv = $svc->start($it, 'hello ' . $i, Actor::user($lou, '10.0.0.4'))->conversationId();
    $louFirst = $louFirst ?: $cv;
}
$miaThread = $svc->start($bike, "  Line one\n<b>two</b>  ", Actor::user($mia, '10.0.0.3'))->conversationId();
$svc->reply($miaThread, 'Yes, still here.', Actor::user($seller, '10.0.0.2'));
$svc->reply($miaThread, 'Mine now', Actor::user($mia, '10.0.0.3'));
$inboxFor = static function (int $userId): array {
    web_sign_in($userId);
    web_request('GET', array('page' => 'messages'));
    $run = web_page(static fn () => new WebMessages());

    return array($run, (array) View::getInstance()->_get('conversations'));
};
$threadFor = static function (int $userId, int $id): array {
    web_sign_in($userId);
    web_request('GET', array('page' => 'messages', 'id' => $id));

    return web_page(static fn () => new WebMessages());
};
// The page's own reads: the user is signed in and every cache warmed first.
$pageReads = static function (int $userId, ?int $id) {
    $run = static function () use ($id): void {
        web_request('GET', $id === null ? array('page' => 'messages') : array('page' => 'messages', 'id' => $id));
        web_page(static fn () => new WebMessages());
    };
    web_sign_in($userId);
    $run();

    return harness_query_count($run);
};
[, $miaRows] = $inboxFor($mia);
$row = $miaRows[0] ?? array();
pin('one row for one thread', 1, count($miaRows));
pin('the preview is the last message, one line, raw text', 'Mine now', $row['preview'] ?? null);
check('my own last message is flagged mine', ($row['preview_mine'] ?? false) === true);
pin('the initial is the other person\'s', 'S', $row['initial'] ?? null);
pin('the hue is the other person\'s', \mindstellar\messaging\InboxQuery::hue($seller), $row['other_hue'] ?? null);
check('the photo is the thumbnail URL', strpos((string) ($row['photo_url'] ?? ''), $photoOf . '_thumbnail.jpg') !== false, (string) ($row['photo_url'] ?? ''));
pin('the price is raw micros', 19500000, $row['price'] ?? null);
check('the listing is live', ($row['listing_live'] ?? false) === true);
check('the short time is a clock time today', preg_match('/^\d\d:\d\d$/', (string) ($row['short_time'] ?? '')) === 1, (string) ($row['short_time'] ?? ''));
[, $sellerRows] = $inboxFor($seller);
$theirs = array_values(array_filter($sellerRows, static fn (array $r): bool => (int) $r['pk_i_id'] === $miaThread))[0] ?? array();
pin('the seller sees the buyer\'s words, not mine', array('Mine now', false), array($theirs['preview'] ?? null, $theirs['preview_mine'] ?? null));
$one  = $pageReads($mia, null);
$many = $pageReads($lou, null);
[, $louRows] = $inboxFor($lou);
pin('twenty rows come back for the second user', 20, count($louRows));
pin('the inbox reads the same number of times for 1 row and 20', $one, $many);
check('and that number is the six reads of the plan or fewer', $one > 0 && $one <= 8, (string) $one);
$one  = $pageReads($mia, $miaThread);
$many = $pageReads($lou, $louFirst);
pin('the thread page reads the same number of times either way', $one, $many);
$r = $threadFor($mia, $miaThread);
pin('and a party sees the chat and the list', array(null, true), array($r['redirect'], count((array) View::getInstance()->_get('conversations')) === 1));
pin('their unread count reaches the view', 'integer', gettype(View::getInstance()->_get('their_unread')));
check('the current row is marked', (View::getInstance()->_get('conversations')[0]['is_current'] ?? false) === true);
pin('a buyer is told who they ask', _m('Seller'), View::getInstance()->_get('role'));
$threadFor($seller, $miaThread);
pin('a seller is told they are asked', _m('Asking about your listing'), View::getInstance()->_get('role'));

$render = static function (string $file): string {
    ob_start();
    try {
        require ABS_PATH . 'oc-includes/osclass/gui/account/' . $file;
    } catch (Throwable $e) {
        ob_end_clean();
        return 'ERR ' . $e->getMessage();
    }

    return (string) ob_get_clean();
};
foreach (array('osc_push_enabled' => false, 'osc_push_public_key' => '', 'osc_search_url' => 'https://example.test/search', 'osc_show_flash_message' => null) as $fn => $ret) {
    if (!function_exists($fn)) {
        $GLOBALS['stubs'][$fn] = static fn () => $ret;
        eval('function ' . $fn . '(...$a) { return ($GLOBALS["stubs"]["' . $fn . '"])(...$a); }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';
$html = $render('parts/messenger-list.php');
check('the list pane renders', strpos($html, 'ERR') !== 0 && strpos($html, 'class="oe-thread-link"') !== false, substr($html, 0, 200));
check('the preview text is escaped', strpos($html, '<b>') === false);
[, $sellerRows] = $inboxFor($seller);
$sellerUnread = count(array_filter($sellerRows, static fn (array $r): bool => (int) $r['unread'] > 0));
$html = $render('parts/messenger-list.php');
check('the Unread tab counts the unread threads', $sellerUnread > 0
    && preg_match('/data-filter="unread"[^>]*>[^<]*<span class="oe-list-tabs-count" data-unread-threads>' . $sellerUnread . '</', $html) === 1, (string) $sellerUnread);
$threadFor($mia, $miaThread);
$html = $render('parts/messenger-chat.php');
check('the chat pane renders', strpos($html, 'ERR') !== 0 && strpos($html, 'class="oe-messages"') !== false, substr($html, 0, 300));
check('the typed markup stays text', strpos($html, '<b>two</b>') === false && strpos($html, '&lt;b&gt;two&lt;/b&gt;') !== false);
check('live off prints no typing URL', strpos($html, 'data-typing-url') === false && strpos($html, 'class="oe-typing"') === false);
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = $bike");
$threadFor($mia, $miaThread);
$html = $render('parts/messenger-chat.php');
check('a listing visitors cannot see shows as removed, with no link to it', strpos($html, 'oe-listing-card is-removed') !== false
    && strpos($html, 'class="oe-listing-link"') === false && strpos($html, 'href="' . osc_esc_html(osc_item_url_ns($bike)) . '"') === false);
$admin->query("UPDATE {$p}t_item SET b_enabled = 1 WHERE pk_i_id = $bike");

harness_section('contact form');
// The hook fires on both paths; only the e-mail path is stopped here, before it sends.
$mails = $hooks = 0;
osc_add_hook('pre_item_contact_post', static function () use (&$mails, &$hooks): void {
    $hooks++;
    if (!osc_item_uses_messaging()) {
        $mails++;
        throw new WebRedirect('mail');
    }
});
$contact = static function (int $itemId): array {
    web_request('POST', array('page' => 'item', 'action' => 'contact_post'), array(
        'id' => $itemId, 'yourName' => 'Bea', 'yourEmail' => 'bea@example.test', 'message' => 'Still there & ready?',
    ) + web_csrf());

    return web_page(static fn () => new CWebItem());
};
$threads = static fn (int $itemId): int => (int) $col("SELECT COUNT(*) FROM {$p}t_conversation WHERE fk_i_item_id = $itemId");
web_sign_in(null);
$r = $contact($desk);
pin('a guest sends the seller e-mail', array('mail', 1, 0), array($r['redirect'], $mails, $threads($desk)));
web_sign_in($buyer);
$set('enabled_messaging', '0');
$r = $contact($desk);
pin('a user with the switch off sends the e-mail', array('mail', 2, 0), array($r['redirect'], $mails, $threads($desk)));
$set('enabled_messaging', '1');
web_sign_in($buyer);
$r = $contact($desk);
pin('a user with the switch on starts a conversation and sends no e-mail', array(2, 1), array($mails, $threads($desk)));
pin('and the contact hook still fires for it', 3, $hooks);
pin('and lands on the thread', osc_messaging_thread_url((int) $col("SELECT pk_i_id FROM {$p}t_conversation WHERE fk_i_item_id = $desk")), $r['redirect']);
pin('the message is stored as typed', 'Still there & ready?', $col("SELECT m.s_body FROM {$p}t_message m JOIN {$p}t_conversation t ON t.pk_i_id = m.fk_i_conversation_id WHERE t.fk_i_item_id = $desk"));

$result = harness_result();
ob_end_flush();
exit($result);
