<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\model\Resource;
use mindstellar\storage\ResourceLocator;
use mindstellar\storage\StorageManager;

/**
 * Streams a resource with a human-friendly download filename.
 *
 * Inline display keeps using the direct static/CDN URL (osc_resource_url); this
 * controller exists only for explicit downloads, where a Content-Disposition
 * turns the id-based on-disk name (4831.jpg) into something readable
 * (red-toyota-corolla-4831.jpg). It resolves the resource from either the legacy
 * item table or the polymorphic t_resource, so it serves listing images, user
 * avatars, page images and ownerless resources alike.
 */
class CWebResource extends BaseModel
{
    public function doModel()
    {
        if (Params::getParam('action') === 'download') {
            $this->download();

            return;
        }

        $this->notFound();
    }

    private function download()
    {
        $id      = Params::getParamInt('id');
        $type    = trim((string) Params::getParam('type'));
        $variant = (string) Params::getParam('variant');

        // The variant becomes part of a filesystem path / storage key, so it is
        // only ever one of the known suffixes.
        if (!in_array($variant, ResourceLocator::variants(), true)) {
            $variant = '';
        }

        $resource = $this->resolveResource($id, $type);
        if ($resource === null) {
            $this->notFound();

            return;
        }

        $filename = osc_resource_download_filename($resource, $variant);

        // s_content_type is stored data; only emit it when it is a well-formed
        // MIME token so it can never inject a header.
        $contentType = (string) ($resource['s_content_type'] ?? '');
        if (!preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $contentType)) {
            $contentType = 'application/octet-stream';
        }

        // 1) A local copy on disk is streamed straight through (local storage, or a
        //    keep-local remote install). readfile() avoids buffering the whole file.
        $localPath = ResourceLocator::localPath($resource, $variant);
        if (is_file($localPath)) {
            $size = @filesize($localPath);
            $this->sendHeaders($contentType, $size === false ? null : $size, $filename);
            readfile($localPath);
            exit;
        }

        // 2) Otherwise the object lives on a remote adapter.
        osc_storage_register_remote();
        $adapter = StorageManager::instance()->forResource($resource);
        if (!$adapter->isRemote()) {
            $this->notFound();

            return;
        }

        // A private (signed-URL) bucket must never be proxied with the server's
        // credentials — that would hand out unauthenticated, non-expiring access and
        // defeat the signed-URL control the admin turned on. Redirect to a
        // short-lived signed URL instead (the friendly name does not apply there).
        if (!$adapter->isPublic()) {
            $signed = method_exists($adapter, 'presignedUrl')
                ? (string) $adapter->presignedUrl(ResourceLocator::storageKey($resource, $variant))
                : '';
            if ($signed === '') {
                $this->notFound();

                return;
            }
            header('Location: ' . $signed, true, 302);
            exit;
        }

        // A public bucket/CDN already serves the object openly, so proxying it to
        // apply the friendly download name adds no exposure beyond the direct URL.
        $bytes = $adapter->get(ResourceLocator::storageKey($resource, $variant));
        if ($bytes === false) {
            $this->notFound();

            return;
        }
        $this->sendHeaders($contentType, strlen($bytes), $filename);
        echo $bytes;
        exit;
    }

    /**
     * Emit the shared download response headers. $length may be null when unknown.
     * The filename is a strict slug + id + extension (ASCII, no quotes/CR/LF), so the
     * quoted form is injection-safe; filename* carries the same value for RFC 6266.
     *
     * @param string   $contentType
     * @param int|null $length
     * @param string   $filename
     */
    private function sendHeaders($contentType, $length, $filename)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');
        if ($length !== null) {
            header('Content-Length: ' . $length);
        }
        header(
            'Content-Disposition: attachment; filename="' . $filename . '"; '
            . "filename*=UTF-8''" . rawurlencode($filename)
        );
        header('Cache-Control: private, max-age=0, must-revalidate');
    }

    /**
     * Resolve the row from the right table. An empty or 'item' type is the legacy
     * t_item_resource; any other value is a t_resource owner type and must match
     * the stored row, so an id cannot be reinterpreted across owner types.
     *
     * @param int    $id
     * @param string $type
     *
     * @return array|null
     */
    private function resolveResource($id, $type)
    {
        if ($id <= 0) {
            return null;
        }

        try {
            if ($type === '' || $type === 'item') {
                $row = ItemResource::newInstance()->findByPrimaryKey($id);

                return (is_array($row) && !empty($row['pk_i_id'])) ? $row : null;
            }

            if (!Resource::isValidOwnerType($type)) {
                return null;
            }

            $row = Resource::newInstance()->findByPrimaryKey($id);
            if (!is_array($row) || empty($row['pk_i_id'])) {
                return null;
            }
            // The URL's declared owner type must match the stored row, so an id
            // cannot be reinterpreted across owner types.
            if ((string) ($row['s_owner_type'] ?? '') !== $type) {
                return null;
            }

            return $row;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function notFound()
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Resource not found';
        exit;
    }

    public function doView($file)
    {
        // Never used: download() streams the file and exits.
    }
}

/* file end: ./oc-includes/osclass/classes/controller/CWebResource.php */
