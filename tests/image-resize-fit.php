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
 * Pins ImageProcessing::resizeTo() in fit mode (force aspect, no upscale): the canvas is the
 * scaled image, so a small logo keeps its size and shape. The listing photo mode, which pads
 * onto the target canvas, must not change. Runs with GD, and with Imagick when it is loaded.
 *
 * Usage: php tests/image-resize-fit.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

$GLOBALS['imagick'] = false;
function osc_use_imagick()
{
    return $GLOBALS['imagick'];
}

function osc_force_aspect_image()
{
    return false;
}

if (!function_exists('osc_get_preference')) {
    function osc_get_preference($key, $section = 'osclass')
    {
        return '';
    }
}

$dir = sys_get_temp_dir() . '/osc-resize-fit-' . getmypid();
@mkdir($dir);

$source = static function (int $w, int $h) use ($dir): string {
    $path = $dir . "/src-{$w}x{$h}.png";
    $im   = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
    imagepng($im, $path);
    imagedestroy($im);

    return $path;
};

$size = static function (string $file, int $w, int $h, ?bool $force, bool $upscale): string {
    $img = ImageProcessing::fromFile($file)->resizeTo($w, $h, $force, $upscale);

    return $img->getWidth() . 'x' . $img->getHeight();
};

$engines = array('GD' => false);
if (extension_loaded('imagick')) {
    $engines['Imagick'] = true;
}

foreach ($engines as $engine => $imagick) {
    $GLOBALS['imagick'] = $imagick;
    harness_section($engine . ': fit mode');

    pin('a small wide logo keeps its size', '240x80', $size($source(240, 80), 1600, 1600, true, false));
    pin('a small tall logo keeps its size', '80x240', $size($source(80, 240), 1600, 1600, true, false));
    pin('a large wide logo scales down in proportion', '800x200', $size($source(1200, 300), 800, 800, true, false));
    pin('a large tall logo scales down in proportion', '200x800', $size($source(300, 1200), 800, 800, true, false));

    harness_section($engine . ': listing photo mode is unchanged');

    pin('padding keeps the target canvas', '640x480', $size($source(240, 80), 640, 480, false, true));
    pin('force aspect with upscale crops the canvas to the scaled height', '640x214', $size($source(240, 80), 640, 480, true, true));
}

if (isset($engines['Imagick'])) {
    $GLOBALS['imagick'] = true;
    harness_section('Imagick: a phone photo stored on its side');

    // 1200x900 with EXIF Orientation 6, as a phone saves a portrait photo.
    $flat = new Imagick();
    $flat->newImage(1200, 900, 'red');
    $flat->setImageFormat('jpeg');
    $tiff  = "MM\x00\x2a\x00\x00\x00\x08" . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, 6, 0) . pack('N', 0);
    $exif  = "Exif\x00\x00" . $tiff;
    $jpeg  = (string) $flat->getImageBlob();
    $phone = $dir . '/phone.jpg';
    file_put_contents($phone, substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));

    $upright = ImageProcessing::fromFile($phone)->autoRotate();
    pin('turning it upright swaps the sides', '900x1200', $upright->getWidth() . 'x' . $upright->getHeight());
    $upright->autoRotate();
    pin('turning it again leaves it upright', '900x1200', $upright->getWidth() . 'x' . $upright->getHeight());

    $kept = $dir . '/phone-kept.jpg';
    ImageProcessing::fromFile($phone)->autoRotate()->saveToFile($kept, 'jpeg');
    $keptIm = new Imagick($kept);
    pin('saved without a resize, it stays upright with no padding', '900x1200', $keptIm->getImageWidth() . 'x' . $keptIm->getImageHeight());
    $fitted = ImageProcessing::fromFile($phone)->autoRotate()->resizeTo(640, 480, true, true);
    pin('force aspect gives a portrait canvas with no padding', '360x480', $fitted->getWidth() . 'x' . $fitted->getHeight());

    $out = $dir . '/phone-out.jpg';
    ImageProcessing::fromFile($phone)->autoRotate()->resizeTo(640, 480, false, true)->saveToFile($out, 'jpeg');
    $read  = new Imagick($out);
    $white = static fn (int $x): bool => $read->getImagePixelColor($x, 240)->getColor()['g'] > 200;
    pin('padding is centred, on both sides', 'white red white', implode(' ', array_map(static fn (int $x): string => $white($x) ? 'white' : 'red', array(2, 320, 637))));
}

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
