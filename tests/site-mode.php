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
 * The site mode: osc_site_mode() and its default, ListingPolicy::mayPost() in shop mode, the
 * list of packages that do not declare the chosen mode, and the preference migration.
 *
 * Usage: php tests/site-mode.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
use mindstellar\site\SiteMode;

$admin = api_admin_boot('osc_site_mode');
$p     = DB_TABLE_PREFIX;
$mode  = static function (string $value): void {
    Preference::getInstance()->set('site_mode', $value);
};
$guest = Actor::guest('192.0.2.80');
$user  = Actor::user(7, '192.0.2.80');
$root  = Actor::admin(1, '192.0.2.80');
Preference::getInstance()->set('reg_user_post', '0');

harness_section('the setting');
pin('unset reads classifieds', ['classifieds', false], [osc_site_mode(), osc_is_shop_mode()]);
$mode('shop');
pin('shop', ['shop', true], [osc_site_mode(), osc_is_shop_mode()]);
$mode('both');
pin('a stored both reads classifieds', 'classifieds', osc_site_mode());
pin('the modes are classifieds and shop', ['classifieds', 'shop'], SiteMode::ALL);
pin('both needs classifieds', ['classifieds'], SiteMode::needed('both'));
pin('shop is a valid mode', true, SiteMode::isValid('shop'));
pin('both is not a valid mode', false, SiteMode::isValid('both'));
$mode('nonsense');
pin('an unknown value reads classifieds', 'classifieds', osc_site_mode());

harness_section('posting');
$mode('classifieds');
pin('classifieds: a guest may post', ListingPolicy::ALLOWED, ListingPolicy::mayPost($guest, 'a@example.test'));
pin('classifieds: a user may post', ListingPolicy::ALLOWED, ListingPolicy::mayPost($user, 'a@example.test'));
$mode('shop');
pin('shop: a user is refused', ListingPolicy::SHOP_MODE, ListingPolicy::mayPost($user, 'a@example.test'));
pin('shop: a guest is refused', ListingPolicy::SHOP_MODE, ListingPolicy::mayPost($guest, 'a@example.test'));
pin('shop: an admin may post', ListingPolicy::ALLOWED, ListingPolicy::mayPost($root, 'a@example.test'));
$mode('both');
pin('a stored both posts as classifieds: a user may post', ListingPolicy::ALLOWED, ListingPolicy::mayPost($user, 'a@example.test'));
pin('a stored both posts as classifieds: a guest may post', ListingPolicy::ALLOWED, ListingPolicy::mayPost($guest, 'a@example.test'));
check('the refusal has a message', \mindstellar\listing\ListingService::refusalMessage(ListingPolicy::SHOP_MODE) !== '');

harness_section('packages that do not declare the mode');
$packages = ['Aisle' => ['shop'], 'Bender' => ['classifieds'], 'Both' => ['classifieds', 'shop'], 'Plain' => ['classifieds']];
pin('classifieds', ['Aisle'], SiteMode::lacking('classifieds', $packages));
pin('shop', ['Bender', 'Plain'], SiteMode::lacking('shop', $packages));
pin('a stored both needs classifieds', ['Aisle'], SiteMode::lacking('both', $packages));
pin('a package that declares the mode works with it', [true, false, false], [SiteMode::declares('shop', ['shop']), SiteMode::declares('shop', ['classifieds']), SiteMode::declares('classifieds', [])]);
pin('the mode in words', ['shop sites', 'classifieds sites'], [SiteMode::noun('shop'), SiteMode::noun('classifieds')]);
pin('nothing lacks when nothing is active', [], SiteMode::lacking('shop', []));

harness_section('the settings screen and the API');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
\mindstellar\admin\form\MainSettingsScreen::register();
$modeField = \mindstellar\settings\SettingsPageRegistry::getInstance()->fields(\mindstellar\admin\form\MainSettingsScreen::PAGE_ID)['site_mode'];
pin('the site type select offers two options', ['classifieds', 'shop'], array_keys($modeField['options']));
$siteEnum = \mindstellar\api\schema\Schema::components()['Site']['properties']['mode']['enum'];
pin('the API mode enum has two values', ['classifieds', 'shop'], $siteEnum);
$siteFeatures = static function (string $value) use ($mode): bool {
    $mode($value);

    return osc_site_mode() === SiteMode::SHOP;
};
check('features.shop is false in classifieds', !$siteFeatures('classifieds'));
check('features.shop is true in shop', $siteFeatures('shop'));
check('features.shop is false for a stored both', !$siteFeatures('both'));

harness_section('parent categories');
$admin->query("INSERT INTO {$p}t_category (fk_i_parent_id, i_expiration_days, i_position, b_enabled) VALUES (NULL, 0, 0, 1)");
$parent = (int) $admin->insert_id;
Preference::getInstance()->set('selectable_parent_categories', '0');
$mode('classifieds');
check('classifieds: the stored off is kept', !osc_selectable_parent_categories());
check('classifieds: a parent category is refused', !(new \mindstellar\utility\Validate())->category($parent));
$mode('shop');
check('shop: on with the stored value off', (bool) osc_selectable_parent_categories());
check('shop: a parent category is accepted', (new \mindstellar\utility\Validate())->category($parent));
Preference::getInstance()->set('selectable_parent_categories', '1');
$mode('classifieds');
check('classifieds: the stored on is kept', (bool) osc_selectable_parent_categories());
Preference::getInstance()->set('selectable_parent_categories', '0');
$admin->query("DELETE FROM {$p}t_category WHERE pk_i_id = {$parent}");

harness_section('the migration');
$run = static function () use ($p): void {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0077_site_mode.php')->up(\mindstellar\database\Connection::getInstance());
};
$row = static fn (): array => $admin->query("SELECT s_value, e_type FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = 'site_mode'")->fetch_all(MYSQLI_NUM);
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'site_mode'");
$run();
pin('adds the default', [['classifieds', 'STRING']], $row());
$run();
pin('runs again without a second row', [['classifieds', 'STRING']], $row());
$admin->query("UPDATE {$p}t_preference SET s_value = 'shop' WHERE s_name = 'site_mode'");
$run();
pin('keeps a value the admin chose', [['shop', 'STRING']], $row());

exit(harness_result());
