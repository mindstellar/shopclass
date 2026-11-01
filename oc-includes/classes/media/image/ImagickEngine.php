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

use Imagick;
use ImagickException;
use ImagickPixel;

/**
 * The ImageMagick engine, used when the extension is loaded and the admin chose it.
 */
final class ImagickEngine implements Engine
{
    /**
     * @param bool $autoOrient use Imagick::autoOrient() where the build has it; false uses the shared table
     */
    public function __construct(private readonly bool $autoOrient = true)
    {
    }

    public function tooLarge(string $path, array $info, int $max): bool
    {
        $pixels = (int) $info[0] * (int) $info[1];
        try {
            // A GIF frame can be larger than the size its header states.
            $ping = new Imagick();
            $ping->pingImage($this->source($path, $info));
            $pixels = max($pixels, $ping->getImageWidth() * $ping->getImageHeight());
            $ping->clear();
        } catch (ImagickException | \RuntimeException $e) {
            return true;
        }

        return $pixels > $max;
    }

    public function open(string $path, array $info): Image
    {
        try {
            $im = new Imagick($this->source($path, $info));
        } catch (ImagickException $e) {
            throw Formats::corrupt($path);
        }
        $this->toSrgb($im);

        return new Image($im, $im->getImageWidth(), $im->getImageHeight(), (string) $info['mime'], $im->getImageOrientation());
    }

    public function orient(Image $image): Image
    {
        $im = $this->im($image);
        // Imagick builds before 3.7 have no autoOrient().
        if ($this->autoOrient && is_callable([$im, 'autoOrient'])) {
            $im->autoOrient();
        } elseif (($steps = $image->upright()) !== null) {
            [$degrees, $flip] = $steps;
            if ($degrees !== 0) {
                $im->rotateImage(new ImagickPixel('none'), $degrees);
            }
            if ($flip) {
                $im->flopImage();
            }
        }
        $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);

        return $image->with($im, $im->getImageWidth(), $im->getImageHeight());
    }

    public function fit(Image $image, int $width, int $height, int $canvasWidth, int $canvasHeight, int $x, int $y): Image
    {
        $im = $this->im($image);
        $im->thumbnailImage($width, $height);
        $canvas = new Imagick();
        $canvas->newImage($canvasWidth, $canvasHeight, new ImagickPixel('none'));
        $canvas->compositeImage($im, Imagick::COMPOSITE_OVER, $x, $y);
        $im->clear();

        return $image->with($canvas, $canvasWidth, $canvasHeight);
    }

    public function composite(Image $image, Image $overlay, array $spots, int $opacity): Image
    {
        $im   = $this->im($image);
        $mark = clone $this->im($overlay);
        if ($opacity < 100) {
            $mark->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            $mark->evaluateImage(Imagick::EVALUATE_MULTIPLY, max(0, $opacity) / 100, Imagick::CHANNEL_ALPHA);
        }
        foreach ($spots as [$x, $y]) {
            $im->compositeImage($mark, Imagick::COMPOSITE_OVER, $x, $y);
        }
        $mark->clear();

        return $image->with($im, $image->width, $image->height, $image->orientation);
    }

    public function encode(Image $image, string $path, string $format, int $quality, int $compression): bool
    {
        $im = $this->im($image);
        try {
            $out = $im;
            if ($format === 'jpeg') {
                $out = new Imagick();
                $out->newImage($image->width, $image->height, new ImagickPixel('white'));
                $out->compositeImage($im, Imagick::COMPOSITE_OVER, 0, 0);
            }
            $out->setImageDepth(8);
            // No EXIF or profiles: smaller files, and no camera or GPS data.
            $out->stripImage();
            if ($format === 'png' || $format === 'gif') {
                $out->setOption('png:compression-level', (string) $compression);
            } else {
                $out->setImageCompressionQuality($quality);
            }
            $out->setImageFormat($format);
            $written = $out->writeImage($format . ':' . $path);
            if ($out !== $im) {
                $out->clear();
            }

            return $written;
        } catch (\Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    public function canWrite(string $format): bool
    {
        $name = strtoupper(Formats::name($format));

        return in_array($name, Imagick::queryFormats($name), true);
    }

    public function copy(Image $image): Image
    {
        return $image->with(clone $this->im($image), $image->width, $image->height, $image->orientation);
    }

    public function free(Image $image): void
    {
        $this->im($image)->clear();
    }

    /**
     * The file with its coder and first frame named, as ImageMagick reads it.
     *
     * @param array<int|string,mixed> $info
     */
    private function source(string $path, array $info): string
    {
        $coder = Formats::coder($info);
        if ($coder === null) {
            throw Formats::corrupt($path);
        }

        return $coder . ':' . $path . '[0]';
    }

    /**
     * Convert to sRGB before the profile is stripped on save, so a wide-gamut or CMYK photo keeps
     * its colours. The profile is CC0, from Compact-ICC-Profiles.
     */
    private function toSrgb(Imagick $im): void
    {
        try {
            $icc = $im->getImageProfiles('icc', true)['icc'] ?? '';
            // A real colour profile is a few KB, a CMYK one about 0.5 MB; a larger one is not parsed.
            if ($icc !== '' && strlen($icc) <= 1048576 && $im->getImageColorspace() !== Imagick::COLORSPACE_GRAY) {
                $im->profileImage('icc', (string) file_get_contents(dirname(__DIR__, 2) . '/icc/sRGB-v2-micro.icc'));
            } elseif ($im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (ImagickException $e) {
            // Colours stay as they were; the photo itself is still usable.
        }
    }

    private function im(Image $image): Imagick
    {
        if (!$image->native instanceof Imagick) {
            throw new \InvalidArgumentException('The image was not opened by ImageMagick.');
        }

        return $image->native;
    }
}
