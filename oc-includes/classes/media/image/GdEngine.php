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

use mindstellar\utility\Formatting;

/**
 * The engine every PHP build has. It reads EXIF orientation only when the exif extension is loaded.
 */
final class GdEngine implements Engine
{
    /** GD holds about 5 bytes a pixel, and a resize or rotate keeps a second copy at once. */
    private const BYTES_PER_PIXEL = 10;

    /** GD may always open this many pixels, so a nearly full memory_limit does not refuse every photo. */
    private const MIN_PIXELS = 1000000;

    public function tooLarge(string $path, array $info, int $max): bool
    {
        // GD reads the whole file into memory before it decodes it.
        $used  = memory_get_usage() + (int) @filesize($path);
        $limit = self::memoryPixels(Formatting::iniBytes((string) ini_get('memory_limit')), $used);

        return (int) $info[0] * (int) $info[1] > min($max, $limit);
    }

    /**
     * How many pixels GD can decode in a memory_limit of $limit bytes with $used already taken.
     *
     * @internal
     * @return int PHP_INT_MAX when there is no limit (-1 or 0)
     */
    public static function memoryPixels(int $limit, int $used): int
    {
        if ($limit <= 0) {
            return PHP_INT_MAX;
        }

        return max(self::MIN_PIXELS, intdiv($limit - $used, self::BYTES_PER_PIXEL));
    }

    public function open(string $path, array $info): Image
    {
        $content = file_get_contents($path);
        $im      = $content === false ? false : imagecreatefromstring($content);
        if ($im === false) {
            throw Formats::corrupt($path);
        }
        imagepalettetotruecolor($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);

        $orientation = 1;
        if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
            // A broken EXIF block only loses the orientation, so its warnings are silenced.
            $exif        = @exif_read_data($path);
            $orientation = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
        }

        return new Image($im, imagesx($im), imagesy($im), (string) $info['mime'], $orientation);
    }

    public function orient(Image $image): Image
    {
        $steps = $image->upright();
        if ($steps === null) {
            return $image;
        }
        [$degrees, $flip] = $steps;
        $im               = $this->gd($image);
        if ($degrees !== 0) {
            // imagerotate() turns anticlockwise.
            $im = imagerotate($im, 360 - $degrees, 0);
            if ($im === false) {
                return $image;
            }
        }
        if ($flip) {
            imageflip($im, IMG_FLIP_HORIZONTAL);
        }

        return $image->with($im, imagesx($im), imagesy($im));
    }

    public function fit(Image $image, int $width, int $height, int $canvasWidth, int $canvasHeight, int $x, int $y): Image
    {
        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $this->gd($image), $x, $y, 0, 0, $width, $height, $image->width, $image->height);

        return $image->with($canvas, $canvasWidth, $canvasHeight);
    }

    public function composite(Image $image, Image $overlay, array $spots, int $opacity): Image
    {
        $im   = $this->gd($image);
        $mark = $opacity < 100 ? $this->fade($this->gd($overlay), $opacity) : $this->gd($overlay);
        imagealphablending($im, true);
        foreach ($spots as [$x, $y]) {
            imagecopy($im, $mark, $x, $y, 0, 0, $overlay->width, $overlay->height);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);

        return $image->with($im, $image->width, $image->height, $image->orientation);
    }

    public function encode(Image $image, string $path, string $format, int $quality, int $compression): bool
    {
        $im = $this->gd($image);
        try {
            switch ($format) {
                case 'gif':
                    return imagegif($im, $path);
                case 'png':
                    return imagepng($im, $path, $compression);
                case 'webp':
                    return imagewebp($im, $path, $quality);
                default:
                    $flat = imagecreatetruecolor($image->width, $image->height);
                    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                    imagealphablending($flat, true);
                    imagecopy($flat, $im, 0, 0, 0, 0, $image->width, $image->height);

                    return imagejpeg($flat, $path, $quality);
            }
        } catch (\ValueError | \TypeError $e) {
            // A path with a null byte throws TypeError; an argument out of range throws ValueError.
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    public function canWrite(string $format): bool
    {
        return match (Formats::name($format)) {
            'jpeg'  => function_exists('imagejpeg'),
            'png'   => function_exists('imagepng'),
            'gif'   => function_exists('imagegif'),
            'webp'  => function_exists('imagewebp'),
            default => false,
        };
    }

    public function copy(Image $image): Image
    {
        $copy = imagecreatetruecolor($image->width, $image->height);
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagecopy($copy, $this->gd($image), 0, 0, 0, 0, $image->width, $image->height);

        return $image->with($copy, $image->width, $image->height, $image->orientation);
    }

    public function free(Image $image): void
    {
        // A GdImage is freed with its last reference.
    }

    /**
     * A copy with every pixel's alpha multiplied by $opacity percent, as ImageMagick does.
     */
    private function fade(\GdImage $src, int $opacity): \GdImage
    {
        $width  = imagesx($src);
        $height = imagesy($src);
        $out    = imagecreatetruecolor($width, $height);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopy($out, $src, 0, 0, 0, 0, $width, $height);
        $keep = max(0, min(100, $opacity)) / 100;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $colour = imagecolorat($out, $x, $y);
                $alpha  = ($colour >> 24) & 0x7f;
                $faded  = 127 - (int) round((127 - $alpha) * $keep);
                if ($faded !== $alpha) {
                    imagesetpixel($out, $x, $y, ($faded << 24) | ($colour & 0xffffff));
                }
            }
        }

        return $out;
    }

    private function gd(Image $image): \GdImage
    {
        if (!$image->native instanceof \GdImage) {
            throw new \InvalidArgumentException('The image was not opened by GD.');
        }

        return $image->native;
    }
}
