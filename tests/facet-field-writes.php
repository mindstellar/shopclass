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
 * Custom field writers keep t_item_facet in step: Field::replace() writes the rows of each
 * storage type (and removes them for an empty value), deleting a field drops its rows and still
 * fires both hooks, a type change that changes the storage queues one rebuild job for that field
 * (which starts over when the type moved while it ran),
 * a listing save or a plugin's own replace() waits for a held meta row and then writes both, and
 * the index build and a save on one listing wait for each other in either order.
 *
 * Usage: php tests/facet-field-writes.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\fields\FieldService;
use mindstellar\job\Job;
use mindstellar\job\JobRegistry;
use mindstellar\job\JobWorker;
use mindstellar\search\FacetIndex;

$admin  = api_admin_boot('osc_facet_field_writes');
$p      = DB_TABLE_PREFIX;
$db     = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', seed_category($admin, 'Vehicles', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'title_character_length' => '100',
    'description_character_length' => '5000', 'moderate_admin_edit' => '1', 'map_type' => 'openstreet'] as $k => $v) {
    osc_set_preference($k, $v, 'osclass', 'STRING');
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.82';
Params::init();

$dao   = Field::getInstance();
$field = static function (string $type) use ($admin, $p, $cat): int {
    $id = seed_exec(
        $admin,
        "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_searchable) VALUES (?, ?, ?, 1)",
        'sss',
        ['F ' . $type . mt_rand(), strtolower($type) . mt_rand(), $type]
    );
    seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', [$cat, $id]);

    return $id;
};
$facet = static function (int $item) use ($admin, $p): array {
    $rows = $admin->query("SELECT s_key, s_value, d_number AS n FROM {$p}t_item_facet WHERE fk_i_item_id = $item ORDER BY s_key, s_value")->fetch_all(MYSQLI_ASSOC);

    return array_map(static fn (array $r): string => $r['s_key'] . '=' . $r['s_value'] . '|' . ($r['n'] === null ? '-' : (float) $r['n']), $rows);
};
$value = static fn (string $sql) => $admin->query($sql)->fetch_row()[0] ?? null;
$item  = seed_item($admin, $cat, $sue);

harness_section('replace() writes the rows of each storage type');
$drop = $field('DROPDOWN');
pin('a scalar write still returns true', true, $dao->replace($item, $drop, 'Red'));
pin('dropdown: meta and facet row', ['Red', ["f$drop=Red|-"]], [$value("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $item AND fk_i_field_id = $drop"), $facet($item)]);
$dao->replace($item, $drop, 'Blue');
pin('dropdown: a new value replaces the row', ["f$drop=Blue|-"], $facet($item));
$dao->replace($item, $drop, '');
pin('dropdown: an empty value removes the row, keeps the meta row', [[], '1'], [$facet($item), (string) $value("SELECT COUNT(*) FROM {$p}t_item_meta WHERE fk_i_item_id = $item AND fk_i_field_id = $drop")]);

$radio = $field('RADIO');
$dao->replace($item, $radio, 'Yes');
pin('radio: one row', ["f$radio=Yes|-"], $facet($item));
$dao->replace($item, $radio, '');

$check = $field('CHECKBOX');
$dao->replace($item, $check, '1');
pin('checkbox ticked: a row', ["f$check=1|-"], $facet($item));
$dao->replace($item, $check, '0');
pin('checkbox unticked: no row', [], $facet($item));

$num = $field('NUMBER');
$dao->replace($item, $num, '12.5');
pin('number: text and d_number 12.5', ["f$num=12.5|12.5"], $facet($item));
$dao->replace($item, $num, 'abc');
pin('number text: no row', [], $facet($item));

$date = $field('DATE');
$dao->replace($item, $date, '1700000000');
pin('date: timestamp and d_number', ["f$date=1700000000|1700000000"], $facet($item));
$dao->replace($item, $date, '');

$span = $field('DATEINTERVAL');
pin('an interval write still returns null', null, $dao->replace($item, $span, ['from' => '1700000000', 'to' => '1700086400']));
pin('date interval: .from and .to rows', ["f$span.from=1700000000|1700000000", "f$span.to=1700086400|1700086400"], $facet($item));
$dao->replace($item, $span, ['from' => '', 'to' => '']);
pin('an emptied interval removes both rows', [], $facet($item));

$text = $field('TEXT');
$dao->replace($item, $text, 'hello');
pin('text: meta row only', [[], 'hello'], [$facet($item), $value("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $item AND fk_i_field_id = $text")]);

harness_section('a facet failure does not stop the meta write');
$fresh = $field('DROPDOWN');
osc_set_preference('facet_ready', '1', 'osclass', 'BOOLEAN');
$admin->query("DELETE FROM {$p}t_job_queue");
$admin->query("DROP TABLE {$p}t_item_facet");
pin('replace() still reports true', true, $dao->replace($item, $fresh, 'Green'));
pin('the meta row is written', 'Green', $value("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $item AND fk_i_field_id = $fresh"));
pin('the index is marked stale', '0', $value("SELECT s_value FROM {$p}t_preference WHERE s_section = 'osclass' AND s_name = 'facet_ready'"));
pin('and a rebuild is queued', '1', (string) $value("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_BUILD . "'"));
$admin->query("DELETE FROM {$p}t_job_queue");
$admin->query('CREATE TABLE ' . $p . 't_item_facet (s_key VARCHAR(48) NOT NULL, s_value VARCHAR(191) NOT NULL, fk_i_item_id INT UNSIGNED NOT NULL, '
    . 'fk_i_variant_id INT UNSIGNED NOT NULL DEFAULT 0, d_number DOUBLE NULL, PRIMARY KEY (s_key, s_value, fk_i_item_id, fk_i_variant_id), '
    . 'INDEX idx_item (fk_i_item_id), INDEX idx_number (s_key, d_number), FOREIGN KEY (fk_i_item_id) REFERENCES ' . $p . 't_item (pk_i_id) ON DELETE CASCADE) '
    . "ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'");

harness_section('a map write is best effort per key');
$pair = $field('DATEINTERVAL');
$admin->query("CREATE TRIGGER {$p}meta_block BEFORE INSERT ON {$p}t_item_meta FOR EACH ROW BEGIN IF NEW.s_multi = 'bad' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END");
pin('a map write returns null', null, $dao->replace($item, $pair, ['from' => '1700000000', 'bad' => 'x', 'to' => '1700086400']));
$admin->query("DROP TRIGGER {$p}meta_block");
pin('a failing key is skipped, the others are written', ['from' => '1700000000', 'to' => '1700086400'], array_column(
    $admin->query("SELECT s_multi, s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $item AND fk_i_field_id = $pair ORDER BY s_multi")->fetch_all(MYSQLI_ASSOC),
    's_value',
    's_multi'
));
$dao->replace($item, $pair, ['from' => '', 'to' => '']);

harness_section('deleting a field');
$gone = $field('DROPDOWN');
$dao->replace($item, $gone, 'Red');
$other = seed_item($admin, $cat, $sue);
$dao->replace($other, $gone, 'Blue');
$dao->replace($item, $drop, 'Keep');
$fired = [];
osc_add_hook('before_delete_field', static function ($id) use (&$fired): void {
    $fired[] = 'before ' . $id;
});
osc_add_hook('after_delete_field', static function ($id) use (&$fired): void {
    $fired[] = 'after ' . $id;
});
$dao->deleteByPrimaryKey($gone);
pin('the field rows are gone from every listing', '0', (string) $value("SELECT COUNT(*) FROM {$p}t_item_facet WHERE s_key = 'f$gone'"));
pin('other fields keep their rows', ["f$drop=Keep|-"], $facet($item));
pin('both hooks fired', ["before $gone", "after $gone"], $fired);

harness_section('a type change queues one rebuild job');
$jobs   = static fn (): array => $admin->query("SELECT s_unique, s_payload FROM {$p}t_job_queue WHERE s_type = '" . FacetIndex::JOB_FIELD . "' ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC);
$bare   = $field('DROPDOWN');
$svc    = FieldService::make('en_US');
$svc->update($bare, ['type' => 'TEXT']);
pin('DROPDOWN to TEXT: one job for the field, from the start, for the new kind', [[(string) $bare, json_encode(['field' => $bare, 'after' => 0, 'kind' => ''])]], array_map('array_values', $jobs()));
$svc->update($bare, ['type' => 'DROPDOWN']);
pin('and back: still one job, not two', 1, count($jobs()));
$svc->update($bare, ['type' => 'TEXT']);
$admin->query("DELETE FROM {$p}t_job_queue");
$svc->update($bare, ['type' => 'TEXTAREA']);
$svc->update($bare, ['type' => 'TEXT']);
pin('TEXT to TEXTAREA to TEXT: no job (neither keeps rows)', 0, count($jobs()));
$svc->update($bare, ['type' => 'DROPDOWN']);
$admin->query("DELETE FROM {$p}t_job_queue");
$svc->update($bare, ['type' => 'RADIO']);
pin('DROPDOWN to RADIO: no job (the same rows)', 0, count($jobs()));
$svc->update($bare, ['type' => 'TEXT']);
$admin->query("DELETE FROM {$p}t_job_queue");
$svc->update($bare, ['name' => 'Renamed']);
pin('a change that leaves the type alone: no job', 0, count($jobs()));

harness_section('the rebuild job writes the rows of the new type');
$run = static function () use ($admin, $p): void {
    $admin->query("UPDATE {$p}t_job_queue SET dt_next_run = '2000-01-01 00:00:00'");
    \mindstellar\job\JobWorker::resetRegistration();
    \mindstellar\job\JobWorker::registerHandlers();
    \mindstellar\job\JobWorker::run(10);
};
$kind  = $field('DROPDOWN');
$typed = seed_item($admin, $cat, $sue);
$dao->replace($typed, $kind, '12');
pin('as a dropdown: a text row', ["f$kind=12|-"], $facet($typed));
$svc->update($kind, ['type' => 'NUMBER']);
$run();
pin('DROPDOWN to NUMBER: the job leaves a number row', [[], ["f$kind=12|12"]], [$jobs(), $facet($typed)]);
$svc->update($kind, ['type' => 'TEXT']);
$run();
pin('NUMBER to TEXT: the job removes it', [[], []], [$jobs(), $facet($typed)]);

harness_section('a type change that rolls back queues no job');
$admin->query("CREATE TRIGGER {$p}field_block BEFORE UPDATE ON {$p}t_meta_fields FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END");
$threw = false;
try {
    $svc->update($kind, ['type' => 'DROPDOWN']);
} catch (Throwable $e) {
    $threw = true;
}
$admin->query("DROP TRIGGER {$p}field_block");
pin('the update failed', [true, 'TEXT'], [$threw, $value("SELECT e_type FROM {$p}t_meta_fields WHERE pk_i_id = $kind")]);
pin('and left no job', [], $jobs());

harness_section('a listing save waits for a held meta row');
$child  = __DIR__ . '/lib/facet-field-child.php';
$raceOne = static function (string $mode, int $itemId, int $fieldId, string $val) use ($admin, $db, $child, $sue, $p): array {
    race_hold($admin, "SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $itemId AND fk_i_field_id = $fieldId FOR UPDATE");
    $run     = race_run($child, $db, [$mode, $itemId, $sue, $fieldId, $val]);
    $waiting = race_wait_for($db, '%t_item_meta%');
    // Meta comes first: the waiting writer must not hold the listing's index rows yet.
    $free = race_facet_free($db, $itemId);
    $out  = race_release($admin, [$run]);
    preg_match('/^(ok|false|threw.*)$/m', $out[0], $m);

    return [$waiting && $free ? 1 : 0, $m[1] ?? trim($out[0])];
};
$raced = $field('DROPDOWN');
$admin->query("UPDATE {$p}t_meta_fields SET s_options = 'Old,New,Plugin' WHERE pk_i_id = $raced");
$one   = seed_item($admin, $cat, $sue);
$dao->replace($one, $raced, 'Old');
[$waiting, $out] = $raceOne('edit', $one, $raced, 'New');
pin('the save waited on the meta row, holding no index row', 1, $waiting);
pin('and then succeeded', 'ok', $out);
pin('meta and facet row both hold the new value', ['New', ["f$raced=New|-"]], [$value("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $one AND fk_i_field_id = $raced"), $facet($one)]);

harness_section('a plugin calling replace() on its own waits too');
[$waiting, $out] = $raceOne('replace', $one, $raced, 'Plugin');
pin('replace waited on the meta row, holding no index row', 1, $waiting);
pin('and then succeeded', 'ok', $out);
pin('the facet row matches the meta row', ['Plugin', ["f$raced=Plugin|-"]], [$value("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $one AND fk_i_field_id = $raced"), $facet($one)]);

harness_section('a build waits for a save in progress');
$built = seed_item($admin, $cat, $sue);
$dao->replace($built, $raced, 'Old');
// The save's first half: the meta row, held uncommitted.
race_hold($admin, "UPDATE {$p}t_item_meta SET s_value = 'New' WHERE fk_i_item_id = $built AND fk_i_field_id = $raced");
$run     = race_run($child, $db, ['build', $built, $sue, $raced, '']);
$waiting = race_wait_for($db, '%t_item_meta%LOCK IN SHARE MODE%');
// Its second half, the index row: the waiting build must not hold it.
$admin->query("DELETE FROM {$p}t_item_facet WHERE fk_i_item_id = $built AND s_key = 'f$raced'");
$admin->query("INSERT INTO {$p}t_item_facet (s_key, s_value, fk_i_item_id, fk_i_variant_id) VALUES ('f$raced', 'New', $built, 0)");
$out = race_release($admin, [$run]);
preg_match('/^(ok|false|threw.*)$/m', $out[0], $m);
pin('the build waited on the meta read', 1, $waiting);
pin('and then succeeded', 'ok', $m[1] ?? trim($out[0]));
pin('the facet row matches the committed meta', ["f$raced=New|-"], $facet($built));

harness_section('a save waits for a build in progress');
\mindstellar\database\Db::beginTransaction();
FacetIndex::rewriteItem($built);
$run     = race_run($child, $db, ['replace', $built, $sue, $raced, 'Later']);
$waiting = race_wait_for($db, '%REPLACE INTO%t_item_meta%');
\mindstellar\database\Db::commit();
$out = race_release($admin, [$run]);
preg_match('/^(ok|false|threw.*)$/m', $out[0], $m);
pin('the save waited on the meta row the build read', 1, $waiting);
pin('and then succeeded', 'ok', $m[1] ?? trim($out[0]));
pin('meta and facet row both hold the save', ['Later', ["f$raced=Later|-"]], [$value("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $built AND fk_i_field_id = $raced"), $facet($built)]);

harness_section('a field read once is not looked up again');
$forget = static function (): void {
    $map = new ReflectionProperty(FacetIndex::class, 'storages');
    $map->setAccessible(true);
    $map->setValue(null, []);
};
$looked = $field('DROPDOWN');
$forget();
$cold = harness_query_count(static fn () => $dao->replace($item, $looked, 'A'));
$forget();
FacetIndex::rememberTypes([['pk_i_id' => (string) $looked, 'e_type' => 'DROPDOWN']]);
$warm = harness_query_count(static fn () => $dao->replace($item, $looked, 'B'));
pin('after rememberTypes() a replace() runs one query less (no field lookup)', $cold - 1, $warm);
$again = harness_query_count(static fn () => $dao->replace($item, $looked, 'C'));
pin('a field looked up once is remembered for the request', $warm, $again);
pin('the row is still written', 'C', $value("SELECT s_value FROM {$p}t_item_facet WHERE fk_i_item_id = $item AND s_key = 'f$looked'"));
$svc->update($looked, ['type' => 'NUMBER']);
pin('a type change updates what the request remembers', 'number', FacetIndex::fieldKind($looked));
$admin->query("UPDATE {$p}t_meta_fields SET e_type = 'DATEINTERVAL' WHERE pk_i_id = $looked");
pin('a fresh read sees a change made elsewhere', ['number', 'interval'], [FacetIndex::fieldKind($looked), FacetIndex::fieldKind($looked, true)]);
$admin->query("DELETE FROM {$p}t_job_queue");

harness_section('a type change lost with a running job is caught at its end');
$cols = implode(', ', array_column($admin->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_item' AND COLUMN_NAME <> 'pk_i_id' ORDER BY ORDINAL_POSITION"
)->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME'));
$listings = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item")->fetch_row()[0];
$total    = $listings() + FacetIndex::BATCH + 1;
while ($listings() < $total) {
    $admin->query("INSERT INTO {$p}t_item ($cols) SELECT $cols FROM {$p}t_item LIMIT " . ($total - $listings()));
}
$ids   = array_map('intval', array_column($admin->query("SELECT pk_i_id FROM {$p}t_item ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC), 'pk_i_id'));
$moved = $field('DROPDOWN');
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) SELECT pk_i_id, $moved, '7', '' FROM {$p}t_item");
$admin->query("INSERT INTO {$p}t_item_facet (s_key, s_value, fk_i_item_id, fk_i_variant_id) SELECT 'f$moved', '7', pk_i_id, 0 FROM {$p}t_item");
$rowsOf = static fn (int $id): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_facet WHERE s_key = 'f$id'")->fetch_row()[0];
// One pass is one batch: the handler's repeat is held back an hour.
$pass = static function () use ($admin, $p): int {
    JobWorker::resetRegistration();
    JobWorker::registerHandlers();
    $real = JobRegistry::handler(FacetIndex::JOB_FIELD);
    JobRegistry::register(FacetIndex::JOB_FIELD, static function (Job $job) use ($real): void {
        $real($job);
        $again = $job->repeatRequest();
        if ($again !== null) {
            $job->repeat($again['payload'], 3600);
        }
    });
    $admin->query("UPDATE {$p}t_job_queue SET dt_next_run = '2000-01-01 00:00:00'");

    return JobWorker::run(10);
};
$drain = static function () use ($pass, $jobs): int {
    $passes = 0;
    while ($jobs() !== [] && $passes < 20) {
        $pass();
        $passes++;
    }

    return $passes;
};
$admin->query("DELETE FROM {$p}t_job_queue");
$svc->update($moved, ['type' => 'NUMBER']);
pin('the queued payload carries the kind', ['field' => $moved, 'after' => 0, 'kind' => 'number'], json_decode($jobs()[0]['s_payload'], true));
$pass();
$job = $jobs();
pin('one pass leaves the job pending at the batch-th listing, kind kept', [1, $ids[FacetIndex::BATCH - 1], 'number'], [
    count($job), (int) (json_decode($job[0]['s_payload'], true)['after'] ?? -1), json_decode($job[0]['s_payload'], true)['kind'] ?? null,
]);
pin('the first batch has number rows', FacetIndex::BATCH, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_facet WHERE s_key = 'f$moved' AND d_number = 7")->fetch_row()[0]);
$svc->update($moved, ['type' => 'TEXT']);
// The second change folded into the running row and was deleted with it.
$admin->query("DELETE FROM {$p}t_job_queue WHERE s_unique IS NOT NULL");
$drain();
pin('every listing lost its rows: the end check started over for the new kind', 0, $rowsOf($moved));

$plain = $field('DROPDOWN');
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) SELECT pk_i_id, $plain, '7', '' FROM {$p}t_item");
$admin->query("DELETE FROM {$p}t_job_queue");
$svc->update($plain, ['type' => 'NUMBER']);
pin('with no change in between the drain runs no extra pass', intdiv(count($ids), FacetIndex::BATCH) + 1, $drain());
pin('and every listing has its number row', count($ids), $rowsOf($plain));
$admin->query("DELETE FROM {$p}t_job_queue");

exit(harness_result());
