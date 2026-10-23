<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The browser wake-up: one short delayed job per recipient, queued after the commit, folded
 * for a burst, skipped for held messages and for push switched off; the job sends nothing
 * when the messages were read, drops browsers that are gone and retries only those that asked.
 *
 * Usage:  php tests/models/messaging-push.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-push  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\database\Db;
use mindstellar\event\DispatcherInterface;
use mindstellar\http\HttpClient;
use mindstellar\http\HttpException;
use mindstellar\job\Job;
use mindstellar\job\JobWorker;
use mindstellar\messaging\ConversationStore;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\event\MessageSent;
use mindstellar\messaging\MessagingService;
use mindstellar\messaging\PushNotifier;
use mindstellar\moderation\MessageModeration;
use mindstellar\push\PushSubscriptions;
use mindstellar\push\WebPushSender;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;

$GLOBALS['mpBase'] = 'http://localhost/';
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return $GLOBALS['mpBase'] . ($with_index ? 'index.php' : '');
    }
}

$admin = api_admin_boot('osc_models_messaging_push');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
$p = DB_TABLE_PREFIX;

/** Answers each push address with the status set for it (201 when none) and records the calls. */
final class MpHttp implements HttpClient
{
    /** @var array<string,int> */
    public array $status = array();
    /** @var string[] */
    public array $calls = array();

