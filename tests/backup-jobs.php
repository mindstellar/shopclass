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
 * Backups and restores as jobs: a backup adds 2,000 files per run and queues itself again
 * with where it stopped; a cancel stops the next run and leaves nothing behind; a restore
 * runs its steps in order, replaces each table, closes and reopens the site, and puts the
 * safety copy back when loading the database fails.
 *
 * No database: the connection is a fake that can be told to fail.
 * Usage:  php tests/backup-jobs.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '6.4.0');
define('DB_TABLE_PREFIX', 'sc_');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hMaintenance.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\backup\BackupJobs;
use mindstellar\backup\BackupStore;
use mindstellar\backup\Builder;
use mindstellar\backup\Restorer;
use mindstellar\database\Connection;
use mindstellar\database\DbException;
use mindstellar\job\Job;

/** Records what would run, and fails on the statements it is told to. */
final class FakeConnection extends Connection
{
    /** @var string[] */
    public $ran = array();

    /** @var string[] substrings that make a statement fail */
    public $failOn = array();

    public function __construct()
    {
    }

    public function execute(string $sql, array $params = array()): int
    {
        foreach ($this->failOn as $needle) {
            if (strpos($sql, $needle) !== false) {
                throw new DbException('Database query failed', 1062);
            }
        }
        $this->ran[] = $sql;

        return 0;
    }
}

$base    = sys_get_temp_dir() . '/osc_backup_jobs_' . getmypid();
$site    = $base . '/site';
$content = $site . '/oc-content';
@mkdir($content . '/uploads/1', 0777, true);
register_shutdown_function(static function () use ($base) {
    exec('rm -rf ' . escapeshellarg($base));
});
for ($i = 1; $i <= 2050; $i++) {
    file_put_contents(sprintf('%s/uploads/1/%04d.txt', $content, $i), 'photo ' . $i);
}
$store = new BackupStore($content . '/downloads/backups/');

// Each dump writes a different row, so the backup and its safety copy can be told apart.
$dumps = 0;
$dump  = static function (string $file, callable $each) use (&$dumps): array {
    $dumps++;
    $each(0, 1, 'sc_t_probe');
    file_put_contents($file, "CREATE TABLE IF NOT EXISTS `/*TABLE_PREFIX*/t_probe` (id int);\n"
        . "insert into `/*TABLE_PREFIX*/t_probe` values\n(" . $dumps . ");\n", FILE_APPEND);

    return array('tables' => 1, 'bytes' => (int) filesize($file));
};
$builder = new Builder($store, $content, array('dump' => $dump));

$saved = array();
$logs  = array();
BackupJobs::effects(array(
    'saved'    => static function (array $p) use (&$saved) {
        $saved[] = $p;

        return true;
    },
    'restored' => static function () {
        return true;
    },
    'log'      => static function (string $text) use (&$logs) {
        $logs[] = $text;

        return true;
    },
));

/** Run a job's steps until it stops asking for more; returns the stages seen and the last payload. */
$runJob = static function (callable $handler, array $payload, int $max = 50): array {
    $stages = array($payload['stage']);
    for ($i = 0; $i < $max; $i++) {
        $job = new Job(array('pk_i_id' => '7', 's_type' => 'backup.x'), $payload);
        $handler($job);
        $repeat = $job->repeatRequest();
        if ($repeat === null) {
            return array($stages, $payload);
        }
        $payload  = $repeat['payload'];
        $stages[] = $payload['stage'];
    }

    return array($stages, $payload);
};

harness_section('A backup in batches');

