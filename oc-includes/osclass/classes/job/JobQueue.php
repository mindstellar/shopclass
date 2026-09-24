<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\job;

use InvalidArgumentException;
use mindstellar\database\DbException;

/**
 * The durable queue behind t_job_queue.
 *
 * Two rules a handler has to live with, both consequences of the table rather than
 * choices made here:
 *
 * - **A job is self-contained.** The payload is a JSON snapshot, never a foreign key to
 *   look up later, because the row that spawned the job is often the row being deleted.
 * - **A handler must be idempotent.** A job is claimed under a worker token, not removed,
 *   so a worker killed mid-job leaves its rows behind and a later tick runs them again.
 *
 * Failures back off: 1, 2, 4 ... up to 64 minutes, and after MAX_ATTEMPTS the job stops
 * retrying and sits in `error` with its last message, where the admin queue screen shows
 * it. Nothing is ever silently dropped.
 */
final class JobQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_ERROR   = 'error';

    /** Attempts before a job stops retrying and waits for a human. */
    public const MAX_ATTEMPTS = 8;

    /** A `running` row older than this is treated as a dead worker's and recovered. */
    public const STALE_LOCK_SECONDS = 900;

    /** The largest encoded payload s_payload (TEXT) holds. */
    public const MAX_PAYLOAD_BYTES = 65535;

    /** @var JobQueue|null */
    private static $instance;

    /**
     * @return JobQueue
     */
    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * The prefixed table name.
     *
     * @return string
     */
    public function table(): string
    {
        return DB_TABLE_PREFIX . 't_job_queue';
    }

    /**
     * Put a job on the queue.
     *
     * @param string              $type    namespaced, e.g. 'category.delete'
     * @param array<string,mixed> $payload anything json_encode() can take
     * @param array<string,mixed> $options delay: seconds to hold it back.
     *                                     storage: adapter id, for storage.* jobs only.
     *
     * @return int the new job id, or 0 when the insert failed
     * @throws InvalidArgumentException on a malformed type, or a payload that cannot be encoded
     *                                  or is larger than MAX_PAYLOAD_BYTES
     */
    public function enqueue(string $type, array $payload = array(), array $options = array()): int
    {
        JobRegistry::assertType($type);

        $encoded = self::encode($payload, 'Job payload for "' . $type . '"');

        $delay   = max(0, (int) ($options['delay'] ?? 0));
        $storage = $options['storage'] ?? null;

        try {
            return osc_db_table($this->table())->insert(array(
                's_type'      => $type,
                's_storage'   => ($storage === null || $storage === '') ? null : (string) $storage,
                's_payload'   => $encoded,
                's_status'    => self::STATUS_PENDING,
                'dt_next_run' => date('Y-m-d H:i:s', time() + $delay),
                'dt_created'  => date('Y-m-d H:i:s'),
            ));
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * Recover stale locks, then claim up to $batch due jobs under a fresh token.
     *
     * The claim is an UPDATE that stamps a token onto the rows, followed by a SELECT of
     * that token. Two workers running at once therefore cannot take the same row: the
     * second one's UPDATE finds nothing still pending, and its SELECT comes back empty.
     *
     * @param int $batch
     *
     * @return array<int,array<string,string|null>> the claimed rows, oldest id first
     */
    public function claim(int $batch = 20): array
    {
        $table = $this->table();
        $now   = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - self::STALE_LOCK_SECONDS);
        $token = uniqid('w', true);

        // A hiccup recovering stale locks must not abort the claim below, so it is
        // absorbed: the worst case is that a dead worker's rows wait one more tick.
        try {
            osc_db_execute(
                'UPDATE ' . $table . ' SET s_status = ?, s_worker = NULL'
                . ' WHERE s_status = ? AND dt_locked < ?',
                array(self::STATUS_PENDING, self::STATUS_RUNNING, $stale)
            );
        } catch (DbException $e) {
            // absorbed
        }

        // ORDER BY and LIMIT on an UPDATE are not expressible through the builder, so
        // this is hand-written: the table name is built from a constant, every value is
        // a bound '?', and the limit is cast to int.
        try {
            osc_db_execute(
                'UPDATE ' . $table . ' SET s_status = ?, s_worker = ?, dt_locked = ?'
                . ' WHERE s_status = ? AND dt_next_run <= ?'
                . ' ORDER BY pk_i_id LIMIT ' . (int) max(1, $batch),
                array(self::STATUS_RUNNING, $token, $now, self::STATUS_PENDING, $now)
            );

            $rows = osc_db_select(
                'SELECT * FROM ' . $table . ' WHERE s_worker = ? AND s_status = ?'
                . ' ORDER BY pk_i_id',
                array($token, self::STATUS_RUNNING)
            );
        } catch (DbException $e) {
            return array();
        }

        return $rows === array() ? array() : osc_db_stringify_rows($rows);
    }

    /**
     * The job is done. Remove it.
     *
     * @param int $id
     *
     * @return void
     */
    public function complete(int $id): void
    {
        try {
            osc_db_table($this->table())->where('pk_i_id', $id)->delete();
        } catch (DbException $e) {
            // The row stays claimed and a later tick recovers it as a stale lock. A
            // handler that ran twice is what idempotence is for.
        }
    }

    /**
     * The handler asked to carry on. Re-queue the job with a new payload, leaving the
     * attempt count alone -- a batch that finished is not a failed attempt.
     *
     * @param int                 $id
     * @param array<string,mixed> $payload
     * @param int                 $delaySeconds
     *
     * @return void
     */
    public function repeat(int $id, array $payload, int $delaySeconds = 0): void
    {
        try {
            $encoded = self::encode($payload, 'Repeat payload');
        } catch (InvalidArgumentException $e) {
            $this->fail($id, $e->getMessage());

            return;
        }

        try {
            osc_db_table($this->table())->where('pk_i_id', $id)->update(array(
                's_payload'   => $encoded,
                's_status'    => self::STATUS_PENDING,
                's_worker'    => null,
                'dt_locked'   => null,
                'dt_next_run' => date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
            ));
        } catch (DbException $e) {
            // absorbed; the stale-lock sweep recovers it
        }
    }

    /**
     * A payload as the JSON s_payload stores.
     *
     * @param array<string,mixed> $payload
     * @param string              $what    names the payload in the error
     *
     * @return string
     * @throws InvalidArgumentException when it cannot be encoded or is larger than MAX_PAYLOAD_BYTES
     */
    private static function encode(array $payload, string $what): string
    {
        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new InvalidArgumentException($what . ' cannot be encoded: ' . json_last_error_msg());
        }
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException($what . ' is larger than ' . self::MAX_PAYLOAD_BYTES . ' bytes');
        }

        return $encoded;
    }

    /**
     * Hand claimed jobs back unrun, so the next tick takes them at once rather than after
     * the stale-lock ceiling.
     *
     * @param int[] $ids
     *
     * @return void
     */
    public function release(array $ids): void
    {
        if ($ids === array()) {
            return;
        }
        try {
            osc_db_table($this->table())
                ->whereIn('pk_i_id', array_map('intval', $ids))
                ->where('s_status', self::STATUS_RUNNING)
                ->update(array(
                    's_status'  => self::STATUS_PENDING,
                    's_worker'  => null,
                    'dt_locked' => null,
                ));
        } catch (DbException $e) {
            // absorbed; the stale-lock sweep recovers them
        }
    }

    /**
     * Record a failed attempt: back off, or dead-letter past the ceiling.
     *
     * @param int    $id
     * @param string $error
     *
     * @return void
     */
    public function fail(int $id, string $error): void
    {
        try {
            $row = osc_db_table($this->table())->where('pk_i_id', $id)->first();
        } catch (DbException $e) {
            return;
        }
        if ($row === null) {
            return;
        }

        $attempts = (int) $row['i_attempts'] + 1;

        $values = array(
            'i_attempts'   => $attempts,
            's_last_error' => substr($error, 0, 250),
            's_worker'     => null,
            'dt_locked'    => null,
        );

        if ($attempts >= self::MAX_ATTEMPTS) {
            $values['s_status'] = self::STATUS_ERROR;
        } else {
            $values['s_status']    = self::STATUS_PENDING;
            $values['dt_next_run'] = date('Y-m-d H:i:s', time() + (2 ** min($attempts - 1, 6)) * 60);
        }

        try {
            osc_db_table($this->table())->where('pk_i_id', $id)->update($values);
        } catch (DbException $e) {
            // absorbed
        }
    }

    /**
     * Count jobs, optionally narrowed to one type.
     *
     * @param string      $status pending|running|error
     * @param string|null $type
     *
     * @return int 0 when the query failed
     */
    public function count(string $status = self::STATUS_PENDING, ?string $type = null): int
    {
        try {
            $q = osc_db_table($this->table())->where('s_status', $status);
            if ($type !== null && $type !== '') {
                $q = $q->where('s_type', $type);
            }

            return $q->count();
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * How many jobs sit in each status, as status => count. Statuses with no jobs are
     * present and zero, so a caller can render the whole set without a lookup per row.
     *
     * @return array<string,int>
     */
    public function summary(): array
    {
        $counts = array(
            self::STATUS_PENDING => 0,
            self::STATUS_RUNNING => 0,
            self::STATUS_ERROR   => 0,
        );

        try {
            $rows = osc_db_select(
                'SELECT s_status, COUNT(*) AS i_count FROM ' . $this->table() . ' GROUP BY s_status'
            );
        } catch (DbException $e) {
            return $counts;
        }

        foreach ($rows as $row) {
            $counts[(string) $row['s_status']] = (int) $row['i_count'];
        }

        return $counts;
    }

    /**
     * A page of jobs for the admin screen, newest first.
     *
     * @param string|null $status
     * @param string|null $type
     * @param int         $limit
     * @param int         $offset
     *
     * @return array<int,array<string,string|null>>
     */
    public function page(?string $status = null, ?string $type = null, int $limit = 25, int $offset = 0): array
    {
        try {
            $q = osc_db_table($this->table());
            if ($status !== null && $status !== '') {
                $q = $q->where('s_status', $status);
            }
            if ($type !== null && $type !== '') {
                $q = $q->where('s_type', $type);
            }

            $rows = $q->orderBy('pk_i_id', 'DESC')
                ->limit(max(1, min(200, $limit)))
                ->offset(max(0, $offset))
                ->get();
        } catch (DbException $e) {
            return array();
        }

        return $rows === array() ? array() : osc_db_stringify_rows($rows);
    }

    /**
     * Every distinct type currently on the queue, sorted. Not the same as the registered
     * types -- a type here with no handler is exactly the problem worth seeing.
     *
     * @return array<int,string>
     */
    public function queuedTypes(): array
    {
        try {
            $rows = osc_db_select(
                'SELECT DISTINCT s_type FROM ' . $this->table() . ' ORDER BY s_type'
            );
        } catch (DbException $e) {
            return array();
        }

        return array_map(static fn ($row) => (string) $row['s_type'], $rows);
    }

    /**
     * Put a dead-lettered job back on the queue with its attempts cleared.
     *
     * @param int $id
     *
     * @return bool whether a row was changed
     */
    public function retry(int $id): bool
    {
        try {
            return osc_db_table($this->table())
                ->where('pk_i_id', $id)
                ->where('s_status', self::STATUS_ERROR)
                ->update(array(
                    's_status'     => self::STATUS_PENDING,
                    'i_attempts'   => 0,
                    's_last_error' => null,
                    's_worker'     => null,
                    'dt_locked'    => null,
                    'dt_next_run'  => date('Y-m-d H:i:s'),
                )) > 0;
        } catch (DbException $e) {
            return false;
        }
    }

    /**
     * Retry every dead-lettered job, optionally of one type.
     *
     * @param string|null $type
     *
     * @return int how many were re-queued
     */
    public function retryAll(?string $type = null): int
    {
        try {
            $q = osc_db_table($this->table())->where('s_status', self::STATUS_ERROR);
            if ($type !== null && $type !== '') {
                $q = $q->where('s_type', $type);
            }

            return $q->update(array(
                's_status'     => self::STATUS_PENDING,
                'i_attempts'   => 0,
                's_last_error' => null,
                's_worker'     => null,
                'dt_locked'    => null,
                'dt_next_run'  => date('Y-m-d H:i:s'),
            ));
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * Throw a job away. Only a dead-lettered one, so this cannot race a running worker.
     *
     * @param int $id
     *
     * @return bool whether a row was removed
     */
    public function forget(int $id): bool
    {
        try {
            return osc_db_table($this->table())
                ->where('pk_i_id', $id)
                ->where('s_status', self::STATUS_ERROR)
                ->delete() > 0;
        } catch (DbException $e) {
            return false;
        }
    }

    /**
     * Throw away every dead-lettered job, optionally of one type.
     *
     * @param string|null $type
     *
     * @return int how many were removed
     */
    public function forgetAll(?string $type = null): int
    {
        try {
            $q = osc_db_table($this->table())->where('s_status', self::STATUS_ERROR);
            if ($type !== null && $type !== '') {
                $q = $q->where('s_type', $type);
            }

            return $q->delete();
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * The jobs that stopped retrying, newest first.
     *
     * @param int $limit
     *
     * @return array<int,array<string,string|null>>
     */
    public function deadLetters(int $limit = 50): array
    {
        return $this->page(self::STATUS_ERROR, null, $limit);
    }
}
