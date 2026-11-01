<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\image\Formats;
use mindstellar\image\GdEngine;
use mindstellar\image\Image;
use mindstellar\image\ImagickEngine;
use mindstellar\image\Processor;
use mindstellar\image\WatermarkText;
use mindstellar\utility\Deprecate;

/**
 * Loads, resizes, rotates and watermarks a photo. It hands each step to mindstellar\image\Processor.
 *
 * @deprecated 7.1.0 Use mindstellar\image\Processor: Processor::site()->open($path), then resizeTo(), orient() and save().
 */
class ImageProcessing
{
    /** The lowest watermark opacity, in percent; 100 is solid. */
    public const WATERMARK_OPACITY_MIN = Processor::OPACITY_MIN;

    private Processor $processor;
    private Image $image;
    private string $ext;

    /** @var bool set while fromFile() builds one, so the deprecation is reported once */
    private static bool $quiet = false;

    /**
     * @param string $imagePath
     *
     * @throws RuntimeException when the file is missing, unreadable, empty, not a photo or too large
     * @deprecated 7.1.0 Use mindstellar\image\Processor::open()
     */
    public function __construct($imagePath)
    {
        if (!self::$quiet) {
            Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Processor::open()');
        }
        $this->processor = Processor::site();
        $this->image     = $this->processor->open((string)$imagePath);
        $this->ext       = $this->processor->defaultExt($this->image);
    }

