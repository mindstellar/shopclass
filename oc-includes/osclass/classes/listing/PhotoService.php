<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listing;

use mindstellar\auth\Actor;
use mindstellar\storage\ResourceUploader;
use mindstellar\utility\DeferredMail;

/**
 * A listing's photos: checking and storing uploads, adding photos to a saved listing, and
 * deleting one, for the web pages, the admin and the API alike. Callers check the actor may
 * manage the listing (ListingPolicy::canManage()) first.
 */
final class PhotoService
{
    /** @var int[] the photos the last store() saved */
    private array $storedIds = array();

    /** @var array<int,array<string,mixed>> their rows */
    private array $storedRows = array();

    /** @var string[] what went wrong with a photo, for the form to show */
    private array $notices = array();

    /**
     * Whether every uploaded file's MIME type is in the allowed-extension list.
     *
     * @param array<string,array<int,mixed>> $aResources A $_FILES entry
     * @param string[]                       $notices    the reason is added here
     *
     * @return bool
     */
    public static function checkTypes($aResources, array &$notices = array()): bool
    {
        $success = true;
        if (!empty($aResources)) {
            foreach ($aResources['error'] as $key => $error) {
                if ($error !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (\mindstellar\storage\UploadMimes::tooManyPixels((string)$aResources['tmp_name'][$key])) {
                    $notices[] = \mindstellar\storage\UploadMimes::tooManyPixelsMessage();

                    return false;
                }
                if (!\mindstellar\storage\UploadMimes::isAllowedImage((string)$aResources['tmp_name'][$key])) {
                    $success = false;
                }
            }

            if (!$success) {
                $notices[] = _m('The file you tried to upload does not have a valid extension');
            }
        }

        return $success;
    }

    /**
     * Whether every uploaded file is within the configured maximum size.
     *
     * @param array<string,array<int,mixed>> $aResources A $_FILES entry
     * @param string[]                       $notices    the reason is added here
     *
     * @return bool
     */
    public static function checkSizes($aResources, array &$notices = array()): bool
    {
        $success = true;

        if (!empty($aResources)) {
            // get allowedExt
            $maxSize = osc_max_size_kb() * 1024;
            foreach ($aResources['error'] as $key => $error) {
                if ($error == UPLOAD_ERR_OK) {
                    $size = $aResources['size'][$key];
                    if ($size >= $maxSize) {
                        $success = false;
                    }
                }
            }
            if (!$success) {
                $notices[] = _m('One of the files you tried to upload exceeds the maximum size');
            }
        }

        return $success;
    }

    /**
     * Store the uploaded images for a listing, honouring the per-item image cap. Each one
     * fires `uploaded_file`; storedIds() and storedRows() name them afterwards.
     *
     * @param array<string,array<int,mixed>> $aResources A $_FILES entry
     * @param int                            $itemId
     *
     * @return int 0 when nothing went wrong
     */
    public function store($aResources, $itemId): int
    {
        $this->storedIds  = array();
        $this->storedRows = array();
        // A form with no file sent still posts the empty photos[] lists.
        if (!empty($aResources) && !empty($aResources['error'])) {
            $itemResourceManager = \ItemResource::getInstance();
            $folder              = osc_uploads_path() . floor($itemId / 100) . '/';

            // The cap is the global preference unless the item's own owner (not the
            // session -- an admin may be uploading on a seller's behalf) holds a
            // listing.photos entitlement. -1 (from the entitlement) and 0 (the
            // preference's own convention) both mean unlimited here.
            $itemOwner        = ListingStore::ownerId((int) $itemId);
            $maxImagesPerItem = osc_max_images_for_user($itemOwner > 0 ? $itemOwner : null);
            $totalItemImages  = $itemResourceManager->countResources($itemId);
            foreach ($aResources['error'] as $key => $error) {
                if (
                    $maxImagesPerItem == -1
                    || $maxImagesPerItem == 0
                    || ($maxImagesPerItem > 0 && $totalItemImages < $maxImagesPerItem)
                ) {
                    if ($error == UPLOAD_ERR_OK) {
                        $tmpName   = $aResources['tmp_name'][$key];
                        $imgres    = \ImageProcessing::fromFile($tmpName);
                        $extension = osc_apply_filter('upload_image_extension', $imgres->getExt());
                        $mime      = osc_apply_filter('upload_image_mime', $imgres->getMime());

                        // Create normal size
                        $path        = $tmpName . '_normal';
                        $normal_path = $path;
                        $size        = explode('x', osc_normal_dimensions());
                        $img         = $imgres->autoRotate();

                        $img = $img->resizeTo((int) $size[0], (int) $size[1]);
                        if (osc_is_watermark_text()) {
                            $img->doWatermarkText(osc_watermark_text(), osc_watermark_text_color());
                        } elseif (osc_is_watermark_image()) {
                            $img->doWatermarkImage();
                        }
                        $img->saveToFile($path, $extension);
                        // Create preview
                        $path = $tmpName . '_preview';
                        $size = explode('x', osc_preview_dimensions());
                        \ImageProcessing::fromFile($normal_path)->resizeTo((int) $size[0], (int) $size[1])
                            ->saveToFile($path, $extension);

                        // Create thumbnail
                        $path = $tmpName . '_thumbnail';
                        $size = explode('x', osc_thumbnail_dimensions());
                        \ImageProcessing::fromFile($normal_path)->resizeTo((int) $size[0], (int) $size[1])
                            ->saveToFile($path, $extension);

                        $totalItemImages++;

                        $resourceId = $itemResourceManager->insertGetId(array(
                            'fk_i_item_id' => $itemId
                        ));

                        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
                            return 3; // PATH CAN NOT BE CREATED
                        }
                        $copies = array(
                            $tmpName . '_normal'    => $folder . $resourceId . '.' . $extension,
                            $tmpName . '_preview'   => $folder . $resourceId . '_preview.' . $extension,
                            $tmpName . '_thumbnail' => $folder . $resourceId . '_thumbnail.' . $extension,
                        );
                        $copied = true;
                        foreach ($copies as $from => $to) {
                            $copied = $copied && osc_copy($from, $to);
                        }
                        // A photo row without its files shows as a broken image, so undo it.
                        if (!$copied) {
                            foreach ($copies as $from => $to) {
                                @unlink($from);
                                @unlink($to);
                            }
                            @unlink($tmpName);
                            $itemResourceManager->deleteResourcesIds(array($resourceId));
                            $totalItemImages--;
                            $this->notices[] = _m('A photo could not be saved. Check that the uploads folder can be written to.');
                            continue;
                        }
                        if (osc_keep_original_image()) {
                            $path = $folder . $resourceId . '_original.' . $extension;
                            ResourceUploader::saveOriginal($tmpName, $path, $extension);
                        }
                        unlink($tmpName . '_normal');
                        unlink($tmpName . '_preview');
                        unlink($tmpName . '_thumbnail');
                        unlink($tmpName);

                        $s_path = str_replace(osc_base_path(), '', $folder);
                        $itemResourceManager->update(
                            array(
                                's_path'         => $s_path,
                                's_name'         => osc_genRandomPassword(),
                                's_extension'    => $extension,
                                's_content_type' => $mime
                            ),
                            array(
                                'pk_i_id'      => $resourceId,
                                'fk_i_item_id' => $itemId
                            )
                        );
                        $this->storedIds[] = (int) $resourceId;
                        $stored             = \ItemResource::getInstance()->findByPrimaryKey($resourceId);
                        if (is_array($stored)) {
                            $this->storedRows[] = $stored;
                        }
                        osc_run_hook('uploaded_file', $stored);
                    }
                }
            }
            unset($itemResourceManager);
        }

        return 0; // NO PROBLEMS
    }

