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

harness_section('Queueing');
$clear();
ListingHoldJobs::queue(11, 7);
pin('seven days queue the delete', 1, $count(ListingHoldJobs::EXPIRE));
pin('and the reminder', 1, $count(ListingHoldJobs::REMIND));
ListingHoldJobs::queue(11, 7);
pin('queueing again leaves one delete', 1, $count(ListingHoldJobs::EXPIRE));
pin('and one reminder', 1, $count(ListingHoldJobs::REMIND));
$clear();
ListingHoldJobs::queue(12, 1);
pin('one day queues only the delete', 1, $count(ListingHoldJobs::EXPIRE));
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