    /**
     * Load an image from disk.
     *
     * @param string $imagePath
     *
     * @return \ImageProcessing
     * @throws RuntimeException when the file is missing, unreadable, empty or too large
     * @deprecated 7.1.0 Use mindstellar\image\Processor::open()
     */
    public static function fromFile($imagePath)
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Processor::open()');
        self::$quiet = true;
        try {
            return new ImageProcessing($imagePath);
        } finally {
            self::$quiet = false;
        }
    }

    /**
     * The width and height in a "WIDTHxHEIGHT" size, such as the "640x480" image size settings.
     *
     * @return array{0:int,1:int}
     * @throws InvalidArgumentException when it is not two whole numbers above 0
     * @deprecated 7.1.0 Use mindstellar\image\Processor::parseSize()
     */
    public static function parseSize(string $size): array
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Processor::parseSize()');

        return Processor::parseSize($size);
    }

    /**
     * The most pixels an image may have before it is opened.
     *
     * @param int $fileBytes The file's size, as GD reads the whole file into memory first
     *
     * @return int
     * @deprecated 7.1.0 Use mindstellar\image\Processor::maxPixels()
     */
    public static function maxPixels(int $fileBytes = 0)
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Processor::maxPixels()');

        return Processor::site()->maxPixels($fileBytes);
    }

    /**
     * How many pixels GD can decode in a memory_limit of $limit bytes with $used already taken.
     *
     * @internal
     * @return int PHP_INT_MAX when there is no limit (-1 or 0)
     * @deprecated 7.1.0 Use mindstellar\image\GdEngine::memoryPixels()
     */
    public static function memoryPixels(int $limit, int $used): int
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\GdEngine::memoryPixels()');

        return GdEngine::memoryPixels($limit, $used);
    }

    /**
     * How many pixels opening the image would decode.
     *
     * @param string $imagePath
     *
     * @return int 0 when the file is not an image
     * @deprecated 7.1.0 Use mindstellar\image\Processor::pixels()
     */
    public static function pixelCount($imagePath)
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Processor::pixels()');

        return Processor::site()->pixels((string)$imagePath);
    }

    /**
     * The image's width, height and type, as getimagesize() gives them.
     *
     * @param string $imagePath
     *
     * @return array<int|string,mixed>|null null when the file is not an image
     * @deprecated 7.1.0 Use mindstellar\image\Formats::info()
     */
    public static function imageInfo($imagePath)
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Formats::info()');

        return Formats::info((string)$imagePath);
    }

    /**
     * Whether photos are made with ImageMagick: it is loaded and the admin chose it.
     *
     * @return bool
     * @deprecated 7.1.0 Use mindstellar\image\ImagickEngine::enabled()
     */
    public static function usesImagick(): bool
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\ImagickEngine::enabled()');

        return ImagickEngine::enabled();
    }

    /**
     * Whether this server can write WebP with the given engine.
     *
     * @param bool $imagick
     *
     * @return bool
     * @deprecated 7.1.0 Use mindstellar\image\Processor::site()->engine()->canWrite('webp')
     */
    public static function canWriteWebp($imagick)
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', "mindstellar\\image\\Processor::site()->engine()->canWrite('webp')");
        if ($imagick) {
            return class_exists('Imagick') && (new ImagickEngine())->canWrite('webp');
        }

        return (new GdEngine())->canWrite('webp');
    }

    /**
     * A watermark opacity held between WATERMARK_OPACITY_MIN and 100; empty reads as 100.
     *
     * @param mixed $value
     * @deprecated 7.1.0 Use mindstellar\image\Processor::opacity()
     */
    public static function watermarkOpacity($value): int
    {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\Processor::opacity()');

        return Processor::opacity($value);
    }

    /**
     * Draw the text watermark as a PNG.
     *
     * @param string                   $watermark_text
     * @param string|null              $font_color Hex colour, '#' optional
     * @param int|null                 $font_size
     * @param array<string,mixed>|null $aOptions   ['background_color'] the outline colour, hex;
     *                                             null reads the stored options
     *
     * @return string the PNG's path, or '' when neither GD with FreeType nor ImageMagick can draw text
     * @deprecated 7.1.0 Use mindstellar\image\WatermarkText::create()
     */
    public static function createWatermarkImageFromText(
        $watermark_text,
        $font_color = null,
        $font_size = null,
        $aOptions = null
    ) {
        Deprecate::deprecatedFunction(__METHOD__, '7.1.0', 'mindstellar\image\WatermarkText::create()');

        return WatermarkText::create(
            (string)$watermark_text,
            $font_color === null ? null : (string)$font_color,
            $font_size === null ? null : (int)$font_size,
            is_array($aOptions) ? $aOptions : null
        );
    }

    /**
     * A copy owns its own pixels, so one size can be cut from it without touching the other.
     */
    public function __clone()
    {
        $this->image = $this->processor->engine()->copy($this->image);
    }

    public function __destruct()
    {
        $this->processor->engine()->free($this->image);
    }

    /**
     * The extension the image will be written with ('png', 'webp' or 'jpg').
     *
     * @return string
     * @deprecated 7.1.0 Use mindstellar\image\Processor::defaultExt()
     */
    public function getExt()
    {
        return $this->ext;
    }

    /**
     * The MIME type the image will be written with.
     *
     * @return string
     * @deprecated 7.1.0 Use mindstellar\image\Formats::mime()
     */
    public function getMime()
    {
        return Formats::mime($this->ext);
    }

    /**
     * Current image width in pixels.
     *
     * @return int
     * @deprecated 7.1.0 Use mindstellar\image\Image::$width
     */
    public function getWidth()
    {
        return $this->image->width;
    }

    /**
     * Current image height in pixels.
     *
     * @return int
     * @deprecated 7.1.0 Use mindstellar\image\Image::$height
     */
    public function getHeight()
    {
        return $this->image->height;
    }

    /**
     * Fit the image inside $width x $height, centred on a see-through canvas.
     *
     * @param int       $width
     * @param int       $height
     * @param bool|null $force_aspect Crop the canvas to the scaled size; null reads the site setting
     * @param bool      $upscale      Allow enlarging an image smaller than the target
     *
     * @return $this
     * @deprecated 7.1.0 Use mindstellar\image\Processor::resizeTo()
     */
    public function resizeTo($width, $height, $force_aspect = null, $upscale = true)
    {
        $this->image = $this->processor->resizeTo($this->image, (int)$width, (int)$height, $force_aspect === null ? null : (bool)$force_aspect, (bool)$upscale);

        return $this;
    }

    /**
     * Write the image to disk, applying the configured JPEG quality / PNG compression.
     *
     * @param string      $imagePath
     * @param string|null $ext Output format ('jpg', 'jpeg', 'png', 'gif' or 'webp', any case);
     *                         defaults to the loaded image's own
     *
     * @return bool false when the image could not be written
     * @throws RuntimeException when the target path exists and is not writable
     * @deprecated 7.1.0 Use mindstellar\image\Processor::save()
     */
    public function saveToFile($imagePath, $ext = null)
    {
        return $this->processor->save($this->image, (string)$imagePath, (string)($ext ?? $this->ext));
    }

    /**
     * Rotate the image upright from its EXIF orientation.
     *
     * @return $this
     * @deprecated 7.1.0 Use mindstellar\image\Processor::orient()
     */
    public function autoRotate()
    {
        $this->image = $this->processor->orient($this->image);

        return $this;
    }

    /**
     * Send the image to the browser as a download, with its content-type headers.
     *
     * @return void
     * @deprecated 7.1.0 Use mindstellar\image\Processor::save() and send the file
     */
    public function show()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'osc_show_');
        if ($tmp === false) {
            return;
        }
        header('Content-Disposition: Attachment;filename=image.' . $this->ext);
        header('Content-type: ' . $this->getMime());
        if ($this->processor->save($this->image, $tmp, $this->ext)) {
            readfile($tmp);
        }
        @unlink($tmp);
    }

    /**
     * Apply a text watermark.
     *
     * @param string                   $watermark_text
     * @param string|null              $font_color          Hex colour, '#' optional
     * @param int|null                 $font_size
     * @param array<string,mixed>|null $a_watermark_options See createWatermarkImageFromText()
     *
     * @return $this
     * @deprecated 7.1.0 Use mindstellar\image\WatermarkText::create() and Processor::stamp()
     */
    public function doWatermarkText($watermark_text, $font_color = null, $font_size = null, $a_watermark_options = null)
    {
        $path = $watermark_text === null || $watermark_text === ''
            ? osc_uploads_path() . 'watermark.png'
            : WatermarkText::create(
                (string)$watermark_text,
                $font_color === null ? null : (string)$font_color,
                $font_size === null ? null : (int)$font_size,
                is_array($a_watermark_options) ? $a_watermark_options : null
            );
        $this->image = $this->processor->stamp($this->image, $path);

        return $this;
    }

    /**
     * Stamp the site's watermark, text or image, when one is set.
     *
     * @param string|null $box the normal size box the mark is drawn for, "WxH"; null reads the
     *                         site's setting
     *
     * @return $this
     * @deprecated 7.1.0 Use mindstellar\image\Processor::siteWatermark()
     */
    public function doSiteWatermark(?string $box = null)
    {
        $this->image = $this->processor->siteWatermark($this->image, $box);

        return $this;
    }

    /**
     * Apply the uploaded watermark image.
     *
     * @return $this
     * @deprecated 7.1.0 Use mindstellar\image\Processor::stamp()
     */
    public function doWatermarkImage()
    {
        $this->image = $this->processor->stamp($this->image, osc_uploads_path() . 'watermark.png');

        return $this;
    }
}
