<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\model\Resource;
use mindstellar\storage\ResourceLocator;
use mindstellar\storage\ResourceUploader;
use mindstellar\storage\StorageManager;

/**
 * All resources belonging to an owner, as an array of resource rows.
 *
 * @param string $ownerType owner type slug ('user', 'page', or a plugin's own)
 * @param int    $ownerId
 *
 * @return array<int,array<string,mixed>>
 */
function osc_get_resources(string $ownerType, int $ownerId): array
{
    return (new Resource())->findByOwner($ownerType, $ownerId);
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
 * @param array<string,mixed> $resource a resource row (from osc_get_resources)
 * @param string              $variant  '' (main), 'thumbnail', 'preview' or 'original'
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

    $url = $base . ResourceLocator::baseName($resource) . $suffix . '.' . ($resource['s_extension'] ?? '');

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
    $remote = StorageManager::getInstance()->remote();
    if ($remote === null || !is_array($resource) || empty($resource['pk_i_id'])) {
        return;
    }
    \mindstellar\storage\StorageJobs::enqueue('offload', $remote->getId(), $resource);
});

// A user's resources are removed by User::deleteUser(), with the user row, so a delete
// that rolls back keeps them.

// A listing's photos are removed by Item::deleteByPrimaryKey(), with the listing, so a
// delete that rolls back keeps them.

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

    // Each run continues where the last stopped, and wraps to the start at the end.
    $offset        = (int) osc_get_preference('resource_sweep_offset');
    $resourceModel = new Resource();
    $ids = $resourceModel->getResourceIdsBatch($offset, $sweepCap);
    osc_set_preference('resource_sweep_offset', count($ids) < $sweepCap ? 0 : $offset + $sweepCap, 'osclass', 'INTEGER');
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
        Log::getInstance()->insertLog(
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
        Resource::OWNER_ITEM => Item::getInstance()->findByPrimaryKey($ownerId),
        Resource::OWNER_USER => User::getInstance()->findByPrimaryKey($ownerId),
        Resource::OWNER_PAGE => Page::getInstance()->findByPrimaryKey($ownerId),
        default              => null,
    };

    return is_array($owner) && !empty($owner);
}

/*
 * -------------------------------------------------------------------------
 * Unified media library (read layer over both resource tables)
 * -------------------------------------------------------------------------
 * The admin Media page and the editor's media picker both browse every uploaded
 * file in t_resource: listing photos (owner type `item`), avatars, page images,
 * unattached library uploads and plugin types, in one shape.
 */

/**
 * Distinct, well-formed owner types currently present in t_resource, listing photos left out.
 *
 * @return string[]
 */
function osc_media_owner_types(): array
{
    $out = array();
    foreach (\mindstellar\storage\MediaQuery::ownerTypes() as $type) {
        if (Resource::isValidOwnerType($type)) {
            $out[] = $type;
        }
    }

    return $out;
}

/**
 * A page of normalised media rows plus the total for a filter. $type is 'all',
 * 'item' (listing photos), or a t_resource owner type ('user', 'page',
 * 'library', a plugin type). Each row carries: src ('item'|'resource'), id,
 * owner_id, owner_type, s_name, s_extension, s_content_type, s_path, s_storage, s_base_name.
 *
 * @param string $type
 * @param int    $iPage    1-based page number
 * @param int    $perPage
 *
 * @return array{rows:array<int,array>,total:int}
 */
function osc_media_library_query(string $type, int $iPage, int $perPage): array
{
    return \mindstellar\storage\MediaQuery::page($type, $iPage, $perPage);
}

/**
 * The thumbnail and full URLs for a normalised media row (see
 * osc_media_library_query). Uses the storage-aware osc_get_resource_url so
 * offloaded files resolve correctly.
 *
 * @param array<string,mixed> $row
 *
 * @return array{thumb:string,full:string}
 */
function osc_media_row_urls(array $row): array
{
    $res = array(
        'pk_i_id'        => $row['id'] ?? '',
        's_base_name'    => $row['s_base_name'] ?? null,
        's_path'         => $row['s_path'] ?? '',
        's_extension'    => $row['s_extension'] ?? '',
        's_storage'      => $row['s_storage'] ?? 'local',
        's_content_type' => $row['s_content_type'] ?? '',
        's_owner_type'   => $row['owner_type'] ?? '',
        'i_owner_id'     => $row['owner_id'] ?? 0,
    );

    return array(
        'thumb' => osc_get_resource_url($res, 'thumbnail'),
        'full'  => osc_get_resource_url($res),
    );
}
