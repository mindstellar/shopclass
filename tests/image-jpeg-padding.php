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
 * GD photos keep their background: a padded photo saved as JPEG keeps white padding in every
 * size, and a see-through PNG stays see-through when the upload step saves it again.
 *
 * Usage: php tests/image-jpeg-padding.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

function osc_use_imagick()
{
    return false;
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

$dir = sys_get_temp_dir() . '/osc-jpeg-padding-' . getmypid();
@mkdir($dir);

// A square red photo: the 640x480 normal size pads it at the sides.
$src = $dir . '/square.jpg';
$im  = imagecreatetruecolor(500, 500);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
imagejpeg($im, $src, 90);

$normal  = ImageProcessing::fromFile($src)->resizeTo(640, 480);
$preview = (clone $normal)->resizeTo(480, 340);
$normal->saveToFile($dir . '/normal.jpg', 'jpg');
$preview->saveToFile($dir . '/preview.jpg', 'jpg');

$bright = static function (string $file, int $x, int $y): int {
    $im = imagecreatefromjpeg($file);
    $c  = imagecolorat($im, $x, $y);

    return min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
};

harness_section('GD, JPEG, a photo padded to the normal size');
check('the normal size pads with white', $bright($dir . '/normal.jpg', 30, 240) > 240);
check('a preview cut from it keeps that padding white, not black', $bright($dir . '/preview.jpg', 40, 170) > 240);
check('and its own padding is white too', $bright($dir . '/preview.jpg', 5, 170) > 240);

harness_section('GD, PNG, the upload step saves the photo again');
// The listing form's upload saves the photo to a staging file before any resize.
$logo = $dir . '/logo.png';
$im   = imagecreatetruecolor(100, 100);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledellipse($im, 50, 50, 40, 40, imagecolorallocate($im, 200, 40, 40));
imagepng($im, $logo);
ImageProcessing::fromFile($logo)->autoRotate()->saveToFile($dir . '/staged.png', 'png');
$alpha = (imagecolorat(imagecreatefrompng($dir . '/staged.png'), 2, 2) >> 24) & 127;
check('a see-through corner stays see-through, not solid black', $alpha === 127);

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

exit(harness_result());