$p   = Builder::begin('files', 'server');
$job = new Job(array('pk_i_id' => '7'), $p);
BackupJobs::create($job, $store, $builder);
$p = $job->repeatRequest()['payload'];
pin('the first run counts the files and opens the archive', array('files', 2050), array($p['stage'], $p['files']['total']));
$job = new Job(array('pk_i_id' => '7'), $p);
BackupJobs::create($job, $store, $builder);
$next = $job->repeatRequest();
check('a run of files asks to run again', $next !== null);
pin('...after 2,000 files', 2000, $next['payload']['files']['done']);
pin('...with the last one as its cursor', 'uploads/1/2000.txt', $next['payload']['files']['cursor']);
pin('...and the page is told', array('running', 2000, 2050), (static function (array $s): array {
    return array($s['status'], $s['files_done'], $s['files_total']);
})($store->state()));
list($stages, $done) = $runJob(static function (Job $job) use ($store, $builder) {
    BackupJobs::create($job, $store, $builder);
}, $next['payload']);
pin('the rest finishes in two more runs', array('files', 'finish'), $stages);
pin('the backup is saved and reported', array('done', 1), array($store->state()['status'], count($saved)));
$zip = new ZipArchive();
$zip->open($store->dir() . $saved[0]['name'], ZipArchive::CHECKCONS);
pin('...with every file once and the manifest', 2051, $zip->numFiles);
$zip->close();
pin('...listed with its manifest', array($saved[0]['name']), array_column($store->all(), 'name'));
pin('...and no part files left', array(), glob($store->dir() . '*.part*'));

harness_section('Cancel');

$p   = Builder::begin('files', 'server');
$job = new Job(array('pk_i_id' => '8'), $p);
BackupJobs::create($job, $store, $builder);
$p = $job->repeatRequest()['payload'];
check('fixture: the part file exists', is_file($store->dir() . $p['name'] . '.part'));
$store->requestCancel($p['run']);
$job = new Job(array('pk_i_id' => '8'), $p);
BackupJobs::create($job, $store, $builder);
pin('a cancelled backup does not run again', null, $job->repeatRequest());
pin('...it says so', 'cancelled', $store->state()['status']);
pin('...and removes what it wrote', array(), glob($store->dir() . $p['name'] . '*'));
pin('another run is not stopped by it', false, $store->cancelRequested('another-run'));

harness_section('Restore');

$dumps = 10;
list(, $everything) = $runJob(static function (Job $job) use ($store, $builder) {
    BackupJobs::create($job, $store, $builder);
}, Builder::begin('everything', 'server'));
$source = $everything['name'];
unlink($content . '/uploads/1/0001.txt');

$conn = new FakeConnection();
$lock = array();
$make = static function (array $overrides = array()) use ($store, $builder, $content, $site, $conn, &$lock): Restorer {
    return new Restorer($store, $builder, $content, $site, $overrides + array(
        'load'        => static function ($handle, callable $each) use ($conn): int {
            return BackupJobs::load($conn, $handle, $each);
        },
        'lock'        => static function () use (&$lock) {
            $lock[] = 'take';

            return static function () use (&$lock) {
                $lock[] = 'release';
            };
        },
        'migrate'     => static function () {
        },
        'after'       => static function () {
        },
        'requeue'     => static function () {
            return true;
        },
        'maintenance' => $site . '/.maintenance',
    ));
};
$restoreJob = static function (Restorer $restorer) use ($store): callable {
    return static function (Job $job) use ($store, $restorer) {
        BackupJobs::restore($job, $store, $restorer);
    };
};

$p = Restorer::begin($source, true, true);
$job = new Job(array('pk_i_id' => '9'), $p);
BackupJobs::restore($job, $store, $make());
$p = $job->repeatRequest()['payload'];
pin('the first step closes the site', OSC_MAINTENANCE_RESTORE_MARKER, file_get_contents($site . '/.maintenance'));
list($stages) = $runJob($restoreJob($make()), $p);
pin('the steps run in order', array('safety', 'database', 'files', 'finish'), array_values(array_unique($stages)));
pin('the restore is reported done', 'done', $store->state()['status']);
pin('each table is dropped before it is made again', array(
    'SET FOREIGN_KEY_CHECKS = 0',
    'DROP TABLE IF EXISTS `sc_t_probe`',
    'CREATE TABLE IF NOT EXISTS `sc_t_probe` (id int)',
    "insert into `sc_t_probe` values\n(11)",
    'SET FOREIGN_KEY_CHECKS = 1',
), $conn->ran);
pin('...under the upgrade lock, let go afterwards', array('take', 'release'), $lock);
pin('a file gone since the backup is back', 'photo 1', file_get_contents($content . '/uploads/1/0001.txt'));
pin('the site is open again', false, file_exists($site . '/.maintenance'));
$safety = array_values(array_filter($store->all(), static function (array $r) {
    return $r['kind'] === 'safety';
}));
pin('a safety copy was saved first', 1, count($safety));

