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
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use Preference;

/**
 * Draws the text watermark as a PNG in the uploads folder, for Processor::stamp().
 */
final class WatermarkText
{
    /**
     * Draw the text as a PNG: the text in its colour with an outline, on a see-through background
     * cut close around it. The same text drawn before is not drawn again.
     *
     * @param string|null              $color   hex colour, '#' optional; null or '' is red
     * @param int|null                 $size    null reads the watermark_font_size filter
     * @param array<string,mixed>|null $options ['background_color'] the outline colour, hex; null reads the stored options
     *
     * @return string the PNG's path, or '' when neither GD with FreeType nor ImageMagick can draw text
     */
    public static function create(string $text, ?string $color = null, ?int $size = null, ?array $options = null): string
    {
        $font  = (string) \Plugins::applyFilter('watermark_font_path', LIB_PATH . 'assets/fonts/open-sans/OpenSans-Regular.ttf');
        $text  = (string) \Plugins::applyFilter('watermark_text_value', $text);
        $size  = max(1, (int) ($size ?? \Plugins::applyFilter('watermark_font_size', 30)));
        $color = $color === null || $color === '' ? 'ff0000' : $color;
        if ($options === null) {
            $options = json_decode((string) Preference::getInstance()->get('watermark_text_options'), true);
        }
        $outline = is_array($options) && !empty($options['background_color']) ? (string) $options['background_color'] : '#000000';
        $text    = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // 'outline' marks this drawing style, so a text mark drawn the old way is drawn again.
        $name = 'watermark_text_' . md5(implode("\n", ['outline', $text, $color, $size, $outline, $font])) . '.png';
        $path = osc_uploads_path() . $name;
        if (is_file($path)) {
            return $path;
        }
        $previous = (string) Preference::getInstance()->get('watermark_text_image_name');
        if (preg_match('/^watermark_text_[0-9a-f]{32}\.png$/', $previous) && is_file(osc_uploads_path() . $previous)) {
            @unlink(osc_uploads_path() . $previous);
        }

        $stroke = max(1, (int) round($size / 12));
        if (!self::drawGd($path, $text, $size, $font, $color, $outline, $stroke)
            && !self::drawImagick($path, $text, $size, $font, $color, $outline, $stroke)
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
    private static function drawGd(string $path, string $text, int $size, string $font, string $color, string $outline, int $stroke): bool
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

        $outlineColor = self::allocate($outline, $image);
        $textColor    = self::allocate($color, $image);
        $x            = $stroke - $left;
        $y            = $stroke - $top;
        for ($dx = -$stroke; $dx <= $stroke; $dx++) {
            for ($dy = -$stroke; $dy <= $stroke; $dy++) {
                if ($dx * $dx + $dy * $dy <= $stroke * $stroke) {
                    imagettftext($image, $size, 0, $x + $dx, $y + $dy, $outlineColor, $font, $text);
                }
            }
        }
        imagettftext($image, $size, 0, $x, $y, $textColor, $font, $text);

        return imagepng($image, $path);
    }

    /**
     * Draw the outlined text with ImageMagick, for a GD built without FreeType. GD sizes text in
     * points at 96 dpi, so the size is scaled to match.
     */
    private static function drawImagick(string $path, string $text, int $size, string $font, string $color, string $outline, int $stroke): bool
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
                (int) ceil($metrics['textWidth']) + 2 * $stroke + 2,
                (int) ceil($metrics['textHeight']) + 2 * $stroke + 2,
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
            $canvas->clear();

            return $written;
        } catch (ImagickException $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);

            return false;
        }
    }

    /**
     * Allocate a hex colour of 3, 6 or 8 digits ('#' optional; the last two digits are opacity). Black when malformed.
     */
    private static function allocate(string $hex, \GdImage $image): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!ctype_xdigit($hex) || (strlen($hex) !== 6 && strlen($hex) !== 8)) {
            return (int) imagecolorallocate($image, 0, 0, 0);
        }
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 0, 6), 2));
        if (strlen($hex) === 6) {
            return (int) imagecolorallocate($image, (int) $r, (int) $g, (int) $b);
        }
        // GD alpha runs 0 (solid) to 127, the reverse of the 8-bit opacity given.
        $alpha = (255 - (int) hexdec(substr($hex, 6, 2))) >> 1;

        return (int) imagecolorallocatealpha($image, (int) $r, (int) $g, (int) $b, $alpha);
    }
}
