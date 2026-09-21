<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\storage;

use ItemResource;
use mindstellar\job\Job;
use mindstellar\job\JobRegistry;
use mindstellar\model\Resource;
use mindstellar\utility\FileSystem;
use RuntimeException;
use Throwable;

/**
 * The `storage.*` job handlers.
 *
 * These were a `switch` inside the storage worker, which is why a plugin could never add
 * a job type of its own. They register through the same call a plugin uses, and the queue
 * that runs them is shared with everything else.
 *
 * Every one is idempotent, because the queue can run a job twice: a worker killed
 * mid-upload leaves its row claimed, and a later tick picks it up again.
 */
final class StorageJobs
{
    /** Resources expanded per seed page. */
    private const SEED_BATCH = 1000;

    /**
     * Register every storage job type. Called from the `register_jobs` hook.
     *
     * @return void
     */
    public static function register(): void
    {
        // The adapter has to exist before any storage job runs, and the request that
        // drains the queue is usually not the one that produced the upload -- a web cron
        // tick, or the CLI. Registering it here ties it to the handlers it serves.
        //
        // Guarded because this runs inside a hook: a fatal here would stop every other
        // handler registering, and take down jobs that have nothing to do with storage.
        if (function_exists('osc_storage_register_remote')) {
            osc_storage_register_remote();
        }

        JobRegistry::register('storage.delete', static fn (Job $job) => self::delete($job));
        JobRegistry::register('storage.offload', static fn (Job $job) => self::offload($job));
        JobRegistry::register('storage.restore', static fn (Job $job) => self::restore($job));
        JobRegistry::register('storage.adopt', static fn (Job $job) => self::adopt($job));
        JobRegistry::register('storage.regenerate', static fn (Job $job) => self::regenerate($job));
        JobRegistry::register('storage.seed', static fn (Job $job) => self::seed($job));
    }

    /**
     * Queue a storage job. One place builds the payload, so the polymorphic discriminator
     * below cannot be dropped by accident at a call site.
     *
     * s_owner_type / i_owner_id are what route a job to the Resource (t_resource) model
     * rather than ItemResource (t_item_resource). Dropping them sent every t_resource
     * offload -- user avatars via the uploaded_resource hook -- to the item table, where
     * the pk hit an unrelated item resource or none: the avatar never flipped, and its
     * freshly uploaded object could even be queued for deletion.
     *
     * @param string              $op        delete|offload|restore|adopt|regenerate
     * @param string              $storageId the adapter this job acts on
     * @param array<string,mixed> $snapshot  resource row fields the handler needs
     *
     * @return int the job id
     */
    public static function enqueue(string $op, string $storageId, array $snapshot): int
    {
        $payload = array(
            'pk_i_id'        => $snapshot['pk_i_id'] ?? null,
            'fk_i_item_id'   => $snapshot['fk_i_item_id'] ?? null,
            's_owner_type'   => $snapshot['s_owner_type'] ?? null,
            'i_owner_id'     => $snapshot['i_owner_id'] ?? null,
            's_path'         => $snapshot['s_path'] ?? null,
            's_extension'    => $snapshot['s_extension'] ?? null,
            's_content_type' => $snapshot['s_content_type'] ?? null,
            's_storage'      => $snapshot['s_storage'] ?? $storageId,
        );
        if (array_key_exists('local', $snapshot)) {
            $payload['local'] = (bool) $snapshot['local'];
        }

        return osc_job_enqueue('storage.' . $op, $payload, array('storage' => $storageId));
    }

    /**
     * Queue one seed job that the worker expands into per-resource jobs in the
     * background. This keeps a bulk migration of a whole catalogue off the admin
     * request, which would otherwise run one INSERT per resource inline and time out.
     *
     * @param string $op              adopt|offload|restore
     * @param string $sourceStorage   where the resources live now
     * @param string $targetStorageId where they are going
     * @param int    $offset          paging offset to resume from
     *
     * @return int the job id
     */
    public static function enqueueSeed(string $op, string $sourceStorage, string $targetStorageId, int $offset = 0): int
    {
        return osc_job_enqueue(
            'storage.seed',
            array('op' => $op, 'source' => $sourceStorage, 'offset' => $offset),
            array('storage' => $targetStorageId)
        );
    }

