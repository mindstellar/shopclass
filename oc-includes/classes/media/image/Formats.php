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

namespace mindstellar\image;

/**
 * The photo formats read and written, shared by every engine.
 */
final class Formats
{
    /** The ImageMagick coder for each getimagesize() type, named on every read so the file cannot pick one. */
    public const CODERS = [
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * The file's width, height and type, as getimagesize() gives them.
     *
     * @return array<int|string,mixed>|null null when the file is not a photo
     */
    public static function info(string $path): ?array
    {
        $info = @getimagesize($path);

        // Only photo formats; anything else would reach a decoder no photo ever needs.
        return is_array($info) && isset(self::CODERS[$info[2]]) ? $info : null;
    }

    /**
     * The ImageMagick coder for a file info() accepted.
     *
     * @param array<int|string,mixed> $info
     */
    public static function coder(array $info): ?string
    {
        return self::CODERS[$info[2]] ?? null;
    }

    /**
     * An extension or format name in lower case, with 'jpg' read as 'jpeg'.
     */
    public static function name(string $ext): string
    {
        $ext = strtolower($ext);

        return $ext === 'jpg' ? 'jpeg' : $ext;
    }

    /**
     * The extension a file of this MIME type is saved with, null for one that is not a photo.
     */
    public static function extension(string $mime): ?string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][strtolower($mime)] ?? null;
    }

    /**
     * The MIME type a file with this extension holds, such as 'image/jpeg' for 'JPG'.
     */
    public static function mime(string $ext): string
    {
        return 'image/' . self::name($ext);
    }

    /**
     * The error for a file that cannot be read as a photo.
     */
    public static function corrupt(string $path): \RuntimeException
    {
        return new \RuntimeException(sprintf(__('%s is corrupt or broken!'), $path));
    }
}
