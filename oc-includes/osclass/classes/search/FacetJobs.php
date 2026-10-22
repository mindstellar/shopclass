<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\search;

use mindstellar\admin\DatabaseTools;
use mindstellar\database\Connection;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\job\Job;
use mindstellar\job\JobQueue;
use mindstellar\job\JobRetry;

/**
 * Builds the listing filter index on the job queue, a batch of listings per run, then queues a
 * separate job that compacts the table. A rebuild replaces rows in place and never clears the
 * ready flag, so searches keep using the index.
 */
final class FacetJobs
{
    private const PACK_LOCK_WAIT = 2;
    private const PACK_WEB_MAX_BYTES = 128 * 1024 * 1024;

    public static function queueBuild(): bool
    {
        return osc_job_ensure(FacetIndex::JOB_BUILD, array('after' => 0));
    }

    /**
     * The filter index jobs on the queue that are pending, running or failed: running first, then newest
     * first. Empty when the queue cannot be read.
     *
     * @return array<int,array{type:string,status:string,payload:array<string,mixed>,error:string,created:string}>
     */
    public static function status(): array
    {
        $rows = [];
        foreach ([FacetIndex::JOB_BUILD, FacetIndex::JOB_FIELD, FacetIndex::JOB_PACK] as $type) {
            $rows = array_merge($rows, JobQueue::getInstance()->page(null, $type, 50));
        }
        usort($rows, static fn (array $a, array $b): int => [$b['s_status'] === JobQueue::STATUS_RUNNING, (int) $b['pk_i_id']] <=> [$a['s_status'] === JobQueue::STATUS_RUNNING, (int) $a['pk_i_id']]);
        $jobs = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['s_payload'], true);
            $jobs[]  = [
                'type'    => (string) $row['s_type'],
                'status'  => (string) $row['s_status'],
                'payload' => is_array($payload) ? $payload : [],
                'error'   => (string) ($row['s_last_error'] ?? ''),
                'created' => (string) $row['dt_created'],
            ];
        }

        return $jobs;
    }

    /**
     * Rebuilds the index in the foreground from listing $from on, sets the ready flag and compacts the table.
     * $line gets one progress line per batch. A DbException is rethrown after its line.
     *
     * @return int the last listing id done
     */
    public static function reindex(int $from, callable $line): int
    {
        $after = $from;
        $done  = 0;
        do {
            try {
                $last = FacetIndex::buildStep($after, FacetIndex::BATCH);
            } catch (DbException $e) {
                $line(sprintf('Stopped after %d listings: %s. Resume with --from=%d', $done, $e->getMessage(), $after));

                throw $e;
            }
            if ($last !== null) {
                $done += FacetIndex::BATCH;
                $after = $last;
                $line(sprintf('%d listings done, last id %d', $done, $after));
            }
        } while ($last !== null);
        $after = max($after, (int) Db::scalar('SELECT MAX(pk_i_id) FROM ' . DB_TABLE_PREFIX . 't_item'));
        self::finish(false);
        $line(sprintf('Index built up to listing #%d', $after));
        try {
            $line(self::pack() ? 'Index table compacted' : 'Index table not compacted: a transaction is open');
        } catch (DbException $e) {
            $line('Index table not compacted (optional, the index works): ' . $e->getMessage());
        }

        return $after;
    }

    /**
     * The end of a full build: drop the terms no row names, set the ready flag and queue the pack,
     * unless the caller packs itself. While a one-field rebuild is queued the flag stays off, and that job's end sets it.
     * Failed build and field jobs are dropped, as this build rewrote what they missed.
     *
     * @throws DbException
     */
    public static function finish(bool $queuePack = true): void
    {
        FacetTerms::prune();
        if (osc_job_count('pending', FacetIndex::JOB_FIELD) > 0 || osc_job_count('running', FacetIndex::JOB_FIELD) > 0) {
            FacetIndex::markBuilt();
        } else {
            FacetIndex::markReady();
        }
        JobQueue::getInstance()->forgetAll(FacetIndex::JOB_BUILD);
        JobQueue::getInstance()->forgetAll(FacetIndex::JOB_FIELD);
        if ($queuePack) {
            osc_job_ensure(FacetIndex::JOB_PACK);
        }
    }

    /**
     * After a schema repair: when it created the index table or the term table, clear the ready flag and queue a build.
     *
     * @param array $ran the statements the repair ran
     */
    public static function afterRepair(array $ran): void
    {
        foreach ($ran as $statement) {
            if (in_array(DatabaseTools::createdTable((string) $statement), [FacetIndex::tableName(), FacetTerms::tableName()], true)) {
                FacetIndex::markStale(true);
                self::queueBuild();

                return;
            }
        }
    }

    /**
     * Rewrite the table with full pages, as a full build ends. The build writes listing by
     * listing into a primary key that starts with the term, which leaves pages half full. The
     * rebuild is online, so searches and saves go on; it waits at most PACK_LOCK_WAIT seconds
     * for a lock. Skipped inside a transaction, which the statement would commit.
     *
     * @return bool true when the table was rewritten, false inside a transaction
     * @throws DbException with the server's reason when the rewrite fails, e.g. on a lock wait, a full online log or a full disk
     */
    public static function pack(): bool
    {
        if (Db::inTransaction()) {
            return false;
        }
        $wait = (int) Db::scalar('SELECT @@SESSION.lock_wait_timeout');
        Db::execute('SET SESSION lock_wait_timeout = ' . self::PACK_LOCK_WAIT);
        try {
            Db::execute('ALTER TABLE ' . FacetIndex::tableName() . ' FORCE, ALGORITHM=INPLACE, LOCK=NONE');
        } catch (DbException $e) {
            // The server's text gives the reason, e.g. a lock wait, a full online log or a rebuild that needs a lock.
            throw new DbException(Connection::getInstance()->lastError() ?: $e->getMessage(), $e->getCode(), $e);
        } finally {
            Db::execute('SET SESSION lock_wait_timeout = ' . $wait);
        }

        return true;
    }

    /**
     * Whether a background pack may run: always from the command line, from a web request
     * only while the table is small enough to rewrite well inside a request's time limit.
     */
    public static function packFits(string $sapi, int $bytes): bool
    {
        return $sapi === 'cli' || $bytes <= self::PACK_WEB_MAX_BYTES;
    }

    /**
     * The table's data and index size in bytes, as the server last counted it.
     *
     * @throws DbException
     */
    public static function tableBytes(): int
    {
        return (int) Db::scalar(
            'SELECT DATA_LENGTH + INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [FacetIndex::tableName()]
        );
    }

    public static function registerJobs(): void
    {
        osc_job_register_handler(FacetIndex::JOB_BUILD, static function (Job $job): void {
            $last = FacetIndex::buildStep((int) $job->get('after', 0), FacetIndex::BATCH);
            if ($last === null) {
                self::finish();

                return;
            }
            $job->repeat(array('after' => $last));
        });
        // A big table is left to search:reindex, so a web request never runs a long rewrite.
        osc_job_register_handler(FacetIndex::JOB_PACK, static function (): void {
            if (!self::packFits(PHP_SAPI, self::tableBytes())) {
                return;
            }
            try {
                $packed = self::pack();
            } catch (DbException $e) {
                throw new JobRetry('Index table not compacted (optional, the index works): ' . $e->getMessage(), 600, 3);
            }
            if (!$packed) {
                throw new JobRetry('Index table not compacted (optional, the index works): a transaction is open', 600, 3);
            }
        });
        /*
         * A type change queued while this runs is lost with the running row, so the last batch checks the
         * field's kind against the queued one and starts over when it moved. Two changes in one run that
         * end on the queued kind are not caught.
         */
        osc_job_register_handler(FacetIndex::JOB_FIELD, static function (Job $job): void {
            $field = (int) $job->get('field', 0);
            $kind  = $job->get('kind');
            $last  = FacetIndex::buildStep((int) $job->get('after', 0), FacetIndex::BATCH, $field);
            if ($last !== null) {
                $job->repeat(array('field' => $field, 'after' => $last, 'kind' => $kind));

                return;
            }
            $now = FacetIndex::fieldKind($field, true);
            if ($kind !== null && $now !== (string) $kind) {
                $job->repeat(array('field' => $field, 'after' => 0, 'kind' => $now));

                return;
            }
            // Searches use the old queries while a retyped field is rebuilt; the last job lets them back, over a fully built index only.
            if (FacetIndex::built() && osc_job_count('pending', FacetIndex::JOB_BUILD) === 0 && osc_job_count('running', FacetIndex::JOB_BUILD) === 0
                && osc_job_count('pending', FacetIndex::JOB_FIELD) === 0 && osc_job_count('running', FacetIndex::JOB_FIELD) <= 1) {
                FacetIndex::markReady();
            }
        });
        osc_job_describe(FacetIndex::JOB_BUILD, __('Listing filter index'), static fn (array $payload): string => sprintf(__('After listing #%d'), (int) ($payload['after'] ?? 0)));
        osc_job_describe(FacetIndex::JOB_PACK, __('Compact the listing filter index'));
        osc_job_describe(FacetIndex::JOB_FIELD, __('Filter index of one field'), static fn (array $payload): string => sprintf(__('After listing #%d'), (int) ($payload['after'] ?? 0)));
    }
}
