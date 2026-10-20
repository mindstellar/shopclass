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

/**
 * This class represents a utility to load, resize, rotate and process images easily.
 */
class ImageProcessing
{
    /** @var mixed an Imagick object, or a GdImage when ImageMagick is not used */
    private $im;
    private $image_info;
    private $ext;
    private $mime;
    private $width;
    private $height;
    private $exif;
    private $watermarked = false;
    private $use_imagick = false;

    /** The largest share of a photo's width or height the watermark may cover. */
    private const WATERMARK_MAX = 0.4;

    /** Below this many pixels on either side the watermark is left off. */
    private const WATERMARK_MIN = 8;

    /** The largest share of a photo's width or height one tile of a tiled watermark may cover. */
    private const WATERMARK_TILE = 0.25;

    /** Tiles repeat every 1.5 mark widths across and 2.5 mark heights down. */
    private const WATERMARK_TILE_STEP_X = 1.5;
    private const WATERMARK_TILE_STEP_Y = 2.5;

    /** The lowest watermark opacity, in percent; 100 is solid. */
    public const WATERMARK_OPACITY_MIN = 10;

    /** The gap between a corner mark and the photo's edge, as a share of its shorter side. */
    private const WATERMARK_GAP = 0.02;

    /** The photo formats read, by getimagesize() type, with their ImageMagick coder. */
    private const PHOTO_CODERS = array(
        IMAGETYPE_JPEG => 'jpeg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    );

    /**
     * ImageProcessing constructor.
     *
     * @param string $imagePath
     *
     * @throws RuntimeException when the file is missing, unreadable or empty
     */
    public function __construct($imagePath)
    {
        if (!file_exists($imagePath)) {
            throw new RuntimeException(sprintf(__('%s does not exist!'), $imagePath));
        }

        if (!is_readable($imagePath)) {
            throw new RuntimeException(sprintf(__('%s is not readable!'), $imagePath));
        }

        if (filesize($imagePath) === 0) {
            throw new RuntimeException(sprintf(__('%s is corrupt or broken!'), $imagePath));
        }

        $this->image_info = self::imageInfo($imagePath);
        if (!is_array($this->image_info)) {
            throw new RuntimeException(sprintf(__('%s is corrupt or broken!'), $imagePath));
        }
        $this->use_imagick = self::usesImagick();
        if (self::pixelCount($imagePath) > self::maxPixels()) {
            throw new RuntimeException(sprintf(__('%s has too many pixels to process.'), $imagePath));
        }

        if ($this->use_imagick) {
            // Only the first frame of an animation is read.
            try {
                $this->im = new Imagick(self::coder($this->image_info) . ':' . $imagePath . '[0]');
            } catch (ImagickException $e) {
                throw new RuntimeException(sprintf(__('%s is corrupt or broken!'), $imagePath));
            }
            $this->toSrgb();

            $geometry     = $this->im->getImageGeometry();
            $this->width  = $geometry['width'];
            $this->height = $geometry['height'];
        } else {
            $content      = file_get_contents($imagePath);
            $this->im     = $content === false ? false : imagecreatefromstring($content);
            if ($this->im === false) {
                throw new RuntimeException(sprintf(__('%s is corrupt or broken!'), $imagePath));
            }
            $this->width  = imagesx($this->im);
            $this->height = imagesy($this->im);

            $this->exif = array();
            if ($this->image_info['mime'] === 'image/jpeg') {
                $this->exif = exif_read_data($imagePath);
            }
        }

        switch ($this->image_info['mime']) {
            case 'image/gif':
            case 'image/png':
                $this->ext  = 'png';
                $this->mime = 'image/png';
                break;
            case 'image/webp':
                // Stays WebP, transparency and all, where the server can write it; else JPEG below.
                if (self::canWriteWebp($this->use_imagick)) {
                    $this->ext  = 'webp';
                    $this->mime = 'image/webp';
                    break;
                }
                // no break
            default:
                $this->ext  = 'jpg';
                $this->mime = 'image/jpeg';
                if (!$this->use_imagick) {
                    $bg = imagecreatetruecolor($this->width, $this->height);
                    imagefill($bg, 0, 0, imagecolorallocatealpha($bg, 255, 255, 255, 127));
                    imagesavealpha($bg, true);
                    imagealphablending($bg, true);
                    imagecopy($bg, $this->im, 0, 0, 0, 0, $this->width, $this->height);
                    $this->im = $bg;
                }
                break;
        }
    }

    /**
     * The width and height in a "WIDTHxHEIGHT" size, such as the "640x480" image size settings.
     *
     * @return array{0:int,1:int}
     * @throws InvalidArgumentException when it is not two whole numbers above 0
     */
    public static function parseSize(string $size): array
    {
        if (!preg_match('/^([0-9]+)x([0-9]+)$/i', trim($size), $m) || (int)$m[1] < 1 || (int)$m[2] < 1) {
            throw new InvalidArgumentException(sprintf('Image size "%s" is not WIDTHxHEIGHT.', $size));
        }

        return [(int)$m[1], (int)$m[2]];
    }

    /**
     * The most pixels an image may have before it is opened. The image_max_pixels filter
     * changes it.
     *
     * @return int
     */
    public static function maxPixels()
    {
        return max(1, (int)Plugins::applyFilter('image_max_pixels', 50000000));
    }

    /**
     * How many pixels opening the image would decode. ImageMagick is asked too when it is in
     * use, because a GIF frame can be larger than the size its header states.
     *
     * @param string $imagePath
     *
     * @return int 0 when the file is not an image
     */
    public static function pixelCount($imagePath)
    {
        $info = self::imageInfo($imagePath);
        if (!is_array($info)) {
            return 0;
        }
        $pixels = $info[0] * $info[1];
        if (self::usesImagick()) {
            try {
                $ping = new Imagick();
                $ping->pingImage(self::coder($info) . ':' . $imagePath . '[0]');
                $pixels = max($pixels, $ping->getImageWidth() * $ping->getImageHeight());
                $ping->clear();
            } catch (ImagickException $e) {
                return PHP_INT_MAX;
            }
        }

        return $pixels;
    }

    /**
     * The image's width, height and type, as getimagesize() gives them.
     *
     * @param string $imagePath
     *
     * @return array<int|string,mixed>|null null when the file is not an image
     */
    public static function imageInfo($imagePath)
    {
        $info = @getimagesize($imagePath);

        // Only photo formats; anything else would reach a decoder no photo ever needs.
        return is_array($info) && isset(self::PHOTO_CODERS[$info[2]]) ? $info : null;
    }

    /**
     * The ImageMagick coder for an image imageInfo() accepted. Named on every read, so
     * ImageMagick cannot pick another decoder from the file's contents.
     *
     * @param array<int|string,mixed> $info
     *
     * @return string
     */
    private static function coder(array $info)
    {
        return self::PHOTO_CODERS[$info[2]];
    }

    /**
     * Convert to standard sRGB before the colour profile is stripped on save, so a wide-gamut
     * phone photo or a CMYK one keeps its colours. The profile is CC0, from Compact-ICC-Profiles.
     *
     * @return void
     */
    private function toSrgb()
    {
        try {
            $icc = $this->im->getImageProfiles('icc', true)['icc'] ?? '';
            // A real colour profile is a few KB, a CMYK one about 0.5 MB; a larger one is not parsed.
            if ($icc !== '' && strlen($icc) <= 1048576 && $this->im->getImageColorspace() !== Imagick::COLORSPACE_GRAY) {
                $this->im->profileImage('icc', (string)file_get_contents(dirname(__DIR__) . '/icc/sRGB-v2-micro.icc'));
            } elseif ($this->im->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
                $this->im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }
        } catch (ImagickException $e) {
            // Colours stay as they were; the photo itself is still usable.
        }
    }

    /**
     * Whether photos are made with ImageMagick: it is loaded and the admin chose it.
     *
     * @return bool
     */
    public static function usesImagick(): bool
    {
        return extension_loaded('imagick') && osc_use_imagick();
    }

    /**
     * Whether this server can write WebP with the given engine.
     *
     * @param bool $imagick
     *
     * @return bool
     */
    public static function canWriteWebp($imagick)
    {
        if ($imagick) {
            return class_exists('Imagick') && in_array('WEBP', Imagick::queryFormats('WEBP'), true);
        }

        return function_exists('imagewebp');
    }

    /**
     * Load an image from disk.
     *
     * @param string $imagePath
     *
     * @return \ImageProcessing
     * @throws RuntimeException when the file is missing, unreadable, empty or too large
     */
    public static function fromFile($imagePath)
    {
        return new ImageProcessing($imagePath);
    }

    /**
     * A copy owns its own pixels, so one size can be cut from it without touching the other.
     */
    public function __clone()
    {
        if ($this->use_imagick) {
            $this->im = clone $this->im;
        } else {
            $copy = imagecreatetruecolor($this->width, $this->height);
            imagealphablending($copy, false);
            imagesavealpha($copy, true);
            imagecopy($copy, $this->im, 0, 0, 0, 0, $this->width, $this->height);
            $this->im = $copy;
        }
    }

    /**
     * Release the underlying Imagick or GD resource.
     */
    public function __destruct()
    {
        // A GD image is an object since PHP 8.0 and is freed with it; only Imagick needs this.
        if ($this->use_imagick) {
            $this->im->destroy();
        }
    }

    /**
     * The extension the image will be written with ('png' or 'jpg').
     *
     * @return string
     */
    public function getExt()
    {
        return $this->ext;
    }

    /**
     * The MIME type the image will be written with.
     *
     * @return string
     */
    public function getMime()
    {
        return $this->mime;
    }

    /**
     * Current image width in pixels.
     *
     * @return int
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * Current image height in pixels.
     *
     * @return int
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * Fit the image inside $width x $height, centred on a background canvas.
     *
     * @param int       $width
     * @param int       $height
     * @param bool|null $force_aspect Crop the canvas to the scaled size; null reads the site setting
     * @param bool      $upscale      Allow enlarging an image smaller than the target
     *
     * @return $this
     */
    public function resizeTo($width, $height, $force_aspect = null, $upscale = true)
    {
        if ($force_aspect === null) {
            $force_aspect = osc_force_aspect_image();
        }

        if (($this->width / $this->height) >= ($width / $height)) {
            if ($upscale) {
                $newW = $width;
            } else {
                $newW = ($this->width > $width) ? $width : $this->width;
            }
            $newH = ceil($this->height * ($newW / $this->width));
            if ($force_aspect) {
                // Without upscaling the scaled width can be below the target, so size both sides.
                $width  = $newW;
                $height = $newH;
            }
        } else {
            if ($upscale) {
                $newH = $height;
            } else {
                $newH = ($this->height > $height) ? $height : $this->height;
            }
            $newW = ceil($this->width * ($newH / $this->height));
            if ($force_aspect) {
                $width  = $newW;
                $height = $newH;
            }
        }

        if ($this->use_imagick) {
            // Padding stays transparent; saving as JPEG turns it white.
            $bg = new Imagick();
            $bg->newImage($width, $height, 'none');
            $this->im->thumbnailImage($width, $height, true);
            $bg->compositeImage(
                $this->im,
                imagick::COMPOSITE_OVER,
                (int) floor(($width - $newW) / 2),
                (int) floor(($height - $newH) / 2)
            );
            $this->im = $bg;
        } else {
            $newIm = imagecreatetruecolor($width, $height);
            imagealphablending($newIm, false);
            $colorTransparent = imagecolorallocatealpha($newIm, 255, 255, 255, 127);
            imagefill($newIm, 0, 0, $colorTransparent);
            imagesavealpha($newIm, true);
            imagecopyresampled(
                $newIm,
                $this->im,
                (int) floor(($width - $newW) / 2),
                (int) floor(($height - $newH) / 2),
                0,
                0,
                $newW,
                $newH,
                $this->width,
                $this->height
            );
            $this->im = $newIm;
        }
        $this->width  = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * Write the image to disk, applying the configured JPEG quality / PNG compression.
     *
     * @param string      $imagePath
     * @param string|null $ext Output format; defaults to the loaded image's own
     *
     * @return void
     * @throws RuntimeException when the target path exists and is not writable
     */
    public function saveToFile($imagePath, $ext = null)
    {
        if (file_exists($imagePath) && !is_writable($imagePath)) {
            throw new RuntimeException("$imagePath is not writable!");
        }

        if ($ext === null) {
            $ext = $this->ext;
        }

        if ($ext === 'webp' && !self::canWriteWebp($this->use_imagick)) {
            $ext = 'jpeg';
        }
        if ($ext !== 'png' && $ext !== 'gif' && $ext !== 'webp') {
            $ext = 'jpeg';
        }

        // Default comes from the admin Media settings (jpeg_quality preference), 82 if unset;
        // the image_jpeg_quality filter can still override it for a specific resize.
        $qualityPref     = (int)osc_get_preference('jpeg_quality');
        if ($qualityPref < 1 || $qualityPref > 100) {
            $qualityPref = 82;
        }
        $jpeg_quality    = max(1, min(100, (int)Plugins::applyFilter('image_jpeg_quality', $qualityPref)));
        $png_compression = max(0, min(9, (int)Plugins::applyFilter('image_png_compression', 6)));

        if ($this->use_imagick) {
            try {
                if ($ext === 'jpeg') {
                    $bg = new Imagick();
                    $bg->newImage($this->width, $this->height, 'white');
                    $this->im->thumbnailImage($this->width, $this->height, true);
                    $bg->compositeImage($this->im, imagick::COMPOSITE_OVER, 0, 0);
                    $this->im  = $bg;
                    $this->ext = 'jpeg';
                }
                $this->im->setImageDepth(8);
                // Strip EXIF/profiles: smaller files, and no leaking camera/GPS metadata.
                $this->im->stripImage();
                if ($ext === 'png' || $ext === 'gif') {
                    $this->im->setOption('png:compression-level', (string)$png_compression);
                } else {
                    $this->im->setImageCompressionQuality($jpeg_quality);
                }
                $this->im->setImageFilename($imagePath);
                $this->im->setImageFormat($ext);
                $this->im->writeImage($imagePath);
            } catch (Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        } else {
            switch ($ext) {
                case 'gif':
                case 'png':
                    imagepng($this->im, $imagePath, $png_compression);
                    break;
                case 'webp':
                    imagesavealpha($this->im, true);
                    imagewebp($this->im, $imagePath, $jpeg_quality);
                    break;
                default:
                    if (($this->ext !== 'jpeg' && $this->ext !== 'jpg') || $this->watermarked) {
                        $this->ext = 'jpeg';
                    }
                    imagejpeg($this->im, $imagePath, $jpeg_quality);
                    break;
            }
        }
    }

    /**
     * Rotate the image upright from its EXIF/Imagick orientation.
     *
     * @return $this
     */
    public function autoRotate()
    {
        if ($this->use_imagick) {
            switch ($this->im->getImageOrientation()) {
                case imagick::ORIENTATION_TOPRIGHT:
                    $this->im->flopImage();
                    break;

                case imagick::ORIENTATION_BOTTOMRIGHT:
                    $this->im->rotateimage(new ImagickPixel('none'), 180); // rotate 180 degrees
                    break;

                case imagick::ORIENTATION_BOTTOMLEFT:
                    $this->im->flopImage();
                    $this->im->rotateImage(new ImagickPixel('none'), 180);
                    break;

                case imagick::ORIENTATION_LEFTTOP:
                    $this->im->flopImage();
                    $this->im->rotateImage(new ImagickPixel('none'), -90);
                    break;

                case imagick::ORIENTATION_RIGHTTOP:
                    $this->im->rotateimage(new ImagickPixel('none'), 90); // rotate 90 degrees CW
                    break;

                case imagick::ORIENTATION_RIGHTBOTTOM:
                    $this->im->flopImage();
                    $this->im->rotateImage(new ImagickPixel('none'), 90);
                    break;

                case imagick::ORIENTATION_LEFTBOTTOM:
                    $this->im->rotateimage(new ImagickPixel('none'), -90); // rotate 90 degrees CCW
                    break;
                default:
                    // DO NOTHING, THE IMAGE IS OK OR WE DON'T KNOW IF IT'S ROTATED
                    break;
            }
        } elseif (isset($this->exif['Orientation'])) {
            switch ($this->exif['Orientation']) {
                case 1:
                default:
                    // DO NOTHING, THE IMAGE IS OK OR WE DON'T KNOW IF IT'S ROTATED
                    break;
                case 2:
                    imageflip($this->im, IMG_FLIP_HORIZONTAL);
                    break;
                case 3:
                    $this->im = imagerotate($this->im, 180, 0);
                    break;
                case 4:
                    $this->im = imagerotate($this->im, 180, 0);
                    imageflip($this->im, IMG_FLIP_HORIZONTAL);
                    break;
                case 5:
                    $this->im = imagerotate($this->im, 270, 0);
                    imageflip($this->im, IMG_FLIP_HORIZONTAL);
                    $aux          = $this->height;
                    $this->height = $this->width;
                    $this->width  = $aux;
                    break;
                case 6:
                    $this->im     = imagerotate($this->im, -90, 0);
                    $aux          = $this->height;
                    $this->height = $this->width;
                    $this->width  = $aux;
                    break;
                case 7:
                    $this->im = imagerotate($this->im, 90, 0);
                    imageflip($this->im, IMG_FLIP_HORIZONTAL);
                    $aux          = $this->height;
                    $this->height = $this->width;
                    $this->width  = $aux;
                    break;
                case 8:
                    $this->im     = imagerotate($this->im, 90, 0);
                    $aux          = $this->height;
                    $this->height = $this->width;
                    $this->width  = $aux;
                    break;
            }
            $this->exif['Orientation'] = 1;
        }

        return $this;
    }

    /**
     * Send the image to the browser as a download, with its content-type headers.
     *
     * @return void
     */
    public function show()
    {
        header('Content-Disposition: Attachment;filename=image.' . $this->ext);
        header('Content-type: ' . $this->mime);
        if ($this->use_imagick) {
            $this->im->getImageBlob();
        } else {
            switch ($this->ext) {
                case 'gif':
                case 'png':
                    imagepng($this->im);
                    break;
                default:
                    imagejpeg($this->im);
                    break;
            }
        }
    }

    /**
     * Apply Text Watermark
     *
     * @param string                   $watermark_text
     * @param string|null              $font_color          Hex colour, '#' optional
     * @param int|null                 $font_size
     * @param array<string,mixed>|null $a_watermark_options See createWatermarkImageFromText()
     *
     * @return $this
     */
    public function doWatermarkText($watermark_text, $font_color = null, $font_size = null, $a_watermark_options = null)
    {
        try {
            $this->doWatermark($watermark_text, $font_color, $font_size, $a_watermark_options);
        } catch (ImagickException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        return $this;
    }

    /**
     * Apply watermark on image from text watermark or image watermark
     *
     * @param string|null              $watermark_text Null or '' uses the uploaded watermark image
     * @param string|null              $font_color     Hex colour, '#' optional
     * @param int|null                 $font_size
     * @param array<string,mixed>|null $aOptions       See createWatermarkImageFromText()
     * @param string|null              $box            The normal size box ("640x480") the mark is drawn for
     *
     * @return $this
     * @throws \ImagickException
     */
    private function doWatermark(
        $watermark_text = null,
        $font_color = null,
        $font_size = null,
        $aOptions = null,
        ?string $box = null
    ) {
        $this->watermarked = true;
        $path    = $watermark_text !== null && $watermark_text !== ''
            ? self::createWatermarkImageFromText($watermark_text, $font_color, $font_size, $aOptions)
            : osc_uploads_path() . 'watermark.png';
        if ($path === '' || !is_file($path)) {
            return $this;
        }
        $tiled   = osc_watermark_place() === 'tile';
        $opacity = osc_watermark_opacity();

        if ($this->use_imagick) {
            $wm   = new Imagick('png:' . $path);
            $geo  = $wm->getImageGeometry();
            $size = $this->watermarkSize($geo['width'], $geo['height'], $box, $tiled);
            if ($size !== null) {
                $wm->resizeImage($size[0], $size[1], Imagick::FILTER_LANCZOS, 1);
                if ($opacity < 100) {
                    $wm->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
                    $wm->evaluateImage(Imagick::EVALUATE_MULTIPLY, $opacity / 100, Imagick::CHANNEL_ALPHA);
                }
                foreach ($this->watermarkSpots($size[0], $size[1], $tiled) as [$x, $y]) {
                    $this->im->compositeImage($wm, Imagick::COMPOSITE_OVER, $x, $y);
                }
            }
            $wm->destroy();
        } else {
            $watermark = imagecreatefrompng($path);
            $size      = $watermark === false ? null : $this->watermarkSize(imagesx($watermark), imagesy($watermark), $box, $tiled);
            if ($size !== null) {
                $scaled = imagecreatetruecolor($size[0], $size[1]);
                imagealphablending($scaled, false);
                imagesavealpha($scaled, true);
                imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
                imagecopyresampled($scaled, $watermark, 0, 0, 0, 0, $size[0], $size[1], imagesx($watermark), imagesy($watermark));
                if ($opacity < 100) {
                    imagefilter($scaled, IMG_FILTER_COLORIZE, 0, 0, 0, (int)round(127 * (100 - $opacity) / 100));
                }

                imagealphablending($this->im, true);
                foreach ($this->watermarkSpots($size[0], $size[1], $tiled) as [$x, $y]) {
                    imagecopy($this->im, $scaled, $x, $y, 0, 0, $size[0], $size[1]);
                }
                imagealphablending($this->im, false);
                imagesavealpha($this->im, true);
            }
        }

        return $this;
    }

    /**
     * The mark's size on this photo, or null when it would be too small to read. The mark is
     * drawn for the normal size box, scales down with smaller sizes, and never covers more
     * than WATERMARK_MAX of the photo's width or height.
     *
     * @param string|null $box   the normal size box, "WxH"; null reads the site's setting
     * @param bool        $tiled a tile is held to WATERMARK_TILE instead
     *
     * @return array{0:int,1:int}|null
     */
    private function watermarkSize(int $width, int $height, ?string $box, bool $tiled = false): ?array
    {
        $max = $tiled ? self::WATERMARK_TILE : self::WATERMARK_MAX;
        if ($width < 1 || $height < 1) {
            return null;
        }
        [$boxWidth, $boxHeight] = self::parseSize($box ?? (string)osc_normal_dimensions());
        $scale = min(
            1.0,
            max($this->width / $boxWidth, $this->height / $boxHeight),
            $max * $this->width / $width,
            $max * $this->height / $height
        );
        $size = array((int)round($width * $scale), (int)round($height * $scale));

        return $size[0] < self::WATERMARK_MIN || $size[1] < self::WATERMARK_MIN ? null : $size;
    }

    /**
     * A watermark opacity held between WATERMARK_OPACITY_MIN and 100; empty reads as 100.
     *
     * @param mixed $value
     */
    public static function watermarkOpacity($value): int
    {
        return $value === '' || $value === null ? 100 : max(self::WATERMARK_OPACITY_MIN, min(100, (int)$value));
    }

    /**
     * Stamp the site's watermark, text or image, when one is set.
     *
     * @param string|null $box the normal size box the mark is drawn for, "WxH"; null reads the
     *                         site's setting
     *
     * @return $this
     */
    public function doSiteWatermark(?string $box = null)
    {
        $text = osc_is_watermark_text();
        if (!$text && !osc_is_watermark_image()) {
            return $this;
        }
        try {
            $this->doWatermark($text ? osc_watermark_text() : null, $text ? osc_watermark_text_color() : null, null, null, $box);
        } catch (ImagickException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        return $this;
    }

    /**
     * Draw the text watermark as a PNG: the text in its colour with an outline, on a see-through
     * background cut close around it. The same text drawn before is not drawn again.
     *
     * @param string                   $watermark_text
     * @param string|null              $font_color Hex colour, '#' optional
     * @param int|null                 $font_size
     * @param array<string,mixed>|null $aOptions   ['background_color'] the outline colour, hex;
     *                                             null reads the stored options
     *
     * @return string the PNG's path, or '' when neither GD with FreeType nor ImageMagick can draw text
     */
    public static function createWatermarkImageFromText(
        $watermark_text,
        $font_color = null,
        $font_size = null,
        $aOptions = null
    ) {
        $font_path      =
            Plugins::applyFilter('watermark_font_path', LIB_PATH . 'assets/fonts/open-sans/OpenSans-Regular.ttf');
        $watermark_text = Plugins::applyFilter('watermark_text_value', $watermark_text);
        if ($font_size === null) {
            $font_size = Plugins::applyFilter('watermark_font_size', 30);
        }
        $font_size  = max(1, (int)$font_size);
        $font_color = $font_color === null || $font_color === '' ? 'ff0000' : (string)$font_color;
        if ($aOptions === null) {
            $aOptions = json_decode((string)Preference::getInstance()->get('watermark_text_options'), true);
        }
        $outline = is_array($aOptions) && !empty($aOptions['background_color']) ? (string)$aOptions['background_color'] : '#000000';
        $text    = html_entity_decode((string)$watermark_text, ENT_QUOTES, 'UTF-8');

        // 'outline' marks this drawing style, so a text mark drawn the old way is drawn again.
        $name = 'watermark_text_' . md5(implode("\n", array('outline', $text, $font_color, $font_size, $outline, $font_path))) . '.png';
        $path = osc_uploads_path() . $name;
        if (is_file($path)) {
            return $path;
        }
        $previous = (string)Preference::getInstance()->get('watermark_text_image_name');
        if (preg_match('/^watermark_text_[0-9a-f]{32}\.png$/', $previous) && is_file(osc_uploads_path() . $previous)) {
            @unlink(osc_uploads_path() . $previous);
        }

        $stroke = max(1, (int)round($font_size / 12));
        if (!self::drawTextGd($path, $text, $font_size, $font_path, $font_color, $outline, $stroke)
            && !self::drawTextImagick($path, $text, $font_size, $font_path, $font_color, $outline, $stroke)
        ) {
            return '';
        }

        Preference::getInstance()->replace('watermark_text_image_name', $name);
        Preference::getInstance()->toArray();

        return $path;
    }

    /**
     * Draw the outlined text with GD. False when GD has no FreeType or cannot read the font.
     */
    private static function drawTextGd(string $path, string $text, int $size, string $font, string $color, string $outline, int $stroke): bool
    {
        if (!function_exists('imagettfbbox')) {
            return false;
        }
        $bbox = @imagettfbbox($size, 0, $font, $text);
        if ($bbox === false) {
            return false;
        }
        $left  = min($bbox[0], $bbox[6]);
        $top   = min($bbox[5], $bbox[7]);
        $image = imagecreatetruecolor(
            max(1, max($bbox[2], $bbox[4]) - $left + 2 * $stroke),
            max(1, max($bbox[1], $bbox[3]) - $top + 2 * $stroke)
        );
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        $black        = imagecolorallocate($image, 0, 0, 0);
        $outlineColor = self::imageColorAllocateHex($outline, $image);
        $textColor    = self::imageColorAllocateHex($color, $image);
        $x            = $stroke - $left;
        $y            = $stroke - $top;
        for ($dx = -$stroke; $dx <= $stroke; $dx++) {
            for ($dy = -$stroke; $dy <= $stroke; $dy++) {
                if ($dx * $dx + $dy * $dy <= $stroke * $stroke) {
                    imagettftext($image, $size, 0, $x + $dx, $y + $dy, $outlineColor === false ? $black : $outlineColor, $font, $text);
                }
            }
        }
        imagettftext($image, $size, 0, $x, $y, $textColor === false ? $black : $textColor, $font, $text);

        return imagepng($image, $path);
    }

    /**
     * Draw the outlined text with ImageMagick, for a GD built without FreeType. GD sizes text in
     * points at 96 dpi, so the size is scaled to match.
     */
    private static function drawTextImagick(string $path, string $text, int $size, string $font, string $color, string $outline, int $stroke): bool
    {
        if (!extension_loaded('imagick') || !is_file($font)) {
            return false;
        }
        try {
            $canvas = new Imagick();
            $canvas->newImage(1, 1, new ImagickPixel('none'));
            $draw = new ImagickDraw();
            $draw->setFont($font);
            $draw->setFontSize($size * 96 / 72);
            $metrics = $canvas->queryFontMetrics($draw, $text);
            $canvas->newImage(
                (int)ceil($metrics['textWidth']) + 2 * $stroke + 2,
                (int)ceil($metrics['textHeight']) + 2 * $stroke + 2,
                new ImagickPixel('none')
            );
            $x = $stroke + 1;
            $y = $stroke + 1 + $metrics['ascender'];
            $draw->setFillColor(new ImagickPixel('#' . ltrim($outline, '#')));
            $draw->setStrokeColor(new ImagickPixel('#' . ltrim($outline, '#')));
            $draw->setStrokeWidth(2 * $stroke);
            $canvas->annotateImage($draw, $x, $y, 0, $text);
            $draw->setFillColor(new ImagickPixel('#' . ltrim($color, '#')));
            $draw->setStrokeWidth(0);
            $draw->setStrokeColor(new ImagickPixel('none'));
            $canvas->annotateImage($draw, $x, $y, 0, $text);
            $canvas->trimImage(0);
            $canvas->setImagePage(0, 0, 0, 0);
            $canvas->setImageFormat('png');
            $written = $canvas->writeImage($path);
            $canvas->destroy();

            return $written;
        } catch (ImagickException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    /**
     * Allocate a hex color for an image
     *
     * @param string            $hex_string 3, 6 or 8 hex digits, '#' optional
     * @param \GdImage|resource $image
     *
     * @return int|false the allocated colour index, or false for a malformed string
     */

    private static function imageColorAllocateHex($hex_string, $image)
    {
        $hex_string = ltrim($hex_string, '#');
        //Check if color has 8, 6 or 3 characters and get values
        if (strlen($hex_string) === 8) {
            $r = hexdec($hex_string[0] . $hex_string[1]);
            $g = hexdec($hex_string[2] . $hex_string[3]);
            $b = hexdec($hex_string[4] . $hex_string[5]);
            //convert hex to decimal than 8 bit to 7 as supported in GD library
            $a      = ((~((int)hexdec($hex_string[6] . $hex_string[7]))) & 0xff) >> 1;
            $output = imagecolorallocatealpha($image, $r, $g, $b, $a);
        } elseif (strlen($hex_string) === 6) {
            $r      = hexdec($hex_string[0] . $hex_string[1]);
            $g      = hexdec($hex_string[2] . $hex_string[3]);
            $b      = hexdec($hex_string[4] . $hex_string[5]);
            $output = imagecolorallocate($image, $r, $g, $b);
        } elseif (strlen($hex_string) === 3) {
            $r      = hexdec($hex_string[0] . $hex_string[0]);
            $g      = hexdec($hex_string[1] . $hex_string[1]);
            $b      = hexdec($hex_string[2] . $hex_string[2]);
            $output = imagecolorallocate($image, $r, $g, $b);
        }
        if (isset($output)) {
            return $output;
        }

        return false;
    }

    /**
     * Where the mark goes, per the site's position setting: one spot, or a staggered grid
     * covering the photo when it is tiled.
     *
     * @return list<array{0:int,1:int}> top-left corners
     */
    private function watermarkSpots(int $width, int $height, bool $tiled): array
    {
        if ($tiled) {
            // Odd rows shift by half a step so the marks stagger; the rows sit centred top to bottom.
            $stepX = (int)ceil($width * self::WATERMARK_TILE_STEP_X);
            $stepY = (int)ceil($height * self::WATERMARK_TILE_STEP_Y);
            $spots = array();
            for ($row = 0, $y = (int)(($this->height % $stepY) / 2); $y < $this->height; $row++, $y += $stepY) {
                for ($x = $row % 2 === 1 ? -intdiv($stepX, 2) : 0; $x < $this->width; $x += $stepX) {
                    $spots[] = array($x, $y);
                }
            }

            return $spots;
        }

        // Edge marks keep a small gap from the edge, so a crop or rounded card does not cut them.
        $gap    = (int)round(min($this->width, $this->height) * self::WATERMARK_GAP);
        $right  = $this->width - $width - $gap;
        $bottom = $this->height - $height - $gap;
        $middle = intdiv($this->width - $width, 2);
        $centre = intdiv($this->height - $height, 2);
        $spot   = match ((string)osc_watermark_place()) {
            'tl'    => array($gap, $gap),
            'tc'    => array($middle, $gap),
            'tr'    => array($right, $gap),
            'ml'    => array($gap, $centre),
            'mr'    => array($right, $centre),
            'bl'    => array($gap, $bottom),
            'bc'    => array($middle, $bottom),
            'br'    => array($right, $bottom),
            default => array($middle, $centre),
        };

        return array($spot);
    }

    /**
     * Apply watermark on image from uploaded image
     *
     * @return $this
     */
    public function doWatermarkImage()
    {
        try {
            $this->doWatermark();
        } catch (ImagickException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        return $this;
    }
}