    /**
     * How many photos a listing of this owner may hold: the site's cap, or the owner's plan's.
     *
     * @param int|null $ownerId null for a guest listing
     *
     * @return int|null null when there is no cap
     */
    public static function cap(?int $ownerId): ?int
    {
        $max = (int) osc_max_images_for_user($ownerId !== null && $ownerId > 0 ? $ownerId : null);

        return $max > 0 ? $max : null;
    }

    /**
     * How many more photos a saved listing may take.
     *
     * @param int|null $ownerId the listing's owner; null for a guest listing
     *
     * @return int|null null when there is no cap
     */
    public static function room(int $itemId, ?int $ownerId): ?int
    {
        $cap = self::cap($ownerId);

        return $cap === null ? null : max(0, $cap - self::count($itemId));
    }

    /**
     * How many photos a listing holds.
     */
    public static function count(int $itemId): int
    {
        return (int) \ItemResource::getInstance()->countResources($itemId);
    }

    /**
     * @return array<string,mixed>|false the photo's t_item_resource row
     */
    public static function find(int $photoId): array|false
    {
        return \ItemResource::getInstance()->findByPrimaryKey($photoId);
    }

    /**
     * The photo (t_item_resource) ids the last store() saved, in order.
     *
     * @return int[]
     */
    public function storedIds(): array
    {
        return $this->storedIds;
    }

    /**
     * The rows of the photos the last store() saved.
     *
     * @return array<int,array<string,mixed>>
     */
    public function storedRows(): array
    {
        return $this->storedRows;
    }

