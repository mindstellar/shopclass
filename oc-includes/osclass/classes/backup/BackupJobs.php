<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\backup;

use mindstellar\admin\DatabaseTools;
use mindstellar\database\Connection;
use mindstellar\database\DbException;
use mindstellar\job\Job;
use mindstellar\job\JobRegistry;
use mindstellar\job\JobWorker;
use RuntimeException;

/**
 * Backups and restores as background jobs. Each run does one step and queues itself
 * again, and writes where it stands to the state file the Backup and restore page reads.
 *
 * A failed step is not retried: it is reported on the page and in the activity log, and
 * the job ends.
 */
final class BackupJobs
{
    public const CREATE  = 'backup.create';
    public const UPLOAD  = 'backup.upload';
    public const RESTORE = 'backup.restore';
    public const PRUNE   = 'backup.prune';

    /** Backups kept on the server and in the bucket, unless the backup_keep preference says otherwise. */
    public const KEEP = 5;

    /** Safety copies kept. */
    public const SAFETY_KEEP = 2;

    /** @var array<string,callable> side effects a test replaces: saved, upload, restored, log, requeue */
    private static $effects = array();

    /**
     * @return void
     */
    public static function register(): void
    {
        JobRegistry::register(self::CREATE, static function (Job $job): void {
            self::create($job);
        });
        JobRegistry::register(self::UPLOAD, static function (Job $job): void {
            self::upload($job);
        });
        JobRegistry::register(self::RESTORE, static function (Job $job): void {
            self::restore($job);
        });
        JobRegistry::register(self::PRUNE, static function (Job $job): void {
            self::prune($job);
        });
        $what = static function (array $p): string {
            return self::whatWord((string) ($p['what'] ?? ''));
        };
        JobRegistry::describe(self::CREATE, __('Make a backup'), $what);
        JobRegistry::describe(self::UPLOAD, __('Upload a backup to the bucket'), $what);
        JobRegistry::describe(self::RESTORE, __('Restore a backup'));
        JobRegistry::describe(self::PRUNE, __('Remove old backups'));
    }

    /**
     * Replace side effects, for tests. An empty array puts the real ones back.
     *
     * @param array<string,callable> $effects
     *
     * @return void
     */
    public static function effects(array $effects): void
    {
        self::$effects = $effects;
    }

    /**
     * One step of a backup.
     *
     * @param Job              $job
     * @param BackupStore|null $store
     * @param Builder|null     $builder
     *
     * @return void
     */
    public static function create(Job $job, ?BackupStore $store = null, ?Builder $builder = null): void
    {
        $store   = $store ?? BackupStore::site();
        $builder = $builder ?? self::builder($store);
        $p       = $job->payload();
        $store->saveState(self::state($p, 'running'));
        try {
            $p = $builder->step($p);
        } catch (BackupFailure $e) {
            self::ended($store, $p, $e);

            return;
        }
        if ($p['stage'] !== 'done') {
            $store->saveState(self::state($p, 'running'));
            $job->repeat($p);

            return;
        }
        if ($p['where'] === 'bucket') {
            $p['stage']  = 'upload';
            $p['upload'] = array();
            $store->saveState(self::state($p, 'running'));
            if (!self::effect('upload', $p)) {
                self::ended($store, $p, self::keep($store, $p, new BackupFailure(__('The upload could not be started.'), 'upload')));
            }

            return;
        }
        $store->saveState(self::state($p, 'done'));
        self::effect('saved', $p);
    }

