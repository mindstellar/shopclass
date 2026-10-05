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
use RuntimeException;
use Throwable;

/**
 * Puts a backup back, in steps: start (check it and close the site), safety copy,
 * database (one run, under the upgrade lock, put back from the safety copy if it fails),
 * files a batch at a time, finish (caches, reopen the site).
 *
 * The database step replaces t_job_queue with the backup's, so `requeue` then puts the
 * running job's row back for the rest of the run.
 */
final class Restorer
{
    /** Statements between two progress reports. */
    private const REPORT_EVERY = 500;

    /** @var BackupStore */
    private $store;

    /** @var Builder makes the safety copy */
    private $builder;

    /** @var string real path of oc-content */
    private $content;

    /** @var array<string,mixed> */
    private $opts;

    /**
     * @param BackupStore         $store
     * @param Builder             $builder
     * @param string              $content oc-content
     * @param string              $site    the site folder
     * @param array<string,mixed> $opts    callables: load fn($handle, callable $each): int, lock fn(): ?callable,
     *                                     migrate fn(): void, after fn(): void, requeue fn(): bool,
     *                                     progress fn(array $p): void; maintenance (the .maintenance path);
     *                                     batch, seconds; source_path, a backup file outside the store
     */
    public function __construct(BackupStore $store, Builder $builder, string $content, string $site, array $opts)
    {
        $this->store   = $store;
        $this->builder = $builder;
        $this->content = rtrim((string) realpath($content), '/');
        $this->opts    = $opts + array(
            'progress'    => static function (array $p): void {
            },
            'maintenance' => rtrim($site, '/') . '/.maintenance',
            'batch'       => Builder::BATCH,
            'seconds'     => Builder::SECONDS,
            'source_path' => null,
        );
    }

    /**
     * The payload of a new restore.
     *
     * @param string $name  a saved backup or an upload in the store
     * @param bool   $db    put back the database
     * @param bool   $files put back the files
     *
     * @return array<string,mixed>
     */
    public static function begin(string $name, bool $db, bool $files): array
    {
        return array(
            'run'        => BackupStore::random(12),
            'kind'       => 'restore',
            'source'     => $name,
            'parts'      => array('database' => $db, 'files' => $files),
            'stage'      => 'start',
            'safety'     => null,
            'safety_name' => '',
            'entries'    => array('total' => 0, 'done' => 0, 'next' => 0, 'refused' => 0),
            'statements' => 0,
            'migrate'    => true,
            'maintenance_was' => null,
            'started'    => time(),
        );
    }

    /**
     * The payload of a new restore of a backup in the bucket, downloaded first as an upload.
     *
     * @param string $bucketName the backup's name in the bucket
     * @param bool   $db
     * @param bool   $files
     *
     * @return array<string,mixed>
     */
    public static function beginFetch(string $bucketName, bool $db, bool $files): array
    {
        $p = self::begin(BackupStore::uploadName('zip'), $db, $files);
        $p['stage']       = 'fetch';
        $p['bucket_name'] = $bucketName;
        $p['fetch']       = array();

        return $p;
    }

    /**
     * Whether there is room for a safety copy of the files: twice their size, or the disk does not say.
     *
     * @param int|null $free
     * @param int      $bytes
     *
     * @return bool
     */
    public static function roomForFileSafety(?int $free, int $bytes): bool
    {
        return $free === null || $free >= 2 * $bytes;
    }

    /**
     * What a backup file holds and whether it may be restored here, without changing
     * anything. Sizes come from the zip's own records, never from its manifest.
     *
     * @param string      $path
     * @param string|null $content oc-content, to check its free space against the files
     * @param int|null    $free    free bytes there; null asks the disk
     *
     * @return array{ok:bool,reason:string,migrate:bool,note:string,manifest:?array,database:bool,files:int,db_bytes:int,files_bytes:int}
     */
    public static function inspect(string $path, ?string $content = null, ?int $free = null): array
    {
        $out = array('manifest' => null, 'database' => false, 'files' => 0, 'db_bytes' => 0, 'files_bytes' => 0);
        if (strtolower(substr($path, -4)) === '.sql') {
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                return Manifest::refuse(__('The file cannot be read.')) + $out;
            }
            $prefix = Manifest::sqlPrefix($handle);
            fclose($handle);

            return Manifest::check(null, OSCLASS_VERSION, DB_TABLE_PREFIX, $prefix)
                + array('database' => true, 'db_bytes' => (int) filesize($path)) + $out;
        }
        try {
            $archive = new BackupArchive($path);
        } catch (RuntimeException $e) {
            return Manifest::refuse(__('This file is not a Shopclass backup.')) + $out;
        }
        $manifest = $archive->manifest();
        $sizes    = $archive->measure($content !== null ? (string) realpath($content) : null);
        $out      = array(
            'manifest'    => $manifest,
            'database'    => $archive->hasDatabase(),
            'files'       => $archive->fileCount(),
            'db_bytes'    => $sizes['database'],
            'files_bytes' => $sizes['files'],
        );
        $archive->close();
        if ($manifest === null) {
            return Manifest::refuse(__('This file is not a Shopclass backup.')) + $out;
        }
        if (!$sizes['safe']) {
            return Manifest::refuse(__('This file cannot be restored: what it holds is too large or too compressed to be a real backup.')) + $out;
        }
        if ($content !== null && $free === null) {
            $disk = @disk_free_space($content);
            $free = $disk === false ? null : (int) $disk;
        }
        if ($content !== null && $free !== null && $sizes['need'] > $free) {
            return Manifest::refuse(sprintf(
                __('There is not enough free space to put back the files: %1$s needed, %2$s free.'),
                DatabaseTools::bytes($sizes['need']),
                DatabaseTools::bytes($free)
            )) + $out;
        }

