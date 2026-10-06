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
 * Rules that used to sit in several places and now sit in one: place resolution for
 * listings and accounts, the resend-activation wait, the bump, and the guest e-mail that
 * belongs to an account.
 *
 * Usage:  php tests/models/shared-rules.php       (standalone, own scratch database)
 *         php tests/run-models.php shared-rules   (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_shared_rules');

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
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\ListingService;
use mindstellar\location\LocationService;
use mindstellar\moderation\ListingModeration;
use mindstellar\user\AccountService;

$p = DB_TABLE_PREFIX;
seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat    = seed_category($admin, 'Cars');
$region = seed_region($admin, 'US', 'Texas');
$city   = seed_city($admin, $region, 'Austin');
$sue    = seed_user($admin, 'sue', 'sue@example.test');

$setPref = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$setPref([
    'moderate_items' => '-1', 'logged_user_item_validation' => '0', 'items_wait_time' => '0',
    'language' => 'en_US', 'title_character_length' => '100', 'description_character_length' => '5000',
]);

harness_section('LocationService::resolve');
$ids = LocationService::resolve(['countryCode' => 'US', 'regionId' => (string) $region, 'cityId' => (string) $city]);
pin('ids that exist give their names', ['US', 'United States', $region, 'Texas', $city, 'Austin'], array_values($ids));
$text = LocationService::resolve(['countryCode' => 'ZZ', 'country' => 'Narnia', 'region' => 'Texas', 'city' => 'Austin']);
pin('an unknown country keeps the typed text and no id', [null, 'Narnia', null, 'Texas', null, 'Austin'], array_values($text));
$named = LocationService::resolve(['countryCode' => 'US', 'region' => 'Texas', 'city' => 'Austin'], true);
pin('with name matching, typed places find their ids', [$region, $city], [$named['regionId'], $named['cityId']]);
$gone = LocationService::resolve(['countryCode' => 'US', 'regionId' => '9999', 'cityId' => 'abc']);
pin('a posted id that does not exist gives null for the id and the name', [null, null, null, null], [$gone['regionId'], $gone['regionName'], $gone['cityId'], $gone['cityName']]);

harness_section('listings and accounts resolve places the same way');
$form    = [
    'catId' => (string) $cat, 'countryId' => 'US', 'country' => 'United States', 'regionId' => (string) $region,
    'city' => 'Austin', 'title' => ['en_US' => 'Red hatchback'], 'description' => ['en_US' => 'A small red car, one owner.'],
    'price' => '1500', 'contactName' => 'Gary', 'contactEmail' => 'gary@example.test',
];
$listing = ListingInput::fromArray($form, Actor::guest(), true);
pin('the listing form', ['US', $region, 'Texas', $city, 'Austin'], [$listing['countryId'], $listing['regionId'], $listing['regionName'], $listing['cityId'], $listing['cityName']]);
$row = (new AccountService())->input(['countryId' => 'US', 'regionId' => $region, 'city' => 'Austin'], false);
pin('the account form', ['US', $region, 'Texas', null, 'Austin'], [$row['fk_c_country_code'], $row['fk_i_region_id'], $row['s_region'], $row['fk_i_city_id'], $row['s_city']]);
$row = (new AccountService())->input(['countryId' => 'US', 'regionId' => 9999], false);
pin('an account form with an unknown region id leaves the stored place alone', false, array_key_exists('fk_i_region_id', $row));

harness_section('resend activation waits for the account holder only');
$setPref(['enabled_user_validation' => '1', 'notify_new_user' => '0']);
$pending = seed_user($admin, 'pat', 'pat@example.test', 0);
$sent    = 0;
osc_add_hook('hook_email_user_validation', static function () use (&$sent): void {
    $sent++;
});
$accounts = new AccountService();
$admin->query("UPDATE {$p}t_user SET dt_access_date = NOW() WHERE pk_i_id = $pending");
pin('right after a link, the holder is told to wait', [false, 0], [$accounts->resendActivation($pending, true), $sent]);
pin('and the wait is the 20 minutes', true, AccountService::resendWait(User::getInstance()->findByPrimaryKey($pending)) > 1100);
pin('an admin does not wait', [true, 1], [$accounts->resendActivation($pending), $sent]);
$admin->query("UPDATE {$p}t_user SET dt_access_date = NOW() - INTERVAL 21 MINUTE WHERE pk_i_id = $pending");
pin('after 20 minutes the holder gets a link', [true, 2], [$accounts->resendActivation($pending, true), $sent]);
pin('and the wait starts again', true, AccountService::resendWait(User::getInstance()->findByPrimaryKey($pending)) > 0);
$adminNotes = 0;
osc_add_hook('hook_email_admin_new_user', static function () use (&$adminNotes): void {
    $adminNotes++;
});
$setPref(['notify_new_user' => '1']);
$admin->query("UPDATE {$p}t_user SET dt_access_date = NOW() - INTERVAL 21 MINUTE WHERE pk_i_id = $pending");
$accounts->resendActivation($pending, true);
$accounts->resendActivation($pending);
pin('the admin is told about the holder\'s request only', 1, $adminNotes);

harness_section('one bump');
$item   = seed_item($admin, $cat, $sue, 'Bumpable');
$bumped = [];
osc_add_hook('item_bumped', static function ($id) use (&$bumped): void {
    $bumped[] = (int) $id;
});
$admin->query("UPDATE {$p}t_item SET dt_pub_date = '2020-01-01 00:00:00' WHERE pk_i_id = $item");
pin('ListingService::bump moves the date and fires item_bumped once', [true, [$item]], [(new ListingService())->bump($item), $bumped]);
$date = static fn (): string => (string) $admin->query("SELECT dt_pub_date FROM {$p}t_item WHERE pk_i_id = $item")->fetch_row()[0];
check('the date moved', $date() > '2020-01-02');
$bumped = [];
$admin->query("UPDATE {$p}t_item SET dt_pub_date = '2020-01-01 00:00:00' WHERE pk_i_id = $item");
ListingModeration::make()->apply('bump', $item, 1, 'test');
pin('the moderation bump goes through it, once', [$item], $bumped);
$bumped = [];
pin('an announce callback replaces the hook', [true, []], [(new ListingService())->bump($item, '2021-01-01 00:00:00', static function (): void {
}), $bumped]);
pin('and no listing means no bump', false, (new ListingService())->bump(999999));

harness_section('a guest cannot post with the e-mail of an account');
pin('a guest with an account\'s e-mail', true, ListingPolicy::usesAccountEmail(Actor::guest('192.0.2.1'), 'sue@example.test'));
pin('not the account\'s own user', false, ListingPolicy::usesAccountEmail(Actor::user($sue, '192.0.2.1'), 'sue@example.test'));
pin('not an admin', false, ListingPolicy::usesAccountEmail(Actor::admin(1, '192.0.2.1'), 'sue@example.test'));
pin('not a new address', false, ListingPolicy::usesAccountEmail(Actor::guest('192.0.2.1'), 'new@example.test'));
pin('not an empty one', false, ListingPolicy::usesAccountEmail(Actor::guest('192.0.2.1'), ''));
pin('the service leaves it to the caller, so plugins posting as a guest still work', ListingPolicy::ALLOWED, ListingPolicy::mayPost(Actor::guest('192.0.2.1'), 'sue@example.test'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
