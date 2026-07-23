<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class StorageQueue
 *
 * Durable job queue backing t_storage_queue. Jobs are self-contained
 * (payload is a JSON snapshot, not a foreign-key lookup) so a job survives
 * the deletion of the row that spawned it. Handlers that consume the queue
 * must be idempotent: rows are claimed under a worker token rather than
 * removed, so a crashed worker's rows are recovered and retried.
 */
class StorageQueue extends DAO
{
    /**
     * @var StorageQueue
     */
    private static $instance;

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('t_storage_queue');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields(
            array(
                'pk_i_id',
                's_type',
                's_storage',
                's_payload',
                's_status',
                'i_attempts',
                's_last_error',
                's_worker',
                'dt_next_run',
                'dt_locked',
                'dt_created',
            )
        );
    }

    /**
     * @return StorageQueue
     */
    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Queue a job. The payload is trimmed to a minimal, self-contained
     * snapshot; duplicates are allowed since handlers are idempotent.
     *
     * @param string $type
     * @param string $storageId
     * @param array  $snapshot
     */
    public function enqueue(string $type, string $storageId, array $snapshot): void
    {
        $now = date('Y-m-d H:i:s');

        $payload = array(
            'pk_i_id'        => $snapshot['pk_i_id'] ?? null,
            'fk_i_item_id'   => $snapshot['fk_i_item_id'] ?? null,
            's_path'         => $snapshot['s_path'] ?? null,
            's_extension'    => $snapshot['s_extension'] ?? null,
            's_content_type' => $snapshot['s_content_type'] ?? null,
            's_storage'      => $snapshot['s_storage'] ?? $storageId,
        );
        if (array_key_exists('local', $snapshot)) {
            $payload['local'] = (bool) $snapshot['local'];
        }

        $this->insert(
            array(
                's_type'      => $type,
                's_storage'   => $storageId,
                's_payload'   => json_encode($payload),
                's_status'    => 'pending',
                'dt_next_run' => $now,
                'dt_created'  => $now,
            )
        );
    }

    /**
     * Recover stale locks (a worker that died mid-job), then claim up to
     * $batch pending, due jobs under a fresh worker token.
     *
     * @param int $batch
     *
     * @return array
     */
    public function claim(int $batch = 20): array
    {
        $table = $this->getTableName();
        $now   = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - 15 * 60);
        $token = uniqid('w', true);

        // Recover stale locks. The result is discarded and the failure swallowed
        // on its own, so a hiccup here never aborts the claim below — the legacy
        // query() returned false without throwing and the sequence ran on.
        try {
            osc_db_execute(
                'UPDATE ' . $table . " SET s_status = 'pending', s_worker = NULL"
                . " WHERE s_status = 'running' AND dt_locked < ?",
                array($stale)
            );
        } catch (\mindstellar\database\DbException $e) {
            // absorbed, as the legacy false return was
        }

        // Claim up to max(1, $batch) pending, due rows under a fresh token, oldest
        // id first. ORDER BY and LIMIT on an UPDATE are not expressible through the
        // builder, so this is hand-written: the table name is a fixed model
        // constant, every value is a bound '?', and the limit is an int.
        try {
            osc_db_execute(
                'UPDATE ' . $table . " SET s_status = 'running', s_worker = ?, dt_locked = ?"
                . " WHERE s_status = 'pending' AND dt_next_run <= ?"
                . ' ORDER BY pk_i_id LIMIT ' . (int) max(1, $batch),
                array($token, $now, $now)
            );
        } catch (\mindstellar\database\DbException $e) {
            // absorbed
        }

        try {
            $rows = osc_db_select(
                'SELECT * FROM ' . $table . ' WHERE s_worker = ?'
                . " AND s_status = 'running' ORDER BY pk_i_id",
                array($token)
            );
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        if ($rows === array()) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }

    /**
     * @param int $id
     */
    public function complete(int $id): void
    {
        $this->deleteByPrimaryKey($id);
    }

    /**
     * Record a failed attempt. Dead-letters the job past the retry ceiling,
     * otherwise reschedules it with an exponential backoff.
     *
     * @param int    $id
     * @param string $error
     */
    public function fail(int $id, string $error): void
    {
        $row = $this->findByPrimaryKey($id);
        if ($row === false) {
            return;
        }

        $attempts     = (int) $row['i_attempts'];
        $nextAttempts = $attempts + 1;

        $values = array(
            'i_attempts'   => $nextAttempts,
            's_last_error' => substr($error, 0, 250),
            's_worker'     => null,
        );

        if ($nextAttempts >= 8) {
            $values['s_status'] = 'error';
        } else {
            $values['s_status']    = 'pending';
            $values['dt_next_run'] = date('Y-m-d H:i:s', time() + (2 ** min($attempts, 7)) * 60);
        }

        $this->updateByPrimaryKey($values, $id);
    }

    /**
     * @param string $status
     *
     * @return int
     */
    public function countByStatus(string $status): int
    {
        try {
            return osc_db_table($this->getTableName())->where('s_status', $status)->count();
        } catch (\mindstellar\database\DbException $e) {
            return 0;
        }
    }

    /**
     * @param int $limit
     *
     * @return array
     */
    public function deadLetters(int $limit = 50): array
    {
        if ($limit <= 0) {
            return array();
        }

        try {
            $rows = osc_db_table($this->getTableName())
                ->select(...$this->getFields())
                ->where('s_status', 'error')
                ->orderBy('pk_i_id', 'DESC')
                ->limit($limit)
                ->get();
        } catch (\mindstellar\database\DbException $e) {
            return array();
        }

        return osc_db_stringify_rows($rows);
    }
}

/* file end: ./oc-includes/osclass/classes/model/StorageQueue.php */
