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

/**
 * What an upload is allowed to be, and what it actually is. Detects a file's real type
 * through finfo, then mime_content_type(), never the type the browser sent.
 *
 * @package mindstellar\storage
 */
final class UploadMimes
{
    /** The image types an upload may be, by extension. Uploads are always photos. */
    private const IMAGE_MIMES = [
        'bmp'  => ['image/bmp'],
        'gif'  => ['image/gif'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpe'  => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'tiff' => ['image/tiff'],
        'tif'  => ['image/tiff'],
        'webp' => ['image/webp'],
    ];

    /** The files a visitor may attach to a contact e-mail: the name's extension and the real type must agree. */
    private const ATTACHMENT_MIMES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain'],
        'doc'  => ['application/msword', 'application/vnd.ms-office'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'odt'  => ['application/vnd.oasis.opendocument.text'],
        'ods'  => ['application/vnd.oasis.opendocument.spreadsheet'],
        'odp'  => ['application/vnd.oasis.opendocument.presentation'],
    ];

    /** The largest attachment, in MB, until the owner sets one. */
    public const DEFAULT_ATTACHMENT_MAX_MB = 5;

    /**
     * Every mime the configured extensions map to. An extension that is not an image adds none.
     *
     * @return string[]
     */
    public static function allowed(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $out = array();
        foreach (explode(',', (string)osc_allowed_extension()) as $ext) {
            $ext = strtolower(trim($ext));
            foreach (self::IMAGE_MIMES[$ext] ?? [] as $mime) {
                if (!in_array($mime, $out, true)) {
                    $out[] = $mime;
                }
            }
        }

        return $cached = $out;
    }

    /**
     * The type of a file on disk, read from the file itself.
     *
     * Asks the image decoder last and lets it overrule: a server can name a file image/jpeg
     * from its bytes while `getimagesize()` refuses it, and only one of those two answers
     * means it is really an image. Returns '' when nothing can tell, which no allow-list
     * matches — a file whose type cannot be established is not an accepted upload.
     *
     * @param string $path
     *
     * @return string
     */
    public static function detect(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $mime = '';
        if (function_exists('finfo_open') && function_exists('finfo_file')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string)@finfo_file($finfo, $path);
                unset($finfo);
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $mime = (string)@mime_content_type($path);
        }

        if (function_exists('getimagesize') && ($mime === '' || stripos($mime, 'image/') !== false)) {
            $info = @getimagesize($path);

            return isset($info['mime']) ? (string)$info['mime'] : '';
        }

        return $mime;
    }

    /**
     * Whether a file on disk is one of the types the site accepts.
     *
     * @param string $path
     *
     * @return bool
     */
    public static function isAllowed(string $path): bool
    {
        $mime = self::detect($path);

        return $mime !== '' && in_array($mime, self::allowed(), true);
    }

    /**
     * Whether a visitor's file may be attached to a contact e-mail: a picture, PDF, text or
     * office document whose name and content agree, no larger than the owner's limit.
     *
     * @param string $path the uploaded file
     * @param string $name the name the visitor gave it
     *
     * @return bool
     */
    public static function isAllowedAttachment(string $path, string $name): bool
    {
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = is_file($path) ? (int) filesize($path) : 0;

        return $size > 0
            && $size <= self::attachmentMaxMb() * 1024 * 1024
            && in_array(self::detect($path), self::ATTACHMENT_MIMES[$ext] ?? [], true);
    }

    /**
     * The extensions a contact e-mail attachment may have, for the form's accept list and its message.
     *
     * @return string[]
     */
    public static function attachmentExtensions(): array
    {
        return array_keys(self::ATTACHMENT_MIMES);
    }

    /**
     * The largest contact e-mail attachment, in MB, from Settings → General.
     *
     * @return int
     */
    public static function attachmentMaxMb(): int
    {
        $v = osc_get_preference('attachment_max_mb');

        return $v === '' || $v === null ? self::DEFAULT_ATTACHMENT_MAX_MB : max(1, (int) $v);
    }

    /**
     * Whether a file is an allowed type *and* an image something can actually decode.
     *
     * A listing photo is never anything else, and the type alone is not enough: with a
     * non-image extension configured, `application/octet-stream` is on the allowed list and
     * any 200 bytes would pass the type check, only to throw when the resizer opened it.
     *
     * @param string $path
     *
     * @return bool
     */
    public static function isAllowedImage(string $path): bool
    {
        if (!self::isAllowed($path)) {
            return false;
        }

        return \ImageProcessing::imageInfo($path) !== null && !self::tooManyPixels($path);
    }

    /**
     * Whether an image has more pixels than the server may open. Its header says so before
     * anything is decoded, so a small file cannot expand into gigabytes of memory.
     *
     * @param string $path
     *
     * @return bool
     */
    public static function tooManyPixels(string $path): bool
    {
        return \ImageProcessing::pixelCount($path) > \ImageProcessing::maxPixels();
    }

    /**
     * The refusal shown for an image over the pixel limit.
     *
     * @return string
     */
    public static function tooManyPixelsMessage(): string
    {
        return sprintf(
            __('The photo is too large. It can have at most %s megapixels.'),
            round(\ImageProcessing::maxPixels() / 1000000)
        );
    }
}
