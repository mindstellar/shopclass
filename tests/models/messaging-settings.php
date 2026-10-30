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
 * The messaging switch, its helpers and its two rate limits.
 *
 * Usage:  php tests/models/messaging-settings.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-settings  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\form\SpamSettingsScreen;
use mindstellar\admin\form\UserSettingsScreen;
use mindstellar\auth\Actor;
use mindstellar\security\ActionThrottle;
use mindstellar\settings\SettingsPageRegistry;

$admin = api_admin_boot('osc_models_messaging_settings');
scratchdb_pin_site_mode($admin, 'classifieds');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};

harness_section('osc_messaging_enabled');
$set('enabled_users', '1');
check(
    'a fresh install ships the switch off',
    str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), "('osclass', 'enabled_messaging', '0', 'BOOLEAN')")
);
pin('off when the preference is unset', false, osc_messaging_enabled());
$set('enabled_messaging', '0');
pin('off when the preference is 0', false, osc_messaging_enabled());
$set('enabled_messaging', '1');
pin('on when the preference is 1', true, osc_messaging_enabled());
$set('enabled_users', '0');
pin('off when users are disabled', false, osc_messaging_enabled());
$set('enabled_users', '1');
pin('the thread link is the messages_thread route', WEB_PATH . 'route/messages_thread', osc_messaging_thread_url(7));
pin('the inbox link is the messages route', WEB_PATH . 'route/messages', osc_messaging_inbox_url());

harness_section('unread count, menu and titles');
$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$cat    = seed_category($admin);
$bike   = seed_item($admin, $cat, $seller, 'Bike');
$signIn = static function (int $userId): void {
    Session::getInstance()->_setEphemeral('userId', (string) $userId);
    View::getInstance()->_erase('_loggedUser');
};
$signOut = static function (): void {
    Session::getInstance()->_dropEphemeral('userId');
    Session::getInstance()->_drop('userId');
    View::getInstance()->_erase('_loggedUser');
};
$menu = static function (): array {
    return array_column((array) osc_apply_filter('user_menu_filter', array()), 'name', 'class');
};
$set('enabled_messaging', '1');
pin('the count is 0 for a guest', 0, osc_messaging_unread_count());
pin('and the menu has no Messages entry', false, isset($menu()['opt_messages']));
harness_reset_container();
\mindstellar\container\Container::root()->get(\mindstellar\messaging\MessagingService::class)->start($bike, 'Hello', Actor::user($buyer, '10.0.0.1'));
$signIn($seller);
$service = \mindstellar\container\Container::root()->get(\mindstellar\messaging\MessagingService::class);
pin('the count matches the service for a seeded thread', array(1, 1), array($service->unreadCount($seller), osc_messaging_unread_count()));
pin('the menu entry carries the count', 'Messages (1)', $menu()['opt_messages'] ?? null);
$set('enabled_messaging', '0');
pin('the count is 0 while messaging is off', 0, osc_messaging_unread_count());
pin('and the menu entry is gone', false, isset($menu()['opt_messages']));
$set('enabled_messaging', '1');
$signIn($buyer);
pin('the buyer has nothing unread, so the entry has no count', 'Messages', $menu()['opt_messages'] ?? null);
$set('site_mode', 'shop');
pin('a shopper gets no Messages entry', false, isset($menu()['opt_messages']));
pin('and no messages page', true, osc_shopper_page_hidden('messages', ''));
$set('site_mode', 'classifieds');
$signOut();

$admin->query('INSERT INTO ' . DB_TABLE_PREFIX . "t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES ($bike, 'es_ES', 'Bici', 'x')");
$lamp = seed_item($admin, $cat, $seller, 'Lamp');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . "t_item_description WHERE fk_i_item_id = $lamp");
$admin->query('INSERT INTO ' . DB_TABLE_PREFIX . "t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES ($lamp, 'fr_FR', 'Lampe', 'x')");
pin('titles come in the asked locale', array($bike => 'Bici', $lamp => 'Lampe'), \mindstellar\listing\ListingStore::titles(array($bike, $lamp), 'es_ES'));
pin('and fall back to another locale', array($bike => 'Bike', $lamp => 'Lampe'), \mindstellar\listing\ListingStore::titles(array($bike, $lamp), 'en_US'));
pin('an unknown id and an empty list give nothing', array(array(), array()), array(\mindstellar\listing\ListingStore::titles(array(999999)), \mindstellar\listing\ListingStore::titles(array())));

harness_section('migration 0086');
$prefs = static function () use ($admin): array {
    $res = $admin->query("SELECT s_name, s_value FROM " . DB_TABLE_PREFIX . "t_preference WHERE s_name IN ('rewrite_messages', 'rewrite_messages_thread') ORDER BY s_name");
    $out = array();
    while ($row = $res->fetch_row()) {
        $out[$row[0]] = $row[1];
    }

    return $out;
};
$admin->query("DELETE FROM " . DB_TABLE_PREFIX . "t_preference WHERE s_name IN ('rewrite_messages', 'rewrite_messages_thread')");
$run = static fn () => (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0086_messaging_routes.php')->up(\mindstellar\database\Connection::getInstance());
$run();
pin('it adds both paths', array('rewrite_messages' => 'user/messages', 'rewrite_messages_thread' => 'user/messages'), $prefs());
$admin->query("UPDATE " . DB_TABLE_PREFIX . "t_preference SET s_value = 'inbox' WHERE s_name = 'rewrite_messages'");
$run();
pin('a re-run keeps an edited path', 'inbox', $prefs()['rewrite_messages']);
check(
    'a fresh install ships both paths',
    str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), "('osclass', 'rewrite_messages', 'user/messages', 'STRING')")
        && str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), "('osclass', 'rewrite_messages_thread', 'user/messages', 'STRING')")
);

harness_section('limits');
pin('send limit default', 30, ActionThrottle::DEFAULT_LIMITS['message_send'] ?? null);
pin('start limit default', 10, ActionThrottle::DEFAULT_LIMITS['message_start'] ?? null);
pin('limitFor falls back to the default', 30, ActionThrottle::limitFor('message_send')[0]);
$set('throttle_message_send', '5');
pin('limitFor reads the stored preference', 5, ActionThrottle::limitFor('message_send')[0]);

harness_section('settings screens');
$limits = SpamSettingsScreen::registerLimits();
$fields = array_keys(SettingsPageRegistry::getInstance()->fields($limits));
check('the limits page lists the send limit', in_array('throttle_message_send', $fields, true));
check('and the start limit', in_array('throttle_message_start', $fields, true));
$users = array_keys(SettingsPageRegistry::getInstance()->fields(UserSettingsScreen::register()));
check('the users page lists the switch', in_array('enabled_messaging', $users, true));

exit(harness_result());
