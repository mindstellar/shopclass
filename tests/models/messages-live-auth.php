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
 * The live authorize check nchan calls before it opens a stream (messages_live_auth): 404 when
 * live is off whoever asks, 401 for a guest, 403 for another user's channel or with messaging
 * off, 200 and an empty body for the visitor's own channel. The channel comes only from the
 * X-Channel-Id header nchan sends.
 *
 * Usage:  php tests/models/messages-live-auth.php      (standalone, own scratch database)
 *         php tests/run-models.php messages-live-auth  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}
ob_start();

use mindstellar\container\Container;
use mindstellar\live\LivePublisher;
use mindstellar\live\NullPublisher;

$admin = api_admin_boot('osc_models_messages_live_auth');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/utility/AjaxResponse.php';

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};

/** A publisher that reports nchan and sends nothing. */
final class LaNchan implements LivePublisher
{
    public function publish(string $channel, array $note): bool
    {
        return true;
    }

    public function subscribeUrl(string $channel): ?string
    {
        return '/live/sub/' . $channel;
    }

    public function mode(): string
    {
        return 'nchan';
    }
}

/** The front base controller, stubbed: the action is called directly. */
class BaseModel
{
    protected $ajax = false;
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php';

/** Sign a user in for this request, or sign out with null. */
function la_sign_in(?int $userId): void
{
    Session::getInstance()->_drop('userId');
    Session::getInstance()->_dropEphemeral('userId');
    View::getInstance()->_exportVariableToView('_loggedUser', $userId === null ? false : User::getInstance()->findByPrimaryKey($userId));
    osc_is_web_user_logged_in();
}

/** One authorize call as nchan makes it: GET, X-Channel-Id set (or not), extra query args. Returns [status, body]. */
function la_auth(?string $channel, array $get = array()): array
{
    $_GET     = array('page' => 'ajax', 'action' => 'messages_live_auth') + $get;
    $_POST    = array();
    $_REQUEST = $_GET;
    $server   = array('REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1');
    unset($_SERVER['HTTP_X_CHANNEL_ID']);
    if ($channel !== null) {
        $server['HTTP_X_CHANNEL_ID'] = $channel;
    }
    $_SERVER = $server + $_SERVER;
    Params::init();
    http_response_code(200);
    $ajax = (new ReflectionClass('CWebAjax'))->newInstanceWithoutConstructor();
    $run  = new ReflectionMethod('CWebAjax', 'messagesLiveAuth');
    $run->setAccessible(true);
    ob_start();
    $run->invoke($ajax);
    $body = (string) ob_get_clean();

    return array((int) http_response_code(), $body);
}

$me    = seed_user($admin, 'bea', 'bea@example.test');
$other = seed_user($admin, 'sam', 'sam@example.test');
$set('enabled_users', '1');
$set('enabled_messaging', '1');

harness_reset_container();
$c = Container::root();

harness_section('live off');
pin('the container gives the no-op publisher with no OSC_LIVE', true, $c->get(LivePublisher::class) instanceof NullPublisher);
la_sign_in($me);
pin('the own channel is 404', array(404, ''), la_auth('user-' . $me));
la_sign_in(null);
pin('a guest is 404, not 401', array(404, ''), la_auth('user-' . $me));
la_sign_in($me);
$set('enabled_messaging', '0');
pin('messaging off is 404, not 403', array(404, ''), la_auth('user-' . $me));
$set('enabled_messaging', '1');

harness_section('live on (nchan)');
$c->instance(LivePublisher::class, new LaNchan());
la_sign_in(null);
pin('a guest is 401', array(401, ''), la_auth('user-' . $me));
la_sign_in($me);
pin('the own channel is 200 and empty', array(200, ''), la_auth('user-' . $me));
pin('another user\'s channel is 403', array(403, ''), la_auth('user-' . $other));
pin('no X-Channel-Id is 403', array(403, ''), la_auth(null));
pin('a channel only starting with the own one is 403', array(403, ''), la_auth('user-' . $me . '0'));
pin('a bare number is 403', array(403, ''), la_auth((string) $me));
pin('the channel is not taken from the query string', array(403, ''), la_auth('user-' . $other, array('channel' => 'user-' . $me, 'HTTP_X_CHANNEL_ID' => 'user-' . $me)));
$set('enabled_messaging', '0');
pin('messaging off is 403', array(403, ''), la_auth('user-' . $me));
$set('enabled_messaging', '1');
la_sign_in($other);
pin('the other user gets their own channel', array(200, ''), la_auth('user-' . $other));
pin('and not the first user\'s', array(403, ''), la_auth('user-' . $me));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
