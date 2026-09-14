<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
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

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
