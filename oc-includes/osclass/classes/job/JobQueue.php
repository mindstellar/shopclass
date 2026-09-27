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

    /** Rows per INSERT in enqueueMany(). */
    private const BULK_CHUNK = 200;

    /** Payload bytes per INSERT in enqueueMany(), well under a 4 MB max_allowed_packet. */
    private const BULK_BYTES = 1048576;

    /** The longest de-duplication key s_unique holds. */
    public const MAX_UNIQUE_LENGTH = 100;

    /**
     * Put a job on the queue.
     *
     * With a unique_key, a waiting job of the same type and key is updated instead of a
     * second one being added: its payload is replaced and its retry state reset. A job
     * loses its key when a worker claims it, so a change that arrives mid-run queues a
     * fresh job rather than folding into one that may already have read stale data.
     *
     * @param string              $type    namespaced, e.g. 'category.delete'
     * @param array<string,mixed> $payload anything json_encode() can take
     * @param array<string,mixed> $options delay: seconds to hold it back.
     *                                     storage: adapter id, for storage.* jobs only.
     *                                     unique_key: de-duplication key, up to 100 characters.
     *
     * @return int the job id (the existing one when a key matched), or 0 when the insert failed
     * @throws InvalidArgumentException on a malformed type or key, or a payload that cannot be
     *                                  encoded or is larger than MAX_PAYLOAD_BYTES
     */
    public function enqueue(string $type, array $payload = array(), array $options = array()): int
    {
        JobRegistry::assertType($type);

        $unique = self::uniqueKey($options['unique_key'] ?? null);
        $row    = $this->row($type, self::encode($payload, 'Job payload for "' . $type . '"'), $options, $unique);

        try {
            if ($unique === null) {
                return osc_db_table($this->table())->insert($row);
            }

            // LAST_INSERT_ID(pk_i_id) makes a matched row's id the insert id.
            $sql = 'INSERT INTO ' . $this->table() . ' (' . implode(', ', array_keys($row)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')'
                . self::onDuplicate() . ', pk_i_id = LAST_INSERT_ID(pk_i_id)';

            return (int) self::retryOnce(static fn () => osc_db_insert_id($sql, array_values($row)));
        } catch (DbException $e) {
            return 0;
        }
    }

    /**
     * Put many jobs of one type on the queue, in chunked multi-row inserts.
     *
     * $options are enqueue()'s; unique_key may also be a Closure fn(array $payload): ?string
     * that names each row's key. Rows with a matching waiting job update it, as enqueue() does.
     *
     * @param string                        $type
     * @param array<int,array<string,mixed>> $payloads
     * @param array<string,mixed>           $options
     *
     * @return int how many payloads were queued or folded into a waiting job
     * @throws InvalidArgumentException as enqueue() does
     */
    public function enqueueMany(string $type, array $payloads, array $options = array()): int
    {
        JobRegistry::assertType($type);

        $keyOf = $options['unique_key'] ?? null;
        $rows  = array();
        foreach ($payloads as $payload) {
            $key    = $keyOf instanceof \Closure ? $keyOf((array) $payload) : $keyOf;
            $rows[] = $this->row(
                $type,
                self::encode((array) $payload, 'Job payload for "' . $type . '"'),
                $options,
                self::uniqueKey($key)
            );
        }

        // One statement needs one column list, so if any row is keyed, all name s_unique.
        if (in_array(true, array_map(static fn ($row) => isset($row['s_unique']), $rows), true)) {
            foreach ($rows as $i => $row) {
                $rows[$i] = $row + array('s_unique' => null);
            }
        }

        $chunks = array();
        $chunk  = array();
        $bytes  = 0;
        foreach ($rows as $row) {
            if ($chunk !== array() && (count($chunk) >= self::BULK_CHUNK || $bytes + strlen($row['s_payload']) > self::BULK_BYTES)) {
                $chunks[] = $chunk;
                $chunk    = array();
                $bytes    = 0;
            }
            $chunk[] = $row;
            $bytes  += strlen($row['s_payload']);
        }
        if ($chunk !== array()) {
            $chunks[] = $chunk;
        }

        $queued = 0;
        foreach ($chunks as $chunk) {
            $columns = array_keys($chunk[0]);
            $params  = array();
            foreach ($chunk as $row) {
                array_push($params, ...array_values($row));
            }
            $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

            $sql = 'INSERT INTO ' . $this->table() . ' (' . implode(', ', $columns) . ')'
                . ' VALUES ' . implode(', ', array_fill(0, count($chunk), $tuple))
                . self::onDuplicate();
            try {
                self::retryOnce(static fn () => osc_db_execute($sql, $params));
                $queued += count($chunk);
            } catch (DbException $e) {
                // A failed chunk is not counted; the others still go in.
            }
        }

        return $queued;
    }

    /**
     * Queue a job of $type only when none is pending or running.
     *
     * @param string              $type
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options as enqueue(); a unique_key also closes the gap
     *                                     between the check and the insert
     *
     * @return bool true when a job of $type is now queued or running
     */
    public function ensure(string $type, array $payload = array(), array $options = array()): bool
    {
        if ($this->count(self::STATUS_PENDING, $type) > 0 || $this->count(self::STATUS_RUNNING, $type) > 0) {
            return true;
        }

        return $this->enqueue($type, $payload, $options) > 0;
    }

    /**
     * Counts per status, and when the oldest pending job was created.
     *
     * @param string|null $type narrow to one type
     *
     * @return array{pending:int,running:int,error:int,oldest:?string}
     */
    public function stats(?string $type = null): array
    {
        $stats = array(self::STATUS_PENDING => 0, self::STATUS_RUNNING => 0, self::STATUS_ERROR => 0, 'oldest' => null);

        try {
            $q = osc_db_table($this->table())
                ->select('s_status')
                ->selectRaw('COUNT(*) AS i_count')
                ->selectRaw('MIN(dt_created) AS dt_oldest')
                ->groupBy('s_status');
            if ($type !== null && $type !== '') {
                $q = $q->where('s_type', $type);
            }
            $rows = $q->get();
        } catch (DbException $e) {
            return $stats;
        }

        foreach ($rows as $row) {
            $status = (string) $row['s_status'];
            if (array_key_exists($status, $stats) && $status !== 'oldest') {
                $stats[$status] = (int) $row['i_count'];
            }
            if ($status === self::STATUS_PENDING) {
                $stats['oldest'] = $row['dt_oldest'] === null ? null : (string) $row['dt_oldest'];
            }
        }

        return $stats;
    }

    /**
     * One row for the table, in a fixed column order.
     *
     * @param string              $type
     * @param string              $encoded
     * @param array<string,mixed> $options
     * @param string|null         $unique
     *
     * @return array<string,mixed>
     */
    private function row(string $type, string $encoded, array $options, ?string $unique): array
    {
        $delay   = max(0, (int) ($options['delay'] ?? 0));
        $storage = $options['storage'] ?? null;

        $row = array(
            's_type'      => $type,
            's_storage'   => ($storage === null || $storage === '') ? null : (string) $storage,
            's_payload'   => $encoded,
            's_status'    => self::STATUS_PENDING,
            'dt_next_run' => date('Y-m-d H:i:s', time() + $delay),
            'dt_created'  => date('Y-m-d H:i:s'),
        );
        // Only a keyed job names s_unique, so a plain one still inserts on a schema that
        // migration 0050 has not reached yet -- as an earlier migration's job does.
        if ($unique !== null) {
            $row['s_unique'] = $unique;
        }

        return $row;
    }

    /**
     * Run $fn, and once more if it fails: a keyed insert can lose a lock race (deadlock)
     * with another insert or a claim, and the second try almost always goes through.
     *
     * @param callable $fn
     *
     * @return mixed
     * @throws DbException when both tries fail
     */
    private static function retryOnce(callable $fn)
    {
        try {
            return $fn();
        } catch (DbException $e) {
            usleep(50000);

            return $fn();
        }
    }

    /**
     * The update a key clash applies: the new payload, and a fresh retry state.
     *
     * @return string
     */
    private static function onDuplicate(): string
    {
        return ' ON DUPLICATE KEY UPDATE s_payload = VALUES(s_payload), s_storage = VALUES(s_storage),'
            . ' dt_next_run = VALUES(dt_next_run), i_attempts = 0, s_last_error = NULL';
    }

    /**
     * A de-duplication key, or null for none.
     *
     * @param mixed $key
     *
     * @return string|null
     * @throws InvalidArgumentException when the key is not a string or is too long
     */
    private static function uniqueKey($key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        // Compared byte for byte in the column, so only printable ASCII: hash anything else.
        if (!is_string($key) || strlen($key) > self::MAX_UNIQUE_LENGTH || !preg_match('/^[\x21-\x7e]+$/', $key)) {
            throw new InvalidArgumentException('A job unique_key must be printable ASCII without spaces, at most '
                . self::MAX_UNIQUE_LENGTH . ' characters');
        }

        return $key;
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

            // A claimed job gives up its key, so a change that arrives mid-run queues a new job.
            // Its own statement: before migration 0050 the column is missing, and the claim
            // must still work.
            try {
                osc_db_execute(
                    'UPDATE ' . $table . ' SET s_unique = NULL WHERE s_worker = ? AND s_unique IS NOT NULL',
                    array($token)
                );
            } catch (DbException $e) {
                // absorbed
            }

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
     * @return bool true when the job gave up for good
     */
    public function fail(int $id, string $error): bool
    {
        try {
            $row = osc_db_table($this->table())->where('pk_i_id', $id)->first();
        } catch (DbException $e) {
            return false;
        }
        if ($row === null) {
            return false;
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

        // A job that gave up must not keep its key, or later work would fold into a job
        // that never runs. Its own statement, as in claim().
        if ($values['s_status'] === self::STATUS_ERROR) {
            try {
                osc_db_execute('UPDATE ' . $this->table() . ' SET s_unique = NULL WHERE pk_i_id = ?', array($id));
            } catch (DbException $e) {
                // absorbed
            }
        }

        return $values['s_status'] === self::STATUS_ERROR;
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
