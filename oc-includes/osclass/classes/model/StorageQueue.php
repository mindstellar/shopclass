<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\job\JobQueue;
use mindstellar\storage\StorageJobs;

/**
 * Deprecated. `t_storage_queue` is now `t_job_queue`, a queue any part of the site can
 * use, and its job types are namespaced -- `offload` became `storage.offload`.
 *
 * This forwards the old shape onto the new one so code outside core that queued a
 * storage job keeps working. New code calls `osc_job_enqueue()`.
 *
 * @deprecated 6.4.0 use osc_job_enqueue() / mindstellar\job\JobQueue
 */
class StorageQueue
{
    /** @var StorageQueue|null */
    private static $instance;

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
     * @param string              $type      delete|offload|restore|adopt|regenerate
     * @param string              $storageId
     * @param array<string,mixed> $snapshot
     *
     * @return void
     */
    public function enqueue(string $type, string $storageId, array $snapshot): void
    {
        StorageJobs::enqueue($type, $storageId, $snapshot);
    }

    /**
     * @param string $op              adopt|offload|restore
     * @param string $sourceStorage
     * @param string $targetStorageId
     * @param int    $offset
     *
     * @return void
     */
    public function enqueueSeed(string $op, string $sourceStorage, string $targetStorageId, int $offset = 0): void
    {
        StorageJobs::enqueueSeed($op, $sourceStorage, $targetStorageId, $offset);
    }

    /**
     * @param int $batch
     *
     * @return array<int,array<string,string|null>>
     */
    public function claim(int $batch = 20): array
    {
        return JobQueue::instance()->claim($batch);
    }

    /**
     * @param int $id
     *
     * @return void
     */
    public function complete(int $id): void
    {
        JobQueue::instance()->complete($id);
    }

    /**
     * @param int    $id
     * @param string $error
     *
     * @return void
     */
    public function fail(int $id, string $error): void
    {
        JobQueue::instance()->fail($id, $error);
    }

    /**
     * @param string $status pending|running|error
     *
     * @return int
     */
    public function countByStatus(string $status): int
    {
        return JobQueue::instance()->count($status);
    }

    /**
     * @param int $limit
     *
     * @return array<int,array<string,string|null>>
     */
    public function deadLetters(int $limit = 50): array
    {
        return JobQueue::instance()->deadLetters($limit);
    }
}