    public function send(string $method, string $url, array $headers = array(), ?string $body = null, array $options = array()): array
    {
        $this->calls[] = $url;
        $code = $this->status[$url] ?? 201;
        if ($code === 0) {
            throw new HttpException('boom');
        }

        return array('status' => $code, 'headers' => array(), 'body' => '');
    }
}

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
$http = new MpHttp();
$fresh = static function () use ($http): MessagingService {
    harness_reset_container();
    $c = Container::root();
    $c->instance(HttpClient::class, $http);
    $c->instance(AddressGuard::class, new AddressGuard(static fn (string $host): array => array('142.250.1.1')));

    return $c->get(MessagingService::class);
};
/** Make every queued push job due and run the worker; returns the push addresses called. */
$runJobs = static function () use ($admin, $p, $http): array {
    $http->calls = array();
    $admin->query("UPDATE {$p}t_job_queue SET dt_next_run = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE s_status = 'pending'");
    JobWorker::resetRegistration();
    JobWorker::run(10);

    sort($http->calls);

    return $http->calls;
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
$asAdmin  = Actor::admin(1, '10.0.0.9');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('enabled_push', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');
$svc  = $fresh();
$subs = Container::root()->get(PushSubscriptions::class);
$epA  = 'https://fcm.googleapis.com/fcm/send/a';
$epB  = 'https://updates.push.services.mozilla.com/wpush/b';
$subs->add($seller, $epA, 'Chrome/130.0');
$subs->add($seller, $epB, 'Firefox/131.0');
$job = "SELECT %s FROM {$p}t_job_queue WHERE s_type = 'message.push'";

harness_section('the job is queued once, after the commit');
$during = null;
$sent   = null;
Db::transaction(static function () use ($svc, $bike, $asBuyer, $p, &$during, &$sent): void {
    $sent   = $svc->start($bike, 'SECRET-TEXT is the bike free?', $asBuyer);
    $during = (int) Db::scalar("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.push'");
});
pin('nothing is queued while the outer transaction is open', 0, $during);
pin('one job after the commit', 1, $count(sprintf($job, 'COUNT(*)')));
pin('keyed on the recipient', "push:$seller", $col(sprintf($job, 's_unique')));
pin('the payload names the recipient only', array('recipient' => $seller), json_decode((string) $col(sprintf($job, 's_payload')), true));
$delay = $count("SELECT TIMESTAMPDIFF(SECOND, NOW(), dt_next_run) FROM {$p}t_job_queue WHERE s_type = 'message.push'");
pin('due about 15 seconds out', true, $delay >= 10 && $delay <= 16);
$svc->start($lamp, 'Lamp ok?', $asBuyer);
$svc->reply($sent->conversationId(), 'Hello again', $asBuyer);
pin('a burst, in two threads, keeps one job', 1, $count(sprintf($job, 'COUNT(*)')));
$admin->query("UPDATE {$p}t_job_queue SET dt_next_run = DATE_ADD(NOW(), INTERVAL 30 SECOND) WHERE s_type = 'message.push'");
$before = (string) $col(sprintf($job, "CONCAT(pk_i_id, '@', dt_next_run)"));
$svc->reply($sent->conversationId(), 'And again', $asBuyer);
pin('a later message does not push the wake-up back', $before, (string) $col(sprintf($job, "CONCAT(pk_i_id, '@', dt_next_run)")));

$admin->query("DELETE FROM {$p}t_job_queue WHERE s_type = 'message.push'");
$inside = null;
Db::transaction(static function () use ($sent, $seller, $buyer, $p, &$inside): void {
    Container::root()->get(DispatcherInterface::class)->dispatch(new MessageSent($sent->messageId(), $sent->conversationId(), $buyer, $seller));
    $inside = (int) Db::scalar("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = 'message.push'");
});
pin('a message_sent heard inside a transaction queues nothing until it commits', array(0, 1), array($inside, $count(sprintf($job, 'COUNT(*)'))));

harness_section('the wake-up');
$calls = $runJobs();
pin('both browsers are woken once', array($epA, $epB), $calls);
pin('the job is done', 0, $count(sprintf($job, 'COUNT(*)') . " AND s_status = 'pending'"));

harness_section('nothing to push');
$svc->start($vase, 'Vase?', $asBuyer);
$svc->markRead($sent->conversationId(), $asSeller);
foreach (array($lamp, $vase) as $item) {
    $conv = (int) $col("SELECT pk_i_id FROM {$p}t_conversation WHERE fk_i_item_id = $item");
    $svc->markRead($conv, $asSeller);
}
pin('a job is waiting', 1, $count(sprintf($job, 'COUNT(*)') . " AND s_status = 'pending'"));
pin('read before the job runs: no push', array(), $runJobs());

$set('enabled_push', '0');
$svc = $fresh();
$svc->start($rope, 'Rope?', $asBuyer);
pin('push off: no job', 0, $count(sprintf($job, 'COUNT(*)') . " AND s_status = 'pending'"));
$set('enabled_push', '1');
$svc = $fresh();

harness_section('push switched off while the job waits');
$cleanup = static fn () => $admin->query("DELETE FROM {$p}t_job_queue WHERE s_type = 'message.push'");
$cleanup();
$ropeConv = (int) $col("SELECT pk_i_id FROM {$p}t_conversation WHERE fk_i_item_id = $rope");
$svc->reply($ropeConv, 'Rope again', $asBuyer);
pin('a job is waiting', 1, $count(sprintf($job, 'COUNT(*)')));
$set('enabled_push', '0');
pin('the admin turned push off: no push', array(), $runJobs());
$set('enabled_push', '1');
$svc = $fresh();

harness_section('a held message never pushes, a released one does');
$cleanup();
$svc->markRead($ropeConv, $asSeller);
$mod = Container::root()->get(MessageModeration::class);
$hold = static function (MessageSending $e): void {
    $e->hold('test');
};
$events = Container::root()->get(DispatcherInterface::class);
$events->listen(MessageSending::class, $hold);
$held = $svc->start($rope, 'Held text', $asBuyer);
$events->remove(MessageSending::class, $hold);
pin('a held message queues no push', 0, $count(sprintf($job, 'COUNT(*)')));
$mod->release($held->messageId(), $asAdmin);
pin('release queues one', 1, $count(sprintf($job, 'COUNT(*)')));
pin('and the payload names the seller', array('recipient' => $seller), json_decode((string) $col(sprintf($job, 's_payload')), true));
$cleanup();
$listeners = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/boot/CoreServices.php');
pin('PushNotifier is not listening to MessageHeld', 0, preg_match('/MessageHeld::class,\s*\[PushNotifier::class/', $listeners));
pin('PushNotifier listens to MessageSent', 1, preg_match('/MessageSent::class,\s*\[PushNotifier::class/', $listeners));

harness_section('gone, failed and retry');
$push = static function () use ($svc, $asBuyer, $cleanup, $sent): void {
    $cleanup();
    $svc->reply($sent->conversationId(), 'ping', $asBuyer);
};
$http->status = array($epA => 410);
$push();
pin('one browser gone: both tried', array($epA, $epB), $runJobs());
pin('its row is deleted', array($epB), array_column($subs->forUser($seller), 'endpoint'));
pin('the job completed', 0, $count(sprintf($job, 'COUNT(*)')));

$subs->add($seller, $epA, 'Chrome/130.0');
$http->status = array($epA => 429);
$push();
pin('one browser asks for a retry: both tried', array($epA, $epB), $runJobs());
pin('one job waits again', 1, $count(sprintf($job, 'COUNT(*)')));
pin('it keeps only the browser that asked', array('recipient' => $seller, 'only' => array($seller . ':' . PushSubscriptions::browserId($epA)), 'round' => 2), json_decode((string) $col(sprintf($job, 's_payload')), true));
$wait = $count("SELECT TIMESTAMPDIFF(SECOND, NOW(), dt_next_run) FROM {$p}t_job_queue WHERE s_type = 'message.push'");
pin('the retry waits about a minute', true, $wait >= 55 && $wait <= 61);
pin('the retry sends to that browser only', array($epA), $runJobs());
pin('the next wait is longer', true, $count("SELECT TIMESTAMPDIFF(SECOND, NOW(), dt_next_run) FROM {$p}t_job_queue WHERE s_type = 'message.push'") >= 115);
$http->status = array($epA => 201);
pin('it succeeds on the next round', array($epA), $runJobs());
pin('and the job is done', 0, $count(sprintf($job, 'COUNT(*)')));

$http->status = array($epA => 429);
$push();
$runJobs();
$runJobs();
$runJobs();
$runJobs();
pin('the retries stop after four rounds', 0, $count(sprintf($job, 'COUNT(*)')));

$http->status = array($epA => 400, $epB => 0);
$push();
pin('failed browsers are tried once', array($epA, $epB), $runJobs());
pin('a failed send completes the job: no retry storm', 0, $count(sprintf($job, 'COUNT(*)')));
pin('failed browsers keep their rows', 2, count($subs->forUser($seller)));
$http->status = array();

harness_section('a run stops sending after its time budget');
$push();
$cleanup();
$http->calls = array();
$stepClock = new class () implements Clock {
    public int $t = 1000000;

    public function now(): int
    {
        return ($this->t += 10) - 10;
    }
};
$c        = Container::root();
$notifier = new PushNotifier($c->get(PushSubscriptions::class), $c->get(WebPushSender::class), $c->get(ConversationStore::class), $stepClock);
$slow     = new Job(array(), array('recipient' => $seller));
$notifier->run($slow);
pin('one browser sent before the budget ran out', 1, count($http->calls));
$again = $slow->repeatRequest();
pin('the rest is queued again at once', 0, $again['delay'] ?? null);
pin('with only the browser not tried, same round', array('recipient' => $seller, 'only' => array_values(array_diff(array_keys($subs->forUser($seller)), array_map(static fn (string $u): string => $seller . ':' . PushSubscriptions::browserId($u), $http->calls))), 'round' => 1), $again['payload'] ?? null);

harness_section('the recipient');
$push();
$admin->query("UPDATE {$p}t_user SET b_enabled = 0 WHERE pk_i_id = $seller");
pin('a disabled user is not pushed', array(), $runJobs());
$admin->query("UPDATE {$p}t_user SET b_enabled = 1 WHERE pk_i_id = $seller");
$push();
$admin->query("UPDATE {$p}t_user SET b_active = 0 WHERE pk_i_id = $seller");
pin('an inactive user is not pushed', array(), $runJobs());
$admin->query("UPDATE {$p}t_user SET b_active = 1 WHERE pk_i_id = $seller");

harness_section('what travels');
$sender = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/messaging/PushNotifier.php');
pin('the notifier handles ids, not text', 0, preg_match('/s_body|getBody|->body\(/', $sender));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
