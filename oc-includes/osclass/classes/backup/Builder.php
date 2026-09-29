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

/**
 * Makes one backup zip in steps: start (count and check space), database, files a batch
 * at a time, finish. The payload carries everything the next step needs, so each step
 * can run in a different request.
 */
final class Builder
{
    /** Files per batch. */
    public const BATCH = 2000;

    /** Seconds per batch. */
    public const SECONDS = 20;

    /** Seconds the first count of the files may take. */
    public const COUNT_SECONDS = 60;

    /** Links outside the site named in the manifest, at most. */
    private const SKIPPED_MAX = 50;

    /** @var BackupStore */
    private $store;

    /** @var string real path of oc-content */
    private $content;

    /** @var string real path of the site */
    private $site;

    /** @var callable fn(string $file, callable $each): array{tables:int,bytes:int} */
    private $dump;

    /** @var callable fn(): int the database size in bytes, for the space check */
    private $dbBytes;

    /** @var callable fn(array $payload): void, told of progress inside a step */
    private $progress;

    /** @var int */
    private $batch;

    /** @var int */
    private $seconds;

    /**
     * @param BackupStore         $store
     * @param string              $content oc-content
     * @param string              $site    the site folder
     * @param array<string,mixed> $opts    dump, db_bytes, progress callables; batch, seconds
     */
    public function __construct(BackupStore $store, string $content, string $site, array $opts = array())
    {
        $this->store    = $store;
        $this->content  = rtrim((string) realpath($content), '/');
        $this->site     = rtrim((string) realpath($site), '/');
        $this->dump     = $opts['dump'] ?? array(DatabaseDump::class, 'write');
        $this->dbBytes  = $opts['db_bytes'] ?? static function (): int {
            return 0;
        };
        $this->progress = $opts['progress'] ?? static function (array $p): void {
        };
        $this->batch    = (int) ($opts['batch'] ?? self::BATCH);
        $this->seconds  = (int) ($opts['seconds'] ?? self::SECONDS);
    }

    /**
     * The payload of a new backup.
     *
     * @param string $what  database|files|everything
     * @param string $where server|download
     * @param string $kind  backup|safety
     *
     * @return array<string,mixed>
     */
    public static function begin(string $what, string $where, string $kind = 'backup'): array
    {
        return array(
            'run'     => BackupStore::random(12),
            'what'    => $what,
            'where'   => $where,
            'kind'    => $kind,
            'name'    => BackupStore::newName($what),
            'stage'   => 'start',
            'zip'     => array(),
            'files'   => array('total' => 0, 'bytes' => 0, 'complete' => true, 'done' => 0, 'bytes_done' => 0, 'cursor' => ''),
            'db'      => array('tables' => 0, 'done' => 0, 'bytes' => 0, 'sha256' => ''),
            'skipped' => array(),
            'started' => time(),
        );
    }

    /**
     * Whether a payload includes the database, and the files.
     *
     * @param array<string,mixed> $p
     *
     * @return array{0:bool,1:bool}
     */
    public static function parts(array $p): array
    {
        return array($p['what'] !== 'files', $p['what'] !== 'database');
    }

    /**
     * Run the next step and return the payload for the one after. The stage is 'done'
     * when the backup is saved.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     * @throws BackupFailure when it fails or was cancelled; the partial files are removed
     */
    public function step(array $p): array
    {
        $stage = (string) $p['stage'];
        try {
            if ($this->store->cancelRequested((string) $p['run'])) {
                throw BackupFailure::cancelled($stage);
            }
            switch ($stage) {
                case 'start':
                    return $this->start($p);
                case 'database':
                    return $this->database($p);
                case 'files':
                    return $this->files($p);
                case 'finish':
                    return $this->finish($p);
            }
            throw new RuntimeException('Unknown backup stage');
        } catch (BackupFailure $e) {
            $this->store->discard((string) $p['name']);
            throw $e;
        } catch (\Throwable $e) {
            $this->store->discard((string) $p['name']);
            throw new BackupFailure(BackupFailure::clean($e->getMessage()), $stage);
        }
    }

