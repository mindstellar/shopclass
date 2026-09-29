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
use mindstellar\job\JobWorker;
use Throwable;

/**
 * What the Backup and restore page asks for: start, cancel, the state of the run in
 * words, and whether the backup folder is open to the web. The words and the stale-run
 * rule are pure, so they can be tested without a site.
 */
final class BackupManager
{
    public const WHAT  = array('database', 'files', 'everything');
    public const WHERE = array('download', 'server');

    /** A run with no job and no news for this long has stopped. */
    public const STALE = 120;

    /**
     * Whether a backup or restore job is waiting or running.
     *
     * @return bool
     */
    public static function busy(): bool
    {
        foreach (array(BackupJobs::CREATE, BackupJobs::RESTORE) as $type) {
            $stats = osc_job_stats($type);
            if ($stats['pending'] + $stats['running'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Queue a backup.
     *
     * @param string $what
     * @param string $where
     *
     * @return string '' when queued, else why not
     */
    public static function startBackup(string $what, string $where): string
    {
        if (!in_array($what, self::WHAT, true) || !in_array($where, self::WHERE, true)) {
            return __('Choose what to back up and where to put it.');
        }
        if (self::busy()) {
            return __('One backup at a time. Wait for the one running to finish.');
        }
        $store = BackupStore::site();
        if (!$store->protect()) {
            return sprintf(__('The backup folder cannot be written: %s'), BackupStore::FOLDER);
        }
        $store->sweep(false);
        $p = Builder::begin($what, $where) + self::siteFacts();
        if (osc_job_enqueue(BackupJobs::CREATE, $p) === 0) {
            return __('The backup could not be started. Try again.');
        }
        $store->saveState(BackupJobs::state($p, 'queued'));

        return '';
    }

    /**
     * Queue a restore of a saved backup or an uploaded file.
     *
     * @param string $name
     * @param bool   $db
     * @param bool   $files
     *
     * @return string '' when queued, else why not
     */
    public static function startRestore(string $name, bool $db, bool $files): string
    {
        if (self::busy()) {
            return __('One backup at a time. Wait for the one running to finish.');
        }
        $check = self::check($name);
        if ($check['reason'] !== '') {
            return $check['reason'];
        }
        $db    = $db && $check['database'];
        $files = $files && $check['files'] > 0;
        if (!$db && !$files) {
            return __('Tick what to put back.');
        }
        if ($db && !self::lockFree()) {
            return __('A database update is running. Try again in a few minutes.');
        }
        $p = Restorer::begin($name, $db, $files);
        $p['source_created'] = (string) ($check['manifest']['created'] ?? '');
        if (osc_job_enqueue(BackupJobs::RESTORE, $p) === 0) {
            return __('The restore could not be started. Try again.');
        }
        BackupStore::site()->saveState(BackupJobs::state($p, 'queued'));

        return '';
    }

    /**
     * Whether a listed backup or an upload may be restored here, and what it holds.
     *
     * @param string $name
     *
     * @return array{reason:string,note:string,manifest:?array,database:bool,files:int,size:int}
     */
    public static function check(string $name): array
    {
        $out  = array('reason' => '', 'note' => '', 'manifest' => null, 'database' => false, 'files' => 0, 'size' => 0);
        $path = BackupStore::site()->path($name);
        if ($path === null) {
            return array('reason' => __('That backup is not in the list any more.')) + $out;
        }
        $info = Restorer::inspect($path);

        return array(
            'reason'   => $info['ok'] ? '' : $info['reason'],
            'note'     => $info['note'],
            'manifest' => $info['manifest'],
            'database' => $info['database'],
            'files'    => $info['files'],
            'size'     => (int) filesize($path),
        );
    }

    /**
     * Ask the running backup to stop. A restore cannot be stopped.
     *
     * @return bool
     */
    public static function cancel(): bool
    {
        $state = self::current();
        if ($state === array() || $state['kind'] !== 'backup' || !in_array($state['status'], array('queued', 'running'), true)) {
            return false;
        }
        BackupStore::site()->requestCancel((string) $state['run']);
        // The next step sees the request at once and cleans up.
        JobWorker::run(5);

        return true;
    }

    /**
     * The state of the running or last run, with a run that stopped without a word
     * reported as failed.
     *
     * @return array<string,mixed>
     */
    public static function current(): array
    {
        $state = BackupStore::site()->state();
        if ($state === array()) {
            return array();
        }

        return self::resolve($state, in_array($state['status'] ?? '', array('queued', 'running'), true) && self::busy(), time());
    }

    /**
     * What the page's poll gets. A job that is waiting because cron is not running is
     * run here, so a site without cron still finishes while the page is open.
     *
     * @return array{status:string,kind:string,title:string,line:string,percent:?int}
     */
    public static function poll(): array
    {
        $state = self::current();
        $live  = in_array($state['status'] ?? '', array('queued', 'running'), true);
        if ($live) {
            $pending = 0;
            foreach (array(BackupJobs::CREATE, BackupJobs::RESTORE) as $type) {
                $pending += osc_job_stats($type)['pending'];
            }
            if ($pending > 0) {
                JobWorker::run(10);
                $state = self::current();
            }
        }
        if ($state === array()) {
            return array('status' => 'idle', 'kind' => '', 'title' => '', 'line' => '', 'percent' => null);
        }
        $words = self::progress($state);

        return array(
            'status'  => (string) $state['status'],
            'kind'    => (string) $state['kind'],
            'title'   => $words['title'],
            'line'    => $words['line'],
            'percent' => $words['percent'],
        );
    }

    /**
     * A state as the page should see it: a queued or running run with no job left and
     * no news for STALE seconds has stopped.
     *
     * @param array<string,mixed> $state
     * @param bool                $jobExists
     * @param int                 $now
     *
     * @return array<string,mixed>
     */
    public static function resolve(array $state, bool $jobExists, int $now): array
    {
        $live = in_array($state['status'] ?? '', array('queued', 'running'), true);
        if (!$live || $jobExists || $now - (int) ($state['updated'] ?? 0) < self::STALE) {
            return $state;
        }
        $state['status']  = 'failed';
        $state['message'] = ($state['kind'] ?? '') === 'restore'
            ? __('It stopped and did not finish.')
            : __('It stopped and did not finish. Nothing was saved.');
        if (($state['kind'] ?? '') === 'restore') {
            $state['rolled_back'] = ($state['stage'] ?? '') === 'database' ? false : null;
            $state['untouched']   = in_array($state['stage'] ?? '', array('start', 'safety'), true);
        }

        return $state;
    }

    /**
     * The running state in words, for the page and for each poll.
     *
     * @param array<string,mixed> $s
     *
     * @return array{title:string,line:string,percent:?int}
     */
    public static function progress(array $s): array
    {
        if (($s['kind'] ?? '') === 'restore') {
            return self::restoreProgress($s);
        }
        $where = ($s['where'] ?? '') === 'download' ? __('to download') : __('saved on the server');
        $title = sprintf(__('Making a backup: %1$s, %2$s.'), BackupJobs::whatWord((string) ($s['what'] ?? '')), $where);
        $hasDb    = ($s['what'] ?? '') !== 'files';
        $hasFiles = ($s['what'] ?? '') !== 'database';
        $dbShare  = $hasDb ? ($hasFiles ? 20 : 95) : 0;
        if (($s['status'] ?? '') === 'queued') {
            return array('title' => $title, 'line' => __('Waiting to start'), 'percent' => 0);
        }
        switch ($s['stage'] ?? '') {
            case 'start':
                return array('title' => $title, 'line' => $hasFiles ? __('Counting files') : __('Starting'), 'percent' => 0);
            case 'database':
                $tables = max(1, (int) $s['db_tables']);
                $done   = min((int) $s['db_done'] + 1, $tables);

                return array(
                    'title'   => $title,
                    'line'    => (int) $s['db_tables'] > 0
                        ? sprintf(__('Saving the database (table %1$d of %2$d)'), $done, $tables)
                        : __('Saving the database'),
                    'percent' => (int) floor($dbShare * ((int) $s['db_done']) / $tables),
                );
            case 'files':
                $total = max(1, (int) $s['files_total']);

                return array(
                    'title'   => $title,
                    'line'    => sprintf(
                        __('Copying files: %1$s of %2$s (%3$s)'),
                        number_format((int) $s['files_done']),
                        number_format((int) $s['files_total']),
                        DatabaseTools::bytes((int) $s['bytes_done'])
                    ),
                    'percent' => (int) min(97, $dbShare + floor((97 - $dbShare) * (int) $s['files_done'] / $total)),
                );
        }

        return array('title' => $title, 'line' => __('Finishing'), 'percent' => 98);
    }

    /**
     * @param array<string,mixed> $s
     *
     * @return array{title:string,line:string,percent:?int}
     */
    private static function restoreProgress(array $s): array
    {
        $when  = BackupJobs::when((string) ($s['source_created'] ?? ''));
        $title = $when !== '' ? sprintf(__('Restoring the backup from %s.'), $when) : __('Restoring the backup.');
        if (($s['status'] ?? '') === 'queued') {
            return array('title' => $title, 'line' => __('Waiting to start'), 'percent' => 0);
        }
        switch ($s['stage'] ?? '') {
            case 'start':
            case 'safety':
                return array('title' => $title, 'line' => __('Step 1 of 4: Saving a safety copy'), 'percent' => 5);
            case 'database':
                return array(
                    'title'   => $title,
                    'line'    => sprintf(__('Step 2 of 4: Loading the database (%s statements)'), number_format((int) $s['statements'])),
                    'percent' => null,
                );
            case 'files':
                $total = max(1, (int) $s['entries_total']);

                return array(
                    'title'   => $title,
                    'line'    => sprintf(
                        __('Step 3 of 4: Putting back files (%1$s of %2$s)'),
                        number_format((int) $s['entries_done']),
                        number_format((int) $s['entries_total'])
                    ),
                    'percent' => (int) min(95, 40 + floor(55 * (int) $s['entries_done'] / $total)),
                );
        }

        return array('title' => $title, 'line' => __('Step 4 of 4: Updates and cache'), 'percent' => 97);
    }

    /**
     * A failed run in words: what stopped, why, and what state the site is in.
     *
     * @param array<string,mixed> $s
     *
     * @return array{lines:string[],reopen:bool}
     */
    public static function failure(array $s): array
    {
        $reason = (string) ($s['message'] ?? '');
        $stage  = (string) ($s['stage'] ?? '');
        if (($s['kind'] ?? '') !== 'restore') {
            $words = array(
                'start'    => __('The backup failed while checking the space.'),
                'database' => __('The backup failed while saving the database.'),
                'files'    => __('The backup failed while copying files.'),
                'finish'   => __('The backup failed while finishing.'),
            );
            $lines = array($words[$stage] ?? __('The backup failed.'), $reason);
            if (strpos($reason, __('Nothing was saved.')) === false) {
                $lines[] = __('Nothing was saved.');
            }

            return array('lines' => array_values(array_filter($lines)), 'reopen' => false);
        }

        if (!empty($s['untouched'])) {
            return array('lines' => array_values(array_filter(array(__('The restore did not start.'), $reason, __('Nothing was changed.')))), 'reopen' => false);
        }
        if ($stage === 'database') {
            $lines = array(__('The restore stopped while loading the database.'), $reason);
            if (($s['rolled_back'] ?? null) === true) {
                $lines[] = __('The safety copy was put back, so the site is as it was before. Visitors still see the maintenance page.');
            } else {
                $lines[] = __('The safety copy could not be put back. The site stays closed. Restore it from the list below.');
            }

            return array('lines' => array_values(array_filter($lines)), 'reopen' => true);
        }
        $words = array(
            'files'   => __('The restore stopped while putting back files.'),
            'updates' => __('The restore stopped while running the database updates.'),
            'finish'  => __('The restore stopped while finishing.'),
        );

        return array('lines' => array_values(array_filter(array(
            $words[$stage] ?? __('The restore stopped.'),
            $reason,
            __('The database was restored. Visitors still see the maintenance page.'),
        ))), 'reopen' => true);
    }

    /**
     * Forget a finished or failed run.
     *
     * @return void
     */
    public static function dismiss(): void
    {
        $state = self::current();
        if ($state !== array() && in_array($state['status'], array('queued', 'running'), true)) {
            return;
        }
        BackupStore::site()->clearState();
    }

    /**
     * Take the site out of the maintenance a failed restore left on.
     *
     * @return bool whether it was on
     */
    public static function reopen(): bool
    {
        $file = ABS_PATH . '.maintenance';
        if (self::busy() || !osc_maintenance_is_restoring($file)) {
            return false;
        }
        @unlink($file);
        BackupStore::site()->clearState();

        return true;
    }

    /**
     * Whether the backup folder answers on the web: true when it does, false when it is
     * closed, null when that could not be checked. Asked at most once a day, or once an
     * hour while it is open.
     *
     * @return bool|null
     */
    public static function probe(): ?bool
    {
        $cached = json_decode((string) osc_get_preference('backup_probe'), true);
        $open   = is_array($cached) && isset($cached['open']) ? (bool) $cached['open'] : null;
        if (is_array($cached) && time() - (int) ($cached['t'] ?? 0) < ($open === true ? 3600 : 86400)) {
            return $open;
        }
        if (!is_file(BackupStore::site()->dir() . BackupStore::PROBE) || !function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init(osc_base_url() . BackupStore::FOLDER . BackupStore::PROBE);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
        ));
        $ok     = curl_exec($ch) !== false;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $open = !$ok || $status === 0 ? null : ($status >= 200 && $status < 300);
        osc_set_preference('backup_probe', (string) json_encode(array('t' => time(), 'open' => $open)), 'osclass', 'STRING');

        return $open;
    }

    /**
     * Facts about the site a backup's manifest records.
     *
     * @return array<string,mixed>
     */
    public static function siteFacts(): array
    {
        try {
            $server = DatabaseTools::server(Connection::instance()->serverInfo())['label'];
        } catch (Throwable $e) {
            $server = '';
        }
        $facts = array(
            'db_version'       => (string) osc_version(),
            'site_url'         => osc_base_url(),
            'db_server'        => $server,
            'photos_in_bucket' => null,
        );
        if (osc_get_preference('storage_active') === 's3') {
            try {
                $count = osc_db_table(DB_TABLE_PREFIX . 't_item_resource')->where('s_storage', 's3')->count();
            } catch (DbException $e) {
                $count = 0;
            }
            $facts['photos_in_bucket'] = array(
                'storage' => 's3',
                'bucket'  => (string) osc_get_preference('storage_s3_bucket'),
                'count'   => $count,
            );
        }

        return $facts;
    }

    /**
     * Whether no database update holds the lock now.
     *
     * @return bool
     */
    private static function lockFree(): bool
    {
        try {
            $conn    = Connection::instance();
            $release = DatabaseTools::upgradeLock($conn);
        } catch (DbException $e) {
            return false;
        }
        if ($release === null) {
            return false;
        }
        $release();

        return true;
    }
}
