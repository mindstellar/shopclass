<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\storage\ResourceLocator;
use mindstellar\storage\StorageManager;

/**
 * Register a storage adapter with the storage manager.
 *
 * Plugins call this on the 'register_storage_adapters' hook, e.g.:
 *   osc_add_hook('register_storage_adapters', function () {
 *       osc_register_storage_adapter(new My_S3_Adapter());
 *   });
 *
 * @param \mindstellar\storage\StorageAdapter $adapter
 *
 * @return void
 */
function osc_register_storage_adapter($adapter)
{
    StorageManager::instance()->register($adapter);
}

StorageManager::instance()->boot();

// Plugins are loaded after this file but before 'init' fires (which happens
// once per request, from BaseModel::__construct), so this is the first safe
// point for a plugin-provided adapter to register itself.
/**
 * Register the bundled S3-compatible adapter from saved preferences, when an
 * install has filled in all four connection settings. Idempotent and cheap —
 * it returns immediately once the adapter is registered for this request, and
 * register() keys by id — so it is safe to call from the `init` hook, from the
 * upload hooks, and before the worker runs.
 *
 * Registering at the point of use (not only on `init`) is deliberate: the
 * offload enqueue below decides whether to queue based on the registered
 * remote, and an upload processed on a request where the `init` registration
 * had not run would otherwise be dropped silently. On a stock install with no
 * connection configured this stays a no-op and behaviour is unchanged.
 */
function osc_storage_register_remote()
{
    if (!function_exists('osc_get_preference')) {
        return;
    }
    if (StorageManager::instance()->adapter('s3') !== null) {
        return;
    }

    $endpoint = osc_get_preference('storage_s3_endpoint', 'osclass');
    $bucket = osc_get_preference('storage_s3_bucket', 'osclass');
    $accessKey = osc_get_preference('storage_s3_access_key', 'osclass');
    $secretKey = osc_get_preference('storage_s3_secret_key', 'osclass');

    if (!$endpoint || !$bucket || !$accessKey || !$secretKey) {
        return;
    }

    osc_register_storage_adapter(new \mindstellar\storage\S3Storage([
        'endpoint' => $endpoint,
        'region' => osc_get_preference('storage_s3_region', 'osclass') ?: 'us-east-1',
        'bucket' => $bucket,
        'access_key' => $accessKey,
        'secret_key' => $secretKey,
        'path_style' => osc_get_bool_preference('storage_s3_path_style', 'osclass'),
        'public_url_base' => osc_get_preference('storage_s3_public_url', 'osclass') ?: '',
        'signed_urls' => osc_get_bool_preference('storage_s3_signed_urls', 'osclass'),
        'signed_ttl' => (int) (osc_get_preference('storage_s3_signed_ttl', 'osclass') ?: 900),
    ]));
}

osc_add_hook('init', static function () {
    osc_run_hook('register_storage_adapters', StorageManager::instance());
    osc_storage_register_remote();
});

// The worker self-exits instantly when the queue is empty, so running it on
// every cron tick is cheap for installs that never queue a job. Register the
// remote first so the worker can resolve the adapter regardless of the request
// context it is triggered from (web cron vs. CLI).
osc_add_hook('cron', static function () {
    osc_storage_register_remote();
    \mindstellar\storage\StorageWorker::run();
});

// Queue a freshly uploaded resource for offload to the configured remote
// adapter. No-op on installs that never configured one.
$oscStorageEnqueueOffload = static function ($resource) {
    if (!is_array($resource) || empty($resource['pk_i_id'])) {
        return;
    }
    // Make sure the configured remote is registered for THIS request before
    // deciding whether to queue — the offload must not hinge on the init-time
    // registration having run on the request that produced the upload.
    osc_storage_register_remote();
    $remote = StorageManager::instance()->remote();
    if ($remote === null) {
        return;
    }
    StorageQueue::newInstance()->enqueue('offload', $remote->getId(), $resource);
};

osc_add_hook('uploaded_file', $oscStorageEnqueueOffload);
// Same, for variants rewritten by the "regenerate images" admin action.
osc_add_hook('regenerated_image', $oscStorageEnqueueOffload);

// Regenerating images needs a local source file to resize from. When the
// resource lives on a remote adapter and local copies were removed
// (storage_keep_local = 'none'), pull one back down synchronously before the
// regenerate action looks for it.
osc_add_hook('regenerate_image', static function ($resource) {
    if (!is_array($resource) || ($resource['s_storage'] ?? 'local') === 'local') {
        return;
    }

    $adapter = StorageManager::instance()->forResource($resource);
    if (!$adapter->isRemote()) {
        return;
    }

    // A local source already exists; nothing to do.
    foreach (array('_original', '') as $variant) {
        if (is_file(ResourceLocator::localPath($resource, $variant))) {
            return;
        }
    }

    // Pull the best available source (_original preferred, then base) to local.
    foreach (array('_original', '') as $variant) {
        $key = ResourceLocator::storageKey($resource, $variant);
        if (!$adapter->exists($key)) {
            continue;
        }

        $data = $adapter->get($key);
        if ($data === false) {
            return;
        }

        (new \mindstellar\utility\FileSystem())->writeToFile(ResourceLocator::localPath($resource, $variant), $data);

        return;
    }
});
