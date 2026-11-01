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
 * The "name is required" switch: a guest's blank name is Anonymous when it is off and an
 * error on /contact_name when it is on; a signed-in poster is never asked.
 * Usage: php tests/models/contact-name-required.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\ExposedSettings;
use mindstellar\admin\form\ItemSettingsScreen;
use mindstellar\auth\Actor;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;

$admin = api_admin_boot('osc_models_contact_name_required');
scratchdb_pin_site_mode($admin, 'classifieds');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';

$p = DB_TABLE_PREFIX;
seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat = seed_category($admin, 'Cars');
$sue = seed_user($admin, 'Al', 'al@example.test');
$admin->query("UPDATE {$p}t_user SET s_name = 'Al' WHERE pk_i_id = " . (int) $sue);
scratchdb_forget_cache();
(new ReflectionProperty(Category::class, 'instance'))->setValue(null, null);

$setPref = static function (array $values): void {
    foreach ($values as $k => $v) {
        Preference::getInstance()->replace($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$setPref([
    'moderate_items' => '-1', 'logged_user_item_validation' => '0', 'items_wait_time' => '0', 'reg_user_post' => '0', 'selectable_parent_categories' => '1',
    'language' => 'en_US', 'title_character_length' => '100', 'description_character_length' => '5000',
]);

$form = static fn (string $name, string $email): array => array(
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500',
    'contactName'  => $name,
    'contactEmail' => $email,
);
// The saved name, or the refusal's [pointer, code, message].
$post = static function (string $name, Actor $actor, string $email = 'gary@example.test') use ($form, $admin, $p): mixed {
    try {
        $id = (new ListingService())->create(ListingInput::fromArray($form($name, $email), $actor, true), $actor)->id();
    } catch (InvalidException $e) {
        $hit = array_values(array_filter($e->errors(), static fn (array $err): bool => $err['pointer'] === '/contact_name'));

        return $hit === array() ? 'other refusal: ' . $e->getMessage() : array($hit[0]['pointer'], $hit[0]['code'], $hit[0]['message']);
    }

    return (string) $admin->query("SELECT s_contact_name FROM {$p}t_item WHERE pk_i_id = " . (int) $id)->fetch_row()[0];
};
$guest = Actor::guest('192.0.2.1');

harness_section('fresh install and upgrade');
check(
    'basic_data.sql ships the switch off',
    str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), "('osclass', 'item_contact_name_required', '0', 'BOOLEAN')")
);
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'item_contact_name_required'");
scratchdb_forget_cache();
osc_reset_preferences();
pin('an upgraded site with no row reads it off', false, osc_item_contact_name_required());

harness_section('switch off');
$setPref(['item_contact_name_required' => '0']);
pin('a blank guest name is saved as Anonymous', 'Anonymous', $post('', $guest));
pin('so is a 2-character one', 'Anonymous', $post('Jo', $guest));

harness_section('switch on');
$setPref(['item_contact_name_required' => '1']);
$refused = array('/contact_name', 'required', 'Add your name.');
pin('a blank guest name is refused', $refused, $post('', $guest));
pin('a 2-character one too, after trim', $refused, $post('  Jo  ', $guest));
pin('a 3-character one is kept', 'Joe', $post('Joe', $guest));
pin('a signed-in poster with a short account name is not blocked', 'Anonymous', $post('', Actor::user((int) $sue, '192.0.2.1'), 'al@example.test'));
pin('nor is an admin', 'Anonymous', $post('', Actor::admin(1, '192.0.2.1')));

harness_section('settings screen');
$page   = ItemSettingsScreen::register();
$build  = new ReflectionMethod(ExposedSettings::class, 'post');
$submit = static function (bool $on) use ($page, $build): void {
    $fields = mindstellar\settings\SettingsPageRegistry::getInstance()->fields($page);
    $data   = $build->invoke(null, osc_settings_values($page), array('item_contact_name_required' => $on), $fields);
    Params::withRequest($data, static fn () => osc_settings_save($page));
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$submit(false);
pin('an unticked box saves 0 and reads back off', array('0', false), array(osc_get_preference('item_contact_name_required'), osc_item_contact_name_required()));
$submit(true);
pin('a ticked box saves 1 and reads back on', array('1', true), array(osc_get_preference('item_contact_name_required'), (bool) osc_settings_values($page)['item_contact_name_required']));

harness_section('publish form');
if (!function_exists('osc_is_edit_page')) {
    function osc_is_edit_page(): bool
    {
        return false;
    }
}
$nameInput = static function (string $on) use ($setPref): string {
    $setPref(['item_contact_name_required' => $on]);
    ob_start();
    ItemForm::contact_name_text(array('s_contact_name' => ''));

    return (string) ob_get_clean();
};
check('on, a guest\'s name box is required by the browser', str_contains($nameInput('1'), 'required="required"'));
check('off, the name box is optional', !str_contains($nameInput('0'), 'required'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
