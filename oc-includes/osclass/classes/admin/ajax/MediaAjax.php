<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\admin\ajax;

use mindstellar\model\Resource;
use mindstellar\storage\ResourceUploader;
use mindstellar\utility\AjaxResponse;
use Params;

/**
 * The editor's media picker and image upload.
 */
final class MediaAjax extends AjaxHandler
{
    /** JSON media for the editor's media picker (read-only). */
    public function mediaList(): void
    {
        $type = Params::getParam('type');
        if ($type === '' || $type === null) {
            $type = 'all';
        }
        if (!in_array($type, array_merge(array('all', 'item'), osc_media_owner_types()), true)) {
            $type = 'all';
        }
        $iPage   = max(1, Params::getParamInt('iPage'));
        $perPage = 30;
        $data    = osc_media_library_query($type, $iPage, $perPage);

        $items = array();
        foreach ($data['rows'] as $row) {
            $urls    = osc_media_row_urls($row);
            $items[] = array(
                'id'         => (int) $row['id'],
                'src'        => $row['src'],
                'owner_type' => $row['owner_type'],
                'owner_id'   => (int) $row['owner_id'],
                'name'       => (string) $row['s_name'],
                'thumb'      => $urls['thumb'],
                'url'        => $urls['full'],
            );
        }
        AjaxResponse::json(array(
            'items'   => $items,
            'total'   => (int) $data['total'],
            'page'    => $iPage,
            'perPage' => $perPage,
            'more'    => ($iPage * $perPage) < (int) $data['total'],
        ));
    }

    /** Editor image upload into the resource pipeline. */
    public function resourceUpload(): void
    {
        $ownerType = Params::getParam('owner_type');
        $ownerId   = Params::getParamInt('owner_id');

        // Two targets only: a page image (the page must exist) and an ownerless library
        // upload, so the endpoint cannot write for arbitrary owners.
        if ($ownerType === Resource::OWNER_LIBRARY) {
            $ownerId = 0;
        } elseif ($ownerType !== Resource::OWNER_PAGE
            || !osc_resource_owner_exists($ownerType, $ownerId)
        ) {
            http_response_code(403);
            AjaxResponse::json(array('error' => __('Upload target not allowed')));

            return;
        }

        if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            http_response_code(400);
            AjaxResponse::json(array('error' => __('No file was received')));

            return;
        }

        // The uploader checks the file is a real image and rewrites it into managed
        // variants, so nothing untrusted is stored as-is.
        $row = (new ResourceUploader())
            ->upload($ownerType, $ownerId, $_FILES['file']['tmp_name']);

        if ($row === false) {
            http_response_code(415);
            AjaxResponse::json(array('error' => __('The file is not a valid image')));

            return;
        }

        // TinyMCE expects { location: url }.
        AjaxResponse::json(array('location' => osc_get_resource_url($row)));
    }
}
