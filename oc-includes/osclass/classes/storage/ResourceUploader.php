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

use ImageProcessing;
use mindstellar\model\Resource;
use mindstellar\utility\FileSystem;
use StorageQueue;
use Throwable;

/**
 * Class ResourceUploader
 *
 * Owner-agnostic upload/delete pipeline for the polymorphic t_resource table.
 * It is the generalised form of the item-only pipeline in
 * ItemActions::uploadItemResources(): validate an image, write the base image
 * plus its variants, record a t_resource row, and fire the storage hooks so the
 * offload queue picks the row up. The item path is intentionally left untouched;
 * new owner types (users, pages, plugin-defined types) go through here.
 *
 * Files live under a per-owner-type directory so the uploads root stays tidy:
 *   oc-content/uploads/{ownerType}/{floor(ownerId/100)}/{pk_i_id}[_variant].{ext}
 * The owner type is slug-validated (Resource::isValidOwnerType) before it is ever
 * used to build a path, so no user-controlled segment reaches the filesystem.
 *
 * @package mindstellar\storage
 */
final class ResourceUploader
{
    /**
     * Validate an image, write its variants and record a t_resource row.
     *
     * $options keys (all optional):
     *   - 'variants'      array  logical-name => 'WxH'. 'normal' is the base file
     *                            ({id}.{ext}); every other name becomes {id}_{name}.
     *                            Defaults to normal/preview/thumbnail at the
     *                            configured item dimensions.
     *   - 'keep_original' bool   also store the untouched source as {id}_original.
     *   - 'watermark'     bool   apply the configured watermark to the base image.
     *
     * @param string $ownerType
     * @param int    $ownerId
     * @param string $tmpFile   absolute path to the uploaded temp file
     * @param array  $options
     *
     * @return array|false the inserted resource row, or false on any failure
     */
    public function upload(string $ownerType, int $ownerId, string $tmpFile, array $options = array()): array|false
    {
        if (!Resource::isValidOwnerType($ownerType) || !is_file($tmpFile)) {
            return false;
        }

        try {
            $imgres = ImageProcessing::fromFile($tmpFile);
        } catch (Throwable $e) {
            return false;
        }

        $extension = osc_apply_filter('upload_image_extension', $imgres->getExt());
        $mime      = osc_apply_filter('upload_image_mime', $imgres->getMime());

        $variants = $options['variants'] ?? array(
            'normal'    => osc_normal_dimensions(),
            'preview'   => osc_preview_dimensions(),
            'thumbnail' => osc_thumbnail_dimensions(),
        );
        $keepOriginal = (bool) ($options['keep_original'] ?? false);
        $watermark    = (bool) ($options['watermark'] ?? false);

        $normalTmp = $tmpFile . '_normal';
        $secondary = array(); // suffix ('_preview', ...) => temp path

        try {
            $baseDims = (string) ($variants['normal'] ?? reset($variants));
            $size     = explode('x', $baseDims);
            $img      = $imgres->autoRotate()->resizeTo((int) ($size[0] ?? 0), (int) ($size[1] ?? 0));
            if ($watermark) {
                if (osc_is_watermark_text()) {
                    $img->doWatermarkText(osc_watermark_text(), osc_watermark_text_color());
                } elseif (osc_is_watermark_image()) {
                    $img->doWatermarkImage();
                }
            }
            $img->saveToFile($normalTmp, $extension);

            foreach ($variants as $name => $dims) {
                if ($name === 'normal') {
                    continue;
                }
                $suffix = '_' . $name;
                $vtmp   = $tmpFile . $suffix;
                $s      = explode('x', (string) $dims);
                ImageProcessing::fromFile($normalTmp)
                    ->resizeTo((int) ($s[0] ?? 0), (int) ($s[1] ?? 0))
                    ->saveToFile($vtmp, $extension);
                $secondary[$suffix] = $vtmp;
            }
        } catch (Throwable $e) {
            $this->cleanupTemps($tmpFile, $normalTmp, $secondary);

            return false;
        }

        $folder = osc_uploads_path() . $ownerType . '/' . floor($ownerId / 100) . '/';
        $sPath  = str_replace(osc_base_path(), '', $folder);
        $sName  = osc_genRandomPassword();
        $now    = date('Y-m-d H:i:s');

        $id = Resource::newInstance()->insertResource($ownerType, $ownerId, array(
            's_path'         => $sPath,
            's_name'         => $sName,
            's_extension'    => $extension,
            's_content_type' => $mime,
            's_storage'      => 'local',
            'dt_created'     => $now,
        ));

        if ($id === false) {
            $this->cleanupTemps($tmpFile, $normalTmp, $secondary);

            return false;
        }

        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
            Resource::newInstance()->deleteResourcesIds(array($id));
            $this->cleanupTemps($tmpFile, $normalTmp, $secondary);

            return false;
        }

