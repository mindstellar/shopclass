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
 * What an upload is allowed to be, and what it actually is.
 *
 * The allowed list was derived from `mimes.php` by the same eighteen lines in two places, and
 * the two then disagreed about how to read a file's type: one asked `getimagesize()`, the other
 * tried finfo, then `mime_content_type()`, then — with neither available — the type the browser
 * sent, which is the one thing about an upload nobody should trust.
 *
 * @package mindstellar\storage
 */
final class UploadMimes
{
    /**
     * Every mime the configured extensions map to.
     *
     * @return string[]
     */
    public static function allowed(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $mimes = array();
        require LIB_PATH . 'osclass/mimes.php';

        $out = array();
        foreach (explode(',', (string)osc_allowed_extension()) as $ext) {
            $ext = strtolower(trim($ext));
            if ($ext === '' || !isset($mimes[$ext])) {
                continue;
            }
            foreach ((array)$mimes[$ext] as $mime) {
                $mime = (string)$mime;
                if ($mime !== '' && !in_array($mime, $out, true)) {
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

        return function_exists('getimagesize') && is_array(@getimagesize($path)) && !self::tooManyPixels($path);
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
