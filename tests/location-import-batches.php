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
 * Pins the batched location import: a large country is split into several bounded batches
 * that each commit, a repeated batch adds no rows, the end result matches a one-pass
 * import, and a failed batch leaves the installer finishable with places under "To finish later".
 *
 * DB-backed.  Usage: php tests/location-import-batches.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\location\LocationImporter;
use mindstellar\location\LocationImportJob;

$admin  = scratchdb_session('osc_location_import_batches');
require_once LIB_PATH . 'osclass/helpers/hJobs.php';
if (!function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}
require_once LIB_PATH . 'osclass/install-functions.php';
// The session starts before any output, so its cookie can still be set.
$session = Session::getInstance();
$session->_set('install_location_failed', '');
$prefix = DB_TABLE_PREFIX;
$count  = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];
$cities = static fn (string $code): int => $count("SELECT COUNT(*) FROM {$prefix}t_city WHERE fk_c_country_code = '{$code}'");
$dupes  = static fn (string $code): int => $count("SELECT COUNT(*) - COUNT(DISTINCT i_source_id) FROM {$prefix}t_city WHERE fk_c_country_code = '{$code}'");

// 2,500 + 300 cities over two regions, with an empty region between them.
$fixture = static function (string $code, int $idBase): array {
    $region = static function (string $name, int $id, int $n) use ($idBase): array {
        $settlements = array();
        for ($i = 1; $i <= $n; $i++) {
            $settlements[] = array('id' => $idBase + $id * 10000 + $i, 'name' => "$name town $i", 'slug' => strtolower("$name-town-$i"));
        }

        return array('id' => $idBase + $id, 'name' => $name, 'slug' => strtolower($name), 'settlements' => $settlements);
    };

    return array('code' => $code, 'name' => "Land $code", 'slug' => "land-$code", 'regions' => array(
        $region('North', 1, 2500),
        $region('Empty', 2, 0),
        $region('South', 3, 300),
    ));
};
$file = static function (array $data): string {
    $path = tempnam(sys_get_temp_dir(), 'loc-batches-');
    LocationImporter::writeNdjson($data, $path);

    return $path;
};

harness_section('a large country is split into bounded batches');
$path    = $file($fixture('ZZ', 0));
$state   = array();
$batches = 0;
$seen    = array();
$states  = array();
do {
    $states[] = $state;
    // A deadline already passed: each batch stops after its first slice.
    $state = (new LocationImporter())->importNdjsonBatch($path, $state, microtime(true));
    $batches++;
    $seen[] = $cities('ZZ');
} while (!$state['done'] && $batches < 50);
check('it took several batches', $batches >= 4, (string) $batches);
$rising = $seen;
sort($rising);
check('each batch committed its own rows', $seen[0] > 0 && $seen[0] < 2800 && $rising === $seen && count(array_unique($seen)) >= 3, json_encode($seen));
check('no batch did more than one slice of cities', max(array_map(static fn ($a, $b) => $b - $a, array_merge(array(0), array_slice($seen, 0, -1)), $seen)) <= 1000);
check('progress ends at 1', $state['progress'] === 1.0);
pin('every city is there once', array(2800, 0), array($cities('ZZ'), $dupes('ZZ')));
pin('the empty region is skipped', 2, $count("SELECT COUNT(*) FROM {$prefix}t_region WHERE fk_c_country_code = 'zz'"));
pin('the report adds up across batches', array(2800, 2, 1), array($state['report']['cities']['inserted'], $state['report']['regions']['inserted'], $state['report']['regions']['skipped_empty']));

harness_section('a repeated batch adds nothing');
$again = (new LocationImporter())->importNdjsonBatch($path, $states[2], microtime(true));
pin('running batch 3 again leaves the same rows', array(2800, 0), array($cities('ZZ'), $dupes('ZZ')));
check('and carries on where it did before', $again['offset'] === $states[3]['offset'] && $again['slice'] === $states[3]['slice']);
$active = $count("SELECT COUNT(*) FROM {$prefix}t_city WHERE fk_c_country_code = 'ZZ' AND b_active = 1");
$re     = array();
do {
    $re = (new LocationImporter())->importNdjsonBatch($path, $re, microtime(true));
} while (!$re['done']);
pin('a second import in batches changes nothing', array(0, 2800, 0, 2800), array(
    $re['report']['cities']['inserted'],
    $re['report']['cities']['unchanged'],
    $re['report']['cities']['deactivated'],
    $count("SELECT COUNT(*) FROM {$prefix}t_city WHERE fk_c_country_code = 'ZZ' AND b_active = 1"),
));
check('all cities stayed active', $active === 2800);
@unlink($path);

harness_section('batches end where one pass ends');
$one = (new LocationImporter())->import($fixture('ZY', 500000));
$sig = static fn (string $code): string => (string) $admin->query("SELECT GROUP_CONCAT(CONCAT(r.s_name, '/', c.s_name, '/', c.b_active) ORDER BY c.i_source_id) FROM {$prefix}t_city c JOIN {$prefix}t_region r ON r.pk_i_id = c.fk_i_region_id WHERE c.fk_c_country_code = '{$code}'")->fetch_row()[0];
$admin->query('SET SESSION group_concat_max_len = 10000000');
check('one pass and batches give the same places', str_replace(array('ZY', 'Zy'), array('ZZ', 'Zz'), $sig('ZY')) === $sig('ZZ') && $one['cities']['inserted'] === 2800);

