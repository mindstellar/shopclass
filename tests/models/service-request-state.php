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
 * The listing, comment and account services leave the request, the View and the session as
 * they found them: the caller passes the custom fields in and reads the new id from the
 * result, e-mail hooks see the listing only while they run, and collaborators can be swapped.
 *
 * Usage:  php tests/models/service-request-state.php        (standalone, own scratch database)
 *         php tests/run-models.php service-request-state    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';

// Loads page helpers that earlier files in the suite stub, so under the runner it runs alone.
if (defined('MODELS_RUNNER')) {
    $srOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $srOut, $srCode);
    $srOut = implode("\n", $srOut);
    echo $srOut, "\n";
    $srFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $srOut, $srM) === 1;
    $srFail  = $srFound ? (int) $srM[2] : 0;
    if (!$srFound || ($srCode !== 0 && $srFail === 0)) {
        $srFail = max(1, $srFail);
    }
    $GLOBALS['okCount']   += $srFound ? (int) $srM[1] : 0;
    $GLOBALS['failCount'] += $srFail;
    if ($srFail > 0) {
        $GLOBALS['failLabels'][] = 'service-request-state: ' . $srFail . ' failed (exit ' . $srCode . ')';
    }

    return;
}

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_service_request_state');

foreach (array(
    'OSC_CACHE_TTL'   => 60,
    'WEB_PATH'        => 'http://localhost/',
    'REL_WEB_URL'     => '/',
    'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
    'UPLOADS_PATH'    => sys_get_temp_dir() . '/',
    'OC_ADMIN'        => false,
    'OSC_DEBUG'       => false,
    'OSC_CSRF_SECRET' => 'service-request-state-secret',
    'BCRYPT_COST'     => 4,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
foreach (array('_m', '__') as $translate) {
    if (!function_exists($translate)) {
        eval('function ' . $translate . '($text) { return $text; }');
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH;
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';
if (!function_exists('osc_item')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
}

use mindstellar\auth\Actor;
use mindstellar\comment\CommentService;
use mindstellar\exception\NotFoundException;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\SavedListing;
use mindstellar\user\AccountService;

/** A listing store that finds nothing. */
final class NoItems extends Item
{
    public function findByPrimaryKey($id)
    {
        return array();
    }
}

/** A user store that finds nothing. */
final class NoUsers extends User
{
    public function findByPrimaryKey($id, $locale = null)
    {
        return array();
    }
}

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cars = seed_category($admin, 'Cars', seed_category($admin, 'Vehicles', null, $locale), $locale);
$sue  = seed_user($admin, 'sue', 'sue@example.test');
foreach (array(
    'enabled_users'                => '1',
    'enabled_comments'             => '1',
    'moderate_items'               => '-1',
    'moderate_comments'            => '-1',
    'reg_user_post_comments'       => '0',
    'notify_new_comment'           => '1',
    'notify_new_comment_user'      => '0',
    'items_wait_time'              => '0',
    'language'                     => 'en_US',
    'currency'                     => 'USD',
    'title_character_length'       => '100',
    'description_character_length' => '5000',
) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.61';
Params::init();

$ip   = '192.0.2.61';
$form = static fn (array $extra = array()): array => $extra + array(
    'catId'        => (string) $cars,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500',
    'currency'     => 'USD',
    'contactPhone' => '5550199',
);
$asUser = static function (?int $userId): void {
    Params::init();
    Session::getInstance()->_dropEphemeral('userId');
    if ($userId !== null) {
        Session::getInstance()->_setEphemeral('userId', (string) $userId);
    }
};
$view = View::getInstance();

harness_section('the listing form save answers with what it saved');
$service = new ListingService();
$saved   = $service->saveForm(ListingInput::fromArray($form(), Actor::user($sue, $ip), true), Actor::user($sue, $ip), true);
check('saveForm returns the saved listing', $saved instanceof SavedListing && $saved->id() > 0);
pin('and does not write the id into the request', '', Params::getParam('itemId'));
pin('the legacy answer is 2 for a live listing', 2, ListingService::legacyResult($saved, true));
pin('and the rows for an edit', 1, ListingService::legacyResult(new SavedListing(5, false, 1), false));
pin('a refusal is its message', 'No.', ListingService::legacyResult('No.', true));
$refused = $service->saveForm(ListingInput::fromArray($form(array('description' => array('en_US' => 'ab'))), Actor::user($sue, $ip), true), Actor::user($sue, $ip), true);
check('a refused save answers with the message', is_string($refused) && $refused !== '');

Params::init();
Params::setParam('meta', array('7' => 'from the request'));
pin('the request\'s custom fields are added by the caller', array('7' => 'from the request'), ListingInput::withMeta(array())['meta']);
pin('but never over the caller\'s own', array('8' => 'given'), ListingInput::withMeta(array('meta' => array('8' => 'given')))['meta']);
Params::init();

$asUser($sue);
$actions = new ItemActions(false);
$actions->prepareDataFrom($form(), true);
$code = $actions->add();
$asUser(null);
pin('ItemActions::add() still answers 2', 2, $code);
check('and knows the new id', $actions->lastItemId() > $saved->id());

harness_section('e-mail hooks see the listing only while they run');
$seen = array();
osc_add_hook('hook_email_new_item_non_register_user', static function ($item) use (&$seen): void {
    $seen['mail'] = osc_item()['pk_i_id'] ?? null;
});
osc_add_hook('posted_item', static function ($item) use (&$seen): void {
    $seen['posted'] = osc_item()['pk_i_id'] ?? null;
});
$view->_exportVariableToView('item', array('pk_i_id' => -1));
$row = Item::getInstance()->findByPrimaryKey($saved->id());
$service->notifyNew($row, 'ACTIVE', Actor::guest($ip));
pin('the guest e-mail hook reads the new listing through osc_item()', $saved->id(), (int) $seen['mail']);
pin('and the page\'s listing is back afterwards', -1, osc_item()['pk_i_id']);
$view->_erase('item');
$service->notifyNew($row, 'ACTIVE', Actor::guest($ip));
pin('with no listing before, none is left behind', false, $view->_exists('item'));

$again = $service->create(ListingInput::fromArray($form(), Actor::user($sue, $ip), true), Actor::user($sue, $ip));
pin('posted_item listeners read the new listing through osc_item()', $again->id(), (int) $seen['posted']);
pin('and it is not left in the View', false, $view->_exists('item'));

harness_section('the comment e-mails see the listing only while they run');
$commentSeen = null;
osc_add_hook('hook_email_new_comment_admin', static function ($mail) use (&$commentSeen): void {
    $commentSeen = osc_item()['pk_i_id'] ?? null;
});
$view->_exportVariableToView('item', array('pk_i_id' => -2));
(new CommentService())->post($saved->id(), array('title' => '', 'body' => 'Still for sale?'), Actor::user($sue, $ip));
pin('the admin e-mail hook reads the listing', $saved->id(), (int) $commentSeen);
pin('and the page\'s listing is back afterwards', -2, osc_item()['pk_i_id']);
$view->_erase('item');

harness_section('the account service leaves the session alone');
$asUser($sue);
Session::getInstance()->_setEphemeral('userName', 'Before');
Params::withRequest(array('s_name' => 'Susan'), static fn () => (new AccountService())->update($sue, mindstellar\user\AccountInput::read(false), Actor::user($sue, $ip)));
pin('a profile save through the service writes no session name', 'Before', Session::getInstance()->_get('userName'));
UserActions::refreshIdentity($sue);
pin('the web caller refreshes it', 'Susan', Session::getInstance()->_get('userName'));
$asUser(null);

$source = static fn (string $path): string => (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/' . $path);
foreach (array('listing/ListingService.php', 'comment/CommentService.php', 'user/AccountService.php') as $path) {
    $code = $source($path);
    pin("$path reads no request, View or session", array(false, false, false), array(str_contains($code, 'Params::'), str_contains($code, 'View::'), str_contains($code, 'Session::')));
}

harness_section('collaborators can be passed in');
$outcome = static function (callable $fn): string {
    try {
        $fn();
    } catch (NotFoundException $e) {
        return 'not found';
    }

    return 'ran';
};
pin('a listing store that finds nothing: activate finds nothing', null, (new ListingService(new NoItems()))->activate($saved->id()));
pin('a comment on a listing the store cannot find is refused', 'not found', $outcome(static fn () => (new CommentService(null, new NoItems()))->post($saved->id(), array('body' => 'Hi'), Actor::user($sue, $ip))));
pin('an account the store cannot find is refused', 'not found', $outcome(static fn () => (new AccountService(new NoUsers()))->requestEmailChange($sue, 'new@example.test', Actor::user($sue, $ip))));
pin('the defaults still find them', 'ran', $outcome(static fn () => (new AccountService())->requestEmailChange($sue, 'sue@example.test', Actor::user($sue, $ip))));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
