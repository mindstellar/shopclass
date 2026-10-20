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
 * ListingStatus is the one live rule: the row check, the SQL Item::liveConditions() hands to
 * search, and osc_item_is_counted() agree, a missing expiry included. Actor carries who acts.
 *
 * DB-free.  Usage: php tests/listing-status.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/../oc-includes/vendor/autoload.php';

use mindstellar\auth\Actor;
use mindstellar\listing\ListingStatus;

$now  = strtotime('2026-06-01 12:00:00');
$live = array('b_enabled' => '1', 'b_active' => '1', 'b_spam' => '0', 'b_premium' => '0', 'dt_expiration' => '2026-07-01 00:00:00');

harness_section('the row');
pin('live', ListingStatus::ACTIVE, ListingStatus::of($live, $now));
pin('past its expiry', ListingStatus::EXPIRED, ListingStatus::of(array('dt_expiration' => '2026-05-01 00:00:00') + $live, $now));
pin('a missing expiry counts as passed, as NULL does in SQL', ListingStatus::EXPIRED, ListingStatus::of(array('dt_expiration' => null) + $live, $now));
pin('premium stays live past its expiry', ListingStatus::ACTIVE, ListingStatus::of(array('dt_expiration' => null, 'b_premium' => '1') + $live, $now));
pin('spam before blocked before pending before expired', array('spam', 'disabled', 'pending'), array(
    ListingStatus::of(array('b_spam' => '1', 'b_enabled' => '0', 'b_active' => '0', 'dt_expiration' => null) + $live, $now),
    ListingStatus::of(array('b_enabled' => '0', 'b_active' => '0', 'dt_expiration' => null) + $live, $now),
    ListingStatus::of(array('b_active' => '0', 'dt_expiration' => null) + $live, $now),
));
check('isLive() is of() === active', ListingStatus::isLive($live, $now) && !ListingStatus::isLive(array('b_active' => 0) + $live, $now));

harness_section('the SQL');
$sql = ListingStatus::liveConditions('i.', $now);
pin('the fragments search has always joined', array(
    'i.b_enabled = 1', 'i.b_active = 1', 'i.b_spam = 0', "(i.b_premium = 1 || i.dt_expiration >= '2026-06-01 12:00:00')",
), $sql);
check('no NULL escape: a NULL expiry is not live in SQL either', strpos(implode(' ', $sql), 'IS NULL') === false);
$item = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/model/Item.php');
check('Item::liveConditions() hands over to it', strpos($item, 'ListingStatus::liveConditions(') !== false);
$utils = (string) file_get_contents(__DIR__ . '/../oc-includes/osclass/utils.php');
preg_match('/function osc_item_is_counted.*?\n}/s', $utils, $counted);
check('osc_item_is_counted() hands over to it', strpos($counted[0] ?? '', 'ListingStatus::isLive(') !== false);

harness_section('Actor');
$both = new Actor(7, 3, '192.0.2.1', 'sec');
pin('a user who is also a signed-in admin', array(7, 3, true, false, 'admin', 3), array($both->userId(), $both->adminId(), $both->isAdmin(), $both->isGuest(), $both->logRole(), $both->logId()));
pin('a guest', array(null, null, false, true, 'user', 0), array(Actor::guest()->userId(), Actor::guest()->adminId(), Actor::guest()->isAdmin(), Actor::guest()->isGuest(), Actor::guest()->logRole(), Actor::guest()->logId()));
pin('admin rights with nobody signed in', array(true, 0), array(Actor::admin(0)->isAdmin(), Actor::admin(0)->logId()));
pin('a user id of 0 is nobody', null, (new Actor(0, null))->userId());
pin('withSecret() leaves the original alone', array('', 'x'), array(Actor::user(5)->secret(), Actor::user(5)->withSecret('x')->secret()));

exit(harness_result());
