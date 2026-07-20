<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\storage;

use ItemResource;
use mindstellar\utility\FileSystem;
use RuntimeException;
use StorageQueue;
use Throwable;

/**
 * Class StorageWorker
 *
 * Drains the storage job queue (t_storage_queue) from the 'cron' hook.
 * Exits immediately when the queue is empty, so wiring it into every cron
 * request is cheap for installs that never queue a job (pure-local installs
 * never do — deletes stay synchronous there).
 *
 * @package mindstellar\storage
 */
class StorageWorker
{
    /**
     * @param int $maxSeconds
     */
    public static function run(int $maxSeconds = 20): void
    {
        $queue = StorageQueue::newInstance();
        if ($queue->countByStatus('pending') === 0) {
            return;
        }

        $start = time();
        while ((time() - $start) < $maxSeconds) {
            $jobs = $queue->claim();
            if (empty($jobs)) {
                break;
            }

            foreach ($jobs as $job) {
                self::process($queue, $job);
            }
        }
    }

    /**
     * @param StorageQueue $queue
     * @param array        $job
     */
    private static function process(StorageQueue $queue, array $job): void
    {
        $id = (int) $job['pk_i_id'];

        try {
            $snapshot = json_decode((string) $job['s_payload'], true);
            if (!is_array($snapshot)) {
                $snapshot = array();
            }

            switch ($job['s_type']) {
                case 'delete':
                    self::handleDelete($job, $snapshot);
                    break;
                case 'offload':
                    self::handleOffload($job, $snapshot);
                    break;
                case 'restore':
                    self::handleRestore($job, $snapshot);
                    break;
                case 'adopt':
                    self::handleAdopt($job, $snapshot);
                    break;
                case 'regenerate':
                    self::handleRegenerate($job, $snapshot);
                    break;
                default:
                    throw new RuntimeException('Unknown storage queue job type: ' . $job['s_type']);
            }

            $queue->complete($id);
        } catch (Throwable $e) {
            $queue->fail($id, $e->getMessage());
        }
    }

    /**
     * Idempotent: removing a key/file that's already gone is not an error.
     *
     * @param array $job
     * @param array $snapshot
     */
    private static function handleDelete(array $job, array $snapshot): void
    {
        $adapter     = StorageManager::instance()->adapter($job['s_storage']);
        $removeLocal = ($snapshot['local'] ?? true) !== false;

        foreach (ResourceLocator::variants() as $variant) {
            if ($adapter !== null && $adapter->isRemote()) {
                try {
                    $adapter->delete(ResourceLocator::storageKey($snapshot, $variant));
                } catch (Throwable $e) {
                    // Missing-key errors from the remote adapter are not fatal here.
                }
            }

            if ($removeLocal) {
                $path = ResourceLocator::localPath($snapshot, $variant);
                if (file_exists($path) && !is_dir($path)) {
                    (new FileSystem())->remove($path);
                }
            }
        }
    }

    /**
     * Idempotent: re-running re-uploads and re-verifies without side effects
     * beyond the ones already applied.
     *
     * @param array $job
     * @param array $snapshot
     */
    private static function handleOffload(array $job, array $snapshot): void
    {
        $adapter = StorageManager::instance()->adapter($job['s_storage']);
        if ($adapter === null || !$adapter->isRemote()) {
            return;
        }

        foreach (ResourceLocator::variants() as $variant) {
            $localPath = ResourceLocator::localPath($snapshot, $variant);
            if (is_file($localPath)) {
                $adapter->put($localPath, ResourceLocator::storageKey($snapshot, $variant), $snapshot['s_content_type'] ?? '');
            }
        }

        if (!$adapter->exists(ResourceLocator::storageKey($snapshot))) {
            throw new RuntimeException('Offload verification failed for resource ' . ($snapshot['pk_i_id'] ?? ''));
        }

        $resource = ItemResource::newInstance()->findByPrimaryKey($snapshot['pk_i_id'] ?? 0);
        if ($resource === false) {
            // The resource row was deleted while the offload was in flight;
            // the remote copy is now orphaned, so queue its removal instead.
            StorageQueue::newInstance()->enqueue('delete', $job['s_storage'], $snapshot);

            return;
        }

        ItemResource::newInstance()->updateByPrimaryKey(array('s_storage' => $job['s_storage']), $snapshot['pk_i_id']);

        if (osc_get_preference('storage_keep_local', 'osclass') === 'none') {
            foreach (ResourceLocator::variants() as $variant) {
                $path = ResourceLocator::localPath($snapshot, $variant);
                if (file_exists($path) && !is_dir($path)) {
                    (new FileSystem())->remove($path);
                }
            }

            if (!empty($snapshot['fk_i_item_id']) && function_exists('osc_invalidate_item_cache')) {
                osc_invalidate_item_cache($snapshot['fk_i_item_id']);
            }
        }
    }

    /**
     * Downloads every variant back to local disk, flips the resource back to
     * the local adapter, then queues removal of the now-redundant remote
     * copies. Idempotent: re-running overwrites the same local files and
     * re-flips a row that may already be 'local'.
     *
     * @param array $job
     * @param array $snapshot
     */
    private static function handleRestore(array $job, array $snapshot): void
    {
        $adapter = StorageManager::instance()->adapter($job['s_storage']);
        if ($adapter === null) {
            throw new RuntimeException('Unknown storage adapter: ' . $job['s_storage']);
        }

        foreach (ResourceLocator::variants() as $variant) {
            $key = ResourceLocator::storageKey($snapshot, $variant);
            if (!$adapter->exists($key)) {
                continue;
            }

            $contents = $adapter->get($key);
            if ($contents === false) {
                throw new RuntimeException('Failed to read ' . $key . ' from ' . $job['s_storage']);
            }

            (new FileSystem())->writeToFile(ResourceLocator::localPath($snapshot, $variant), $contents);
        }

        if (!empty($snapshot['pk_i_id'])) {
            ItemResource::newInstance()->updateByPrimaryKey(array('s_storage' => 'local'), $snapshot['pk_i_id']);
        }

        $deleteSnapshot          = $snapshot;
        $deleteSnapshot['local'] = false;
        StorageQueue::newInstance()->enqueue('delete', $job['s_storage'], $deleteSnapshot);
    }

    /**
     * Adoption of pre-existing remote objects into the resource table is not
     * implemented yet; dead-letter visibly rather than silently no-op.
     *
     * @param array $job
     * @param array $snapshot
     */
    private static function handleAdopt(array $job, array $snapshot): void
    {
        throw new RuntimeException('not implemented');
    }

    /**
     * Regenerates a single resource's image variants. Queued by the
     * "Regenerate images" admin action instead of run inline, one job per
     * resource, when a remote storage adapter is active — that keeps each
     * regeneration (and any pull-back of a remote-only source) off the
     * request thread. The 'regenerated_image' hook fired from inside
     * regenerateResourceImages() enqueues the offload back to remote
     * storage via the existing listener.
     *
     * @param array $job
     * @param array $snapshot
     */
    private static function handleRegenerate(array $job, array $snapshot): void
    {
        $resource = ItemResource::newInstance()->findByPrimaryKey($snapshot['pk_i_id'] ?? 0);
        if ($resource === false) {
            return; // resource deleted meanwhile; nothing to regenerate
        }

        \ItemActions::regenerateResourceImages($resource);
    }
}
