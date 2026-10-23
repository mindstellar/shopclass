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
 * osc_item_uses_messaging(): the contact form starts a conversation only when messaging is on,
 * the visitor is signed in and the listing has a seller account that is not the visitor.
 *
 * Usage:  php tests/models/messaging-contact.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-contact  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\messaging\MessagingService;

$admin = api_admin_boot('osc_models_messaging_contact');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$cat    = seed_category($admin);
$bike   = seed_item($admin, $cat, $seller, 'Bike');
$guest  = seed_item($admin, $cat, null, 'Guest sofa');

$view = View::getInstance();
$show = static function (int $itemId) use ($view): void {
    $view->_exportVariableToView('item', Item::getInstance()->findByPrimaryKey($itemId));
};
$signIn = static function (?int $userId) use ($view): void {
    Session::getInstance()->_drop('userId');
    $view->_exportVariableToView('_loggedUser', $userId === null ? false : User::getInstance()->findByPrimaryKey($userId));
};

$set('enabled_users', '1');
$set('enabled_messaging', '1');

harness_section('osc_item_uses_messaging');
$show($bike);
$signIn($buyer);
pin('switch on, signed in, someone else\'s listing', true, osc_item_uses_messaging());
$set('enabled_messaging', '0');
pin('switch off', false, osc_item_uses_messaging());
$set('enabled_messaging', '1');
$signIn(null);
pin('a guest', false, osc_item_uses_messaging());
$signIn($seller);
pin('the seller\'s own listing', false, osc_item_uses_messaging());
$signIn($buyer);
$set('site_mode', 'shop');
pin('a shop-mode buyer keeps the e-mail form', false, osc_item_uses_messaging());
$set('site_mode', 'classifieds');
pin('and gets messaging back outside shop mode', true, osc_item_uses_messaging());
$show($guest);
pin('a listing with no seller account', false, osc_item_uses_messaging());

harness_section('what the controller calls');
harness_reset_container();
$svc = Container::root()->get(MessagingService::class);
$sent = $svc->start($bike, 'Is the bike still for sale?', Actor::user($buyer, '10.0.0.1'));
pin('the buyer\'s inbox shows the new thread', array($sent->conversationId()), array_map('intval', array_column($svc->inbox(Actor::user($buyer, '10.0.0.1')), 'pk_i_id')));

exit(harness_result());
