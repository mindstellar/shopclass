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

use mindstellar\auth\Actor;
use mindstellar\billing\Billing;
use mindstellar\billing\EntitlementStore;
use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Wallet;
use mindstellar\database\Db;
use mindstellar\job\JobQueue;
use mindstellar\job\JobWorker;
use mindstellar\listing\ListingHoldJobs;
use mindstellar\listing\ListingService;
use mindstellar\listing\ListingStatus;
use mindstellar\listing\ListingStore;
use mindstellar\mail\event\MailComposing;
use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = [])
    {
        return 'https://shop.test/index.php?page=billing&action=' . substr((string) $name, 8) . '&' . http_build_query($args);
    }
}

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
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'contactEmail' => 'owner@shop.test', 'date_format' => 'Y-m-d'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
require_once ABS_PATH . 'oc-includes/osclass/emails.php';
foreach (['osc_get_domain' => 'shop.test', 'osc_mailserver_name_from' => '', 'osc_mailserver_mail_from' => 'noreply@shop.test', 'osc_themes_path' => ABS_PATH . 'oc-content/themes/', 'osc_theme' => ''] as $fn => $value) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($value, true) . '; }');
    }
}

/** A mailer that keeps what it would have sent. */
final class HoldMailRecorder extends PHPMailer
{
    /** @var array<int,array<string,string>> */
    public static array $sent = [];

    public function send()
    {
        $to           = $this->getToAddresses();
        self::$sent[] = ['to' => (string) ($to[0][0] ?? ''), 'subject' => $this->Subject, 'body' => $this->Body];

        return true;
    }
}
osc_add_filter('init_send_mail', static fn ($mail) => new HoldMailRecorder(true));

$pageRow = static fn () => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_pages WHERE s_internal_name = 'email_listing_unpaid_reminder'")->fetch_row()[0];
$runReminder = static function (int $id) use ($clear, $due, $admin, $p): void {
    $clear();
    ListingHoldJobs::queue($id, 7);
    $admin->query("DELETE FROM {$p}t_job_queue WHERE s_type = '" . ListingHoldJobs::EXPIRE . "'");
    $due();
    JobWorker::resetRegistration();
    JobWorker::run(10);
};
$runMigration = static function () {
    $migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0082_listing_unpaid_reminder_mail.php';
    $migration->up(\mindstellar\database\Connection::getInstance());
};

$runMigration();
$userMail = 'remind@example.test';
$admin->query("UPDATE {$p}t_user SET s_email = '{$userMail}' WHERE pk_i_id = {$user}");
foreach ([Billing::PREF_ENABLED => 'BOOLEAN', 'billing_slot_enabled' => 'BOOLEAN'] as $k => $t) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', $t);
}
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_credits', '3', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_slot();

$other = seed_item($admin, seed_category($admin, 'Bikes'), $user, 'Unpaid <b>too</b>');
$hold($other);
$admin->query("UPDATE {$p}t_item SET dt_expiration = '2031-03-04 10:00:00' WHERE pk_i_id = {$other}");
$deadline = osc_format_date('2031-03-04 10:00:00');
HoldMailRecorder::$sent = [];
$runReminder($other);
pin('the reminder job ran and left the listing', true, $exists($other));
pin('one mail goes out', 1, count(HoldMailRecorder::$sent));
$mail = HoldMailRecorder::$sent[0] ?? ['to' => '', 'subject' => '', 'body' => ''];
pin('to the owner', $userMail, $mail['to']);
check('with the subject', str_contains($mail['subject'], 'Pay to publish'));
check('the pay link is the publish page for this listing', str_contains($mail['body'], 'page=billing') && str_contains($mail['body'], 'action=publish') && str_contains($mail['body'], 'itemId=' . $other));
$credits = ListingCheckout::quote($other, $user)['credits'];
check('it names the credits', $credits > 0 && str_contains($mail['body'], 'costs ' . $credits . ' credits'));
check('and the deadline', str_contains($mail['body'], $deadline));
check('a title with markup arrives escaped', str_contains($mail['body'], '&lt;b&gt;too&lt;/b&gt;') && !str_contains($mail['body'], '<b>too'));
pin('nothing is left waiting', 0, $queue->count(JobQueue::STATUS_PENDING));
pin('and nothing failed', 0, $queue->count(JobQueue::STATUS_ERROR));
JobWorker::run(10);
pin('running the worker again sends no second mail', 1, count(HoldMailRecorder::$sent));

