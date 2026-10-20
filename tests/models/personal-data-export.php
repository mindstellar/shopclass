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
 * A person's data export holds core's tables, and a plugin adds its own through the
 * `personal_data_export` filter; a filter that returns junk or throws cannot empty the export.
 *
 * Usage:  php tests/models/personal-data-export.php       (standalone, own scratch database)
 *         php tests/run-models.php personal-data-export   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\privacy\PersonalData;

$admin = api_admin_boot('osc_models_personal_data_export');
seed_locale($admin);
$sue = seed_user($admin, 'sue', 'sue@example.test');

harness_section('the export');
$out = PersonalData::export($sue);
check('holds the account', isset($out['data']['t_user']['rows']));
pin('an unknown account has none', null, PersonalData::export(999999));

harness_section('a plugin adds its tables');
$seen = null;
osc_add_filter('personal_data_export', $add = static function (array $out, int $userId) use (&$seen): array {
    $seen                           = $userId;
    $out['data']['demo_bookings'] = array('retention' => PersonalData::ERASE_DELETED, 'why' => 'Bookings they made.', 'rows' => array(array('id' => 1)));

    return $out;
});
$out = PersonalData::export($sue);
pin('the filter is given the account id', $sue, $seen);
pin('its section is in the export', array(array('id' => 1)), $out['data']['demo_bookings']['rows'] ?? null);
check('core sections stay', isset($out['data']['t_user']));
osc_remove_filter('personal_data_export', $add);

osc_add_filter('personal_data_export', $junk = static fn () => null);
check('a filter that returns junk leaves the export whole', isset(PersonalData::export($sue)['data']['t_user']));
osc_remove_filter('personal_data_export', $junk);

osc_add_filter('personal_data_export', $broken = static function (): array {
    throw new RuntimeException('plugin bug');
});
$logged = ini_set('error_log', '/dev/null');
check('a filter that throws leaves the export whole', isset(PersonalData::export($sue)['data']['t_user']));
ini_set('error_log', (string) $logged);
osc_remove_filter('personal_data_export', $broken);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