    /**
     * Idempotent: removing a key or file that is already gone is not an error.
     *
     * @param Job $job the payload's `local` false keeps local files
     *
     * @return void
     * @throws RuntimeException when a local file exists but cannot be removed
     */
    private static function delete(Job $job): void
    {
        $snapshot    = $job->payload();
        $adapter     = StorageManager::instance()->adapter($job->storage());
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
     * Idempotent: re-running re-uploads and re-verifies without side effects beyond the
     * ones already applied.
     *
     * @param Job $job
     *
     * @return void
     * @throws RuntimeException when the uploaded base object cannot be read back
     */
    private static function offload(Job $job): void
    {
        $snapshot = $job->payload();
        $storage  = (string) $job->storage();
        $adapter  = StorageManager::instance()->adapter($storage);
        if ($adapter === null || !$adapter->isRemote()) {
            return;
        }

        foreach (ResourceLocator::variants() as $variant) {
            $localPath = ResourceLocator::localPath($snapshot, $variant);
            if (is_file($localPath)) {
                $adapter->put(
                    $localPath,
                    ResourceLocator::storageKey($snapshot, $variant),
                    $snapshot['s_content_type'] ?? ''
                );
            }
        }

        if (!$adapter->exists(ResourceLocator::storageKey($snapshot))) {
            throw new RuntimeException('Offload verification failed for resource ' . ($snapshot['pk_i_id'] ?? ''));
        }

        if (self::resolveRow($snapshot) === null) {
            // The resource row was deleted while the offload was in flight; the remote
            // copy is now orphaned, so queue its removal instead.
            self::enqueue('delete', $storage, $snapshot);

            return;
        }

        self::updateStorage($snapshot, $storage);

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
     * Downloads every variant back to local disk, flips the resource back to the local
     * adapter, then queues removal of the now-redundant remote copies. Idempotent:
     * re-running overwrites the same local files and re-flips a row that may already be
     * local.
     *
     * @param Job $job
     *
     * @return void
     * @throws RuntimeException when the adapter is unknown or a variant cannot be downloaded
     */
    private static function restore(Job $job): void
    {
        $snapshot = $job->payload();
        $storage  = (string) $job->storage();
        $adapter  = StorageManager::instance()->adapter($storage);
        if ($adapter === null) {
            throw new RuntimeException('Unknown storage adapter: ' . $storage);
        }

        foreach (ResourceLocator::variants() as $variant) {
            $key = ResourceLocator::storageKey($snapshot, $variant);
            if (!$adapter->exists($key)) {
                continue;
            }

            $contents = $adapter->get($key);
            if ($contents === false) {
                throw new RuntimeException('Failed to read ' . $key . ' from ' . $storage);
            }

            (new FileSystem())->writeToFile(ResourceLocator::localPath($snapshot, $variant), $contents);
        }

        self::updateStorage($snapshot, 'local');

        $deleteSnapshot          = $snapshot;
        $deleteSnapshot['local'] = false;
        self::enqueue('delete', $storage, $deleteSnapshot);
    }

    /**
     * Adopts a resource already sitting in a remote bucket -- one an older Better S3
     * install uploaded and then deleted locally -- without re-uploading it: flips
     * s_storage once the object is confirmed present remotely and absent locally.
     * Idempotent: re-running re-flips a row that may already point at the adapter.
     *
     * @param Job $job
     *
     * @return void
     */
    private static function adopt(Job $job): void
    {
        $snapshot = $job->payload();
        $storage  = (string) $job->storage();
        $adapter  = StorageManager::instance()->adapter($storage);
        if ($adapter === null || !$adapter->isRemote()) {
            return;
        }

        // Only adopt rows whose local base copy is gone (better-s3 deleted locals after
        // upload). A local copy still there means the resource is local: nothing to adopt.
        if (is_file(ResourceLocator::localPath($snapshot, ''))) {
            return;
        }

        if (!$adapter->exists(ResourceLocator::storageKey($snapshot, ''))) {
            return;
        }

        self::updateStorage($snapshot, $storage);
    }

    /**
     * Regenerates one resource's image variants. Queued by the "Regenerate images" admin
     * action instead of run inline, one job per resource, when a remote adapter is
     * active -- that keeps each regeneration, and any pull-back of a remote-only source,
     * off the request thread. The `regenerated_image` hook fired from inside
     * regenerateResourceImages() queues the offload back to remote storage.
     *
     * @param Job $job
     *
     * @return void
     */
    private static function regenerate(Job $job): void
    {
        $resource = ItemResource::newInstance()->findByPrimaryKey($job->get('pk_i_id', 0));
        if ($resource === false) {
            return; // resource deleted meanwhile; nothing to regenerate
        }

        \ItemActions::regenerateResourceImages($resource);
    }

    /**
     * Expand a bulk-migration seed into per-resource jobs, one page at a time.
     *
     * Pages resources on the source storage, queues an op job for each, then asks to run
     * again from the next offset while a full page came back. Running this in the worker
     * rather than the admin request is what lets a catalogue of any size migrate without
     * the request timing out.
     *
     * @param Job $job the payload carries op, source and offset; the job's storage is the target
     *
     * @return void
     */
    private static function seed(Job $job): void
    {
        $op     = (string) $job->get('op', '');
        $source = (string) $job->get('source', 'local');
        $offset = (int) $job->get('offset', 0);
        $target = (string) $job->storage();

        // Only the bulk migration types seed per-resource work.
        if (!in_array($op, array('adopt', 'offload', 'restore'), true)) {
            return;
        }

        $rows = ItemResource::newInstance()->getResourcesBatchByStorage($source, $offset, self::SEED_BATCH);

        foreach ($rows as $row) {
            self::enqueue($op, $target, $row);
        }

        // A full page means there may be more, so carry on from the next offset on a
        // later tick. A short or empty page means the catalogue is exhausted.
        if (count($rows) === self::SEED_BATCH) {
            $job->repeat(
                array('op' => $op, 'source' => $source, 'offset' => $offset + self::SEED_BATCH)
            );
        }
    }

    /**
     * Resolve the resource row a snapshot points at from the right table: snapshots
     * carrying an s_owner_type belong to t_resource (the polymorphic table), everything
     * else is a legacy item snapshot in t_item_resource. Jobs queued before that upgrade
     * have no owner fields and take the item path exactly as before.
     *
     * @param array<string,mixed> $snapshot
     *
     * @return array<string,mixed>|null the row, or null when it no longer exists
     */
    private static function resolveRow(array $snapshot): ?array
    {
        $pk = (int) ($snapshot['pk_i_id'] ?? 0);
        if ($pk <= 0) {
            return null;
        }

        $model = !empty($snapshot['s_owner_type'])
            ? Resource::newInstance()
            : ItemResource::newInstance();

        $row = $model->findByPrimaryKey($pk);

        // Both models inherit DAO::findByPrimaryKey, which returns false (not null) for a
        // missing row; normalise so callers only have to guard one shape.
        return $row === false ? null : $row;
    }

    /**
     * Point a resource row at a storage adapter, dispatching to the owning model. Both
     * model updates flush their own row/owner cache.
     *
     * @param array<string,mixed> $snapshot
     * @param string              $storageId
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
     * dropped. Owner snapshots clear the Resource::findByOwner cache; item snapshots keep
     * the exact legacy behaviour.
     *
     * @param array<string,mixed> $snapshot
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
}