    /**
     * One step of the upload of a finished backup to the bucket. Once the bucket holds it
     * at the right size, with its manifest beside it, the copy here is removed.
     *
     * @param Job              $job
     * @param BackupStore|null $store
     * @param object|null      $bucket a BackupBucket adapter
     *
     * @return void
     */
    public static function upload(Job $job, ?BackupStore $store = null, ?object $bucket = null): void
    {
        $store  = $store ?? BackupStore::site();
        $bucket = $bucket ?? BackupBucket::adapter();
        $p      = $job->payload();
        $name   = (string) $p['name'];
        $state  = (array) ($p['upload'] ?? array());
        $store->saveState(self::state($p, 'running'));
        try {
            if ($bucket === null) {
                throw new BackupFailure(BackupBucket::addressProblem() ?: __('Saving to the bucket is not set up any more.'), 'upload');
            }
            if ($store->cancelRequested((string) $p['run'])) {
                throw BackupFailure::cancelled('upload');
            }
            if ($store->path($name) === null) {
                throw new BackupFailure(__('The backup file is gone.'), 'upload');
            }
            $deadline = microtime(true) + Builder::SECONDS;
            $ok = $bucket->putLarge($store->dir() . $name, BackupBucket::key($name), static function (int $done, int $total) use (&$p, $store, $deadline): bool {
                $p['upload_done']  = $done;
                $p['upload_total'] = $total;
                $store->saveState(self::state($p, 'running'));

                return microtime(true) < $deadline && !$store->cancelRequested((string) $p['run']);
            }, $state);
            $p['upload'] = $state;
            if (!$ok) {
                throw new BackupFailure(BackupFailure::clean((string) ($state['error'] ?? '')), 'upload');
            }
            if (empty($state['done'])) {
                if ($store->cancelRequested((string) $p['run'])) {
                    throw BackupFailure::cancelled('upload');
                }
                $store->saveState(self::state($p, 'running'));
                $job->repeat($p);

                return;
            }
            // The manifest goes up last: a backup in the bucket counts only with it beside it.
            $manifest = (array) $store->manifest($name);
            $manifest['kind'] = 'backup';
            $store->saveManifest($name, $manifest);
            $side = array();
            if (!$bucket->putLarge($store->sidecarPath($name), BackupBucket::sidecarKey($name), static function (): bool {
                return true;
            }, $side) || empty($side['done'])) {
                $bucket->deleteMany(array(BackupBucket::key($name)));
                throw new BackupFailure(BackupFailure::clean((string) ($side['error'] ?? '')), 'upload');
            }
        } catch (BackupFailure $e) {
            $store->forgetBucketList();
            if ($bucket !== null) {
                $bucket->abortLarge(BackupBucket::key($name), $state);
            }
            if ($e->cancelled) {
                $store->discard($name);
            } else {
                $e = self::keep($store, $p, $e);
            }
            self::ended($store, $p, $e);

            return;
        }
        $store->forgetBucketList();
        BackupBucket::checkPublic($bucket, BackupBucket::key($name));
        $store->delete($name);
        $p['stage'] = 'done';
        $store->saveState(self::state($p, 'done'));
        self::effect('saved', $p);
    }

    /**
     * An upload that failed: keep the backup here as a saved server backup, and say so.
     *
     * @param BackupStore         $store
     * @param array<string,mixed> $p
     * @param BackupFailure       $e
     *
     * @return BackupFailure
     */
    private static function keep(BackupStore $store, array $p, BackupFailure $e): BackupFailure
    {
        $name     = (string) $p['name'];
        $manifest = $store->manifest($name);
        if ($manifest === null || $store->path($name) === null) {
            return $e;
        }
        $manifest['kind'] = 'backup';
        $store->saveManifest($name, $manifest);

        return new BackupFailure(trim($e->getMessage() . ' ' . __('The backup was kept on the server instead.')), 'upload');
    }

    /**
     * One step of a restore.
     *
     * @param Job              $job
     * @param BackupStore|null $store
     * @param Restorer|null    $restorer
     *
     * @return void
     */
    public static function restore(Job $job, ?BackupStore $store = null, ?Restorer $restorer = null, ?object $bucket = null): void
    {
        $store = $store ?? BackupStore::site();
        $p     = $job->payload();
        if (($p['stage'] ?? '') === 'fetch') {
            self::fetch($job, $store, $bucket ?? BackupBucket::adapter());

            return;
        }
        $restorer = $restorer ?? self::restorer($store, $job->id());
        $store->saveState(self::state($p, 'running'));
        try {
            $p = $restorer->step($p);
        } catch (BackupFailure $e) {
            self::ended($store, $p, $e);

            return;
        }
        if ($p['stage'] === 'done') {
            $store->saveState(self::state($p, 'done'));
            self::effect('restored', $p);

            return;
        }
        $store->saveState(self::state($p, 'running'));
        $job->repeat($p);
    }

