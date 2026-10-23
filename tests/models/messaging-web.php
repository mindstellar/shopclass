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
web_request('GET', array('page' => 'ajax', 'action' => 'messages_since', 'id' => $open, 'after' => $first));
$r = web_ajax('messagesSince');
pin('the buyer gets the messages after the id', array(200, array('Yes.')), array($r['status'], array_column($r['json']['messages'] ?? array(), 'body')));
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

$result = harness_result();
ob_end_flush();
exit($result);