harness_section('the job keeps its place between requests');
$source = $file($fixture('ZX', 900000));
$fetch  = static fn (array $entry, string $to): bool => copy($source, $to);
$entry  = array('code' => 'ZX', 'name' => 'Land ZX', 'file' => 'ZX.json', 'sha' => '', 'ndjson' => 'ZX.ndjson', 'installed' => false);
$job    = new LocationImportJob(false, $fetch);
$start  = $job->start($entry);
check('start only downloads', $start['ok'] && !$start['done'] && $cities('ZX') === 0);
$steps = 0;
do {
    $step = (new LocationImportJob(false, $fetch))->step('ZX', 0.0);
    $steps++;
} while ($step['ok'] && !$step['done'] && $steps < 50);
check('steps run until done', $step['ok'] && $step['done'] && $steps >= 4, (string) $steps);
pin('every city is there once', array(2800, 0), array($cities('ZX'), $dupes('ZX')));
check('the finished job keeps only a short done marker', $count("SELECT COUNT(*) FROM {$prefix}t_key_value WHERE s_group = 'location_import' AND s_value LIKE '%\"finished\":true%'") === 1);
check('a step for a country never started fails cleanly', (new LocationImportJob(false, $fetch))->step('ZV')['ok'] === false);
check('a failed download fails the start', (new LocationImportJob(false, static fn (): bool => false))->start($entry)['ok'] === false);

harness_section('two requests for one country');
$entryW = array('code' => 'ZW', 'file' => 'ZW.json', 'sha' => '', 'ndjson' => 'ZW.ndjson');
$sourceW = $file($fixture('ZW', 700000));
$fetchW  = static fn (array $entry, string $to): bool => copy($sourceW, $to);
$jobW    = static fn (): LocationImportJob => new LocationImportJob(false, $fetchW);
$filesBefore = glob(osc_uploads_path() . 'locations-*.ndjson') ?: array();
$jobW()->start($entryW);
$jobW()->step('ZW', 0.0);
$before = $cities('ZW');
$name   = $jobW()->lockName('ZW');
// A second connection holds the lock, as a batch still running in another request would.
$other  = new mysqli(getenv('DRIFT_DB_HOST') ?: '127.0.0.1', getenv('DRIFT_DB_USER') ?: 'root', getenv('DRIFT_DB_PASS') === false ? 'root' : getenv('DRIFT_DB_PASS'), '', (int) (getenv('DRIFT_DB_PORT') ?: 33061));
$other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 0)");
$busy = $jobW()->step('ZW', 0.0);
check('a batch asked for while another runs answers busy and imports nothing', !empty($busy['busy']) && $busy['ok'] && !$busy['done'] && $cities('ZW') === $before, json_encode($busy));
$other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($name) . "')");
$again = $jobW()->start($entryW);
check('a second start carries on the running job', $again['ok'] && $again['progress'] > 0 && $cities('ZW') === $before, json_encode($again));
do {
    $step = $jobW()->step('ZW', 0.0);
} while ($step['ok'] && !$step['done']);
pin('the carried-on job ends with every city once', array(2800, 0), array($cities('ZW'), $dupes('ZW')));
$repeat = $jobW()->step('ZW', 0.0);
check('the last batch asked for again answers done', $repeat['ok'] && $repeat['done'], json_encode($repeat));
check('no country file is left in the upload folder', array_diff(glob(osc_uploads_path() . 'locations-*.ndjson') ?: array(), $filesBefore) === array());
@unlink($sourceW);
@unlink($source);

harness_section('a failed batch leaves the install finishable');
$calls   = array();
$flaky   = static function (string $code, int $batch) use (&$calls): array {
    $calls[] = $batch;
    if ($batch === 2) {
        throw new RuntimeException('gateway');
    }

    return array('ok' => true, 'done' => false, 'progress' => $batch / 4);
};
$first  = install_locations_unit('ZX', 0, $flaky);
$second = install_locations_unit('ZX', 1, $flaky);
check('a batch with more to do asks for the next', $first['status'] && $first['more'] && $second['more']);
check('the country stays listed while batches remain', $session->_get('install_location_failed') === 'ZX');
$failed = install_locations_unit('ZX', 2, $flaky);
check('a failed batch answers false with a message', $failed['status'] === false && $failed['message'] !== '' && empty($failed['more']));
check('and the done step lists places to finish later', $session->_get('install_location_failed') === 'ZX'
    && (new \mindstellar\install\FinishLater(array('install_location_failed' => $session->_get('install_location_failed')), array(), null))->items() !== array());
$last = install_locations_unit('ZX', 3, static fn (): array => array('ok' => true, 'done' => true, 'progress' => 1.0));
check('a last batch that works clears it', $last['status'] && empty($last['more']) && $session->_get('install_location_failed') === '');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