    /**
     * One step of the download of a bucket backup into the backup folder, as an upload
     * would arrive. The restore then starts from it.
     *
     * @param Job         $job
     * @param BackupStore $store
     * @param object|null $bucket
     *
     * @return void
     */
    private static function fetch(Job $job, BackupStore $store, ?object $bucket): void
    {
        $p     = $job->payload();
        $local = $store->dir() . $p['source'];
        $state = (array) ($p['fetch'] ?? array());
        $store->saveState(self::state($p, 'running'));
        try {
            if ($bucket === null) {
                throw new BackupFailure(BackupBucket::addressProblem() ?: __('Saving to the bucket is not set up any more.'), 'fetch');
            }
            if (!preg_match(BackupStore::UPLOAD, (string) $p['source']) || !BackupStore::isName((string) $p['bucket_name'])) {
                throw new BackupFailure(__('That backup is not in the list any more.'), 'fetch');
            }
            if (!$store->protect()) {
                throw new BackupFailure(BackupStore::unwritable(), 'fetch');
            }
            $deadline = microtime(true) + Builder::SECONDS;
            $ok = $bucket->getLarge(BackupBucket::key((string) $p['bucket_name']), $local, static function (int $done, int $total) use (&$p, $store, $deadline): bool {
                $p['fetch_done']  = $done;
                $p['fetch_total'] = $total;
                $store->saveState(self::state($p, 'running'));

                return microtime(true) < $deadline;
            }, $state);
            $p['fetch'] = $state;
            if (!$ok) {
                throw new BackupFailure(BackupFailure::clean((string) ($state['error'] ?? '')), 'fetch');
            }
        } catch (BackupFailure $e) {
            @unlink($local);
            self::ended($store, $p, $e);

            return;
        }
        if (!empty($state['done'])) {
            $p['stage'] = 'start';
        }
        $store->saveState(self::state($p, 'running'));
        $job->repeat($p);
    }

    /**
     * Remove backups past the number kept.
     *
     * @param Job $job
     *
     * @return void
     */
    public static function prune(Job $job): void
    {
        $store = BackupStore::site();
        $keep  = self::keepCount();
        $store->prune('backup', $keep);
        $store->prune('safety', self::SAFETY_KEEP);
        $bucket = BackupBucket::adapter();
        if ($bucket !== null) {
            $store->bucketPrune($bucket, $keep);
        }
    }

    /**
     * How many backups are kept in each place.
     *
     * @return int
     */
    public static function keepCount(): int
    {
        $keep = (int) osc_get_preference('backup_keep');

        return $keep > 0 ? $keep : self::KEEP;
    }

    /**
     * The state the page shows, from a payload.
     *
     * @param array<string,mixed> $p
     * @param string              $status queued|running|done|failed|cancelled
     *
     * @return array<string,mixed>
     */
    public static function state(array $p, string $status): array
    {
        $state = array(
            'run'     => (string) ($p['run'] ?? ''),
            'kind'    => ($p['kind'] ?? '') === 'restore' ? 'restore' : 'backup',
            'status'  => $status,
            'stage'   => (string) ($p['stage'] ?? ''),
            'started' => (int) ($p['started'] ?? time()),
        );
        if ($state['kind'] === 'restore') {
            $safety = (array) ($p['safety'] ?? array());

            return $state + array(
                'source'         => (string) ($p['source'] ?? ''),
                'source_created' => (string) ($p['source_created'] ?? ''),
                'parts'          => (array) ($p['parts'] ?? array()),
                'statements'     => (int) ($p['statements'] ?? 0),
                'entries_done'   => (int) ($p['entries']['done'] ?? 0),
                'entries_total'  => (int) ($p['entries']['total'] ?? 0),
                'safety_name'    => (string) ($p['safety_name'] ?? ''),
                'safety_stage'   => (string) ($safety['stage'] ?? ''),
                'safety_files'   => !empty($p['safety_files']),
                'from_bucket'    => (string) ($p['bucket_name'] ?? '') !== '',
                'fetch_done'     => (int) ($p['fetch_done'] ?? 0),
                'fetch_total'    => (int) ($p['fetch_total'] ?? 0),
            );
        }

        return $state + array(
            'what'        => (string) ($p['what'] ?? ''),
            'where'       => (string) ($p['where'] ?? ''),
            'name'        => (string) ($p['name'] ?? ''),
            'size'        => (int) ($p['size'] ?? 0),
            'db_done'     => (int) ($p['db']['done'] ?? 0),
            'db_tables'   => (int) ($p['db']['tables'] ?? 0),
            'files_done'  => (int) ($p['files']['done'] ?? 0),
            'files_total' => (int) ($p['files']['total'] ?? 0),
            'bytes_done'  => (int) ($p['files']['bytes_done'] ?? 0),
            'upload_done'  => (int) ($p['upload_done'] ?? 0),
            'upload_total' => (int) ($p['upload_total'] ?? 0),
            'skipped'     => array_values((array) ($p['skipped'] ?? array())),
        );
    }

