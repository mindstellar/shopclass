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
 * The `storage.*` job handlers, registered through the same call a plugin uses. Each is
 * idempotent, since a worker killed mid-job leaves its row claimed for a later tick to pick up.
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
        // The adapter must exist before any storage job runs, and the request draining
        // the queue is often not the one that produced the upload. Guarded: a fatal here
        // must not stop every other handler from registering.
        if (function_exists('osc_storage_register_remote')) {
            osc_storage_register_remote();
        }

        JobRegistry::register('storage.delete', static fn (Job $job) => self::delete($job));
        JobRegistry::register('storage.offload', static fn (Job $job) => self::offload($job));
        JobRegistry::register('storage.restore', static fn (Job $job) => self::restore($job));
        JobRegistry::register('storage.adopt', static fn (Job $job) => self::adopt($job));
        JobRegistry::register('storage.regenerate', static fn (Job $job) => self::regenerate($job));
        JobRegistry::register('storage.seed', static fn (Job $job) => self::seed($job));
        JobRegistry::register('storage.purge', static fn (Job $job) => self::purge($job));

        $photo = static fn (array $p): string => !empty($p['fk_i_item_id'])
            ? sprintf(__('Photo #%1$d of listing #%2$d'), (int) ($p['pk_i_id'] ?? 0), (int) $p['fk_i_item_id'])
            : sprintf(__('File #%d'), (int) ($p['pk_i_id'] ?? 0));
        JobRegistry::describe('storage.delete', __('Delete a file from storage'), $photo);
        JobRegistry::describe('storage.offload', __('Move a file to remote storage'), $photo);
        JobRegistry::describe('storage.restore', __('Bring a file back to this server'), $photo);
        JobRegistry::describe('storage.adopt', __('Adopt a file already in remote storage'), $photo);
        JobRegistry::describe('storage.regenerate', __('Rebuild photo sizes'), $photo);
        JobRegistry::describe('storage.seed', __('Queue files for a storage move'));
        JobRegistry::describe(
            'storage.purge',
            __('Delete files from storage'),
            static fn (array $p): string => sprintf(__('%d files'), count((array) ($p['rows'] ?? array())))
        );
    }

    /**
     * Queue a storage job. One place builds the payload, so s_owner_type / i_owner_id --
     * which route a job to the Resource model instead of ItemResource -- cannot be
     * dropped by accident at a call site.
     *
     * @param string              $op        delete|offload|restore|adopt|regenerate
     * @param string              $storageId the adapter this job acts on
     * @param array<string,mixed> $snapshot  resource row fields the handler needs
     *
     * @return int the job id
     */
    public static function enqueue(string $op, string $storageId, array $snapshot): int
    {
        $payload              = self::snapshot($snapshot);
        $payload['s_storage'] = $snapshot['s_storage'] ?? $storageId;
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

    /** The row fields a storage job needs to find a resource's files and its owner. */
    private const ROW_FIELDS = array(
        'pk_i_id', 's_base_name', 'fk_i_item_id', 's_owner_type', 'i_owner_id', 's_path', 's_extension', 's_content_type', 's_storage',
    );

    /** Rows one purge job carries at most, so a job with a remote delete per variant stays short. */
    public const PURGE_BATCH = 50;

    /**
     * Remove the files of deleted resource rows once the delete has committed. Files on this
     * server go at once; rows on remote storage, or on an install with a remote adapter, go
     * through one `storage.purge` job per batch instead of one job per file.
     *
     * @param array<int,array<string,mixed>> $rows deleted t_resource rows or listing photo rows
     *
     * @return void
     */
    public static function purgeAfterCommit(array $rows): void
    {
        if ($rows === array()) {
            return;
        }
        \mindstellar\database\Db::afterCommit(static function () use ($rows): void {
            $queued = array();
            $local  = StorageManager::getInstance()->remote() === null;
            foreach ($rows as $row) {
                if (empty($row['pk_i_id'])) {
                    continue;
                }
                if ($local && ($row['s_storage'] ?? 'local') === 'local') {
                    self::removeFiles(array($row));
                } else {
                    $queued[] = self::snapshot($row);
                }
            }
            foreach (array_chunk($queued, self::PURGE_BATCH) as $batch) {
                osc_job_enqueue('storage.purge', array('rows' => $batch));
            }
        });
    }

    /**
     * Remove every file of the rows a purge job carries, on their storage and on this server.
     * Idempotent: a file or key that is already gone is not an error.
     *
     * @param Job $job
     *
     * @return void
     * @throws RuntimeException when a local file exists but cannot be removed
     */
    private static function purge(Job $job): void
    {
        $failed = null;
        foreach ((array) $job->get('rows', array()) as $row) {
            if (!is_array($row) || empty($row['pk_i_id'])) {
                continue;
            }
            try {
                self::deleteFiles($row, (string) ($row['s_storage'] ?? 'local'), true);
            } catch (RuntimeException $e) {
                $failed ??= $e;
            }
        }
        // Every row was tried; a retry of the whole job is safe, as each removal is idempotent.
        if ($failed !== null) {
            throw $failed;
        }
    }

    /**
     * The fields a job needs to find a row's files after the row is gone.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function snapshot(array $row): array
    {
        $out = array();
        foreach (self::ROW_FIELDS as $field) {
            $out[$field] = $row[$field] ?? null;
        }

        return $out;
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
        $snapshot = $job->payload();
        self::deleteFiles($snapshot, $job->storage(), ($snapshot['local'] ?? true) !== false);
    }

    /**
     * Remove a resource's files from a remote adapter and, unless told not to, from this server.
     * Missing files and keys are not errors.
     *
     * @param array<string,mixed> $row
     *
     * @return void
     * @throws RuntimeException when a local file exists but cannot be removed
     */
    private static function deleteFiles(array $row, string $storage, bool $removeLocal): void
    {
        if (!ResourceLocator::isUploadPath($row)) {
            return;
        }
        $adapter = StorageManager::getInstance()->adapter($storage);
        if ($adapter !== null && $adapter->isRemote()) {
            foreach (ResourceLocator::variants() as $variant) {
                try {
                    $adapter->delete(ResourceLocator::storageKey($row, $variant));
                } catch (Throwable $e) {
                    // Missing-key errors from the remote adapter are not fatal here.
                }
            }
        }
        if ($removeLocal) {
            self::removeLocal($row);
        }
    }

    /**
     * Remove the files of resources on this server now, one row at a time, so a file that
     * cannot be removed does not keep the others.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return void
     */
    public static function removeFiles(array $rows): void
    {
        foreach ($rows as $row) {
            try {
                self::removeLocal($row);
            } catch (Throwable $e) {
                error_log('Resource ' . ($row['pk_i_id'] ?? '?') . ' files not removed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Remove each local variant of a resource that is still a file.
     *
     * @param array<string,mixed> $snapshot
     *
     * @return void
     */
    private static function removeLocal(array $snapshot): void
    {
        if (!ResourceLocator::isUploadPath($snapshot)) {
            return;
        }
        foreach (ResourceLocator::variants() as $variant) {
            $path = ResourceLocator::localPath($snapshot, $variant);
            if (file_exists($path) && !is_dir($path)) {
                (new FileSystem())->remove($path);
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
        $adapter  = StorageManager::getInstance()->adapter($storage);
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
            self::removeLocal($snapshot);
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
        $adapter  = StorageManager::getInstance()->adapter($storage);
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
        $adapter  = StorageManager::getInstance()->adapter($storage);
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
     * PhotoService::regenerateImages() queues the offload back to remote storage.
     *
     * @param Job $job
     *
     * @return void
     */
    private static function regenerate(Job $job): void
    {
        $resource = ItemResource::getInstance()->findByPrimaryKey($job->get('pk_i_id', 0));
        if ($resource === false) {
            return; // resource deleted meanwhile; nothing to regenerate
        }

        \mindstellar\listing\PhotoService::regenerateImages($resource);
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

        $rows = ItemResource::getInstance()->getResourcesBatchByStorage($source, $offset, self::SEED_BATCH);

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
     * Resolve the resource row a snapshot points at: a snapshot carrying an s_owner_type
     * is read for that owner type, anything else is a listing photo. Item snapshots
     * have no owner fields.
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

        if (!empty($snapshot['s_owner_type'])) {
            // The owner type must match, so a job queued before a row was renumbered cannot reach another row.
            return (new Resource())->findOwned((string) $snapshot['s_owner_type'], $pk);
        }

        $row = ItemResource::getInstance()->findByPrimaryKey($pk);

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
            $row = self::resolveRow($snapshot);
            if ($row !== null) {
                (new Resource())->updateResource((int) $row['pk_i_id'], array('s_storage' => $storageId));
            }

            return;
        }

        ItemResource::getInstance()->updateByPrimaryKey(array('s_storage' => $storageId), $pk);
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
            (new Resource())->invalidateOwnerCache(
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
