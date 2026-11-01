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
 * Opens, fits, watermarks and saves photos the same way whichever engine draws them.
 */
final class Processor
{
    /** The largest share of a photo's width or height the watermark may cover. */
    private const WATERMARK_MAX = 0.4;

    /** Below this many pixels on either side the watermark is left off. */
    private const WATERMARK_MIN = 8;

    /** The largest share of a photo's width or height one tile of a tiled watermark may cover. */
    private const WATERMARK_TILE = 0.25;

    /** Tiles repeat every 1.5 mark widths across and 2.5 mark heights down. */
    private const WATERMARK_TILE_STEP_X = 1.5;
    private const WATERMARK_TILE_STEP_Y = 2.5;

    /** The gap between a corner mark and the photo's edge, as a share of its shorter side. */
    private const WATERMARK_GAP = 0.02;

    public function __construct(private readonly Engine $engine)
    {
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    /**
     * Open a photo, refusing one larger than the image_max_pixels filter or the engine allows before it is decoded.
     *
     * @throws \RuntimeException when the file is missing, unreadable, not a photo or too large
     */
    public function open(string $path): Image
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf(__('%s does not exist!'), $path));
        }
        if (!is_readable($path)) {
            throw new \RuntimeException(sprintf(__('%s is not readable!'), $path));
        }
        $info = filesize($path) === 0 ? null : Formats::info($path);
        if ($info === null) {
            throw Formats::corrupt($path);
        }
        if ($this->engine->tooLarge($path, $info, max(1, (int) \Plugins::applyFilter('image_max_pixels', 50000000)))) {
            throw new \RuntimeException(sprintf(__('%s has too many pixels to process.'), $path));
        }

        return $this->engine->open($path, $info);
    }

    /**
     * The width and height in a "WIDTHxHEIGHT" size, such as the "640x480" image size settings.
     *
     * @return array{0:int,1:int}
     * @throws \InvalidArgumentException when it is not two whole numbers above 0
     */
    public static function parseSize(string $size): array
    {
        if (!preg_match('/^([0-9]+)x([0-9]+)$/i', trim($size), $m) || (int) $m[1] < 1 || (int) $m[2] < 1) {
            throw new \InvalidArgumentException(sprintf('Image size "%s" is not WIDTHxHEIGHT.', $size));
        }

        return [(int) $m[1], (int) $m[2]];
    }

    public function orient(Image $image): Image
    {
        return $this->engine->orient($image);
    }

    /**
     * Fit the image inside $width x $height, centred on a see-through canvas.
     *
     * @param bool|null $forceAspect crop the canvas to the scaled size; null reads the site setting
     * @param bool      $upscale     allow enlarging an image smaller than the target
     */
    public function resizeTo(Image $image, int $width, int $height, ?bool $forceAspect = null, bool $upscale = true): Image
    {
        $forceAspect ??= (bool) osc_force_aspect_image();

        if (($image->width / $image->height) >= ($width / $height)) {
            $newW = $upscale ? $width : min($width, $image->width);
            $newH = (int) ceil($image->height * ($newW / $image->width));
        } else {
            $newH = $upscale ? $height : min($height, $image->height);
            $newW = (int) ceil($image->width * ($newH / $image->height));
        }
        if ($forceAspect) {
            // Without upscaling the scaled size can be below the target, so size both sides.
            $width  = $newW;
            $height = $newH;
        }

        $x = (int) floor(($width - $newW) / 2);
        $y = (int) floor(($height - $newH) / 2);

        return $this->engine->fit($image, $newW, $newH, $width, $height, $x, $y);
    }

    /**
     * The extension a photo is saved with by default: 'png' for PNG and GIF, 'webp' where it can
     * be written, else 'jpg'.
     */
    public function defaultExt(Image $image): string
    {
        return match ($image->mime) {
            'image/png', 'image/gif' => 'png',
            'image/webp'             => $this->engine->canWrite('webp') ? 'webp' : 'jpg',
            default                  => 'jpg',
        };
    }

    /**
     * The format an extension is written as, in any case: 'png', 'gif', 'webp' where it can be written, else 'jpeg'.
     */
    public function format(string $ext): string
    {
        $ext = Formats::name($ext);
        if ($ext === 'webp' && !$this->engine->canWrite('webp')) {
            return 'jpeg';
        }

        return in_array($ext, ['png', 'gif', 'webp'], true) ? $ext : 'jpeg';
    }

    /**
     * Write the image with the site's JPEG quality and PNG compression.
     *
     * @param string|null $ext 'jpg', 'jpeg', 'png', 'gif' or 'webp', any case; null uses defaultExt()
     *
     * @return bool false, with a warning, when the file could not be written
     * @throws \RuntimeException when the file exists and is not writable
     */
    public function save(Image $image, string $path, ?string $ext = null): bool
    {
        if (file_exists($path) && !is_writable($path)) {
            throw new \RuntimeException("$path is not writable!");
        }

        return $this->engine->encode($image, $path, $this->format($ext ?? $this->defaultExt($image)), $this->quality(), $this->pngCompression());
    }

    /**
     * JPEG and WebP quality: the jpeg_quality preference (82 when unset), then the image_jpeg_quality filter.
     */
    public function quality(): int
    {
        $pref = (int) osc_get_preference('jpeg_quality');
        if ($pref < 1 || $pref > 100) {
            $pref = 82;
        }

        return max(1, min(100, (int) \Plugins::applyFilter('image_jpeg_quality', $pref)));
    }

    /**
     * PNG compression, 0-9: 6 unless the image_png_compression filter changes it.
     */
    public function pngCompression(): int
    {
        return max(0, min(9, (int) \Plugins::applyFilter('image_png_compression', 6)));
    }

    /**
     * Stamp $mark on the image, sized for the normal size box and placed per $place.
     *
     * @param string $box     the normal size box, "WxH"
     * @param string $place   'tile', a corner or edge code ('tl', 'tc', 'tr', 'ml', 'mr', 'bl', 'bc', 'br'), else centred
     * @param int    $opacity percent; the mark's alpha is multiplied by it
     */
    public function watermark(Image $image, Image $mark, string $box, string $place, int $opacity): Image
    {
        $size = $this->watermarkSize($image->width, $image->height, $mark->width, $mark->height, $box, $place === 'tile');
        if ($size === null) {
            return $image;
        }
        [$w, $h] = $size;
        $scaled  = $this->engine->fit($this->engine->copy($mark), $w, $h, $w, $h, 0, 0);
        $image   = $this->engine->composite($image, $scaled, $this->watermarkSpots($image->width, $image->height, $w, $h, $place), $opacity);
        $this->engine->free($scaled);

        return $image;
    }

    /**
     * The mark's size on a photo, or null when it would be too small to read. It is drawn for the
     * normal size box, scales down with smaller sizes, and never covers more than WATERMARK_MAX.
     *
     * @return array{0:int,1:int}|null
     */
    private function watermarkSize(int $imageW, int $imageH, int $markW, int $markH, string $box, bool $tiled = false): ?array
    {
        if ($markW < 1 || $markH < 1) {
            return null;
        }
        $max                    = $tiled ? self::WATERMARK_TILE : self::WATERMARK_MAX;
        [$boxWidth, $boxHeight] = self::parseSize($box);
        $scale                  = min(
            1.0,
            max($imageW / $boxWidth, $imageH / $boxHeight),
            $max * $imageW / $markW,
            $max * $imageH / $markH
        );
        $size = [(int) round($markW * $scale), (int) round($markH * $scale)];

        return $size[0] < self::WATERMARK_MIN || $size[1] < self::WATERMARK_MIN ? null : $size;
    }

    /**
     * Where a mark goes: one spot, or a staggered grid covering the photo when $place is 'tile'.
     *
     * @return list<array{0:int,1:int}> top-left corners
     */
    private function watermarkSpots(int $imageW, int $imageH, int $markW, int $markH, string $place): array
    {
        if ($place === 'tile') {
            // Odd rows shift by half a step so the marks stagger; the rows sit centred top to bottom.
            $stepX = (int) ceil($markW * self::WATERMARK_TILE_STEP_X);
            $stepY = (int) ceil($markH * self::WATERMARK_TILE_STEP_Y);
            $spots = [];
            for ($row = 0, $y = intdiv($imageH % $stepY, 2); $y < $imageH; $row++, $y += $stepY) {
                for ($x = $row % 2 === 1 ? -intdiv($stepX, 2) : 0; $x < $imageW; $x += $stepX) {
                    $spots[] = [$x, $y];
                }
            }

            return $spots;
        }

        // Edge marks keep a small gap from the edge, so a crop or rounded card does not cut them.
        $gap    = (int) round(min($imageW, $imageH) * self::WATERMARK_GAP);
        $right  = $imageW - $markW - $gap;
        $bottom = $imageH - $markH - $gap;
        $middle = intdiv($imageW - $markW, 2);
        $centre = intdiv($imageH - $markH, 2);

        return [match ($place) {
            'tl'    => [$gap, $gap],
            'tc'    => [$middle, $gap],
            'tr'    => [$right, $gap],
            'ml'    => [$gap, $centre],
            'mr'    => [$right, $centre],
            'bl'    => [$gap, $bottom],
            'bc'    => [$middle, $bottom],
            'br'    => [$right, $bottom],
            default => [$middle, $centre],
        }];
    }
}
