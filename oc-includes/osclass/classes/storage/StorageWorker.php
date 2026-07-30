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
use mindstellar\model\Resource;
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

        $resource = self::resolveRow($snapshot);
        if ($resource === null) {
            // The resource row was deleted while the offload was in flight;
            // the remote copy is now orphaned, so queue its removal instead.
            StorageQueue::newInstance()->enqueue('delete', $job['s_storage'], $snapshot);

            return;
        }

        self::updateStorage($snapshot, $job['s_storage']);

        if (osc_get_preference('storage_keep_local', 'osclass') === 'none') {
            foreach (ResourceLocator::variants() as $variant) {
                $path = ResourceLocator::localPath($snapshot, $variant);
                if (file_exists($path) && !is_dir($path)) {
                    (new FileSystem())->remove($path);
                }
            }

            self::invalidateOwnerCaches($snapshot);
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

        self::updateStorage($snapshot, 'local');

        $deleteSnapshot          = $snapshot;
        $deleteSnapshot['local'] = false;
        StorageQueue::newInstance()->enqueue('delete', $job['s_storage'], $deleteSnapshot);
    }

    /**
     * Adopts a resource already sitting in a remote bucket (e.g. one an
     * older Better S3 install uploaded and then deleted locally) without
     * re-uploading it: flips s_storage once the object is confirmed present
     * remotely and confirmed absent locally. Idempotent: re-running just
     * re-flips a row that may already point at the adapter.
     *
     * @param array $job
     * @param array $snapshot
     */
    private static function handleAdopt(array $job, array $snapshot): void
    {
        $adapter = StorageManager::instance()->adapter($job['s_storage']);
        if ($adapter === null || !$adapter->isRemote()) {
            return;
        }

        // Only adopt rows whose local base copy is gone (better-s3 deleted locals after upload).
        // If a local copy still exists, leave the resource local — nothing to adopt.
        if (is_file(ResourceLocator::localPath($snapshot, ''))) {
            return;
        }

        if (!$adapter->exists(ResourceLocator::storageKey($snapshot, ''))) {
            return;
        }

        self::updateStorage($snapshot, $job['s_storage']);
    }

    /**
     * Resolve the resource row a snapshot points at from the right table:
     * snapshots that carry an s_owner_type belong to t_resource (the polymorphic
     * table), everything else is a legacy item snapshot in t_item_resource. Jobs
     * queued before this upgrade have no owner fields, so they take the item path
     * exactly as before.
     *
     * @param array $snapshot
     *
     * @return array|null the row, or null when it no longer exists
     */
    private static function resolveRow(array $snapshot): ?array
    {
        $pk = (int) ($snapshot['pk_i_id'] ?? 0);
        if ($pk <= 0) {
            return null;
        }

        if (!empty($snapshot['s_owner_type'])) {
            return Resource::newInstance()->findByPrimaryKey($pk);
        }

        $row = ItemResource::newInstance()->findByPrimaryKey($pk);

        return $row === false ? null : $row;
    }

    /**
     * Point a resource row at a storage adapter, dispatching to the owning model.
     * Both model updates flush their own row/owner cache.
     *
     * @param array  $snapshot
     * @param string $storageId
     *
     * @return void
     */
    private static function updateStorage(array $snapshot, string $storageId): void
    {
        $pk = (int) ($snapshot['pk_i_id'] ?? 0);
        if ($pk <= 0) {
            return;
        }

        if (!empty($snapshot['s_owner_type'])) {
            Resource::newInstance()->updateResource($pk, array('s_storage' => $storageId));

            return;
        }

        ItemResource::newInstance()->updateByPrimaryKey(array('s_storage' => $storageId), $pk);
    }

    /**
     * Invalidate the caches a resource participates in after its local copies are
     * dropped. Owner snapshots clear the Resource::findByOwner cache; item
     * snapshots keep the exact legacy behaviour (osc_invalidate_item_cache).
     *
     * @param array $snapshot
     *
     * @return void
     */
    private static function invalidateOwnerCaches(array $snapshot): void
    {
        if (!empty($snapshot['s_owner_type'])) {
            Resource::newInstance()->invalidateOwnerCache(
                (string) $snapshot['s_owner_type'],
                (int) ($snapshot['i_owner_id'] ?? 0)
            );

            return;
        }

        if (!empty($snapshot['fk_i_item_id']) && function_exists('osc_invalidate_item_cache')) {
            osc_invalidate_item_cache($snapshot['fk_i_item_id']);
        }
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
