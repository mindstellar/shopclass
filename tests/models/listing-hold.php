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
 * The jobs for a listing saved and not yet paid for: the delete at the deadline and the
 * reminder a day before.
 *
 * Usage:  php tests/models/listing-hold.php       (standalone, own scratch database)
 *         php tests/run-models.php listing-hold   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\job\JobQueue;
use mindstellar\job\JobWorker;
use mindstellar\listing\ListingHoldJobs;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingStatus;

$admin = api_admin_boot('osc_models_listing_hold');
$p     = DB_TABLE_PREFIX;
seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$user  = seed_user($admin);
$queue = JobQueue::getInstance();

$count = static function (string $type) use ($admin, $p): int {
    $r = $admin->query("SELECT COUNT(*) AS c FROM {$p}t_job_queue WHERE s_type = '{$type}'")->fetch_assoc();

    return (int) $r['c'];
};
$exists = static function (int $id) use ($admin, $p): bool {
    return (int) $admin->query("SELECT COUNT(*) AS c FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_assoc()['c'] === 1;
};
$hold = static function (int $id) use ($admin, $p): void {
    $admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL WHERE pk_i_id = {$id}");
};
$clear = static function () use ($admin, $p): void {
    $admin->query("DELETE FROM {$p}t_job_queue");
};
$due = static function () use ($admin, $p): void {
    $admin->query("UPDATE {$p}t_job_queue SET dt_next_run = DATE_SUB(NOW(), INTERVAL 1 DAY)");
};

$runsIn = static function (string $type, int $item) use ($admin, $p): float {
    $at = $admin->query("SELECT UNIX_TIMESTAMP(dt_next_run) - UNIX_TIMESTAMP() FROM {$p}t_job_queue WHERE s_type = '{$type}' AND s_unique = '{$item}'")->fetch_row()[0] ?? -86400;

    return round((int) $at / 86400, 1);
};

harness_section('Queueing');
$clear();
ListingHoldJobs::queue(11, 7);
pin('seven days queue the delete', 1, $count(ListingHoldJobs::EXPIRE));
pin('and the reminder', 1, $count(ListingHoldJobs::REMIND));
pin('the delete runs in seven days', 7.0, $runsIn(ListingHoldJobs::EXPIRE, 11));
pin('the reminder a day earlier', 6.0, $runsIn(ListingHoldJobs::REMIND, 11));
ListingHoldJobs::queue(11, 7);
pin('queueing again leaves one delete', 1, $count(ListingHoldJobs::EXPIRE));
pin('and one reminder', 1, $count(ListingHoldJobs::REMIND));
$clear();
ListingHoldJobs::queue(12, 1);
pin('one day queues only the delete', 1, $count(ListingHoldJobs::EXPIRE));
pin('and it runs in one day', 1.0, $runsIn(ListingHoldJobs::EXPIRE, 12));
pin('and no reminder', 0, $count(ListingHoldJobs::REMIND));
$clear();

harness_section('The delete');
$cat  = seed_category($admin, 'Cars');
$held = seed_item($admin, $cat, $user, 'Unpaid');
$live = seed_item($admin, $cat, $user, 'Live');
$late = seed_item($admin, $cat, $user, 'Paid meanwhile');
$hold($held);
$hold($late);
ListingHoldJobs::queue($held, 7);
ListingHoldJobs::queue($live, 7);
ListingHoldJobs::queue($late, 7);
ListingHoldJobs::queue(999999, 7);
$admin->query("UPDATE {$p}t_item SET dt_first_pub_date = NOW() WHERE pk_i_id = {$late}");
$due();
JobWorker::resetRegistration();
JobWorker::run(10);
check('the held listing is gone', !$exists($held));
pin('with its description', 0, (int) $admin->query("SELECT COUNT(*) AS c FROM {$p}t_item_description WHERE fk_i_item_id = {$held}")->fetch_assoc()['c']);
check('a live listing stays', $exists($live));
check('a listing published meanwhile stays', $exists($late));
pin('nothing is left waiting', 0, $queue->count(JobQueue::STATUS_PENDING));
pin('and nothing failed', 0, $queue->count(JobQueue::STATUS_ERROR));

harness_section('The reminder');
$clear();
$other = seed_item($admin, seed_category($admin, 'Bikes'), $user, 'Unpaid too');
$hold($other);
ListingHoldJobs::queue($other, 7);
$admin->query("DELETE FROM {$p}t_job_queue WHERE s_type = '" . ListingHoldJobs::EXPIRE . "'");
$due();
JobWorker::resetRegistration();
JobWorker::run(10);
pin('the reminder job ran and left the listing', true, $exists($other));
pin('nothing is left waiting', 0, $queue->count(JobQueue::STATUS_PENDING));
pin('and nothing failed', 0, $queue->count(JobQueue::STATUS_ERROR));
$clear();

harness_section('The daily sweep');
$clear();
$cat2   = seed_category($admin, 'Vans');
$lost   = seed_item($admin, $cat2, $user, 'Unpaid, its job lost');
$fresh  = seed_item($admin, $cat2, $user, 'Unpaid, still in time');
$old    = seed_item($admin, $cat2, $user, 'Live, long expired');
$hold($lost);
$hold($fresh);
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE pk_i_id IN ({$lost}, {$old})");
ListingHoldJobs::queueSweep();
pin('cron_daily queues one sweep', 1, $count(ListingHoldJobs::SWEEP));
JobWorker::resetRegistration();
JobWorker::run(10);
check('a held listing past its deadline with no job is gone', !$exists($lost));
check('a held listing still in time stays', $exists($fresh));
check('an expired live listing stays', $exists($old));
pin('and nothing failed', 0, $queue->count(JobQueue::STATUS_ERROR));
$clear();

harness_section('Status filters against rows');
$cat3     = seed_category($admin, 'Trucks');
$sHeld    = seed_item($admin, $cat3, $user, 'Held truck');
$sBlocked = seed_item($admin, $cat3, $user, 'Blocked truck', 19.50, 0, 0);
$sPending = seed_item($admin, $cat3, $user, 'Pending truck', 19.50, 0, 1);
$hold($sHeld);
$ids = static function (string $status) use ($cat3): array {
    $rows = ListingStatus::condition(\mindstellar\database\Db::table(DB_TABLE_PREFIX . 't_item')->select('pk_i_id'), [$status])
        ->where('fk_i_category_id', $cat3)->orderBy('pk_i_id')->get();

    return array_map(static fn (array $r): int => (int) $r['pk_i_id'], $rows);
};
pin('unpaid finds only the held row', [$sHeld], $ids(ListingStatus::UNPAID));
pin('disabled finds the blocked row, not the held one', [$sBlocked], $ids(ListingStatus::DISABLED));
pin('pending finds the waiting row, not the held one', [$sPending], $ids(ListingStatus::PENDING));

harness_section('Activate and enable leave it held');
$stuck = seed_item($admin, seed_category($admin, 'Boats'), $user, 'Unpaid boat');
$hold($stuck);
$status = static function (int $id) use ($admin, $p): string {
    return ListingStatus::of($admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_assoc());
};
$secret = (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = {$stuck}")->fetch_row()[0];
pin('the owner cannot activate it', null, (new ListingService())->activate($stuck));
pin('nor with its secret', null, (new ListingService())->activate($stuck, $secret));
pin('an admin enable does nothing', false, (new ListingService())->enable($stuck));
pin('it still waits for payment', ListingStatus::UNPAID, $status($stuck));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
