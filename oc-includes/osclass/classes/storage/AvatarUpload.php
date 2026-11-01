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

use mindstellar\image\Processor;
use mindstellar\model\Resource;
use Params;

/**
 * Class AvatarUpload
 *
 * Avatar upload / removal for a user, shared by the front-end profile and the
 * admin user editor.
 *
 * @package mindstellar\storage
 */
final class AvatarUpload
{
    /**
     * Handle a posted avatar file or remove_avatar for a user.
     *
     * Replace semantics: one avatar per user, so any previous avatar is removed
     * before a new one is stored. A posted remove_avatar just clears it, even when
     * the feature is disabled. The file is validated as a real image and size-capped
     * before it is accepted. No-op when the feature is disabled or no file was sent.
     *
     * @param int    $userId     avatar owner
     * @param string $flashScope flash message section for errors ('pubMessages' or 'admin')
     *
     * @return void
     */
    public static function handle(int $userId, string $flashScope = 'pubMessages'): void
    {
        if ($userId <= 0) {
            return;
        }

        if (Params::getParam('remove_avatar') != '') {
            (new ResourceUploader())->deleteByOwner(Resource::OWNER_USER, $userId);

            return;
        }

        if (!osc_get_preference('enabled_user_avatars')) {
            return;
        }

        $avatar = Params::getFiles('avatar');
        if (empty($avatar) || !isset($avatar['error']) || $avatar['error'] != UPLOAD_ERR_OK) {
            return;
        }
        if (!isset($avatar['tmp_name']) || !is_uploaded_file($avatar['tmp_name'])) {
            return;
        }

        $maxSize = osc_max_size_kb() * 1024;
        if (isset($avatar['size']) && $avatar['size'] > $maxSize) {
            osc_add_flash_error_message(_m('The avatar you tried to upload exceeds the maximum size'), $flashScope);

            return;
        }

        if (!self::isImage($avatar['tmp_name'])) {
            osc_add_flash_error_message(_m('The avatar you tried to upload is not a valid image'), $flashScope);

            return;
        }

        if (!self::replace($userId, $avatar['tmp_name'])) {
            osc_add_flash_error_message(_m('The avatar could not be saved. Please try again.'), $flashScope);
        }
    }

    /**
     * Save an image file already on disk, such as a downloaded social profile
     * picture, as the user's avatar. Makes the same sizes as an upload and
     * replaces the old avatar only once the new one is stored. $path is read, never changed.
     *
     * @param int    $userId avatar owner
     * @param string $path   image file on disk
     *
     * @return bool true when the avatar was stored; false when avatars are off, or the file is
     *              too large, not an image or could not be stored
     */
    public static function store(int $userId, string $path): bool
    {
        if ($userId <= 0 || !osc_get_preference('enabled_user_avatars') || !is_file($path)
            || (int) filesize($path) > osc_max_size_kb() * 1024 || !self::isImage($path)
        ) {
            return false;
        }
        // The uploader consumes its file, so it gets a copy in the temp folder.
        $copy = osc_uploads_path() . 'temp/avatar_' . bin2hex(random_bytes(8));
        if (!@copy($path, $copy)) {
            return false;
        }
        $stored = self::replace($userId, $copy);
        if (is_file($copy)) {
            @unlink($copy);
        }

        return $stored;
    }

    /**
     * Whether the file opens as a real image.
     *
     * @param string $path
     *
     * @return bool
     */
    private static function isImage(string $path): bool
    {
        return Processor::site()->opens($path);
    }

    /**
     * Store $path in the normal and thumbnail sizes, then drop the old avatar.
     *
     * @param int    $userId
     * @param string $path
     *
     * @return bool false, with the old avatar kept, when the new one could not be stored
     */
    private static function replace(int $userId, string $path): bool
    {
        $dimensions = osc_get_preference('avatar_dimensions') ?: '200x200';

        return (new ResourceUploader())->replaceOwned(Resource::OWNER_USER, $userId, $path, array(
            'variants' => array(
                'normal'    => $dimensions,
                'thumbnail' => '64x64',
            ),
        )) !== false;
    }
}
