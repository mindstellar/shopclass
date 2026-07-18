<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
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
    /** @var \Imagick */
    private $im;
    private $image_info;
    private $ext;
    private $mime;
    private $font;
    private $width;
    private $height;
    private $exif;
    private $watermarked = false;
    private $use_imagick = false;

    /**
     * ImageProcessing constructor.
     *
     * @param $imagePath
     *
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

        $this->image_info = getimagesize($imagePath);

        if (extension_loaded('imagick') && osc_use_imagick()) {
            $this->use_imagick = true;
        }

        if ($this->use_imagick) {
            try {
                $this->im = new Imagick($imagePath);
            } catch (ImagickException $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
            /**
             * Check if image have more frames and get the first frame if it has.
             */
            if ($this->im->getNumberImages() > 1) {
                $this->im->destroy();
                try {
                    $this->im = new Imagick($imagePath . '[0]');
                } catch (ImagickException $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }
            }

            $geometry     = $this->im->getImageGeometry();
            $this->width  = $geometry['width'];
            $this->height = $geometry['height'];
        } else {
            $content      = file_get_contents($imagePath);
            $this->im     = imagecreatefromstring($content);
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
            default:
                $this->ext  = 'jpg';
                $this->mime = 'image/jpeg';
                if (!$this->use_imagick) {
                    $bg = imagecreatetruecolor($this->width, $this->height);
                    imagefill($bg, 0, 0, imagecolorallocatealpha($bg, 255, 255, 255, 127));
                    imagesavealpha($bg, true);
                    imagealphablending($bg, true);
                    imagecopy($bg, $this->im, 0, 0, 0, 0, $this->width, $this->height);
                    imagedestroy($this->im);
                    $this->im = $bg;
                }
                break;
        }
    }

    /**
     * @param $imagePath
     *
     * @return \ImageProcessing
     *
     */
    public static function fromFile($imagePath)
    {
        return new ImageProcessing($imagePath);
    }

    public function __destruct()
    {
        if ($this->use_imagick) {
            $this->im->destroy();
        } else {
            imagedestroy($this->im);
        }
    }

    /**
     * @return string
     */
    public function getExt()
    {
        return $this->ext;
    }

    /**
     * @return string
     */
    public function getMime()
    {
        return $this->mime;
    }

    /**
     * @return int
     */
    public function getWidth()
    {
        return $this->width;
    }

    /**
     * @return int
     */
    public function getHeight()
    {
        return $this->height;
    }

    /**
     * @param      $width
     * @param      $height
     * @param null $force_aspect
     * @param bool $upscale
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
                $width = $newW;
            }
        }

        if ($this->use_imagick) {
            $bg = new Imagick();
            if ($this->ext === 'jpg') {
                $bg->newImage($width, $height, 'white');
            } else {
                $bg->newImage($width, $height, 'none');
            }
            $this->im->thumbnailImage($width, $height, true);
            $bg->compositeImage(
                $this->im,
                imagick::COMPOSITE_OVER,
                floor(($width - $newW) / 2),
                floor(($height - $newH) / 2)
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
                floor(($width - $newW) / 2),
                floor(($height - $newH) / 2),
                0,
                0,
                $newW,
                $newH,
                $this->width,
                $this->height
            );
            imagedestroy($this->im);
            $this->im = $newIm;
        }
        $this->width  = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * @param      $imagePath
     * @param null $ext
     *
     */
    public function saveToFile($imagePath, $ext = null)
    {
        if (file_exists($imagePath) && !is_writable($imagePath)) {
            throw new RuntimeException("$imagePath is not writable!");
        }

        if ($ext === null) {
            $ext = $this->ext;
        }

        if ($ext !== 'png' && $ext !== 'gif') {
            $ext = 'jpeg';
        }

        if ($this->use_imagick) {
            try {
                if ($ext === 'jpeg' && ($this->ext !== 'jpeg' && $this->ext !== 'jpg')) {
                    $bg = new Imagick();
                    $bg->newImage($this->width, $this->height, 'white');
                    $this->im->thumbnailImage($this->width, $this->height, true);
                    $bg->compositeImage($this->im, imagick::COMPOSITE_OVER, 0, 0);
                    $this->im  = $bg;
                    $this->ext = 'jpeg';
                }
                $this->im->setImageDepth(8);
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
                    imagepng($this->im, $imagePath, 0);
                    break;
                default:
                    if (($ext === 'jpeg' && ($this->ext !== 'jpeg' && $this->ext !== 'jpg')) || $this->watermarked) {
                        $this->ext = 'jpeg';
                    }
                    imagejpeg($this->im, $imagePath);
                    break;
            }
        }
    }

    /**
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
     * @param string $watermark_text
     * @param string $font_color
     * @param int    $font_size
     * @param null   $a_watermark_options
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
     * @param string $watermark_text
     * @param string $font_color
     * @param int    $font_size
     * @param null   $aOptions
     *
     * @return $this
     * @throws \ImagickException
     */
    private function doWatermark(
        $watermark_text = null,
        $font_color = null,
        $font_size = null,
        $aOptions = null
    ) {
        $this->watermarked = true;
        if ($watermark_text !== null && $watermark_text) {
            $path_watermark = osc_uploads_path() . Preference::newInstance()->get('watermark_text_image_name');
            if (!file_exists($path_watermark)) {
                $path_watermark = self::createWatermarkImageFromText(
                    $watermark_text,
                    $font_color,
                    $font_size,
                    $aOptions
                );
            }
        } else {
            $path_watermark = osc_uploads_path() . 'watermark.png';
        }

        if ($this->use_imagick) {
            $wm                 = new Imagick($path_watermark);
            $watermark_geometry = $wm->getImageGeometry();
            $watermark_height   = $watermark_geometry['height'];
            $watermark_width    = $watermark_geometry['width'];

            $this->calculateWatermarkPosition($watermark_width, $watermark_height, $dest_x, $dest_y);
            $this->im->compositeImage($wm, imagick::COMPOSITE_OVER, $dest_x, $dest_y);

            $wm->destroy();
        } else {
            $watermark        = imagecreatefrompng($path_watermark);
            $watermark_width  = imagesx($watermark);
            $watermark_height = imagesy($watermark);

            $this->calculateWatermarkPosition($watermark_width, $watermark_height, $dest_x, $dest_y);
            $this->imageCopyMergeAlpha(
                $this->im,
                $watermark,
                $dest_x,
                $dest_y,
                0,
                0,
                $watermark_width,
                $watermark_height,
                100
            );
            imagedestroy($watermark);
        }

        return $this;
    }

    /**
     * Create and return watermark image from text
     *
     * @param string $watermark_text
     * @param string $font_color
     * @param int    $font_size
     * @param array  $aOptions See below
     *                         array[]
     *                         ['watermark_width']  int value for watermark width
     *                         ['watermark_height'] int value for watermark height
     *                         ['text_offset_x']    int x position of text
     *                         ['text_offset_y']    int y position of text
     *                         ['text_angle']       int angle for text
     *                         ['background_color'] string hexadecimal color value
     *
     * @return string Return PNG image path
     * @throws \ImagickException
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
        if ($font_color === null) {
            $font_color = 'ff0000';
        }

        if ($aOptions === null) {
            $aOptions = json_decode(Preference::newInstance()->get('watermark_text_options'), true);
        }

        $validate_option = static function ($options_array, $option_name, $default_value) {
            if (isset($options_array[$option_name]) && $options_array[$option_name]) {
                return $options_array[$option_name];
            }

            return $default_value;
        };

        $watermark_width  = (int)$validate_option($aOptions, 'watermark_width', 200);
        $watermark_height = (int)$validate_option($aOptions, 'watermark_height', $font_size);
        $text_offset_x    = (int)$validate_option($aOptions, 'text_offset_x', 0);
        $text_offset_y    = (int)$validate_option($aOptions, 'text_offset_y', $watermark_height);
        $text_angle       = (int)$validate_option($aOptions, 'text_angle', 0);
        $background_color = ltrim($validate_option($aOptions, 'background_color', '#000000'), '#');

        $imagickLoaded = extension_loaded('imagick');
        $use_imagick   = Preference::newInstance()->get('use_imagick');

        $watermark_settings_md5 = md5($watermark_text . $font_color . $font_size . json_encode($aOptions) .
            $use_imagick);
        $watermark_filename     = 'watermark_text_' . $watermark_settings_md5 . '.png';

        //Check if image is already generated with same settings (In case it is not saved in preference)
        if (file_exists(osc_uploads_path() . $watermark_filename)) {
            // Image found return image path
            return osc_uploads_path() . $watermark_filename;
        }

        //Check if any image saved in preference
        $pref_watermark_text_image_name = Preference::newInstance()->get('watermark_text_image_name');
        if ($pref_watermark_text_image_name && file_exists(osc_uploads_path() . $pref_watermark_text_image_name)) {
            //Remove it because we will generate a new one.
            unlink(osc_uploads_path() . $pref_watermark_text_image_name);
        }

        // No image is generated create a new one
        // Check if Imagick enabled and loaded or create image with GD library
        if ($imagickLoaded && $use_imagick) {
            // Create some objects
            $imagick    = new Imagick();
            $draw       = new ImagickDraw();
            $background = new ImagickPixel('#' . $background_color);

            $imagick->newImage($watermark_width, $watermark_height, $background);
            $font_color = ltrim($font_color, '#');
            $draw->setFillColor('#' . $font_color);
            $draw->setFont($font_path);
            $draw->setFontSize($font_size * 1.33);
            $imagick->annotateImage($draw, $text_offset_x, $text_offset_y, $text_angle, $watermark_text);
            $imagick->setImageFormat('png');

            //Save Image
            $imagick->writeImage(osc_uploads_path() . $watermark_filename);

            // Clean Memory
            $imagick->destroy();
            $draw->destroy();
            $background->destroy();
        } else {
            $image = imagecreatetruecolor($watermark_width, $watermark_height);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            $background_color_index = self::imageColorAllocateHex($background_color, $image);
            imagefill($image, 0, 0, $background_color_index);
            $text_color_index = self::imageColorAllocateHex($font_color, $image);
            imagettftext(
                $image,
                $font_size,
                $text_angle,
                $text_offset_x,
                $text_offset_y,
                $text_color_index,
                $font_path,
                html_entity_decode($watermark_text, null, 'UTF-8')
            );

            //Write Image
            imagepng($image, osc_uploads_path() . $watermark_filename);

            // Clean memory
            imagedestroy($image);
        }

        // save new image name to preference
        Preference::newInstance()->replace('watermark_text_image_name', $watermark_filename);
        // Reset preferences
        Preference::newInstance()->toArray();

        // return path of new image
        return osc_uploads_path() . $watermark_filename;
    }

    /**
     * Allocate a hex color for an image
     *
     * @param string   $hex_string
     * @param resource $image
     *
     * @return bool|int allocate color or return false
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
     * @param int       $watermark_width
     * @param int       $watermark_height
     * @param int|float $dest_x
     * @param int|float $dest_y
     */
    private function calculateWatermarkPosition($watermark_width, $watermark_height, &$dest_x, &$dest_y)
    {
        switch (osc_watermark_place()) {
            case 'tl':
                $dest_x = 0;
                $dest_y = 0;
                break;
            case 'tr':
                $dest_x = $this->width - $watermark_width;
                $dest_y = 0;
                break;
            case 'bl':
                $dest_x = 0;
                $dest_y = $this->height - $watermark_height;
                break;
            case 'br':
                $dest_x = $this->width - $watermark_width;
                $dest_y = $this->height - $watermark_height;
                break;
            default:
                $dest_x = ($this->width - $watermark_width) / 2;
                $dest_y = ($this->height - $watermark_height) / 2;
                break;
        }
    }

    /**
     * @param      $dst_im
     * @param      $src_im
     * @param      $dst_x
     * @param      $dst_y
     * @param      $src_x
     * @param      $src_y
     * @param      $src_w
     * @param      $src_h
     * @param      $pct
     * @param null $trans
     *
     * @return bool
     */
    private function imageCopyMergeAlpha(
        &$dst_im,
        $src_im,
        $dst_x,
        $dst_y,
        $src_x,
        $src_y,
        $src_w,
        $src_h,
        $pct,
        $trans = null
    ) {
        imagealphablending($dst_im, false);
        imagesavealpha($dst_im, true);

        $dst_w = imagesx($dst_im);
        $dst_h = imagesy($dst_im);

        $src_x = max($src_x, 0);
        $src_y = max($src_y, 0);
        $dst_x = max($dst_x, 0);
        $dst_y = max($dst_y, 0);
        if ($dst_x + $src_w > $dst_w) {
            $src_w = $dst_w - $dst_x;
        }
        if ($dst_y + $src_h > $dst_h) {
            $src_h = $dst_h - $dst_y;
        }

        for ($x_offset = 0; $x_offset < $src_w; $x_offset++) {
            for ($y_offset = 0; $y_offset < $src_h; $y_offset++) {
                $srccolor = imagecolorsforindex($src_im, imagecolorat($src_im, $src_x + $x_offset, $src_y + $y_offset));
                $dstcolor = imagecolorsforindex($dst_im, imagecolorat($dst_im, $dst_x + $x_offset, $dst_y + $y_offset));

                if ($trans === null || ($srccolor !== $trans)) {
                    $src_a = $srccolor['alpha'] * $pct / 100;
                    // blend
                    $src_a = 127 - $src_a;
                    $dst_a = 127 - $dstcolor['alpha'];
                    $dst_r = ($srccolor['red'] * $src_a + $dstcolor['red'] * $dst_a * (127 - $src_a) / 127) / 127;
                    $dst_g = ($srccolor['green'] * $src_a + $dstcolor['green'] * $dst_a * (127 - $src_a) / 127) / 127;
                    $dst_b = ($srccolor['blue'] * $src_a + $dstcolor['blue'] * $dst_a * (127 - $src_a) / 127) / 127;
                    $dst_a = 127 - ($src_a + $dst_a * (127 - $src_a) / 127);
                    $color = imagecolorallocatealpha($dst_im, $dst_r, $dst_g, $dst_b, $dst_a);

                    if (!imagesetpixel($dst_im, $dst_x + $x_offset, $dst_y + $y_offset, $color)) {
                        return false;
                    }
                    imagecolordeallocate($dst_im, $color);
                }
            }
        }

        return true;
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