    /**
     * A Builder wired to this site. $opts replaces any of its options, and 'content'
     * points it at another oc-content.
     *
     * @param BackupStore         $store
     * @param array<string,mixed> $opts
     *
     * @return Builder
     */
    public static function builder(BackupStore $store, array $opts = array()): Builder
    {
        $content = (string) ($opts['content'] ?? ABS_PATH . 'oc-content');
        unset($opts['content']);

        return new Builder($store, $content, $opts + array(
            'db_bytes' => static function (): int {
                $size = DatabaseTools::size(Connection::instance(), DB_TABLE_PREFIX);

                return $size === null ? 0 : $size['bytes'];
            },
            'progress' => static function (array $p) use ($store): void {
                $store->saveState(self::state($p, 'running'));
            },
        ));
    }

    /**
     * A Restorer wired to this site. $opts replaces any of its options; 'content' and
     * 'site' point it at another site, and 'builder' holds options for the safety copy.
     *
     * @param BackupStore         $store
     * @param int                 $jobId the job running it
     * @param array<string,mixed> $opts
     *
     * @return Restorer
     */
    public static function restorer(BackupStore $store, int $jobId, array $opts = array()): Restorer
    {
        $conn    = Connection::instance();
        $content = (string) ($opts['content'] ?? ABS_PATH . 'oc-content');
        $site    = (string) ($opts['site'] ?? ABS_PATH);
        $builder = self::builder($store, array('content' => $content) + (array) ($opts['builder'] ?? array()));
        unset($opts['content'], $opts['site'], $opts['builder']);

        return new Restorer($store, $builder, $content, $site, $opts + array(
            'load'     => static function ($handle, callable $each) use ($conn): int {
                return self::load($conn, $handle, $each);
            },
            'lock'     => static function () use ($conn): ?callable {
                try {
                    return DatabaseTools::upgradeLock($conn);
                } catch (DbException $e) {
                    return null;
                }
            },
            'migrate'  => static function (): void {
                $result = DatabaseTools::upgrade();
                if ($result['error'] !== 0) {
                    throw new RuntimeException($result['message'] !== '' ? $result['message'] : __('The database update failed.'));
                }
            },
            'after'    => static function (): void {
                if (function_exists('osc_cache_flush')) {
                    osc_cache_flush();
                }
                osc_reset_preferences();
                if (function_exists('osc_calculate_location_slug')) {
                    osc_calculate_location_slug(osc_subdomain_type());
                }
            },
            'requeue'  => static function () use ($jobId): bool {
                return self::effect('requeue', $jobId);
            },
            'progress' => static function (array $p) use ($store): void {
                $store->saveState(self::state($p, 'running'));
            },
        ));
    }

    /**
     * Load an SQL backup, replacing this site's tables. A failure names the MySQL error
     * number only: the server's own words can quote values from the file.
     *
     * @param Connection $conn
     * @param resource   $handle
     * @param callable   $each fn(int $ran): void after each statement
     *
     * @return int statements run
     * @throws RuntimeException when a statement fails
     */
    public static function load(Connection $conn, $handle, callable $each): int
    {
        try {
            return DatabaseTools::restore($conn, $handle, $each, true);
        } catch (DbException $e) {
            throw new RuntimeException($e->getCode() > 0
                ? sprintf(__('A statement in the backup failed (MySQL error %d).'), $e->getCode())
                : __('A statement in the backup failed.'));
        }
    }

    /**
     * Record a run that failed or was cancelled.
     *
     * @param BackupStore         $store
     * @param array<string,mixed> $p
     * @param BackupFailure       $e
     *
     * @return void
     */
    private static function ended(BackupStore $store, array $p, BackupFailure $e): void
    {
        $state = self::state($p, $e->cancelled ? 'cancelled' : 'failed');
        $state['stage']       = $e->stage;
        $state['message']     = $e->getMessage();
        $state['rolled_back'] = $e->rolledBack;
        $state['untouched']   = ($p['kind'] ?? '') === 'restore' && Restorer::untouched($e);
        $state['finished']    = time();
        $store->saveState($state);
        if (!$e->cancelled) {
            self::effect('log', (($p['kind'] ?? '') === 'restore' ? __('Restore failed') : __('Backup failed')) . ': ' . $e->getMessage());
        } else {
            self::effect('log', __('Backup cancelled'));
        }
    }

