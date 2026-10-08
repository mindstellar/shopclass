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
 * A photo file in the temp folder that passed the listing form's checks: an accepted type, an image
 * that decodes, within the pixel and file size limits. A staged photo also has a token and an
 * expiry.
 */
final class PhotoFile
{
    /** Image type => the extension the temp file gets. */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * @param string $extension of its real type: jpg, png, gif or webp
     * @param string $token     a staged photo's token; '' for one not staged
     * @param int    $expiresAt Unix time a staged photo's token stops working; 0 for one not staged
     */
    public function __construct(private string $path, private string $extension, private string $token = '', private int $expiresAt = 0)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /**
     * Delete the file.
     */
    public function discard(): void
    {
        @unlink($this->path);
    }

    /**
     * The photo a request carries, in multipart field `photo` or as a raw image body.
     *
     * @param int $maxBytes the site's largest photo
     *
     * @throws ProblemException 413, 415 or 422 when there is no usable photo
     */
    public static function fromRequest(Request $request, PhotoStage $stage, int $maxBytes): self
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
    public static function checked(string $path, int $maxBytes, string $pointer = '/photo'): self
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

        return new self($final, $extension);
    }

    private static function tooLarge(int $maxBytes): ProblemException
    {
        return ProblemException::of('too_large', 'A photo may be at most ' . max(1, intdiv($maxBytes, 1024)) . ' KB.');
    }
}