HoldMailRecorder::$sent = [];
$paid = seed_item($admin, seed_category($admin, 'Skates'), $user, 'Paid already');
$runReminder($paid);
pin('a published listing gets no mail', 0, count(HoldMailRecorder::$sent));
$gone = seed_item($admin, seed_category($admin, 'Kayaks'), $user, 'Deleted soon');
$hold($gone);
ListingHoldJobs::queue($gone, 7);
$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = {$gone}");
$admin->query("DELETE FROM {$p}t_job_queue WHERE s_type = '" . ListingHoldJobs::EXPIRE . "'");
$due();
JobWorker::resetRegistration();
JobWorker::run(10);
pin('a deleted listing gets no mail', 0, count(HoldMailRecorder::$sent));
$stale = seed_item($admin, seed_category($admin, 'Canoes'), $user, 'Paid since');
$hold($stale);
$snapshot = $admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = {$stale}")->fetch_assoc();
\mindstellar\mail\CoreEmails::listingUnpaidReminder($snapshot);
pin('the mail itself sends for a held listing', 1, count(HoldMailRecorder::$sent));
HoldMailRecorder::$sent = [];
$admin->query("UPDATE {$p}t_item SET b_enabled = 1, b_active = 1, dt_first_pub_date = NOW() WHERE pk_i_id = {$stale}");
\mindstellar\mail\CoreEmails::listingUnpaidReminder($snapshot);
pin('and re-checks the hold: a held snapshot of a listing paid since gets no mail', 0, count(HoldMailRecorder::$sent));

$admin->query("UPDATE {$p}t_pages_description d JOIN {$p}t_pages g ON g.pk_i_id = d.fk_i_pages_id SET d.s_title = 'Please pay {ITEM_TITLE}' WHERE g.s_internal_name = 'email_listing_unpaid_reminder' AND d.fk_c_locale_code = 'en_US'");
HoldMailRecorder::$sent = [];
$runReminder($other);
pin('the admin\'s edited title reaches the mail', 'Please pay Unpaid too', trim(HoldMailRecorder::$sent[0]['subject'] ?? ''));

$admin->query("DELETE FROM {$p}t_pages WHERE s_internal_name = 'email_listing_unpaid_reminder'");
HoldMailRecorder::$sent = [];
$runReminder($other);
pin('with the template row gone, the default text still goes out', 1, count(HoldMailRecorder::$sent));
check('it is the default', str_contains(HoldMailRecorder::$sent[0]['subject'] ?? '', 'Pay to publish'));

$seen = [];
osc_add_filter('email_listing_unpaid_reminder_title', static function ($text, $item = null) use (&$seen) {
    $seen[] = $text;

    return 'Old filter: ' . $text;
}, 10, 2);
HoldMailRecorder::$sent = [];
$runReminder($other);
check('an old title filter runs', $seen !== [] && str_contains(HoldMailRecorder::$sent[0]['subject'] ?? '', 'Old filter'));

$off = static function (MailComposing $e): void {
    $e->suppress();
};
$dispatcher = \mindstellar\container\Container::root()->get(\mindstellar\event\Dispatcher::class);
$dispatcher->listen(MailComposing::class, $off);
HoldMailRecorder::$sent = [];
$runReminder($other);
pin('a listener that suppresses stops the mail', 0, count(HoldMailRecorder::$sent));
$dispatcher->remove(MailComposing::class, $off);
$clear();

