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
 * ListingInput::fromArray() takes the owner and the starting status from the Actor it is
 * given, not from the session or the request, and ListingService refuses with structured
 * errors that still read as the same flash text.
 *
 * Usage:  php tests/models/listing-input-actor.php       (standalone, own scratch database)
 *         php tests/run-models.php listing-input-actor   (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_listing_input_actor');

foreach (array('_m', '__') as $translate) {
    if (!function_exists($translate)) {
        eval('function ' . $translate . '($text) { return $text; }');
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

use mindstellar\auth\Actor;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\validation\InvalidException;

seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Cars');
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
$ann = seed_user($admin, 'ann', 'ann@example.test');
$admin->query('UPDATE ' . DB_TABLE_PREFIX . 't_user SET i_items = 3 WHERE pk_i_id = ' . (int) $ann);

$setPref = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::newInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$setPref([
    'moderate_items' => '-1', 'logged_user_item_validation' => '0', 'items_wait_time' => '0',
    'language' => 'en_US', 'title_character_length' => '100', 'description_character_length' => '5000',
]);

$form = static fn (array $extra = array()): array => $extra + array(
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500',
    'contactName'  => 'Guest Gary',
    'contactEmail' => 'gary@example.test',
    'contactPhone' => '5550199',
);
$active = static fn (Actor $actor): string => ListingInput::fromArray($form(), $actor, true)['active'];

harness_section('the owner comes from the actor');
Params::setParam('title', 'from the request');
Params::setParam('contactEmail', 'request@example.test');
$guest = ListingInput::fromArray($form(), Actor::guest('192.0.2.1'), true);
pin('a guest has no owner', null, $guest['userId']);
pin('a guest\'s contact details are the form\'s', array('Guest Gary', 'gary@example.test'), array($guest['contactName'], $guest['contactEmail']));
pin('the request is not read', array('en_US' => 'Red hatchback'), $guest['title']);

$user = ListingInput::fromArray($form(), Actor::user($sue, '192.0.2.1'), true);
pin('a user owns the listing', (int) $sue, (int) $user['userId']);
pin('and the contact details are the account\'s', 'sue@example.test', $user['contactEmail']);

$byId = ListingInput::fromArray($form(array('ownerId' => (string) $tom)), Actor::admin(1), true);
pin('an admin can name the owner', (int) $tom, (int) $byId['userId']);
pin('a user cannot, ownerId is ignored', (int) $sue, (int) ListingInput::fromArray($form(array('ownerId' => (string) $tom)), Actor::user($sue), true)['userId']);
pin('an admin\'s ownerId 0 means no owner', null, ListingInput::fromArray($form(array('ownerId' => '0')), Actor::admin(1), true)['userId']);
pin('without ownerId an admin gets the account of the contact e-mail', (int) $tom, (int) ListingInput::fromArray($form(array('contactEmail' => 'tom@example.test')), Actor::admin(1), true)['userId']);

harness_section('the starting status comes from the actor');
$setPref(['moderate_items' => '-1']);
pin('no moderation: active for everyone', array('ACTIVE', 'ACTIVE'), array($active(Actor::guest()), $active(Actor::user($sue))));
$setPref(['moderate_items' => '0', 'logged_user_item_validation' => '0']);
pin('moderate all: a guest and a user wait', array('INACTIVE', 'INACTIVE'), array($active(Actor::guest()), $active(Actor::user($sue))));
$setPref(['logged_user_item_validation' => '1']);
pin('moderate all, users trusted: only a guest waits', array('INACTIVE', 'ACTIVE'), array($active(Actor::guest()), $active(Actor::user($sue))));
$setPref(['moderate_items' => '3', 'logged_user_item_validation' => '0']);
pin('moderate the first 3: a user with none yet waits, a guest waits', array('INACTIVE', 'INACTIVE'), array($active(Actor::guest()), $active(Actor::user($sue))));
pin('past the first 3 a user is active', 'ACTIVE', $active(Actor::user($ann)));
pin('an admin posts active whatever the setting', 'ACTIVE', $active(Actor::admin(1)));
pin('an edit has no status', false, array_key_exists('active', ListingInput::fromArray($form(array('id' => '1')), Actor::user($sue), false)));

harness_section('refusals are structured and read as before');
$setPref(['moderate_items' => '-1']);
$service = new ListingService();
$refused = null;
try {
    $service->create(ListingInput::fromArray($form(array('description' => array('en_US' => 'ab'))), Actor::user($sue, '192.0.2.1'), true), Actor::user($sue, '192.0.2.1'));
} catch (InvalidException $e) {
    $refused = $e;
}
pin('the listing is refused', true, $refused instanceof InvalidException);
$first = $refused->errors()[0] ?? array();
pin('with the member, code and message', array('/description', 'too_short', 'Description too short (en_US).'), array($first['pointer'] ?? null, $first['code'] ?? null, $first['message'] ?? null));
pin('the web text is the messages, one per line', implode('', array_map(static fn (array $e): string => $e['message'] . PHP_EOL, $refused->errors())), $refused->getMessage());
