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
 * The "new message" e-mail: one delayed job per conversation and recipient, queued after the
 * commit, a mail with a link and no message text, and no mail when the thread was read,
 * blocked or closed before the job ran.
 *
 * Usage:  php tests/models/messaging-mail.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-mail  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\job\JobWorker;
use mindstellar\mail\event\MailComposing;
use mindstellar\messaging\MessagingService;

$GLOBALS['sent_mail'] = array();

$admin = api_admin_boot('osc_models_messaging_mail');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
$p = DB_TABLE_PREFIX;
if (!function_exists('osc_themes_path')) {
    function osc_themes_path()
    {
        return ABS_PATH . 'oc-content/themes/';
    }
}
if (!function_exists('osc_get_domain')) {
    function osc_get_domain()
    {
        return 'example.test';
    }
}

// The real mailer, swapped for one that records what it was asked to send.
final class RecordingMailer extends \PHPMailer\PHPMailer\PHPMailer
{
    public function send()
    {
        $GLOBALS['sent_mail'][] = array('to' => $this->getToAddresses()[0][0] ?? '', 'subject' => $this->Subject, 'body' => $this->Body);

        return true;
    }
}
osc_add_filter('init_send_mail', static fn () => new RecordingMailer(true));

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$count = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_row()[0];
};
$col = static function (string $sql) use ($admin) {
    return $admin->query($sql)->fetch_row()[0];
};
/** Make every queued job due and run the worker; returns the mails it sent. */
$runJobs = static function () use ($admin, $p): array {
    $GLOBALS['sent_mail'] = array();
    $admin->query("UPDATE {$p}t_job_queue SET dt_next_run = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE s_status = 'pending'");
    JobWorker::resetRegistration();
    JobWorker::run(10);

    return $GLOBALS['sent_mail'];
};
$fresh = static function (): MessagingService {
    harness_reset_container();

    return Container::root()->get(MessagingService::class);
};

$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$cat    = seed_category($admin);
$bike   = seed_item($admin, $cat, $seller, 'Bike');
$lamp   = seed_item($admin, $cat, $seller, 'Lamp');
$vase   = seed_item($admin, $cat, $seller, 'Vase');
$rope   = seed_item($admin, $cat, $seller, 'Rope');
$asBuyer  = Actor::user($buyer, '10.0.0.1');
$asSeller = Actor::user($seller, '10.0.0.2');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');
$svc = $fresh();
$job = "SELECT %s FROM {$p}t_job_queue WHERE s_type = 'message.notify'";

harness_section('the job is queued once, after the commit');
$during = null;
$sent   = null;
Db::transaction(static function () use ($svc, $bike, $asBuyer, $count, $p, &$during, &$sent): void {
    $sent   = $svc->start($bike, 'SECRET-TEXT is the bike free?', $asBuyer);
    $during = $count("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.notify'");
});
pin('nothing is queued while the outer transaction is open', 0, $during);
pin('one job after the commit', 1, $count(sprintf($job, 'COUNT(*)')));
$conv = $sent->conversationId();
pin('keyed on conversation and recipient', "$conv:$seller", $col(sprintf($job, 's_unique')));
$delay = $count("SELECT TIMESTAMPDIFF(SECOND, NOW(), dt_next_run) FROM {$p}t_job_queue WHERE s_type = 'message.notify'");
pin('due about 60 seconds out', true, $delay >= 55 && $delay <= 61);
$svc->start($bike, 'Hello again', $asBuyer);
pin('a second message in the delay keeps one job', 1, $count(sprintf($job, 'COUNT(*)')));
pin('payload names the thread and the recipient', array('conversation' => $conv, 'recipient' => $seller), json_decode((string) $col(sprintf($job, 's_payload')), true));

harness_section('the mail');
$mails = $runJobs();
pin('one mail for the burst', 1, count($mails));
pin('it goes to the seller', 'sam@example.test', $mails[0]['to'] ?? null);
pin('the subject holds the listing title', true, str_contains((string) ($mails[0]['subject'] ?? ''), 'Bike'));
pin('the body holds the thread link', true, str_contains((string) ($mails[0]['body'] ?? ''), osc_esc_html(osc_messaging_thread_url($conv))));
pin('the body holds no message text', false, str_contains((string) ($mails[0]['body'] ?? '') . ($mails[0]['subject'] ?? ''), 'SECRET-TEXT') || str_contains((string) ($mails[0]['body'] ?? ''), 'Hello again'));
pin('the job is done', 0, $count(sprintf($job, 'COUNT(*)') . " AND s_status = 'pending'"));

harness_section('old and typed listeners');
$titleFilter = static fn (string $text, $conversation, $recipient): string => 'Hey ' . $recipient['s_username'] . ' ' . $conversation['pk_i_id'] . ' ' . $text;
osc_add_filter('email_new_message_title', $titleFilter);
$svc->start($lamp, 'Lamp ok?', $asBuyer);
$mails = $runJobs();
pin('an old title callback gets the text, conversation and user', true, str_starts_with((string) ($mails[0]['subject'] ?? ''), 'Hey sam '));
osc_remove_filter('email_new_message_title', $titleFilter);
$svc = $fresh();
Container::root()->get(\mindstellar\event\DispatcherInterface::class)->listen(MailComposing::class, static function (MailComposing $m): void {
    if ($m->kind() === 'new_message') {
        $m->suppress();
    }
});
$svc->start($vase, 'Vase ok?', $asBuyer);
pin('a suppressing listener sends nothing', 0, count($runJobs()));

harness_section('no mail when it no longer matters');
$svc = $fresh();
$a = $svc->start($rope, 'Rope?', $asBuyer);
$svc->markRead($a->conversationId(), $asSeller);
pin('read before the job runs: no mail', 0, count($runJobs()));
$cord = seed_item($admin, $cat, $seller, 'Cord');
$b = $svc->start($cord, 'Cord?', $asBuyer);
$svc->block($b->conversationId(), $asBuyer);
pin('blocked before the job runs: no mail', 0, count($runJobs()));
$twine = seed_item($admin, $cat, $seller, 'Twine');
$c = $svc->start($twine, 'Twine?', $asBuyer);
$admin->query("UPDATE {$p}t_conversation SET s_state = 'closed' WHERE pk_i_id = " . $c->conversationId());
pin('closed before the job runs: no mail', 0, count($runJobs()));
$chain = seed_item($admin, $cat, $seller, 'Chain');
$d = $svc->start($chain, 'Chain?', $asBuyer);
$admin->query("DELETE FROM {$p}t_item WHERE pk_i_id = $chain");
$admin->query("DELETE FROM {$p}t_item_description WHERE fk_i_item_id = $chain");
$mails = $runJobs();
pin('listing deleted before the job runs: mail still goes', 1, count($mails));
pin('with the removed-listing title', true, str_contains((string) ($mails[0]['subject'] ?? ''), 'a removed listing'));

harness_section('start to reply through the container');
$svc   = $fresh();
$shelf = seed_item($admin, $cat, $seller, 'Shelf');
$e     = $svc->start($shelf, 'Shelf?', $asBuyer);
pin('the seller sees it in the inbox', true, in_array($e->conversationId(), array_map(static fn (array $r): int => (int) $r['pk_i_id'], $svc->inbox($asSeller)), true));
pin('one mail', 1, count($runJobs()));
$svc->reply($e->conversationId(), 'Yes it is', $asSeller);
pin('the buyer has one unread', 1, $svc->unreadCount($buyer));
$mails = $runJobs();
pin('and one mail, to the buyer', array(1, 'bea@example.test'), array(count($mails), $mails[0]['to'] ?? null));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
