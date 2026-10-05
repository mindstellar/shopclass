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

namespace mindstellar\api\write;

use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\storage\UploadMimes;

/**
 * One uploaded photo, copied into the temp folder and checked as the listing form checks
 * its photos: a type the site accepts, an image that decodes, within the pixel limit and
 * within the site's file size.
 */
final class PhotoFile
{
    /** Image type => the extension the temp file gets. */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private function __construct()
    {
    }

    /**
     * The photo a request carries, in multipart field `photo` or as a raw image body.
     *
     * @param int $maxBytes the site's largest photo
     *
     * @throws ProblemException 413, 415 or 422 when there is no usable photo
     */
    public static function fromRequest(Request $request, PhotoStage $stage, int $maxBytes): CheckedPhoto
    {
        $upload = $request->file('photo');
        if ($upload !== null) {
            if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw self::tooLarge($maxBytes);
            }
            if ($upload['error'] !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'])) {
                throw ProblemException::field('/photo', 'invalid', 'did not arrive whole; send it again');
            }
            $path = $stage->tempPath('upload');
            if (!@copy($upload['tmp_name'], $path)) {
                throw ProblemException::of('server_error', 'The photo could not be stored.');
            }
        } elseif (str_starts_with($request->contentType(), 'image/')) {
            $body = $request->body();
            if ($body === null) {
                throw self::tooLarge(min($maxBytes, Request::MAX_UPLOAD));
            }
            if ($body === '') {
                throw ProblemException::field('/photo', 'invalid', 'is empty');
            }
            $path = $stage->tempPath('upload');
            if (@file_put_contents($path, $body) === false) {
                throw ProblemException::of('server_error', 'The photo could not be stored.');
            }
        } else {
            throw ProblemException::of('unsupported_media_type', 'Send the photo as multipart/form-data in a field named photo, or as the image itself.');
        }

        try {
            return self::checked($path, $maxBytes);
        } catch (ProblemException $e) {
            @unlink($path);

            throw $e;
        }
    }

    /**
     * A file the site would accept as a photo, renamed to its real extension.
     *
     * @throws ProblemException 413 or 422
     */
    public static function checked(string $path, int $maxBytes, string $pointer = '/photo'): CheckedPhoto
    {
        if ($maxBytes > 0 && (int) @filesize($path) > $maxBytes) {
            throw self::tooLarge($maxBytes);
        }
        if (UploadMimes::tooManyPixels($path)) {
            throw ProblemException::field($pointer, 'invalid', UploadMimes::tooManyPixelsMessage());
        }
        if (!UploadMimes::isAllowedImage($path)) {
            throw ProblemException::field($pointer, 'invalid', 'is not an image this site accepts');
        }
        $extension = self::EXTENSIONS[UploadMimes::detect($path)] ?? 'jpg';
        $final     = (string) preg_replace('/\.[A-Za-z0-9]+$/D', '', $path) . '.' . $extension;
        if ($final !== $path && !@rename($path, $final)) {
            throw ProblemException::of('server_error', 'The photo could not be stored.');
        }

        return new CheckedPhoto($final, $extension);
    }

    private static function tooLarge(int $maxBytes): ProblemException
    {
        return ProblemException::of('too_large', 'A photo may be at most ' . max(1, intdiv($maxBytes, 1024)) . ' KB.');
    }
}
