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
 * the POST-only send, the ETag and 304 on the poll check, the status map, typed reads, and the
 * live authorize check nchan calls before it opens a stream.
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
check('the body is read raw, as the inbox reply reads it', strpos($send, "Params::getParamString('body', false, false, false)") !== false);
check('the id is read as an int', strpos($send, "Params::getParamInt('id')") !== false);
check('it answers the message the service stored', strpos($send, '$sent->message()') !== false && strpos($send, '->find(') === false);
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
check('the status is the exception\'s own', strpos($refusal, 'AjaxResponse::json($body, $e->httpStatus())') !== false);
check('a field error carries its errors', strpos($refusal, "\$body['errors'] = \$e->errors()") !== false);
check('a sign-in block is not called a rate limit', strpos($refusal, "isRateLimit() ? 'rate_limited' : 'login_blocked'") !== false);

harness_section('each refusal\'s status');
foreach (array('Refused', 'Forbidden', 'NotFound', 'Conflict', 'Invalid', 'Blocked') as $name) {
    require_once __DIR__ . "/../oc-includes/osclass/classes/exception/{$name}Exception.php";
}
$statuses = array(
    422 => new \mindstellar\exception\RefusedException('x'),
    403 => new \mindstellar\exception\ForbiddenException('x', 'sign_in'),
    404 => new \mindstellar\exception\NotFoundException('x'),
    409 => new \mindstellar\exception\ConflictException('x', 'closed'),
    429 => \mindstellar\exception\BlockedException::rateLimit('x', 5),
);
foreach ($statuses as $status => $e) {
    pin(get_class($e) . " is $status", $status, $e->httpStatus());
}
pin('InvalidException is 422', 422, (new \mindstellar\exception\InvalidException('/body', 'minLength', 'x'))->httpStatus());
check('a rate limit carries retry_after', strpos($refusal, "'retry_after'") !== false && strpos($refusal, '->retryAfter()') !== false);

harness_section('live auth');
check('messages_live_auth is in the map', (bool) preg_match("/'messages_live_auth'\\s*=>\\s*'messagesLiveAuth'/", $source));
$auth  = $method('messagesLiveAuth');
$off   = strpos($auth, "->mode() === 'off'");
$who   = strpos($auth, 'Actor::visitor()');
$e404  = strpos($auth, 'http_response_code(404)');
check('it exists', $auth !== '');
check('live off answers 404', $off !== false && $e404 !== false && $off < $e404);
check('the 404 check comes before the visitor is read', $off !== false && $who !== false && $off < $who && $e404 < $who);
check('the publisher comes from the container, not the environment', strpos($auth, 'Container::root()->get(LivePublisher::class)') !== false && strpos($auth, 'getenv(') === false);
check('a guest is 401', strpos($auth, '=== null') !== false && strpos($auth, 'http_response_code(401)') !== false);
check('messaging off is 403', strpos($auth, '!osc_messaging_enabled()') !== false && strpos($auth, 'http_response_code(403)') !== false);
check('the channel comes from nchan\'s X-Channel-Id header', strpos($auth, "Params::getServerParam('HTTP_X_CHANNEL_ID'") !== false);
check('the channel is not read from the request', !preg_match('/Params::getParam\w*\(/', $auth));
check('the channel compare is strict, against the visitor\'s own channel', strpos($auth, "\$channel !== 'user-' . \$userId") !== false && strpos($auth, '!=') === strpos($auth, '!=='));
check('no-store is sent first', strpos($auth, "header('Cache-Control: no-store')") !== false && strpos($auth, "header('Cache-Control: no-store')") < (int) $off);
check('no body is sent', strpos($auth, 'echo') === false && strpos($auth, 'AjaxResponse::') === false);
check('no CSRF check (GET, no state)', strpos($auth, 'osc_csrf_check') === false);
check('no flash, no redirect, no session, no log', !preg_match('/flash|redirect|Session::|error_log|_set\(/i', $auth));

harness_section('no untyped reads');
$all = $send . $check . $since . $actor . $refusal . $auth;
check('none of the messages actions calls getParam(', strpos($all, 'getParam(') === false);

exit(harness_result());