harness_section('Migration 0082: the template row, by name');
pin('the row is gone', 0, $pageRow());
$runMigration();
pin('the migration adds it', 1, $pageRow());
$descCount = static fn () => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_pages_description d JOIN {$p}t_pages g ON g.pk_i_id = d.fk_i_pages_id WHERE g.s_internal_name = 'email_listing_unpaid_reminder'")->fetch_row()[0];
pin('with one en_US text', 1, $descCount());
$firstId = (int) $admin->query("SELECT pk_i_id FROM {$p}t_pages WHERE s_internal_name = 'email_listing_unpaid_reminder'")->fetch_row()[0];
$runMigration();
pin('a second run leaves one row', 1, $pageRow());
pin('and one text', 1, $descCount());
$admin->query("DELETE FROM {$p}t_pages WHERE pk_i_id = {$firstId}");
$admin->query("INSERT INTO {$p}t_pages (pk_i_id, s_internal_name, b_indelible, dt_pub_date) VALUES ({$firstId}, 'email_other', 1, NOW())");
$runMigration();
pin('an id taken by another page is not a problem: the row comes back with a new id', [1, true], [$pageRow(), (int) $admin->query("SELECT pk_i_id FROM {$p}t_pages WHERE s_internal_name = 'email_listing_unpaid_reminder'")->fetch_row()[0] !== $firstId]);
$clear();

harness_section('The daily sweep');
$clear();
$cat2   = seed_category($admin, 'Vans');
$lost   = seed_item($admin, $cat2, $user, 'Unpaid, its job lost');
$fresh  = seed_item($admin, $cat2, $user, 'Unpaid, still in time');
$old    = seed_item($admin, $cat2, $user, 'Live, long expired');
$raw    = seed_item($admin, $cat2, $user, 'Hidden by a plugin');
$hold($lost);
$hold($fresh);
$hold($raw);
ListingHoldJobs::queue($lost, 7);
ListingHoldJobs::queue($fresh, 7);
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE pk_i_id IN ({$lost}, {$old}, {$raw})");
ListingHoldJobs::queueSweep();
pin('cron_daily queues one sweep', 1, $count(ListingHoldJobs::SWEEP));
JobWorker::resetRegistration();
JobWorker::run(10);
check('a held listing past its deadline, its job not run yet, is gone', !$exists($lost));
check('a held listing still in time stays', $exists($fresh));
check('an expired live listing stays', $exists($old));
check('a hidden row with no delete job stays', $exists($raw));
pin('and nothing failed', 0, $queue->count(JobQueue::STATUS_ERROR));

harness_section('A row with no delete job and a delete job running');
$clear();
$mine = seed_item($admin, $cat2, $user, 'Unpaid, due now');
$hold($mine);
ListingHoldJobs::queue($mine, 1);
$due();
JobWorker::resetRegistration();
JobWorker::run(10);
check('the due delete removes its own listing', !$exists($mine));
check('and leaves the hidden row', $exists($raw));

harness_section('A delete job that could not delete');
$clear();
$stubborn = seed_item($admin, $cat2, $user, 'Unpaid, delete refused');
$hold($stubborn);
ListingHoldJobs::queue($stubborn, 1);
$due();
$refuse = static function (): void {
    throw new RuntimeException('refused');
};
osc_add_hook('before_delete_item', $refuse);
JobWorker::resetRegistration();
JobWorker::run(10);
osc_remove_hook('before_delete_item', $refuse);
check('the listing is still there', $exists($stubborn));
pin('a keyed delete job is queued again', [(string) $stubborn], array_column($admin->query("SELECT s_unique FROM {$p}t_job_queue WHERE s_type = '" . ListingHoldJobs::EXPIRE . "' AND s_status = 'pending'")->fetch_all(MYSQLI_ASSOC), 's_unique'));
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE pk_i_id = {$stubborn}");
ListingHoldJobs::sweep();
check('so the sweep deletes it', !$exists($stubborn));
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
ListingHoldJobs::queue($stuck, 7);
$status = static function (int $id) use ($admin, $p): string {
    return ListingStatus::of($admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_assoc());
};
$secret = (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = {$stuck}")->fetch_row()[0];
pin('the owner cannot activate it', null, (new ListingService())->activate($stuck));
pin('nor with its secret', null, (new ListingService())->activate($stuck, $secret));
pin('an admin enable does nothing', false, (new ListingService())->enable($stuck));
pin('it still waits for payment', ListingStatus::UNPAID, $status($stuck));
$draft = seed_item($admin, seed_category($admin, 'Rafts'), $user, 'Plugin draft');
$hold($draft);
pin('a row hidden the same way with no delete job is a plugin\'s: enable works', [true, '1'], [(new ListingService())->enable($draft), (string) $admin->query("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id = {$draft}")->fetch_row()[0]]);

harness_section('The delete and a payment at the same moment');
foreach ([Billing::PREF_ENABLED => 'BOOLEAN', 'billing_slot_enabled' => 'BOOLEAN'] as $k => $t) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', $t);
}
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_credits', '3', 'osclass', 'INTEGER');
osc_set_preference('billing_slot_quantity', '1', 'osclass', 'INTEGER');
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
osc_reset_preferences();
osc_register_billing_slot();
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$cat4   = seed_category($admin, 'Planes', null, 'en_US', 1, 30);

