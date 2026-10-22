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

harness_section('messaging threads');
// functions.php needs the full boot, so the real function is lifted out of it and registered here.
$fn = file_get_contents(__DIR__ . '/../../oc-includes/osclass/functions.php');
preg_match('/^function osc_messaging_personal_data\(.*?^}\n/ms', $fn, $lift);
check('the export function is in functions.php', isset($lift[0]));
eval($lift[0]);
osc_add_filter('personal_data_export', 'osc_messaging_personal_data');
if (!function_exists('osc_item_url_from_item')) {
    function osc_item_url_from_item($item, $locale = '')
    {
        return WEB_PATH . 'item/' . $item['pk_i_id'];
    }
}
$bea = seed_user($admin, 'bea', 'bea@example.test');
$sam = seed_user($admin, 'sam', 'sam@example.test');
$cat = seed_category($admin);
$bike = seed_item($admin, $cat, $sam, 'Bike');
$lamp = seed_item($admin, $cat, $sue, 'Lamp');
$store = new \mindstellar\messaging\ConversationStore();
$msgs  = new \mindstellar\messaging\MessageStore();
$now   = '2026-01-01 10:00:00';
$mk = static function (int $item, int $buyer, int $seller) use ($store, $now): int {
    return $store->create(array(
        'fk_i_item_id' => $item, 'fk_i_buyer_id' => $buyer, 'fk_i_seller_id' => $seller,
        's_state' => 'open', 'dt_created' => $now, 'dt_last_message' => $now,
    ));
};
$say = static function (int $conv, int $from, string $text) use ($msgs, $now): void {
    $msgs->create(array('fk_i_conversation_id' => $conv, 'fk_i_sender_id' => $from, 's_body' => $text, 's_state' => 'sent', 'dt_created' => $now));
};
$thread = $mk($bike, $bea, $sam);
$say($thread, $bea, 'buyer text');
$say($thread, $sam, 'seller text');
$other = $mk($lamp, $bea, $sue);
$say($other, $sue, 'sue text');
$ola = seed_user($admin, 'ola', 'ola@example.test');
$kept = $mk($lamp, $ola, $sue);
$say($kept, $ola, 'ola text');

$mine = static function (array $out): array {
    return $out['data']['messaging']['rows'] ?? array();
};
$bodies = static fn (array $rows): array => array_column($rows['messages'] ?? array(), 's_body');
$outBea = PersonalData::export($bea);
pin('the buyer sees both threads', 2, count($mine($outBea)['conversations'] ?? array()));
pin('the buyer gets only their own text', array('buyer text'), $bodies($mine($outBea)));
$outSam = PersonalData::export($sam);
pin('the seller gets one thread', 1, count($mine($outSam)['conversations'] ?? array()));
pin('the seller gets only their own text', array('seller text'), $bodies($mine($outSam)));
pin('the section says it is erased with the account', PersonalData::ERASE_CASCADE, $outBea['data']['messaging']['retention'] ?? null);
check('the two tables are no longer listed as not included', !isset($outBea['not_included']['t_conversation']) && !isset($outBea['not_included']['t_message']));

harness_section('deleting the buyer erases their threads');
\User::getInstance()->deleteUser($bea);
$c = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];
pin('no threads left for the buyer', 0, $c('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_conversation WHERE fk_i_buyer_id = ' . $bea));
pin('another buyer\'s thread survives', 1, $c('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_conversation WHERE pk_i_id = ' . $kept));
pin('and its message', 1, $c('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_message WHERE fk_i_conversation_id = ' . $kept));
pin('no messages left in the thread', 0, $c('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . 't_message WHERE fk_i_conversation_id IN (' . $thread . ',' . $other . ')'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
