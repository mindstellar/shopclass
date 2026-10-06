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
 * With e-mail validation off, registering moved every guest listing and alert under that
 * e-mail to the new account, so anyone could take a guest seller's listings by signing up with
 * their address. Guest listings now move only once the address is confirmed.
 *
 * Usage:  php tests/models/guest-listing-claim.php          (standalone, own scratch database)
 *         php tests/run-models.php guest-listing-claim      (as part of the suite)
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_guest_listing_claim');

require_once __DIR__ . '/../lib/action-standins.php';

$prefix = DB_TABLE_PREFIX;
seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Claims');

$victim    = 'seller@example.test';
$guestItem = seed_item($admin, $cat, null, 'Guest listing');
$other     = seed_user($admin, 'other', 'other@example.test');
$otherItem = seed_item($admin, $cat, $other, 'Owned listing');
foreach (array($guestItem, $otherItem) as $id) {
    $admin->query("UPDATE {$prefix}t_item SET s_contact_email = '$victim', s_contact_name = 'Seller' WHERE pk_i_id = $id");
}
$admin->query("INSERT INTO {$prefix}t_alerts (s_email, fk_i_user_id, s_search, b_active) VALUES ('$victim', NULL, '{}', 1)");
$alertId = (int)$admin->insert_id;

$ownerOf = static function (string $table, int $id) use ($admin, $prefix) {
    $row = $admin->query("SELECT fk_i_user_id FROM {$prefix}$table WHERE pk_i_id = $id")->fetch_row();

    return $row[0] === null ? null : (int)$row[0];
};

$itemsOf = static function (int $id) use ($admin, $prefix): string {
    return (string)$admin->query("SELECT i_items FROM {$prefix}t_user WHERE pk_i_id = $id")->fetch_row()[0];
};

harness_section('registering with validation off');
Params::setParam('s_name', 'Not the seller');
Params::setParam('s_email', $victim);
Params::setParam('s_username', 'squatter');
Params::setParam('s_password', 'correct horse battery');
Params::setParam('s_password2', 'correct horse battery');
pin('the account is created and active straight away', 2, (new UserActions(false))->add());
$newId = (int)(User::getInstance()->findByEmail($victim)['pk_i_id'] ?? 0);
check('the account exists', $newId > 0);
pin('the guest listing stays a guest listing', null, $ownerOf('t_item', $guestItem));
pin('the guest alert stays a guest alert', null, $ownerOf('t_alerts', $alertId));

harness_section('once the address is confirmed');
$code = 'confirm-code-1';
$hash = $admin->real_escape_string(\mindstellar\security\ActionToken::hash($code));
$admin->query("UPDATE {$prefix}t_user SET b_active = 0, s_secret = '$hash' WHERE pk_i_id = $newId");
$accounts = new \mindstellar\user\AccountService();
$refusal  = static function (callable $fn): string {
    try {
        $fn();
    } catch (\mindstellar\validation\RefusedException $e) {
        return get_class($e);
    }

    return '';
};
pin('a wrong code is refused', 'mindstellar\validation\NotFoundException', $refusal(fn () => $accounts->confirm($newId, 'wrong')));
pin('a wrong code moves nothing', null, $ownerOf('t_item', $guestItem));
pin('the right code confirms the account', '', $refusal(fn () => $accounts->confirm($newId, $code)));
pin('the guest listing moves to the account', $newId, $ownerOf('t_item', $guestItem));
pin('the guest alert moves to the account', $newId, $ownerOf('t_alerts', $alertId));
pin('the account counts the listing', '1', $itemsOf($newId));
pin('a listing another account owns is left alone', $other, $ownerOf('t_item', $otherItem));
pin('the code works once', 'mindstellar\validation\NotFoundException', $refusal(fn () => $accounts->confirm($newId, $code)));
pin('confirming twice counts the listing once', '1', $itemsOf($newId));

harness_section('an account an admin makes');
$adminGuest = seed_item($admin, $cat, null, 'Another guest listing');
$admin->query("UPDATE {$prefix}t_item SET s_contact_email = 'made@example.test' WHERE pk_i_id = $adminGuest");
Params::setParam('s_name', 'Made by admin');
Params::setParam('s_email', 'made@example.test');
Params::setParam('s_username', 'madebyadmin');
(new UserActions(true))->add();
$madeId = (int)(User::getInstance()->findByEmail('made@example.test')['pk_i_id'] ?? 0);
check('the account exists', $madeId > 0);
pin('it takes the guest listing at once', $madeId, $ownerOf('t_item', $adminGuest));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