// The parent takes the seller lock, the listing lock or both, and pays; the child deletes at the
// deadline. With one lock only, the child must still wait, so each of its two locks is pinned.
$race = static function (string $mode, string $locks = 'both') use ($admin, $p, $cat4, $dbName, $hold, $clear, $due): array {
    static $n = 0;
    $n++;
    $clear();
    $uid  = seed_user($admin, 'racer' . $n, 'racer' . $n . '@example.test');
    seed_item($admin, $cat4, $uid, 'Live plane ' . $n);
    $held = seed_item($admin, $cat4, $uid, 'Held plane ' . $n);
    $hold($held);
    Wallet::credit($uid, 3, Wallet::REASON_GRANT);
    ListingHoldJobs::queue($held, 7);
    if ($mode === 'sweep') {
        $admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE pk_i_id = {$held}");
    } else {
        $due();
    }
    $child = null;
    $pipes = [];
    try {
        $out = Db::transaction(static function () use ($admin, $p, $uid, $held, $mode, $locks, $dbName, &$child, &$pipes): array {
            if ($locks !== 'row') {
                EntitlementStore::lockSeller($uid);
            }
            if ($locks !== 'seller') {
                ListingStore::find($held, ['*'], true);
            }
            $child = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../lib/listing-expire-child.php', $dbName, (string) $held, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            usleep(300000);
            // Pay only once the child is stuck on a lock, so a slow start cannot hide a missing lock.
            $waiting = false;
            for ($i = 0; $i < 30 && !$waiting; $i++) {
                $waiting = (int) $admin->query("SELECT COUNT(*) FROM information_schema.INNODB_TRX t JOIN information_schema.PROCESSLIST l ON l.ID = t.trx_mysql_thread_id WHERE t.trx_state = 'LOCK WAIT' AND l.DB = '{$dbName}'")->fetch_row()[0] > 0;
                if (!$waiting) {
                    usleep(100000);
                }
            }
            if ($locks === 'row') {
                // Taking the seller lock now would invert the order, so the payment is written directly.
                Db::execute("UPDATE {$p}t_item SET b_enabled = 1, b_active = 1, dt_first_pub_date = NOW() WHERE pk_i_id = ?", [$held]);
                $paid = true;
            } else {
                try {
                    $paid = ListingCheckout::publish($held, Actor::user($uid))['published'];
                } catch (Throwable $e) {
                    $paid = false;
                }
            }

            return [$waiting, $paid];
        });
    } catch (Throwable $e) {
        // A deadlock rolls the payment back: report it as not paid.
        $out = [false, false];
    }
    [$waiting, $published] = $out;
    $printed = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    proc_close($child);
    $row    = $admin->query("SELECT b_enabled, b_active, dt_first_pub_date FROM {$p}t_item WHERE pk_i_id = {$held}")->fetch_assoc();
    $debits = (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_ledger WHERE fk_i_user_id = {$uid} AND s_ref_type = 'item'")->fetch_row()[0];

    return [
        'waited'  => $waiting,
        'paid'    => $published,
        'child'   => $printed,
        'live'    => $row !== null && $row['b_enabled'] === '1' && $row['b_active'] === '1' && $row['dt_first_pub_date'] !== null,
        'debits'  => $debits,
    ];
};

$r = $race('expire');
pin('the delete job waits for the payment', [true, true], [$r['waited'], $r['paid']]);
pin('then keeps the listing it finds paid', 'kept', $r['child']);
pin('which is live with one debit', [true, 1], [$r['live'], $r['debits']]);
pin('and the job did not fail', 0, $queue->count(JobQueue::STATUS_ERROR));

$r = $race('sweep');
pin('the sweep waits for the payment', [true, true], [$r['waited'], $r['paid']]);
pin('then keeps the listing it finds paid', 'kept', $r['child']);
pin('which is live with one debit', [true, 1], [$r['live'], $r['debits']]);

foreach (['expire', 'sweep'] as $mode) {
    $r = $race($mode, 'seller');
    pin($mode . ': the seller lock alone makes the delete wait', [true, true], [$r['waited'], $r['paid']]);
    pin($mode . ': and the paid listing is kept', ['kept', true], [$r['child'], $r['live']]);
    $r = $race($mode, 'row');
    pin($mode . ': the listing lock alone makes the delete wait', true, $r['waited']);
    pin($mode . ': and the paid listing is kept', ['kept', true], [$r['child'], $r['live']]);
}
pin('no delete job failed', 0, $queue->count(JobQueue::STATUS_ERROR));

harness_section('The delete and a payment starting together');
// Both wait on the parent's seller lock, the payment first. A delete that took the listing lock
// before the seller lock would deadlock with the payment once the parent lets go.
$clear();
$uid  = seed_user($admin, 'together', 'together@example.test');
seed_item($admin, $cat4, $uid, 'Live together');
$held = seed_item($admin, $cat4, $uid, 'Held together');
$hold($held);
ListingHoldJobs::queue($held, 7);
Wallet::credit($uid, 3, Wallet::REASON_GRANT);
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE pk_i_id = {$held}");
$admin->begin_transaction();
$admin->query("SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = {$uid} FOR UPDATE");
$payer = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../lib/listing-publish-child.php', $dbName, (string) $held, (string) $uid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $payPipes);
usleep(800000);
$deleter = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../lib/listing-expire-child.php', $dbName, (string) $held, 'sweep'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $delPipes);
usleep(800000);
$bothWait = proc_get_status($payer)['running'] && proc_get_status($deleter)['running'];
$admin->commit();
$paidOut = trim(stream_get_contents($payPipes[1]) . stream_get_contents($payPipes[2]));
$delOut  = trim(stream_get_contents($delPipes[1]) . stream_get_contents($delPipes[2]));
proc_close($payer);
proc_close($deleter);
pin('both waited', true, $bothWait);
pin('the payment publishes and the delete keeps it, with no deadlock', ['published', 'kept'], [$paidOut, $delOut]);
$clear();

harness_section('Migration 0081: rows a plugin wrote without a first publish date');
$clear();
$cat    = seed_category($admin, 'Legacy');
$legacy = seed_item($admin, $cat, $user, 'Legacy hidden');
$hold($legacy);
$queued = seed_item($admin, $cat, $user, 'Really held');
$hold($queued);
ListingHoldJobs::queue($queued, 7);
$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0081_item_first_pub_date_backfill.php';
$migration->up(\mindstellar\database\Connection::getInstance());
$first = static fn (int $id) => $admin->query("SELECT dt_first_pub_date FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_row()[0];
check('a hidden legacy row gets a first publish date, so it is not held', $first($legacy) !== null);
pin('a row with a queued hold delete stays held', null, $first($queued));
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE pk_i_id IN ({$legacy}, {$queued})");
ListingHoldJobs::sweep();
pin('the sweep keeps the legacy row and deletes the held one', [true, false], [$exists($legacy), $exists($queued)]);
$migration->up(\mindstellar\database\Connection::getInstance());
pin('running again changes nothing', true, $first($legacy) !== null);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
