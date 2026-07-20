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

        $this->dao->query(
            'UPDATE ' . $table . " SET s_status = 'pending', s_worker = NULL"
            . " WHERE s_status = 'running' AND dt_locked < " . $this->dao->escape($stale)
        );

        $this->dao->query(
            'UPDATE ' . $table . " SET s_status = 'running', s_worker = " . $this->dao->escape($token)
            . ', dt_locked = ' . $this->dao->escape($now)
            . " WHERE s_status = 'pending' AND dt_next_run <= " . $this->dao->escape($now)
            . ' ORDER BY pk_i_id LIMIT ' . max(1, $batch)
        );

        $result = $this->dao->query(
            'SELECT * FROM ' . $table . ' WHERE s_worker = ' . $this->dao->escape($token)
            . " AND s_status = 'running' ORDER BY pk_i_id"
        );

        if ($result === false || $result->numRows() === 0) {
            return array();
        }

        return $result->result();
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
        $this->dao->select('COUNT(*) AS count');
        $this->dao->from($this->getTableName());
        $this->dao->where('s_status', $status);
        $result = $this->dao->get();

        if ($result === false || $result->numRows() === 0) {
            return 0;
        }

        $row = $result->row();

        return (int) $row['count'];
    }

    /**
     * @param int $limit
     *
     * @return array
     */
    public function deadLetters(int $limit = 50): array
    {
        $this->dao->select($this->getFields());
        $this->dao->from($this->getTableName());
        $this->dao->where('s_status', 'error');
        $this->dao->orderBy('pk_i_id', 'DESC');
        $result = $this->dao->get('', $limit);

        if ($result === false || $result->numRows() === 0) {
            return array();
        }

        return $result->result();
    }
}

/* file end: ./oc-includes/osclass/classes/model/StorageQueue.php */