harness_section('Rollback');

file_put_contents($site . '/.maintenance', 'owner');
$conn->ran    = array();
$conn->failOn = array('(11)');
list(, $last) = $runJob($restoreJob($make()), Restorer::begin($source, true, false));
$state = $store->state();
pin('a failed load stops the restore', array('failed', 'database'), array($state['status'], $state['stage']));
pin('...puts the safety copy back', true, $state['rolled_back']);
check('...by loading it after the failed backup', end($conn->ran) === 'SET FOREIGN_KEY_CHECKS = 1'
    && in_array("insert into `sc_t_probe` values\n(13)", $conn->ran, true));
pin('...keeps the site closed', OSC_MAINTENANCE_RESTORE_MARKER, file_get_contents($site . '/.maintenance'));
pin('...and gives the MySQL error number, never its words', 'A statement in the backup failed (MySQL error 1062).', $state['message']);
check('...so no value from the file reaches the log', $logs !== array() && strpos((string) end($logs), '(11)') === false
    && strpos((string) end($logs), 'MySQL error 1062') !== false, (string) end($logs));

$conn->failOn = array('(11)', '(14)');
file_put_contents($site . '/.maintenance', 'owner');
$runJob($restoreJob($make()), Restorer::begin($source, true, false));
pin('when the safety copy fails too, it says so', false, $store->state()['rolled_back']);

harness_section('Nothing changed');

$lock = array();
file_put_contents($site . '/.maintenance', 'owner');
$runJob($restoreJob($make(array('lock' => static function () {
    return null;
}))), Restorer::begin($source, true, false));
$state = $store->state();
pin('a running update stops the restore before the database', array('failed', 'database', true), array($state['status'], $state['stage'], $state['untouched']));
pin('...and the site is put back as it was', 'owner', file_get_contents($site . '/.maintenance'));

$newer = $store->dir() . 'upload-aaaaaaaaaaaaaaaa.sql';
file_put_contents($newer, "CREATE TABLE IF NOT EXISTS `oc_t_preference` (x int);\n");
unlink($site . '/.maintenance');
$runJob($restoreJob($make()), Restorer::begin('upload-aaaaaaaaaaaaaaaa.sql', true, false));
pin('an old dump with another prefix is refused at the start', array('failed', 'start'), array($store->state()['status'], $store->state()['stage']));
pin('...without closing the site', false, file_exists($site . '/.maintenance'));

harness_section('Discard keeps saved backups');

$saved0 = $store->dir() . $source;
$json0  = substr($saved0, 0, -4) . '.json';
$store->discard($source);
check('discard() leaves a saved backup in place', is_file($saved0) && is_file($json0));

$p   = Builder::begin('files', 'server');
$job = new Job(array('pk_i_id' => '10'), $p);
BackupJobs::create($job, $store, $builder);
$partial = $job->repeatRequest()['payload'];
check('fixture: a partial run has its part file', is_file($store->dir() . $partial['name'] . '.part'));
$store->discard($partial['name']);
pin('discard() removes a run that did not finish', array(), glob($store->dir() . $partial['name'] . '*'));

