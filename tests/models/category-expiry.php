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
 * Category expiry and listing end dates: "never" (0) really clears a date, the category
 * row save no longer re-dates listings, and the batched expiry job.
 *
 * Usage:  php tests/models/category-expiry.php
 *         php tests/run-models.php category-expiry
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\job\CategoryJobs;
use mindstellar\job\Job;
use mindstellar\job\JobQueue;
use mindstellar\job\JobRegistry;
use mindstellar\job\JobWorker;

$admin = scratchdb_session('osc_models_category_expiry');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('DEMO')) {
    define('DEMO', true);
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once __DIR__ . '/../lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDatabase.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

Preference::getInstance();

$locale = 'en_US';
seed_locale($admin, $locale, 'English');
seed_currency($admin);
seed_country($admin);
$user = seed_user($admin);

const NEVER = '9999-12-31 23:59:59';

$fired = array();
osc_add_hook('item_expiration_updated', static function ($id, $date) use (&$fired): void {
    $fired[] = (int) $id;
});

$expiry = static function (int $id) use ($admin): string {
    $r = $admin->query('SELECT dt_expiration FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $id)->fetch_assoc();

    return (string) $r['dt_expiration'];
};
$pubPlus = static function (int $id, int $days) use ($admin): string {
    $r = $admin->query(
        'SELECT DATE_ADD(dt_pub_date, INTERVAL ' . $days . ' DAY) AS d FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $id
    )->fetch_assoc();

    return (string) $r['d'];
};
$days = static function (int $categoryId) use ($admin): int {
    $r = $admin->query('SELECT i_expiration_days FROM ' . DB_TABLE_PREFIX . 't_category WHERE pk_i_id = ' . $categoryId)->fetch_assoc();

    return (int) $r['i_expiration_days'];
};

$items = Item::getInstance();

/* ---------------------------------------------------------------------------
 * Item::updateExpirationDate
 * ------------------------------------------------------------------------ */
harness_section('A listing end date');

$cat = seed_category($admin, 'Dates');
$a   = seed_item($admin, $cat, $user, 'A');

$fired = array();
pin('0 means never', NEVER, $items->updateExpirationDate($a, 0));
pin('the row says never', NEVER, $expiry($a));
pin('and item_expiration_updated fired', array($a), $fired);

pin('60 as an int is 60 days after publishing', $pubPlus($a, 60), $items->updateExpirationDate($a, 60));
pin('the row has it', $pubPlus($a, 60), $expiry($a));

pin("'0' as a string means never too", NEVER, $items->updateExpirationDate($a, '0'));
pin("'60' as a string is 60 days", $pubPlus($a, 60), $items->updateExpirationDate($a, '60'));

pin('a date string is written as it is', '2031-01-02 03:04:05', $items->updateExpirationDate($a, '2031-01-02 03:04:05'));

pin("'' changes nothing", false, $items->updateExpirationDate($a, ''));
pin('null changes nothing', false, $items->updateExpirationDate($a, null));
pin('false changes nothing', false, $items->updateExpirationDate($a, false));
pin('a negative number changes nothing', false, $items->updateExpirationDate($a, -5));
pin('the date stays as it was', '2031-01-02 03:04:05', $expiry($a));

/* ---------------------------------------------------------------------------
 * Saving the category row leaves the listings alone
 * ------------------------------------------------------------------------ */
harness_section('Saving a category row');

$items->updateExpirationDate($a, '2031-01-02 03:04:05');
$fired = array();
Category::getInstance()->updateByPrimaryKey(
    array('fields' => array('i_expiration_days' => 10), 'aFieldsDescription' => array()),
    $cat
);
pin('the expiry days were saved', 10, $days($cat));
pin('the listing keeps its date', '2031-01-02 03:04:05', $expiry($a));
pin('and no expiry hook fired', array(), $fired);

Category::getInstance()->updateByPrimaryKey(
    array('fields' => array('i_expiration_days' => 0), 'aFieldsDescription' => array()),
    $cat
);
pin('0 days saved on the row leaves the listing too', '2031-01-02 03:04:05', $expiry($a));

/* ---------------------------------------------------------------------------
 * Category::updateExpiration
 * ------------------------------------------------------------------------ */
harness_section('Category::updateExpiration');

$top   = seed_category($admin, 'Top');
$mid   = seed_category($admin, 'Mid', $top);
$low   = seed_category($admin, 'Low', $mid);
$aside = seed_category($admin, 'Aside');
$inTop = seed_item($admin, $top, $user, 'In top');
$inMid = seed_item($admin, $mid, $user, 'In mid');
$inLow = seed_item($admin, $low, $user, 'In low');
$away  = seed_item($admin, $aside, $user, 'Away');
$awayWas = $expiry($away);

$fired = array();
Category::getInstance()->updateExpiration($top, 31);
pin('the category is set', 31, $days($top));
pin('its listing gets the new date', $pubPlus($inTop, 31), $expiry($inTop));
pin('a subcategory is not touched without the flag', 0, $days($mid));
check('nor its listing', $expiry($inMid) !== $pubPlus($inMid, 31));

$fired = array();
Category::getInstance()->updateExpiration($top, 0, true);
pin('0 with the flag sets every level', array(0, 0, 0), array($days($top), $days($mid), $days($low)));
pin('the top listing never ends', NEVER, $expiry($inTop));
pin('nor the one in the subcategory', NEVER, $expiry($inMid));
pin('nor the one two levels down', NEVER, $expiry($inLow));
pin('a category outside the tree is untouched', $awayWas, $expiry($away));
$counts = array_count_values($fired);
pin('the hook fired once for each listing', array($inTop => 1, $inMid => 1, $inLow => 1), $counts);

$fired = array();
Category::getInstance()->updateExpiration($top, 15, true);
pin('days reach the deepest level', 15, $days($low));
pin('and its listing', $pubPlus($inLow, 15), $expiry($inLow));
sort($fired);
$want = array($inTop, $inMid, $inLow);
sort($want);
pin('each listing fired once, never twice', $want, $fired);

/* ---------------------------------------------------------------------------
 * The batched job
 * ------------------------------------------------------------------------ */
harness_section('Re-dating in the background');

$queue = JobQueue::getInstance();
$queue->forgetAll();
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_job_queue');
JobWorker::resetRegistration();
CategoryJobs::register();
check('the job type is registered', JobRegistry::has(CategoryJobs::EXPIRY));
pin('with its label', 'Give listings new end dates', JobRegistry::name(CategoryJobs::EXPIRY));

$bulkA = seed_category($admin, 'Bulk A');
$bulkB = seed_category($admin, 'Bulk B', $bulkA);
$total = 2 * CategoryJobs::BATCH + 50;
$bulk  = array();
for ($i = 0; $i < $total; $i++) {
    $bulk[] = seed_item($admin, $i % 2 === 0 ? $bulkA : $bulkB, $user, 'Bulk ' . $i);
}
$outside = seed_item($admin, $aside, $user, 'Outside');
$outsideWas = $expiry($outside);

check('a request is queued', CategoryJobs::requestExpiry(array($bulkA, $bulkB), 0));
pin('one job is pending', 1, $queue->count(JobQueue::STATUS_PENDING, CategoryJobs::EXPIRY));
check('no ids queue nothing', !CategoryJobs::requestExpiry(array(0, -1), 5));

$rows = $queue->claim(1);
pin('the job is claimable', 1, count($rows));
$payload = json_decode((string) $rows[0]['s_payload'], true) ?: array();
pin('the payload carries the ids, days and a start', array('category_ids' => array($bulkA, $bulkB), 'days' => 0, 'after' => 0), $payload);

$handler = JobRegistry::handler(CategoryJobs::EXPIRY);
$neverCount = static function () use ($admin, $bulkA, $bulkB): int {
    $r = $admin->query(
        'SELECT COUNT(*) AS c FROM ' . DB_TABLE_PREFIX . "t_item WHERE dt_expiration = '" . NEVER . "'"
        . ' AND fk_i_category_id IN (' . $bulkA . ',' . $bulkB . ')'
    )->fetch_assoc();

    return (int) $r['c'];
};

$runs     = 0;
$progress = array();
$next     = $payload;
$fired    = array();
do {
    $job = new Job($rows[0], $next);
    $handler($job);
    $runs++;
    $progress[] = $neverCount();
    $repeat = $job->repeatRequest();
    $next   = $repeat === null ? null : $repeat['payload'];
} while ($next !== null && $runs < 10);

pin('three runs, one batch each', array(CategoryJobs::BATCH, 2 * CategoryJobs::BATCH, $total), $progress);
pin('then it stops', 3, $runs);
pin('every listing fired the hook once', $total, count(array_unique($fired)));
pin('and no listing twice', $total, count($fired));
pin('a listing outside is left alone', $outsideWas, $expiry($outside));

$fired = array();
$job   = new Job($rows[0], $payload);
$handler($job);
pin('a repeated run changes nothing', array(), $fired);
pin('every listing still never ends', $total, $neverCount());

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_job_queue');
CategoryJobs::requestExpiry(array($bulkA, $bulkB), 7);
JobWorker::run(120);
pin('the worker carries a queued job to the end', $pubPlus($bulk[$total - 1], 7), $expiry($bulk[$total - 1]));
pin('and nothing gave up', 0, $queue->count(JobQueue::STATUS_ERROR));
pin('nothing is left', 0, $queue->count(JobQueue::STATUS_PENDING));

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_job_queue');

(new \ReflectionProperty(Category::class, 'instance'))->setValue(null, null);
\mindstellar\category\CategoryStore::forget();

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
