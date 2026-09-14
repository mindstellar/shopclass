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
use Params;
use Throwable;

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

        try {
            ImageProcessing::fromFile($avatar['tmp_name']);
        } catch (Throwable $e) {
            osc_add_flash_error_message(_m('The avatar you tried to upload is not a valid image'), $flashScope);

            return;
        }

        $dimensions = osc_get_preference('avatar_dimensions') ?: '200x200';

        $uploader = new ResourceUploader();
        $uploader->deleteByOwner(Resource::OWNER_USER, $userId);
        $uploader->upload(Resource::OWNER_USER, $userId, $avatar['tmp_name'], array(
            'variants' => array(
                'normal'    => $dimensions,
                'thumbnail' => '64x64',
            ),
        ));
    }
}
