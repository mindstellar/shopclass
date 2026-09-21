<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Deleting a category, and the size at which it stops happening in the request.
 *
 * A small category must still be gone the moment the admin clicks delete -- deferring
 * that would trade a real problem for an annoying one. A big one must not be attempted
 * inline at all: measured on a production copy, 808 listings took 17.9 seconds, so the
 * 39,225-listing category there is about 14.5 minutes of held locks, and a host's
 * max_execution_time turns that into a rollback and a category nobody can delete.
 *
 * The thresholds are lowered here rather than seeding thousands of rows; what is pinned
 * is the decision and the batching, not the constants.
 *
 * Usage:  php tests/models/categoryjobs.php
 *         php tests/run-models.php categoryjobs
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\job\CategoryJobs;
use mindstellar\job\JobQueue;
use mindstellar\job\JobWorker;

$admin = scratchdb_session('osc_models_categoryjobs');

// The same bootstrap tests/models/item.php and tests/models/category.php use: both
// models are real here, because what is being tested is the delete cascade they run.
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
    // osc_deleteResource() short-circuits under DEMO, so the cascade never reaches the
    // filesystem while still firing every hook and every database delete.
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
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDatabase.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hJobs.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';

Preference::newInstance();

$queue = JobQueue::instance();

$locale = 'en_US';
seed_locale($admin, $locale, 'English');
seed_currency($admin);
seed_country($admin);
$user = seed_user($admin);

$countItems = static function (int $categoryId) use ($admin): int {
    $res = $admin->query(
        'SELECT COUNT(*) AS c FROM ' . DB_TABLE_PREFIX . 't_item WHERE fk_i_category_id = ' . $categoryId
    );
    $r = $res->fetch_assoc();
    $res->free();

    return (int) $r['c'];
};

$categoryExists = static function (int $id) use ($admin): bool {
    $res = $admin->query(
        'SELECT COUNT(*) AS c FROM ' . DB_TABLE_PREFIX . 't_category WHERE pk_i_id = ' . $id
    );
    $r = $res->fetch_assoc();
    $res->free();

    return (int) $r['c'] > 0;
};

$isEnabled = static function (int $id) use ($admin): ?int {
    $res = $admin->query(
        'SELECT b_enabled FROM ' . DB_TABLE_PREFIX . 't_category WHERE pk_i_id = ' . $id
    );
    $r = $res->fetch_assoc();
    $res->free();

    return $r === null ? null : (int) $r['b_enabled'];
};

/* ---------------------------------------------------------------------------
 * The tree walk everything else stands on
 * ------------------------------------------------------------------------ */
harness_section('Walking the tree');

$root  = seed_category($admin, 'Motors');
$child = seed_category($admin, 'Cars', $root);
$leaf  = seed_category($admin, 'Saloons', $child);
$other = seed_category($admin, 'Property');

$ids = CategoryJobs::treeIds($root);
pin('the tree holds the category and its descendants', 3, count($ids));
check('the root is in it', in_array($root, $ids, true));
check('the leaf is in it', in_array($leaf, $ids, true));
check('an unrelated category is not', !in_array($other, $ids, true));
pin('deepest first, so a caller may delete in order', $leaf, $ids[0]);
pin('and the root is last', $root, $ids[count($ids) - 1]);

pin('a leaf is a tree of one', array($other), CategoryJobs::treeIds($other));
pin('a category that is not there has no tree', array(), CategoryJobs::treeIds(999999));

/* ---------------------------------------------------------------------------
 * Counting across the tree
 * ------------------------------------------------------------------------ */
harness_section('Counting listings');

pin('an empty tree has no listings', 0, CategoryJobs::countItems($ids));

seed_item($admin, $root, $user, 'In the root');
seed_item($admin, $leaf, $user, 'In the leaf');
seed_item($admin, $other, $user, 'Somewhere else');

pin('listings anywhere in the tree are counted', 2, CategoryJobs::countItems($ids));
pin('an empty id list counts nothing', 0, CategoryJobs::countItems(array()));

/* ---------------------------------------------------------------------------
 * The decision: inline or queued
 * ------------------------------------------------------------------------ */
harness_section('Small enough to do now');

$queue->forgetAll();
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_job_queue');

$small = seed_category($admin, 'Small');
seed_item($admin, $small, $user, 'One');
seed_item($admin, $small, $user, 'Two');

pin('a small category is deleted in the request', 'done', CategoryJobs::requestDelete($small));
check('and it is gone', !$categoryExists($small));
pin('with nothing queued', 0, $queue->count(JobQueue::STATUS_PENDING));

pin('a category that is not there cannot be deleted', 'failed', CategoryJobs::requestDelete(999999));
pin('nor can id zero', 'failed', CategoryJobs::requestDelete(0));
pin('nor a negative id', 'failed', CategoryJobs::requestDelete(-1));

harness_section('Too big to do now');

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_job_queue');

$big      = seed_category($admin, 'Big');
$bigChild = seed_category($admin, 'Big child', $big);

// One more than the inline limit, split across the tree, so the decision has to be
// made on the whole tree rather than the category that was clicked.
$total = CategoryJobs::INLINE_LIMIT + 1;
for ($i = 0; $i < $total - 10; $i++) {
    seed_item($admin, $big, $user, 'Big listing ' . $i);
}
for ($i = 0; $i < 10; $i++) {
    seed_item($admin, $bigChild, $user, 'Child listing ' . $i);
}

pin('the tree is over the limit', $total, CategoryJobs::countItems(CategoryJobs::treeIds($big)));
pin('so it is queued instead', 'queued', CategoryJobs::requestDelete($big));
pin('one job was queued', 1, $queue->count(JobQueue::STATUS_PENDING, CategoryJobs::TYPE));
pin('the category is hidden immediately', 0, $isEnabled($big));
pin('and so is its child', 0, $isEnabled($bigChild));
check('but the category is still there', $categoryExists($big));
pin('and no listing has been removed yet', $total, CategoryJobs::countItems(CategoryJobs::treeIds($big)));

// One claim, one batch. The job goes back on the queue rather than finishing, which is
// what keeps a single run bounded.
JobWorker::resetRegistration();
CategoryJobs::register();

$rows = $queue->claim(1);
pin('the job is claimable', 1, count($rows));
$job = new \mindstellar\job\Job($rows[0], json_decode((string) $rows[0]['s_payload'], true) ?: array());
$handler = \mindstellar\job\JobRegistry::handler(CategoryJobs::TYPE);
$handler($job);

pin('one run removes one batch and no more', $total - CategoryJobs::BATCH, CategoryJobs::countItems(CategoryJobs::treeIds($big)));
check('and it asked to run again', $job->repeatRequest() !== null);
check('rather than deleting the category yet', $categoryExists($big));

// Left alone, the worker carries it through to the end.
$queue->repeat((int) $rows[0]['pk_i_id'], $job->repeatRequest()['payload']);
$ran = JobWorker::run(120);
check('the worker finished the job', $ran > 0);
check('the category is gone', !$categoryExists($big));
check('so is its child', !$categoryExists($bigChild));
pin('nothing is left on the queue', 0, $queue->count(JobQueue::STATUS_PENDING));
pin('and nothing gave up', 0, $queue->count(JobQueue::STATUS_ERROR));

harness_section('Unrelated categories are untouched');

check('a sibling category survives', $categoryExists($other));
pin('with its listing', 1, $countItems($other));

$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_job_queue');

exit(harness_result());