        return Manifest::check($manifest, OSCLASS_VERSION, DB_TABLE_PREFIX) + $out;
    }

    /**
     * Run the next step and return the payload for the one after; 'done' when finished.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     * @throws BackupException
     */
    public function step(array $p): array
    {
        $stage = (string) $p['stage'];
        try {
            switch ($stage) {
                case 'start':
                    return $this->start($p);
                case 'safety':
                    return $this->safety($p);
                case 'database':
                    return $this->database($p);
                case 'files':
                    return $this->files($p);
                case 'finish':
                    return $this->finish($p);
            }
            throw new RuntimeException('Unknown restore stage');
        } catch (BackupException $e) {
            $failure = $e;
        } catch (Throwable $e) {
            $failure = new BackupException(BackupException::clean($e->getMessage()), $stage);
        }
        // Nothing was changed yet: open the site again as it was.
        if (self::untouched($failure)) {
            $this->reopen($p);
        }
        throw $failure;
    }

    /**
     * Whether a step changed nothing yet, so a failure there leaves the site as it was.
     *
     * @param BackupException $e
     *
     * @return bool
     */
    public static function untouched(BackupException $e): bool
    {
        return $e->rolledBack === null && in_array($e->stage, array('fetch', 'start', 'safety', 'database'), true);
    }

    /**
     * Check the backup again and close the site.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function start(array $p): array
    {
        $path = $this->source($p);
        if ($path === null) {
            throw new BackupException(__('The backup file is gone.'), 'start');
        }
        $info = self::inspect($path, $p['parts']['files'] ? $this->content : null);
        if (!$info['ok']) {
            throw new BackupException($info['reason'], 'start');
        }
        $p['parts']['database'] = !empty($p['parts']['database']) && $info['database'];
        $p['parts']['files']    = !empty($p['parts']['files']) && $info['files'] > 0;
        if (!$p['parts']['database'] && !$p['parts']['files']) {
            throw new BackupException(__('There is nothing to put back.'), 'start');
        }
        $p['migrate']          = $info['migrate'];
        $p['entries']['total'] = $p['parts']['files'] ? $info['files'] : 0;

        $file = (string) $this->opts['maintenance'];
        $was  = is_file($file) ? @file_get_contents($file) : null;
        $p['maintenance_was'] = is_string($was) ? $was : null;
        if (@file_put_contents($file, OSC_MAINTENANCE_RESTORE_MARKER) === false) {
            throw new BackupException(__('The site could not be put in maintenance mode.'), 'start');
        }

        $filesBytes = $info['files_bytes'];
        $free       = $this->store->freeSpace();
        $roomFiles  = $p['parts']['files'] && self::roomForFileSafety($free, $filesBytes);
        $p['safety_files'] = $roomFiles;
        if ($p['parts']['database']) {
            $p['safety'] = Builder::begin($roomFiles ? 'everything' : 'database', 'server', 'safety');
        } elseif ($roomFiles) {
            $p['safety'] = Builder::begin('files', 'server', 'safety');
        }
        $p['stage'] = $p['safety'] !== null ? 'safety' : 'files';

        return $p;
    }

    /**
     * One step of the safety copy.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function safety(array $p): array
    {
        try {
            $p['safety'] = $this->builder->step((array) $p['safety']);
        } catch (BackupException $e) {
            throw new BackupException(
                sprintf(__('The safety copy could not be saved: %s'), $e->getMessage()),
                'safety'
            );
        }
        ($this->opts['progress'])($p);
        if ($p['safety']['stage'] === 'done') {
            $p['safety_name'] = (string) $p['safety']['name'];
            $p['stage']       = $p['parts']['database'] ? 'database' : 'files';
        }

        return $p;
    }

    /**
     * Load the database in one run, and put the safety copy back if that fails.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function database(array $p): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);
        $release = ($this->opts['lock'])();
        if ($release === null) {
            throw new BackupException(__('A database update is running. Try again in a few minutes.'), 'database');
        }
        try {
            try {
                $this->load($this->source($p), $p);
            } catch (Throwable $e) {
                $reason = BackupException::clean($e->getMessage());
                throw new BackupException($reason, 'database', $this->rollback($p));
            }
        } finally {
            $release();
        }

        if (!empty($p['migrate'])) {
            try {
                ($this->opts['migrate'])();
            } catch (Throwable $e) {
                throw new BackupException(BackupException::clean($e->getMessage()), 'updates');
            }
        }

        $p['stage'] = $p['parts']['files'] ? 'files' : 'finish';
        if (!($this->opts['requeue'])()) {
            throw new BackupException(__('The restore could not carry on in the background.'), 'updates');
        }

        return $p;
    }

    /**
     * The backup file being restored: one in the store, or the file given by path.
     *
     * @param array<string,mixed> $p
     *
     * @return string|null
     */
    private function source(array $p): ?string
    {
        $given = $this->opts['source_path'];
        if (is_string($given)) {
            return is_file($given) ? $given : null;
        }

        return $this->store->path((string) $p['source']);
    }

    /**
     * Run a backup's database: a zip's database.sql or a bare .sql file.
     *
     * @param string|null         $path
     * @param array<string,mixed> $p
     *
     * @return void
     */
    private function load(?string $path, array &$p): void
    {
        if ($path === null) {
            throw new RuntimeException(__('The backup file is gone.'));
        }
        $archive = null;
        if (strtolower(substr($path, -4)) === '.sql') {
            $handle = @fopen($path, 'rb');
        } else {
            $archive = new BackupArchive($path);
            $handle  = $archive->database();
        }
        if ($handle === false) {
            throw new RuntimeException(__('The file cannot be read.'));
        }
        try {
            ($this->opts['load'])($handle, function (int $ran) use (&$p): void {
                if ($ran % self::REPORT_EVERY === 0) {
                    $p['statements'] = $ran;
                    ($this->opts['progress'])($p);
                }
            });
        } finally {
            fclose($handle);
            if ($archive !== null) {
                $archive->close();
            }
        }
    }

    /**
     * Put the safety copy's database back.
     *
     * @param array<string,mixed> $p
     *
     * @return bool whether it went back
     */
    private function rollback(array $p): bool
    {
        if ((string) $p['safety_name'] === '') {
            return false;
        }
        try {
            $this->load($this->store->path((string) $p['safety_name']), $p);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Put back one batch of files.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function files(array $p): array
    {
        $path = $this->source($p);
        if ($path === null) {
            throw new BackupException(__('The backup file is gone.'), 'files');
        }
        $archive = new BackupArchive($path);
        try {
            $r = $archive->extract(
                $this->content,
                (int) $p['entries']['next'],
                (int) $this->opts['batch'],
                microtime(true) + (int) $this->opts['seconds']
            );
        } finally {
            $archive->close();
        }
        $p['entries']['next']     = $r['next'];
        $p['entries']['done']    += $r['written'];
        $p['entries']['refused'] += $r['refused'];
        if ($r['done']) {
            $p['stage'] = 'finish';
        }

        return $p;
    }

    /**
     * Clear caches, remove an uploaded file, and open the site again.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function finish(array $p): array
    {
        ($this->opts['after'])();
        if ($this->opts['source_path'] === null && preg_match(BackupStore::UPLOAD, (string) $p['source'])) {
            @unlink($this->store->dir() . $p['source']);
        }
        $this->reopen($p);
        $p['stage'] = 'done';

        return $p;
    }

    /**
     * Put maintenance mode back to how it was before the restore.
     *
     * @param array<string,mixed> $p
     *
     * @return void
     */
    private function reopen(array $p): void
    {
        $file = (string) $this->opts['maintenance'];
        if (!function_exists('osc_maintenance_is_restoring') || !osc_maintenance_is_restoring($file)) {
            return;
        }
        if (is_string($p['maintenance_was'] ?? null)) {
            @file_put_contents($file, $p['maintenance_was']);
        } else {
            @unlink($file);
        }
    }
}
