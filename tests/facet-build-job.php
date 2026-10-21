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
 * The listing filter index backfill as jobs: a batch per run with the cursor kept in the job,
 * the ready flag set and the table rewritten only when the last batch is done, a failed run that resumes from its cursor,
 * a listing that fails partway rolled back on its own,
 * one pending build at a time, and a one-field rebuild that puts the flag back unless another rebuild is queued.
 *
 * Usage: php tests/facet-build-job.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/facet-rows.php';

use mindstellar\job\Job;
use mindstellar\job\JobRegistry;
use mindstellar\job\JobWorker;
use mindstellar\search\FacetIndex;
use mindstellar\search\FacetJobs;

$admin = api_admin_boot('osc_facet_build_job');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);

$colour = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type) VALUES ('colour', 'colour', 'DROPDOWN')", '', []);
$gift   = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type) VALUES ('gift', 'gift', 'CHECKBOX')", '', []);

// 2 * BATCH + 1 listings: one real one, doubled by copying its row.
seed_item($admin, $cat, null, 'Mug');
$cols = implode(', ', array_column($admin->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item' AND COLUMN_NAME <> 'pk_i_id' ORDER BY ORDINAL_POSITION"
)->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME'));
$total = 2 * FacetIndex::BATCH + 1;
while ((int) $admin->query("SELECT COUNT(*) FROM {$p}t_item")->fetch_row()[0] < $total) {
    $admin->query("INSERT INTO {$p}t_item ($cols) SELECT $cols FROM {$p}t_item LIMIT " . ($total - (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item")->fetch_row()[0]));
}
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) SELECT pk_i_id, $colour, 'Red', '' FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) SELECT pk_i_id, $gift, '1', '' FROM {$p}t_item");

$ids   = array_map('intval', array_column($admin->query("SELECT pk_i_id FROM {$p}t_item ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC), 'pk_i_id'));
$facet = FacetIndex::tableName();
$pref  = static function (string $name) use ($admin, $p): ?string {
    $r = $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = '$name'")->fetch_row();

    return $r ? $r[0] : null;
};
$setFlag = static function (string $value) use ($admin, $p): void {
    $admin->query("DELETE FROM {$p}t_preference WHERE s_name IN ('facet_ready', 'facet_built_at')");
    if ($value !== '') {
        $admin->query("INSERT INTO {$p}t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', 'facet_ready', '$value', 'BOOLEAN')");
    }
    osc_reset_preferences();
};
$jobs = static fn (string $type): array => $admin->query("SELECT pk_i_id, s_payload, i_attempts FROM {$p}t_job_queue WHERE s_type = '$type' AND s_status IN ('pending', 'running')")->fetch_all(MYSQLI_ASSOC);
$count = static fn (string $where = '1'): int => (int) $admin->query('SELECT COUNT(*) FROM ' . facet_rows_sql() . " x WHERE $where")->fetch_row()[0];
$valueOf = static fn (string $key, int $item) => $admin->query('SELECT s_value FROM ' . facet_rows_sql() . " x WHERE s_key = '$key' AND fk_i_item_id = $item")->fetch_row()[0] ?? null;
$due   = static function () use ($admin, $p): void {
    $admin->query("UPDATE {$p}t_job_queue SET dt_next_run = '2000-01-01 00:00:00'");
};
// The worker re-claims a repeating job in the same call, so each handler is wrapped to
// hold its repeat back: one run is one batch. $replace swaps the build handler.
$pass = static function (?callable $replace = null) use ($due): int {
    JobWorker::resetRegistration();
    JobWorker::registerHandlers();
    foreach ([FacetIndex::JOB_BUILD, FacetIndex::JOB_FIELD] as $type) {
        $real = JobRegistry::handler($type);
        JobRegistry::register($type, static function (Job $job) use ($real, $type, $replace): void {
            ($replace !== null && $type === FacetIndex::JOB_BUILD ? $replace : $real)($job);
            $again = $job->repeatRequest();
            if ($again !== null) {
                $job->repeat($again['payload'], 3600);
            }
        });
    }
    $due();

    return JobWorker::run(10);
};
$cursorOf = static fn (array $job): int => (int) (json_decode($job['s_payload'], true)['after'] ?? -1);

harness_section('the full build, a batch per run');
$admin->query("DELETE FROM $facet");
$admin->query("DELETE FROM {$p}t_job_queue");
$admin->query("INSERT INTO {$p}t_facet_term (s_key, s_value) VALUES ('f$colour', 'Orphan')");
$orphans = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_facet_term WHERE s_value = 'Orphan'")->fetch_row()[0];
$setFlag('0');
check('a build is queued', FacetJobs::queueBuild());
pin('a second call while one is pending reports it queued', true, FacetJobs::queueBuild());
pin('one build job', 1, count($jobs(FacetIndex::JOB_BUILD)));

// InnoDB gives a table a new id when it rewrites it.
$view    = $admin->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME IN ('INNODB_TABLES', 'INNODB_SYS_TABLES')")->fetch_row()[0];
$tableId = static fn (): int => (int) $admin->query("SELECT TABLE_ID FROM information_schema.$view WHERE NAME = CONCAT(DATABASE(), '/$facet')")->fetch_row()[0];
$before  = $tableId();
$pass();
$job = $jobs(FacetIndex::JOB_BUILD);
pin('after pass 1 the job waits at the batch-th listing', $ids[FacetIndex::BATCH - 1], $cursorOf($job[0]));
pin('the first batch has rows', 2 * FacetIndex::BATCH, $count());
pin('the flag is still off', '0', $pref('facet_ready'));

$pass();
$job = $jobs(FacetIndex::JOB_BUILD);
pin('after pass 2 the cursor moved on', $ids[2 * FacetIndex::BATCH - 1], $cursorOf($job[0]));
pin('the flag is still off', '0', $pref('facet_ready'));
pin('a term no row names stays until the last batch', 1, $orphans());
pin('and the table is not rewritten before it', $before, $tableId());

$before = $tableId();
$pass();
pin('after pass 3 no job remains', [], $jobs(FacetIndex::JOB_BUILD));
check('the last batch rewrites the table', $tableId() !== $before);
pin('the last batch prunes it', 0, $orphans());
pin('every listing has both rows', 2 * $total, $count());
pin('the flag is on', '1', $pref('facet_ready'));
check('the build time is set', (int) $pref('facet_built_at') > 0);

harness_section('an interrupted build resumes from its cursor');
$admin->query("DELETE FROM $facet");
$setFlag('0');
FacetJobs::queueBuild();
$pass();
$cursor = $cursorOf($jobs(FacetIndex::JOB_BUILD)[0]);
// A sentinel on the done listings: a redo would overwrite it.
facet_retag($admin, "f$colour", 'Done', "f.fk_i_item_id <= $cursor");
$pass(static function (Job $job): void {
    throw new RuntimeException('Database went away');
});
$job = $jobs(FacetIndex::JOB_BUILD);
pin('the job is still there', 1, count($job));
pin('with its cursor', $cursor, $cursorOf($job[0]));
pin('the flag is still off', '0', $pref('facet_ready'));
$pass();
pin('the clean pass carries on from the cursor', $ids[2 * FacetIndex::BATCH - 1], $cursorOf($jobs(FacetIndex::JOB_BUILD)[0]));
pin('the first listings were not redone', FacetIndex::BATCH, $count("s_key = 'f$colour' AND s_value = 'Done'"));
$pass();
pin('and finishes', [], $jobs(FacetIndex::JOB_BUILD));
pin('with every row', 2 * $total, $count());
pin('and the flag on', '1', $pref('facet_ready'));

harness_section('a listing that fails partway rolls back alone');
facet_retag($admin, "f$colour", 'Stale');
$bad = $ids[10];
$admin->query("CREATE TRIGGER {$p}facet_block BEFORE INSERT ON $facet FOR EACH ROW BEGIN IF NEW.fk_i_item_id = $bad AND NEW.fk_i_term_id IN (SELECT pk_i_id FROM {$p}t_facet_term WHERE s_key = 'f$gift') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END");
$setFlag('0');
FacetJobs::queueBuild();
$pass();
$job = $jobs(FacetIndex::JOB_BUILD);
pin('the job is still there, at its start', [1, 0], [count($job), $job ? $cursorOf($job[0]) : -1]);
pin('the listings before it are done', 10, $count("s_key = 'f$colour' AND s_value = 'Red' AND fk_i_item_id < $bad"));
pin('the failed listing keeps its old rows', ['Stale', 2], [
    $valueOf("f$colour", $bad),
    $count("fk_i_item_id = $bad"),
]);
pin('the listings after it are not touched', 0, $count("s_key = 'f$colour' AND s_value = 'Red' AND fk_i_item_id > $bad"));
pin('the flag is still off', '0', $pref('facet_ready'));
$admin->query("DROP TRIGGER {$p}facet_block");
for ($i = 0; $i < 3; $i++) {
    $pass();
}
pin('without the fault it finishes', [[], 0, '1'], [$jobs(FacetIndex::JOB_BUILD), $count("s_value = 'Stale'"), $pref('facet_ready')]);

harness_section('a rebuild keeps the flag on');
facet_retag($admin, "f$colour", 'Stale');
FacetJobs::queueBuild();
$pass();
pin('mid-rebuild the flag is still on', '1', $pref('facet_ready'));
pin('the first batch is fresh', 'Red', $valueOf("f$colour", $ids[0]));
pin('a later listing is stale until its batch', 'Stale', $valueOf("f$colour", (int) end($ids)));
$pass();
$pass();
pin('the rebuild finished', [], $jobs(FacetIndex::JOB_BUILD));

harness_section('one field');
facet_retag($admin, "f$colour", 'Stale');
facet_retag($admin, "f$gift", 'Stale');
$setFlag('0');
osc_job_enqueue(FacetIndex::JOB_FIELD, ['field' => $colour, 'after' => 0], ['unique_key' => (string) $colour]);
for ($i = 0; $i < 3; $i++) {
    $pass();
}
pin('the job is done', [], $jobs(FacetIndex::JOB_FIELD));
pin('that field is rewritten', 0, $count("s_key = 'f$colour' AND s_value = 'Stale'"));
pin('the other field is untouched', $total, $count("s_key = 'f$gift' AND s_value = 'Stale'"));
pin('the last rebuild job lets searches back on the index', '1', $pref('facet_ready'));

$setFlag('0');
osc_job_enqueue(FacetIndex::JOB_FIELD, ['field' => $colour, 'after' => 0], ['unique_key' => (string) $colour]);
osc_job_enqueue(FacetIndex::JOB_BUILD, ['after' => 0]);
$admin->query("UPDATE {$p}t_job_queue SET dt_next_run = '2000-01-01 00:00:00' WHERE s_type = '" . FacetIndex::JOB_FIELD . "'");
$admin->query("UPDATE {$p}t_job_queue SET dt_next_run = '2999-01-01 00:00:00' WHERE s_type = '" . FacetIndex::JOB_BUILD . "'");
JobWorker::resetRegistration();
JobWorker::run(2);
pin('a pending full build keeps the flag off', ['0', 0], [$pref('facet_ready'), count($jobs(FacetIndex::JOB_FIELD))]);
$admin->query("DELETE FROM {$p}t_job_queue");

harness_section('the Jobs tab text');
JobWorker::resetRegistration();
JobWorker::registerHandlers();
pin('the build has a name', 'Listing filter index', JobRegistry::name(FacetIndex::JOB_BUILD));
pin('the field job has a name', 'Filter index of one field', JobRegistry::name(FacetIndex::JOB_FIELD));
pin('the detail names the cursor', 'After listing #42', JobRegistry::detail(FacetIndex::JOB_BUILD, ['after' => 42]));

exit(harness_result());