        osc_copy($normalTmp, $folder . $id . '.' . $extension);
        foreach ($secondary as $suffix => $vtmp) {
            osc_copy($vtmp, $folder . $id . $suffix . '.' . $extension);
        }
        if ($keepOriginal) {
            osc_copy($tmpFile, $folder . $id . '_original.' . $extension);
        }

        $this->cleanupTemps($tmpFile, $normalTmp, $secondary);

        $row = array(
            'pk_i_id'        => $id,
            's_owner_type'   => $ownerType,
            'i_owner_id'     => $ownerId,
            's_name'         => $sName,
            's_extension'    => $extension,
            's_content_type' => $mime,
            's_path'         => $sPath,
            's_storage'      => 'local',
            'dt_created'     => $now,
            'dt_updated'     => null,
        );

        osc_run_hook('uploaded_resource', $row);

        return $row;
    }

    /**
     * Delete a single resource: its files (all variants, local and/or remote via
     * the storage adapter), its row, and its cached owner list. Mirrors the item
     * delete path (osc_deleteResource): remove local files when nothing has been
     * offloaded, otherwise queue a remote delete job.
     *
     * @param array $resourceRow
     *
     * @return void
     */
    public function delete(array $resourceRow): void
    {
        if (empty($resourceRow['pk_i_id'])) {
            return;
        }

        $this->purgeFiles($resourceRow);

        Resource::newInstance()->deleteResourcesIds(array((int) $resourceRow['pk_i_id']));
        Resource::newInstance()->invalidateOwnerCache(
            (string) ($resourceRow['s_owner_type'] ?? ''),
            (int) ($resourceRow['i_owner_id'] ?? 0)
        );

        osc_run_hook('delete_resource', $resourceRow);
    }

    /**
     * Delete every resource owned by (ownerType, ownerId): files + rows + queue
     * jobs + cache. This is the app-level cascade a delete hook fires when an
     * owner record (a user, a page, ...) is removed.
     *
     * @param string $ownerType
     * @param int    $ownerId
     *
     * @return void
     */
    public function deleteByOwner(string $ownerType, int $ownerId): void
    {
        if (!Resource::isValidOwnerType($ownerType)) {
            return;
        }

        $rows = Resource::newInstance()->findByOwner($ownerType, $ownerId);
        foreach ($rows as $row) {
            $this->purgeFiles($row);
            osc_run_hook('delete_resource', $row);
        }

        // Single row+cache delete for the whole owner, after files are handled.
        Resource::newInstance()->deleteByOwner($ownerType, $ownerId);
    }

    /**
     * Remove a resource's files, or queue their removal when the row lives on (or
     * an install has configured) a remote adapter. Never touches the database.
     *
     * @param array $row
     *
     * @return void
     */
    private function purgeFiles(array $row): void
    {
        if (empty($row['pk_i_id'])) {
            return;
        }

        $storage = $row['s_storage'] ?? 'local';

        if ($storage === 'local' && StorageManager::instance()->remote() === null) {
            foreach (ResourceLocator::variants() as $variant) {
                $path = ResourceLocator::localPath($row, $variant);
                if (file_exists($path) && !is_dir($path)) {
                    (new FileSystem())->remove($path);
                }
            }

            return;
        }

        StorageQueue::newInstance()->enqueue('delete', $storage, $row);
    }

    /**
     * Best-effort removal of the working temp files produced during an upload.
     *
     * @param string $tmpFile
     * @param string $normalTmp
     * @param array  $secondary
     *
     * @return void
     */
    private function cleanupTemps(string $tmpFile, string $normalTmp, array $secondary): void
    {
        foreach (array_merge(array($tmpFile, $normalTmp), array_values($secondary)) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
