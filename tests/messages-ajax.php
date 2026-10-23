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
 * Pins the messages AJAX actions in CWebAjax: the three entries, CSRF before the service,
 * the POST-only send, the ETag and 304 on the poll check, the status map, and typed reads.
 *
 * DB-free and source-level.  Usage: php tests/messages-ajax.php
 */

require_once __DIR__ . '/lib/harness.php';

$file   = __DIR__ . '/../oc-includes/osclass/classes/controller/CWebAjax.php';
$source = (string) file_get_contents($file);
$method = static fn (string $name): string => harness_method_source($file, $name);

harness_section('the action map');
foreach (array('messages_send' => 'messagesSend', 'messages_check' => 'messagesCheck', 'messages_since' => 'messagesSince') as $action => $name) {
    check("$action is in the map", (bool) preg_match("/'$action'\\s*=>\\s*'$name'/", $source));
    check("$name exists", $method($name) !== '');
}

harness_section('send');
$send = $method('messagesSend');
$post = strpos($send, "!== 'POST'");
$csrf = strpos($send, 'osc_csrf_check()');
$call = strpos($send, '->reply(');
check('it refuses anything but POST with 405', $post !== false && strpos($send, '405') !== false);
check('it checks CSRF', $csrf !== false);
check('CSRF comes before the service call', $csrf !== false && $call !== false && $csrf < $call);
check('the method check comes first', $post !== false && $csrf !== false && $post < $csrf);
check('the visitor is checked before CSRF', strpos($send, 'messagingActor()') !== false && strpos($send, 'messagingActor()') < $csrf);
check('the body is read as a string', strpos($send, "Params::getParamString('body')") !== false);
check('the id is read as an int', strpos($send, "Params::getParamInt('id')") !== false);
check('it answers the stored row', strpos($send, 'MessageStore::class') !== false && strpos($send, '->find(') !== false);
check('it answers ok and held', strpos($send, "'ok' => true") !== false && strpos($send, "'held' =>") !== false);

harness_section('check');
$check = $method('messagesCheck');
check('the tag has the unread count and the md5 of the latest time', strpos($check, "\$activity['unread']") !== false && strpos($check, "md5((string) \$activity['latest'])") !== false);
check('it compares If-None-Match', strpos($check, 'osc_etag_matches(') !== false && strpos($check, 'HTTP_IF_NONE_MATCH') !== false);
check('a match answers 304', strpos($check, 'http_response_code(304)') !== false);
$match = strpos($check, 'osc_etag_matches(');
$json  = strpos($check, 'AjaxResponse::json(');
check('the 304 branch returns before any body', $match !== false && $json !== false && strpos(substr($check, $match, $json - $match), 'return;') !== false);
check('no body is sent in the 304 branch', substr_count(substr($check, $match, $json - $match), 'echo') === 0);
check('it sends the ETag and no-store', strpos($check, "header('ETag: '") !== false && strpos($check, 'private, no-store') !== false);

harness_section('since');
$since = $method('messagesSince');
check('it reads id and after as ints', strpos($since, "Params::getParamInt('id')") !== false && strpos($since, "Params::getParamInt('after')") !== false);
check('it answers messages, state and blocked', strpos($since, "'messages'") !== false && strpos($since, "'state'") !== false && strpos($since, "'blocked'") !== false);

harness_section('the visitor and the refusals');
$actor = $method('messagingActor');
check('a guest is 401 sign_in', strpos($actor, 'SIGN_IN') !== false && strpos($actor, '401') !== false);
check('messaging off is 403 disabled', strpos($actor, 'osc_messaging_enabled()') !== false && strpos($actor, '403') !== false);
$refusal = $method('messagingRefusal');
foreach (array('ForbiddenException' => '403', 'NotFoundException' => '404', 'ConflictException' => '409', 'InvalidException' => '422', 'BlockedException' => '429') as $class => $status) {
    check("$class maps to $status", (bool) preg_match("/instanceof $class\\).*?\\\$status\\s*=\\s*[^;]*$status/s", $refusal));
}
check('a rate limit carries retry_after', strpos($refusal, "'retry_after'") !== false && strpos($refusal, '->retryAfter()') !== false);

harness_section('no untyped reads');
$all = $send . $check . $since . $actor . $refusal;
check('none of the messages actions calls getParam(', strpos($all, 'getParam(') === false);

exit(harness_result());
