<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\model\Resource;
use mindstellar\storage\ResourceUploader;
use mindstellar\storage\StorageManager;

/**
 * All resources belonging to an owner, as an array of resource rows.
 *
 * @param string $ownerType owner type slug ('user', 'page', or a plugin's own)
 * @param int    $ownerId
 *
 * @return array
 */
function osc_get_resources(string $ownerType, int $ownerId): array
{
    return Resource::newInstance()->findByOwner($ownerType, $ownerId);
}

/**
 * Public URL for a resource row's variant, routed through the same resource_path
 * and resource_url filters the item helpers use — so remote-adapter URL
 * substitution and private-bucket presigning apply identically to any owner type.
 *
 * The name differs from the loop-context osc_resource_url() in hItems.php, which
 * is a zero-argument public API over the current item resource; this one resolves
 * an explicit resource row of any owner type.
 *
 * @param array  $resource a resource row (from osc_get_resources)
 * @param string $variant  '' (main), 'thumbnail', 'preview' or 'original'
 *
 * @return string
 */
function osc_get_resource_url(array $resource, string $variant = ''): string
{
    $base = (string) osc_apply_filter(
        'resource_path',
        osc_base_url() . ($resource['s_path'] ?? ''),
        $resource
    );

    [$suffix, $filter] = match ($variant) {
        'thumbnail' => array('_thumbnail', 'resource_thumbnail_url'),
        'preview'   => array('_preview', 'resource_preview_url'),
        'original'  => array('_original', 'resource_original_url'),
        default     => array('', 'resource_url'),
    };

    $url = $base . ($resource['pk_i_id'] ?? '') . $suffix . '.' . ($resource['s_extension'] ?? '');

    return (string) osc_apply_filter($filter, $url, $resource);
}

/*
 * -------------------------------------------------------------------------
 * Integrity plumbing for the polymorphic resource table
 * -------------------------------------------------------------------------
 * t_resource carries no database foreign key (an owner can be an item, a user,
 * a page or a plugin-defined type — MySQL cannot enforce a polymorphic FK), so
 * ownership integrity is maintained at the application level: a delete hook per
 * owner type cascades file+row cleanup, and a daily sweep removes anything whose
 * owner has vanished. Plugins that register their own owner type should register
 * their own delete hook the same way the core ones below do.
 */

// Offload a freshly uploaded (non-item) resource to the configured remote
// adapter. Item uploads fire 'uploaded_file' and are handled in hStorage.php;
// this is the owner-agnostic equivalent for ResourceUploader uploads. The row
// carries s_owner_type, so the worker routes it through the Resource model.
osc_add_hook('uploaded_resource', static function ($resource) {
    $remote = StorageManager::instance()->remote();
    if ($remote === null || !is_array($resource) || empty($resource['pk_i_id'])) {
        return;
    }
    StorageQueue::newInstance()->enqueue('offload', $remote->getId(), $resource);
});

// App-level cascade: deleting a user removes every resource it owns (avatars in
// A3, and anything a plugin attached to owner type 'user').
osc_add_hook('delete_user', static function ($id) {
    (new ResourceUploader())->deleteByOwner(Resource::OWNER_USER, (int) $id);
});

// Future-proofing: no t_resource rows use owner type 'item' until the item
// backfill lands, but registering the cascade now means they are covered the
// moment they exist. The legacy t_item_resource path is unaffected.
osc_add_hook('delete_item', static function ($id) {
    (new ResourceUploader())->deleteByOwner(Resource::OWNER_ITEM, (int) $id);
});

// Daily orphan sweep: reclaim resources whose owner record is gone.
osc_add_hook('cron_daily', 'osc_sweep_orphan_resources');

/**
 * Walk t_resource and delete resources whose owner no longer exists.
 *
 * Bounded and conservative by design so it is safe on shared hosting:
 *  - at most SWEEP_CAP rows are examined per run;
 *  - only the owner types core knows how to resolve (item/user/page) are
 *    checked — unknown (plugin) types are skipped and left for their owner;
 *  - a 48h grace window means an in-flight upload is never mistaken for an
 *    orphan.
 * Ids are snapshotted up front so deleting rows mid-walk cannot shift the page.
 *
 * @return void
 */
function osc_sweep_orphan_resources(): void
{
    $sweepCap = 500;
    $graceCutoff = time() - (48 * 3600);

    $resourceModel = Resource::newInstance();
    $ids = $resourceModel->getResourceIdsBatch(0, $sweepCap);
    if (empty($ids)) {
        return;
    }

    $uploader = new ResourceUploader();
    $removed  = 0;

    foreach ($ids as $id) {
        $row = $resourceModel->findByPrimaryKey((int) $id);
        if ($row === null) {
            continue;
        }

        $ownerType = (string) ($row['s_owner_type'] ?? '');
        $ownerId   = (int) ($row['i_owner_id'] ?? 0);

        // Only core-known owner types are resolvable here.
        if (!in_array($ownerType, array(Resource::OWNER_ITEM, Resource::OWNER_USER, Resource::OWNER_PAGE), true)) {
            continue;
        }

        // Grace window: never touch a row younger than 48h.
        $created = strtotime((string) ($row['dt_created'] ?? '')) ?: 0;
        if ($created === 0 || $created > $graceCutoff) {
            continue;
        }

        if (osc_resource_owner_exists($ownerType, $ownerId)) {
            continue;
        }

        $uploader->delete($row);
        $removed++;
    }

    if ($removed > 0 && class_exists('Log')) {
        Log::newInstance()->insertLog(
            'resources',
            'orphan_sweep',
            0,
            'Removed ' . $removed . ' orphan resource(s)',
            'cron',
            0
        );
    }
}

/**
 * Whether the owner record a resource points at still exists.
 *
 * @param string $ownerType
 * @param int    $ownerId
 *
 * @return bool
 */
function osc_resource_owner_exists(string $ownerType, int $ownerId): bool
{
    if ($ownerId <= 0) {
        return false;
    }

    $owner = match ($ownerType) {
        Resource::OWNER_ITEM => Item::newInstance()->findByPrimaryKey($ownerId),
        Resource::OWNER_USER => User::newInstance()->findByPrimaryKey($ownerId),
        Resource::OWNER_PAGE => Page::newInstance()->findByPrimaryKey($ownerId),
        default              => null,
    };

    return is_array($owner) && !empty($owner);
}
