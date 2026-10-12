<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the watermark size: drawn for the normal image size, scaled down with smaller sizes,
 * never over 40% of a photo's width or height, and left off when too small to read. Each size
 * gets its own mark. Runs with GD, and with Imagick when it is loaded.
 *
 * Usage: php tests/image-watermark.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\storage\ResourceUploader;

$dir = sys_get_temp_dir() . '/osc-watermark-' . getmypid();
@mkdir($dir);

$GLOBALS['imagick'] = false;
$GLOBALS['place']   = 'br';
function osc_use_imagick()
{
    return $GLOBALS['imagick'];
}
function osc_force_aspect_image()
{
    return false;
}
function osc_get_preference($key, $section = 'osclass')
{
    return '';
}
function osc_uploads_path()
{
    return $GLOBALS['dir'] . '/';
}
function osc_is_watermark_text()
{
    return false;
}
function osc_is_watermark_image()
{
    return true;
}
function osc_watermark_place()
{
    return $GLOBALS['place'];
}
function osc_normal_dimensions()
{
    return '640x480';
}
function osc_preview_dimensions()
{
    return '480x360';
}
function osc_thumbnail_dimensions()
{
    return '240x180';
}

if (!class_exists('Plugins')) {
    class Plugins
    {
        public static function applyFilter($name, $value)
        {
            return $value;
        }
    }
}

$photo = static function (int $w, int $h) use ($dir): string {
    $path = $dir . "/photo-{$w}x{$h}.png";
    $im   = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagepng($im, $path);

    return $path;
};

$mark = static function (int $w, int $h) use ($dir): void {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
    imagepng($im, $dir . '/watermark.png');
};

// A 200x30 mark that is see-through except for a black 50x10 bar in its middle.
$seeThrough = static function () use ($dir): void {
    $im = imagecreatetruecolor(200, 30);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 75, 10, 124, 19, imagecolorallocatealpha($im, 0, 0, 0, 0));
    imagepng($im, $dir . '/watermark.png');
};

// The box around the dark pixels, as "WxH@X,Y", or "none".
$box = static function (string $file): string {
    $im = imagecreatefrompng($file);
    imagepalettetotruecolor($im);
    $x0 = $y0 = PHP_INT_MAX;
    $x1 = $y1 = -1;
    for ($x = 0; $x < imagesx($im); $x++) {
        for ($y = 0; $y < imagesy($im); $y++) {
            if ((imagecolorat($im, $x, $y) >> 16 & 0xFF) < 128) {
                $x0 = min($x0, $x);
                $y0 = min($y0, $y);
                $x1 = max($x1, $x);
                $y1 = max($y1, $y);
            }
        }
    }

    return $x1 < 0 ? 'none' : ($x1 - $x0 + 1) . 'x' . ($y1 - $y0 + 1) . '@' . $x0 . ',' . $y0;
};

$stamp = static function (int $w, int $h) use ($photo, $box, $dir): string {
    $out = $dir . '/out.png';
    ImageProcessing::fromFile($photo($w, $h))->doSiteWatermark()->saveToFile($out, 'png');

    return $box($out);
};

$engines = array('GD' => false);
if (extension_loaded('imagick')) {
    $engines['Imagick'] = true;
}

foreach ($engines as $engine => $imagick) {
    $GLOBALS['imagick'] = $imagick;
    $GLOBALS['place']   = 'br';

    harness_section($engine . ': size');

    $mark(200, 30);
    pin('a mark on the normal size keeps its size, 2% from the corner', '200x30@430,440', $stamp(640, 480));
    pin('a thumbnail gets the mark scaled to its width', '75x11@161,165', $stamp(240, 180));

    $mark(120, 30);
    pin('a portrait photo filling the normal box\'s height keeps the full mark', '120x30@233,443', $stamp(360, 480));

    $mark(1200, 600);
    pin('a mark wider than the photo is held to 40% of its width', '256x128@374,342', $stamp(640, 480));

    $mark(100, 400);
    pin('a tall mark is held to 40% of the height', '48x192@582,278', $stamp(640, 480));

    $mark(40, 20);
    pin('a mark too small to read is left off', 'none', $stamp(100, 75));

    $mark(400, 20);
    pin('a mark too short to read is left off, though wide enough', 'none', $stamp(240, 180));

    $mark(200, 19);
    pin('a mark scaled to 7 px high is left off', 'none', $stamp(240, 180));
    $mark(200, 22);
    pin('a mark scaled to 8 px high is kept', '75x8@161,168', $stamp(240, 180));

    $seeThrough();
    pin('the see-through part of a mark leaves the photo alone', '50x10@505,450', $stamp(640, 480));

    harness_section($engine . ': place');

    $mark(200, 30);
    $GLOBALS['place'] = 'tl';
    pin('top left keeps the gap', '200x30@10,10', $stamp(640, 480));
    $GLOBALS['place'] = 'tr';
    pin('top right keeps the gap', '200x30@430,10', $stamp(640, 480));
    $GLOBALS['place'] = 'bl';
    pin('bottom left keeps the gap', '200x30@10,440', $stamp(640, 480));
    $GLOBALS['place'] = 'centre';
    pin('centre has no gap', '200x30@220,225', $stamp(640, 480));
    $GLOBALS['place'] = 'br';

    harness_section($engine . ': every size');

    $normal = ImageProcessing::fromFile($photo(640, 480));
    $copy   = clone $normal;
    $copy->doSiteWatermark()->saveToFile($dir . '/copy.png', 'png');
    $normal->saveToFile($dir . '/clean.png', 'png');
    pin('stamping a copy leaves the original clean', 'none', $box($dir . '/clean.png'));
    $GLOBALS['place'] = 'br';

    $files = array('' => $dir . '/n.png', '_preview' => $dir . '/p.png', '_thumbnail' => $dir . '/t.png');
    $sizes = array($files['_preview'] => '480x360', $files['_thumbnail'] => '240x180');
    $write = static function (bool $mark) use ($photo, $files, $sizes): void {
        ResourceUploader::writeSizes(ImageProcessing::fromFile($photo(640, 480)), '640x480', $files[''], $sizes, 'png', $mark);
    };
    $write(true);
    pin('the normal size is stamped', '200x30@430,440', $box($files['']));
    pin('the preview gets its own scaled mark', '150x23@323,330', $box($files['_preview']));
    pin('the thumbnail gets its own scaled mark', '75x11@161,165', $box($files['_thumbnail']));

    $write(false);
    pin('no sizes are stamped when the mark is off', 'none|none|none', implode('|', array_map($box, $files)));
}

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
