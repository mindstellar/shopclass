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
use mindstellar\security\ActionThrottle;
use mindstellar\settings\SettingsPageRegistry;

$admin = api_admin_boot('osc_models_messaging_settings');
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
pin('the thread link', 'http://localhost/index.php?page=messages&id=7', osc_messaging_thread_url(7));

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