    /**
     * What went wrong with a photo since this service was made, for the form to show.
     *
     * @return string[]
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * Add photos to a saved listing, in one transaction. It is an edit: a site that checks
     * edits checks this one too, and `edited_item` fires once photos were stored.
     *
     * @param array<string,array<int,mixed>> $files a $_FILES entry
     *
     * @return int[] the photos stored; none when the listing has as many as it may hold
     */
    public function add(int $itemId, array $files, Actor $actor): array
    {
        return self::cleanUpOnFailure($this, function () use ($itemId, $files, $actor): array {
            return DeferredMail::transaction(function () use ($itemId, $files, $actor): array {
                $this->store($files, $itemId);
                if ($this->storedIds === array()) {
                    return array();
                }
                if (!$actor->isAdmin() && osc_moderate_admin_edit()) {
                    (new ListingService())->disable($itemId);
                }
                osc_run_hook('edited_item', \Item::getInstance()->findByPrimaryKey($itemId));

                return $this->storedIds;
            });
        });
    }

    /**
     * Delete one photo: its row, then its files once that commits. With $itemId, only a photo
     * of that listing; with $code, only when the code matches the photo's own.
     *
     * @return bool false when there is no such photo, or its row could not be deleted
     */
    public function delete(int $photoId, ?int $itemId, Actor $actor, ?string $code = null): bool
    {
        $resource = $photoId > 0 ? \ItemResource::getInstance()->findByPrimaryKey($photoId) : null;
        if (!is_array($resource) || !isset($resource['pk_i_id'])
            || ($itemId !== null && (int) ($resource['fk_i_item_id'] ?? 0) !== $itemId)
            || ($code !== null && ($code === '' || !hash_equals((string) ($resource['s_name'] ?? ''), $code)))
        ) {
            return false;
        }

        // One transaction, so the files go only when the row is gone too.
        try {
            return DeferredMail::transaction(function () use ($resource, $photoId, $actor): bool {
                $deleted = PhotoStore::delete($photoId, (int) $resource['fk_i_item_id']);
                if ($deleted === 0) {
                    throw new \RuntimeException('The photo row was not deleted.');
                }
                $this->removeFiles($resource, $actor);
                \Log::getInstance()->insertLog('item', 'deleteResource', $photoId, (string) $photoId, $actor->logRole(), $actor->logId());

                return true;
            });
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Remove a photo's files, here or on remote storage, and fire `delete_resource`. The row
     * is the caller's to delete.
     *
     * @param array<string,mixed> $resource the t_item_resource row
     *
     * @return bool false on a demo install, where nothing is deleted
     */
    public function removeFiles(array $resource, Actor $actor): bool
    {
        if (defined('DEMO')) {
            return false;
        }
        \Log::getInstance()->insertLog('item', 'delete resource', $resource['pk_i_id'], $resource['pk_i_id'], $actor->logRole(), $actor->logId());

        $backtrace = '';
        foreach (debug_backtrace() as $k => $v) {
            if (in_array($v['function'], array('include', 'include_once', 'require', 'require_once'), true)) {
                $backtrace .= '#' . $k . ' ' . $v['function'] . '(' . $v['args'][0] . ') called@ [' . $v['file'] . ':' . $v['line'] . '] / ';
            } else {
                $backtrace .= '#' . $k . ' ' . $v['function'] . ' called@ [' . ($v['file'] ?? '') . ':' . ($v['line'] ?? '') . '] / ';
            }
        }
        \Log::getInstance()->insertLog('item', 'delete resource backtrace', $resource['pk_i_id'], $backtrace, $actor->logRole(), $actor->logId());

        // A file cannot come back, so it goes only once the delete has committed.
        \mindstellar\database\Db::afterCommit(static function () use ($resource): void {
            if (($resource['s_storage'] ?? 'local') === 'local'
                && \mindstellar\storage\StorageManager::getInstance()->remote() === null) {
                try {
                    foreach (\mindstellar\storage\ResourceLocator::VARIANTS as $variant) {
                        $file = $resource['s_path'] . $resource['pk_i_id'] . $variant . '.' . $resource['s_extension'];
                        if (file_exists($file) && !is_dir($file)) {
                            (new \mindstellar\utility\FileSystem())->remove($file);
                        }
                    }
                } catch (\Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }
            } else {
                \mindstellar\storage\StorageJobs::enqueue('delete', $resource['s_storage'] ?? 'local', $resource);
            }
            osc_run_hook('delete_resource', $resource);
        });

        return true;
    }

    /**
     * Remove the files of photos a save stored before it was rolled back. Their rows went
     * with the rollback, so nothing else would.
     *
     * @param array<int,array<string,mixed>> $resources t_item_resource rows, as `uploaded_file` passed them
     */
    public static function discardStored(array $resources): void
    {
        foreach ($resources as $resource) {
            $base = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'];
            foreach (\mindstellar\storage\ResourceLocator::VARIANTS as $variant) {
                @unlink($base . $variant . '.' . $resource['s_extension']);
            }
            osc_run_hook('delete_resource', $resource);
        }
    }

    /**
     * Run a save that stores photos through $photos; when it throws, remove the files of the
     * photos it had stored, as the rollback took their rows.
     *
     * @param callable(): mixed $save
     *
     * @return mixed what $save returns
     */
    public static function cleanUpOnFailure(self $photos, callable $save): mixed
    {
        try {
            return $save();
        } catch (\Throwable $e) {
            self::discardStored($photos->storedRows);

            throw $e;
        }
    }

    /**
     * Delete resources from the hard drive
     *
     * @param int                                 $itemId
     * @param bool                                $is_admin
     * @param array<int,array<string,mixed>>|null $resources Rows the caller read before the
     *                                                       delete; looked up when null
     *
     * @return void
     */
    public static function deleteFilesFromDisk($itemId, $is_admin = false, $resources = null)
    {
        // $resources lets the caller supply rows it read earlier. The item delete reads
        // them before its transaction and calls this after the commit, when the rows are
        // gone and a fresh lookup would find nothing to unlink.
        if (!is_array($resources)) {
            $resources = \ItemResource::getInstance()->getAllResourcesFromItem($itemId);
        }
        \Log::getInstance()
            ->insertLog(
                'itemActions',
                'deleteResourcesFromHD',
                $itemId,
                (string) $itemId,
                $is_admin ? 'admin' : 'user',
                $is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );
        $log_ids = '';
        foreach ($resources as $resource) {
            osc_deleteResource($resource['pk_i_id'], $is_admin, $resource);
            $log_ids .= $resource['pk_i_id'] . ',';
        }
        \Log::getInstance()->insertLog(
            'itemActions',
            'deleteResourcesFromHD',
            $itemId,
            substr($log_ids, 0, 250),
            $is_admin ? 'admin' : 'user',
            $is_admin ? osc_logged_admin_id() : osc_logged_user_id()
        );
    }

    /**
     * Regenerate the normal/preview/thumbnail variants of a single resource
     * from its best available local source (preferring the original, then
     * the current normal, then the preview). No-op when none of those exist
     * locally or the resource isn't an image.
     *
     * Used by the "Regenerate images" admin action, run inline for every
     * resource on local installs and via the storage queue 'regenerate' job,
     * one resource at a time, on installs backed by a remote adapter.
     *
     * @param array $resource
     *
     * @return void
     */
    public static function regenerateImages(array $resource): void
    {
        osc_run_hook('regenerate_image', $resource);
        if (strpos($resource['s_content_type'], 'image') === false) {
            return;
        }

        if (file_exists(osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_original.'
            . $resource['s_extension'])
        ) {
            $image_tmp    = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_original.'
                . $resource['s_extension'];
            $use_original = true;
        } elseif (file_exists(osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '.'
            . $resource['s_extension'])
        ) {
            $image_tmp    = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '.'
                . $resource['s_extension'];
            $use_original = false;
        } elseif (file_exists(osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_preview.'
            . $resource['s_extension'])
        ) {
            $image_tmp    = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_preview.'
                . $resource['s_extension'];
            $use_original = false;
        } else {
            return;
        }

        // Create normal size
        $path        = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '.'
            . $resource['s_extension'];
        $path_normal = $path;
        $size        = explode('x', osc_normal_dimensions());
        $img         = \ImageProcessing::fromFile($image_tmp)->resizeTo((int) $size[0], (int) $size[1]);
        if ($use_original) {
            if (osc_is_watermark_text()) {
                $img->doWatermarkText(osc_watermark_text(), osc_watermark_text_color());
            } elseif (osc_is_watermark_image()) {
                $img->doWatermarkImage();
            }
        }
        $img->saveToFile($path);

        // Create preview
        $path = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_preview.'
            . $resource['s_extension'];
        $size = explode('x', osc_preview_dimensions());
        \ImageProcessing::fromFile($path_normal)->resizeTo((int) $size[0], (int) $size[1])->saveToFile($path);

        // Create thumbnail
        $path = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_thumbnail.'
            . $resource['s_extension'];
        $size = explode('x', osc_thumbnail_dimensions());
        \ImageProcessing::fromFile($path_normal)->resizeTo((int) $size[0], (int) $size[1])->saveToFile($path);

        osc_run_hook(
            'regenerated_image',
            \ItemResource::getInstance()->findByPrimaryKey($resource['pk_i_id'])
        );
    }
}
