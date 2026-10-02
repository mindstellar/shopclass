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
 * What the photo uploader may shrink in the browser before upload.
 *
 * The box is the normal image size: the server keeps nothing larger unless the original is
 * kept, and it enlarges anything smaller, so the browser must never go below it.
 */
final class BrowserResize
{
    /** A file at or under this size that already fits the box is sent as it is. */
    public const MIN_BYTES = 1500000;

    /** The server re-encodes every stored JPEG, so the browser's copy stays close to lossless. */
    public const QUALITY = 0.92;

    /**
     * The uploader's resize config for this site, or null when resizing is off.
     *
     * @return array<string,int|float|bool>|null
     */
    public static function config(): ?array
    {
        return self::resolve(
            (bool)osc_get_preference('browser_resize'),
            osc_keep_original_image(),
            (string)osc_normal_dimensions()
        );
    }

    /**
     * @param bool   $enabled      the browser_resize preference
     * @param bool   $keepOriginal while the full-size photo is kept, only a file the site
     *                             would refuse as too large is shrunk
     * @param string $normal       the normal size, "WIDTHxHEIGHT"
     *
     * @return array<string,int|float|bool>|null
     */
    public static function resolve(bool $enabled, bool $keepOriginal, string $normal): ?array
    {
        if (!$enabled || !preg_match('/^([0-9]+)x([0-9]+)$/i', trim($normal), $m)) {
            return null;
        }
        $width  = (int)$m[1];
        $height = (int)$m[2];
        if ($width < 1 || $height < 1) {
            return null;
        }

        return array(
            'maxWidth'     => $width,
            'maxHeight'    => $height,
            'minBytes'     => self::MIN_BYTES,
            'quality'      => self::QUALITY,
            'onlyOversize' => $keepOriginal,
        );
    }
}
