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
 * Pins the glue in the site messages controller: CSRF on every state change, switch check in the
 * constructor, reading a thread marks it read, typed request reads only.
 *
 * DB-free and source-level.  Usage: php tests/messages-controller.php
 */

require_once __DIR__ . '/lib/harness.php';

$file   = __DIR__ . '/../oc-includes/osclass/classes/controller/CWebMessages.php';
$source = (string) file_get_contents($file);

harness_section('state changes');
foreach (array('sendPost', 'blockPost', 'unblockPost', 'reportPost') as $method) {
    $body = harness_method_source($file, $method);
    check("$method was parsed", $body !== '');
    check("$method checks CSRF before anything else", preg_match('/^\s*osc_csrf_check\(\);/m', $body) === 1
        && strpos($body, 'osc_csrf_check()') < strpos($body, 'Params::'));
}

harness_section('reading');
$thread = harness_method_source($file, 'thread');
check('thread() marks the conversation read', strpos($thread, '->markRead(') !== false);
check('thread() reads it first, so a stranger is refused before anything is cleared', strpos($thread, '->thread(') < strpos($thread, '->markRead('));
$constructor = harness_method_source($file, '__construct');
check('the constructor checks the switch', strpos($constructor, '!osc_messaging_enabled()') !== false);
check('and sends the visitor to the dashboard', strpos($constructor, 'osc_user_dashboard_url()') !== false);
$authFail = harness_method_source($file, 'showAuthFailPage');
check('a signed-out GET remembers the page for after login', strpos($authFail, "Params::getServerParam('REQUEST_METHOD') === 'GET'") !== false
    && strpos($authFail, 'osc_set_login_redirect(osc_base_url() . Params::getRequestURI(false, false, false))') !== false
    && strpos($authFail, 'osc_set_login_redirect(') < strpos($authFail, 'parent::showAuthFailPage()'));
$route = harness_method_source($file, 'doModel');
check('a shopper is sent home', strpos($route, 'redirectShopperHome()') !== false);

harness_section('the list rows');
check('the inbox and the thread both build their rows with listRows()', strpos(harness_method_source($file, 'inbox'), '$this->listRows(') !== false
    && strpos($thread, '$this->listRows(') !== false);
check('the thread is read and marked read before the list is built', strpos($thread, '->markRead(') < strpos($thread, '$this->listRows('));
$rows = harness_method_source($file, 'listRows');
foreach (array('lastVisible(', '$this->listingFacts(', 'UserStore::displayNames(', "'preview_mine'", "'photo_url'", "'short_time'", "'initial'", "'listing_live'") as $needle) {
    check("listRows() uses {$needle}", strpos($rows, $needle) !== false);
}
$loop = substr($rows, strpos($rows, 'foreach ('));
check('no read sits inside the row loop', (bool) !preg_match('/lastVisible\(|ListingStore|UserStore|PhotoStore|Db::|listingFacts|service\(\)/', $loop));
foreach (array('conversation', 'messages', 'names', 'title', 'item_url', 'can_send', 'my_block', 'their_block', 'conversations', 'older', 'role', 'their_unread') as $key) {
    check("thread() still exports '{$key}'", strpos($thread, "_exportVariableToView('{$key}'") !== false);
}

harness_section('request reads');
check('no untyped Params::getParam(', strpos($source, 'Params::getParam(') === false);
check('a rate limit uses the shared wording', strpos(harness_method_source($file, 'sendPost'), 'osc_message_throttle_message($e->retryAfter())') !== false);
check('the message body is read raw, not purified', strpos($source, "Params::getParamString('body', false, false, false)") !== false);
check('no getInstance() of a service', strpos($source, 'MessagingService::getInstance') === false);

exit(harness_result());
