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
 * StorageJobs::purgeAfterCommit(): files on this server go at once; files on remote storage go
 * through one storage.purge job per batch of rows, and the job removes every variant there and here.
 *
 * Usage:  php tests/storage-purge.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\job\Job;
use mindstellar\storage\StorageAdapter;
use mindstellar\storage\StorageJobs;
use mindstellar\storage\StorageManager;

$GLOBALS['purge_pref'] = 'local';
$GLOBALS['purge_jobs'] = array();
$GLOBALS['purge_root'] = sys_get_temp_dir() . '/osc-purge-' . getmypid() . '/';

function osc_get_preference($key, $section = 'osclass')
{
    return $key === 'storage_active' ? $GLOBALS['purge_pref'] : '';
}

function osc_base_path()
{
    return $GLOBALS['purge_root'];
}

function osc_job_enqueue(string $type, array $payload = array(), array $options = array()): int
{
    $GLOBALS['purge_jobs'][] = array($type, $payload);

    return count($GLOBALS['purge_jobs']);
}

final class PurgeFakeRemote implements StorageAdapter
{
    /** @var string[] */
    public array $deleted = array();

    public function getId(): string
    {
        return 'fake';
    }

    public function put(string $localPath, string $key, string $contentType): bool
    {
        return true;
    }

    public function get(string $key): string|false
    {
        return false;
    }

    public function exists(string $key): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        $this->deleted[] = $key;

        return true;
    }

    public function url(string $key): string
    {
        return '';
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function isPublic(): bool
    {
        return true;
    }
}

/** A photo row with every variant on disk. */
function purge_row(int $id, string $storage = 'local'): array
{
    $row = array(
        'pk_i_id' => $id, 'fk_i_item_id' => 7, 's_name' => 'secret', 's_path' => 'oc-content/uploads/0/',
        's_extension' => 'jpg', 's_content_type' => 'image/jpeg', 's_storage' => $storage,
    );
    @mkdir($GLOBALS['purge_root'] . $row['s_path'], 0777, true);
    foreach (array('', '_original', '_preview', '_thumbnail') as $variant) {
        file_put_contents($GLOBALS['purge_root'] . $row['s_path'] . $id . $variant . '.jpg', 'x');
    }

    return $row;
}

function purge_files_left(): int
{
    return count(glob($GLOBALS['purge_root'] . 'oc-content/uploads/0/*.jpg') ?: array());
}

harness_section('a site with no remote storage');
StorageJobs::purgeAfterCommit(array(purge_row(1), purge_row(2)));
pin('the files go at once and nothing is queued', array(0, array()), array(purge_files_left(), $GLOBALS['purge_jobs']));

harness_section('a site with remote storage');
$remote = new PurgeFakeRemote();
StorageManager::getInstance()->register($remote);
$GLOBALS['purge_pref'] = 'fake';
StorageJobs::purgeAfterCommit(array(purge_row(3, 'fake'), purge_row(4, 'fake'), purge_row(5)));
pin('one job carries every row, without the delete code', array(1, 'storage.purge', array(3, 4, 5), false), array(
    count($GLOBALS['purge_jobs']),
    $GLOBALS['purge_jobs'][0][0],
    array_column($GLOBALS['purge_jobs'][0][1]['rows'], 'pk_i_id'),
    isset($GLOBALS['purge_jobs'][0][1]['rows'][0]['s_name']),
));
pin('the files stay until the job runs', 12, purge_files_left());

$purge = new ReflectionMethod(StorageJobs::class, 'purge');
$purge->setAccessible(true);
$purge->invoke(null, new Job(array('s_type' => 'storage.purge'), $GLOBALS['purge_jobs'][0][1]));
pin('the job removes every variant from remote storage and from this server', array(8, 0), array(count($remote->deleted), purge_files_left()));
pin('a row on this server is not sent to remote storage', array(), preg_grep('/^0\/5/', $remote->deleted));
$purge->invoke(null, new Job(array('s_type' => 'storage.purge'), $GLOBALS['purge_jobs'][0][1]));
pin('running it twice is not an error', 0, purge_files_left());

harness_section('unsafe rows');
@mkdir($GLOBALS['purge_root'] . 'outside', 0777, true);
file_put_contents($GLOBALS['purge_root'] . 'outside/9.jpg', 'keep');
$purge->invoke(null, new Job(array('s_type' => 'storage.purge'), array('rows' => array(
    array('pk_i_id' => 9, 's_path' => 'oc-content/uploads/../../outside/', 's_extension' => 'jpg', 's_storage' => 'local'),
    array('pk_i_id' => 9, 's_path' => $GLOBALS['purge_root'] . 'outside/', 's_extension' => 'jpg', 's_storage' => 'local'),
    array('pk_i_id' => 9, 's_path' => 'outside/', 's_extension' => 'jpg/../../x', 's_storage' => 'local'),
))));
pin('a job never deletes outside the site folder', true, is_file($GLOBALS['purge_root'] . 'outside/9.jpg'));
@unlink($GLOBALS['purge_root'] . 'outside/9.jpg');
@rmdir($GLOBALS['purge_root'] . 'outside');

$GLOBALS['purge_pref'] = 'local';
$stuck = purge_row(10);
@mkdir($GLOBALS['purge_root'] . 'oc-content/uploads/locked/', 0777, true);
$locked = array_merge($stuck, array('pk_i_id' => 11, 's_path' => 'oc-content/uploads/locked/'));
file_put_contents($GLOBALS['purge_root'] . 'oc-content/uploads/locked/11.jpg', 'x');
chmod($GLOBALS['purge_root'] . 'oc-content/uploads/locked/', 0555);
$errorLog = ini_set('error_log', '/dev/null');
StorageJobs::purgeAfterCommit(array($locked, $stuck));
ini_set('error_log', (string) $errorLog);
chmod($GLOBALS['purge_root'] . 'oc-content/uploads/locked/', 0777);
pin('a file that cannot be removed does not keep the next row\'s files', 0, purge_files_left());
@unlink($GLOBALS['purge_root'] . 'oc-content/uploads/locked/11.jpg');
@rmdir($GLOBALS['purge_root'] . 'oc-content/uploads/locked');
$GLOBALS['purge_pref'] = 'fake';

harness_section('many rows');
$GLOBALS['purge_jobs'] = array();
StorageJobs::purgeAfterCommit(array_map(static fn (int $id): array => array('pk_i_id' => $id, 's_storage' => 'fake'), range(1, StorageJobs::PURGE_BATCH * 2 + 1)));
pin('rows are split into batches', array(StorageJobs::PURGE_BATCH, StorageJobs::PURGE_BATCH, 1), array_map(
    static fn (array $job): int => count($job[1]['rows']),
    $GLOBALS['purge_jobs']
));

@array_map('unlink', glob($GLOBALS['purge_root'] . 'oc-content/uploads/0/*') ?: array());
@rmdir($GLOBALS['purge_root'] . 'oc-content/uploads/0');
@rmdir($GLOBALS['purge_root'] . 'oc-content/uploads');
@rmdir($GLOBALS['purge_root'] . 'oc-content');
@rmdir($GLOBALS['purge_root']);

exit(harness_result());