    /**
     * Run a side effect, or its test replacement.
     *
     * @param string $name
     * @param mixed  $arg
     *
     * @return bool
     */
    private static function effect(string $name, $arg): bool
    {
        if (isset(self::$effects[$name])) {
            return (bool) (self::$effects[$name])($arg);
        }
        switch ($name) {
            case 'log':
                JobWorker::log('backup', 0, (string) $arg);

                return true;
            case 'saved':
                return self::saved($arg);
            case 'upload':
                return osc_job_enqueue(self::UPLOAD, $arg, array('unique_key' => self::UPLOAD)) > 0;
            case 'restored':
                $when = self::when((string) ($arg['source_created'] ?? ''));
                JobWorker::log('backup', 0, $when !== '' ? sprintf(__('Restore finished from the backup of %s'), $when) : __('Restore finished'));
                osc_job_enqueue(self::PRUNE, array(), array('unique_key' => 'prune'));

                return true;
            case 'requeue':
                return self::requeue($arg);
        }

        return false;
    }

    /**
     * After a backup is saved: remember it, log it, and prune the old ones.
     *
     * @param array<string,mixed> $p
     *
     * @return bool
     */
    private static function saved(array $p): bool
    {
        $words = array('download' => __('to download'), 'bucket' => __('in the bucket'));
        $where = $words[$p['where']] ?? __('on the server');
        $line  = sprintf(
            __('Backup saved: %1$s, %2$s, %3$s, %4$s'),
            self::when(date('c', (int) $p['started'])),
            self::whatWord((string) $p['what']),
            DatabaseTools::bytes((int) ($p['size'] ?? 0)),
            $where
        );
        JobWorker::log('backup', 0, $line);
        if (in_array($p['where'], array('server', 'bucket'), true) && ($p['kind'] ?? 'backup') === 'backup') {
            osc_set_preference('backup_last', (string) json_encode(array(
                'date'  => date('c', (int) $p['started']),
                'what'  => $p['what'],
                'where' => $p['where'],
                'size'  => (int) ($p['size'] ?? 0),
            )), 'osclass', 'STRING');
            osc_job_enqueue(self::PRUNE, array(), array('unique_key' => 'prune'));
        }

        return true;
    }

    /**
     * Put the running restore's own row back after the database step replaced the queue
     * table, so the worker can queue its next step. Backup jobs the old table brought
     * back are stale and go; another job restored under the same id is moved aside.
     *
     * @param int $id
     *
     * @return bool
     */
    private static function requeue(int $id): bool
    {
        $table = DB_TABLE_PREFIX . 't_job_queue';
        try {
            osc_db_execute('DELETE FROM ' . $table . ' WHERE s_type LIKE ?', array('backup.%'));
            $other = osc_db_table($table)->where('pk_i_id', $id)->first();
            if ($other !== null) {
                unset($other['pk_i_id']);
                osc_db_table($table)->insert($other);
                osc_db_table($table)->where('pk_i_id', $id)->delete();
            }
            $now = date('Y-m-d H:i:s');
            osc_db_table($table)->insert(array(
                'pk_i_id'     => $id,
                's_type'      => self::RESTORE,
                's_payload'   => '{}',
                's_status'    => 'running',
                'dt_next_run' => $now,
                'dt_created'  => $now,
                'dt_locked'   => $now,
            ));
        } catch (DbException $e) {
            return false;
        }

        return true;
    }

    /**
     * What a backup holds, in a word.
     *
     * @param string $what
     *
     * @return string
     */
    public static function whatWord(string $what): string
    {
        $words = array(
            'database'   => __('Database'),
            'files'      => __('Files'),
            'everything' => __('Everything'),
        );

        return $words[$what] ?? $what;
    }

    /**
     * An ISO date as the site shows dates, with the time.
     *
     * @param string $iso
     *
     * @return string
     */
    public static function when(string $iso): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return '';
        }

        return date('j M Y H:i', $ts);
    }
}