// Every state shape that names the saved backup; clean-up and a replayed run must leave it alone.
$restoreDone = BackupJobs::state(array('stage' => 'done') + Restorer::begin($source, true, true), 'done');
$shapes = array(
    'restore finished' => $restoreDone,
    'restore failed'   => array('status' => 'failed', 'stage' => 'database') + $restoreDone,
    'stale'            => array('status' => 'running', 'updated' => time() - 3600) + $restoreDone,
    'upload source'    => array('source' => BackupStore::uploadName('zip'), 'safety_name' => $source) + $restoreDone,
    'download name'    => BackupJobs::state(array('stage' => 'finish', 'name' => $source) + Builder::begin('everything', 'download'), 'running'),
);
$conn->failOn = array();
check('fixture: fewer backups than KEEP', count(array_filter(array_column($store->all(), 'kind'), static function ($k) {
    return $k === 'backup';
})) < BackupJobs::KEEP);
foreach ($shapes as $label => $state) {
    file_put_contents($store->dir() . '.state.json', json_encode($state));
    $store->sweep(false);
    $store->sweep(true);
    $store->prune('backup', BackupJobs::KEEP);
    $store->prune('safety', BackupJobs::SAFETY_KEEP);
    try {
        $builder->step(array('stage' => 'bogus', 'run' => 'x', 'name' => $source));
    } catch (\mindstellar\backup\BackupFailure $e) {
    }
    $runJob($restoreJob($make()), Restorer::begin($source, true, true));
    pin($label . ': the restore finishes', 'done', $store->state()['status']);
    check($label . ': the backup survives', is_file($saved0) && is_file($json0)
        && in_array($source, array_column($store->all(), 'name'), true));
}

harness_section('Pruning');

$pruneStore = new BackupStore($base . '/prune/');
@mkdir($pruneStore->dir(), 0777, true);
/** A saved file with its manifest, made $age minutes ago. */
$seedBackup = static function (string $kind, string $what, int $age) use ($pruneStore): string {
    $name = date('Y-m-d-His', time() - $age * 60) . '-' . $what . '-' . BackupStore::random(16) . '.zip';
    file_put_contents($pruneStore->dir() . $name, 'zip');
    $pruneStore->saveManifest($name, array('format' => 1, 'what' => $what, 'kind' => $kind,
        'shopclass_version' => '6.4.0', 'created' => date('c', time() - $age * 60)));

    return $name;
};
$backups = array();
$safety  = array();
for ($i = 0; $i < BackupJobs::KEEP + 2; $i++) {
    $backups[] = $seedBackup('backup', 'everything', 100 + $i * 10);
}
// Safety copies are the newest, so a prune that counted them would push real backups out.
for ($i = 0; $i < BackupJobs::SAFETY_KEEP + 2; $i++) {
    $safety[] = $seedBackup('safety', 'database', 1 + $i);
}
$download = $seedBackup('download', 'database', 0);
file_put_contents($pruneStore->dir() . 'upload-aaaaaaaaaaaaaaaa.zip', 'upload');

$names = static function (string $kind) use ($pruneStore): array {
    return array_column(array_filter($pruneStore->all(), static function (array $r) use ($kind) {
        return $r['kind'] === $kind;
    }), 'name');
};
pin('backups past the number kept go, newest kept', 2, $pruneStore->prune('backup', BackupJobs::KEEP));
pin('...exactly the newest KEEP backups stay', array_slice($backups, 0, BackupJobs::KEEP), $names('backup'));
pin('...and no safety copy is touched', count($safety), count($names('safety')));
pin('safety copies keep their own newest SAFETY_KEEP', 2, $pruneStore->prune('safety', BackupJobs::SAFETY_KEEP));
pin('...exactly those', array_slice($safety, 0, BackupJobs::SAFETY_KEEP), $names('safety'));
pin('...and the backups are still all there', array_slice($backups, 0, BackupJobs::KEEP), $names('backup'));
check('a download waiting to be fetched is never counted or pruned', is_file($pruneStore->dir() . $download));
check('...nor an upload', is_file($pruneStore->dir() . 'upload-aaaaaaaaaaaaaaaa.zip'));

BackupJobs::effects(array());

exit(harness_result());

/* file end: ./tests/backup-jobs.php */