    /**
     * Count the files, check the space, and open the archive.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function start(array $p): array
    {
        if (!$this->store->protect()) {
            throw new BackupFailure(__('The backup folder cannot be written.'), 'start');
        }
        list($db, $files) = self::parts($p);
        $bytes = $db ? max(0, (int) ($this->dbBytes)()) : 0;
        if ($files) {
            $walker = new FileWalker($this->content, $this->site);
            $count  = $walker->count(microtime(true) + self::COUNT_SECONDS);
            $p['files']['total']    = $count['count'];
            $p['files']['bytes']    = $count['bytes'];
            $p['files']['complete'] = $count['complete'];
            $p['skipped']           = array_slice($walker->skipped(), 0, self::SKIPPED_MAX);
            $bytes                 += $count['bytes'];
        }
        $need = (int) ($bytes * 1.1) + 200 * 1048576;
        $free = $this->store->freeSpace();
        if ($free !== null && $free < $need) {
            throw new BackupFailure(sprintf(
                __('Not enough space on the server: needs about %1$s, %2$s free. Nothing was saved.'),
                DatabaseTools::bytes($need),
                DatabaseTools::bytes($free)
            ), 'start');
        }

        $zip = new ZipWriter($this->partPath($p));
        $p['zip'] = $zip->state();
        $zip->close();
        $p['stage'] = $db ? 'database' : 'files';

        return $p;
    }

    /**
     * Dump the database and add it to the archive, in one run.
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
        $sql = $this->store->dir() . $p['name'] . '.sql';
        @unlink($sql);
        $this->store->writePrivate($sql, '');
        try {
            $info = ($this->dump)($sql, function (int $done, int $total) use (&$p): void {
                if ($this->store->cancelRequested((string) $p['run'])) {
                    throw BackupFailure::cancelled('database');
                }
                $p['db']['done']   = $done;
                $p['db']['tables'] = $total;
                ($this->progress)($p);
            });
            $zip   = new ZipWriter($this->partPath($p), $p['zip']);
            $entry = $zip->addFile('database.sql', $sql, true);
            $p['zip'] = $zip->state();
            $zip->close();
        } finally {
            @unlink($sql);
        }
        $p['db'] = array('tables' => (int) $info['tables'], 'done' => (int) $info['tables'], 'bytes' => $entry['size'], 'sha256' => $entry['sha256']);
        $p['stage'] = self::parts($p)[1] ? 'files' : 'finish';

        return $p;
    }

    /**
     * Add one batch of files.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function files(array $p): array
    {
        $deadline = microtime(true) + $this->seconds;
        $zip      = new ZipWriter($this->partPath($p), $p['zip']);
        $walker   = new FileWalker($this->content, $this->site);
        $added    = 0;
        $finished = true;
        foreach ($walker->files((string) $p['files']['cursor']) as $rel => $file) {
            if ($added >= $this->batch || ($added > 0 && microtime(true) > $deadline)) {
                $finished = false;
                break;
            }
            $p['files']['cursor'] = $rel;
            if (!is_readable($file[0])) {
                continue;
            }
            $entry = $zip->addFile(BackupArchive::FILES . $rel, $file[0]);
            $p['files']['done']++;
            $p['files']['bytes_done'] += $entry['size'];
            $added++;
        }
        $p['zip'] = $zip->state();
        $zip->close();
        if ($p['files']['done'] > $p['files']['total']) {
            $p['files']['total'] = $p['files']['done'];
        }
        if ($finished) {
            $p['stage'] = 'finish';
        }

        return $p;
    }

    /**
     * Add the manifest, close the archive, and save the manifest beside it.
     *
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function finish(array $p): array
    {
        list($db, $files) = self::parts($p);
        $contents = array();
        if ($db) {
            $contents['database'] = array('tables' => $p['db']['tables'], 'bytes' => $p['db']['bytes'], 'sha256' => $p['db']['sha256']);
        }
        if ($files) {
            $contents['files'] = array('count' => $p['files']['done'], 'bytes' => $p['files']['bytes_done'], 'root' => 'oc-content');
        }
        $manifest = Manifest::build(array(
            'what'             => $p['what'],
            'kind'             => $p['where'] === 'download' ? 'download' : $p['kind'],
            'created'          => date('c', (int) $p['started']),
            'contents'         => $contents,
            'skipped'          => array(
                'links_outside_root' => array_map(static function ($rel) {
                    return 'oc-content/' . $rel;
                }, (array) $p['skipped']),
                'excluded'           => array_map(static function ($rel) {
                    return 'oc-content/' . $rel;
                }, FileWalker::EXCLUDED),
            ),
            'db_version'       => (string) ($p['db_version'] ?? ''),
            'site_url'         => (string) ($p['site_url'] ?? ''),
            'db_server'        => (string) ($p['db_server'] ?? ''),
            'photos_in_bucket' => $p['photos_in_bucket'] ?? null,
        ));
        $json = (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $zip = new ZipWriter($this->partPath($p), $p['zip']);
        $zip->addString('manifest.json', $json);
        $zip->finish();
        if (!@rename($this->partPath($p), $this->store->dir() . $p['name'])) {
            throw new RuntimeException('Could not save the backup');
        }
        $this->store->saveManifest((string) $p['name'], $manifest);
        clearstatcache();
        $p['size']  = (int) filesize($this->store->dir() . $p['name']);
        $p['zip']   = array();
        $p['stage'] = 'done';

        return $p;
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return string
     */
    private function partPath(array $p): string
    {
        return $this->store->dir() . $p['name'] . '.part';
    }
}
