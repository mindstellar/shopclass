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
 * Pins the ImageMagick colour path: a CMYK or colour-profiled photo comes out in sRGB.
 * Skipped where ImageMagick is not loaded.
 *
 * Usage: php tests/image-colour.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

$GLOBALS['imagick'] = true;
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

if (!extension_loaded('imagick')) {
    echo "SKIP  ImageMagick is not loaded\n";
    exit(harness_result());
}

$dir = sys_get_temp_dir() . '/osc-image-colour-' . getmypid();
@mkdir($dir);
$gd  = static function (string $path): array {
    $im = imagecreatefromstring((string)file_get_contents($path));
    $c  = imagecolorat($im, 5, 5);

    return array(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
};

harness_section('Colour');
$cmyk = new Imagick();
$cmyk->newImage(20, 20, 'red');
$cmyk->transformImageColorspace(Imagick::COLORSPACE_CMYK);
$cmyk->setImageFormat('jpeg');
file_put_contents($dir . '/cmyk.jpg', $cmyk->getImageBlob());
ImageProcessing::fromFile($dir . '/cmyk.jpg')->saveToFile($dir . '/cmyk-out.jpg', 'jpeg');
[$r, $g, $b] = $gd($dir . '/cmyk-out.jpg');
check('a CMYK photo comes out red, not with inverted colours', $r > 200 && $g < 60 && $b < 60, "rgb($r,$g,$b)");

$icc = new Imagick();
$icc->newImage(20, 20, 'blue');
$icc->setImageFormat('jpeg');
$icc->profileImage('icc', (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/icc/sRGB-v2-micro.icc'));
file_put_contents($dir . '/icc.jpg', $icc->getImageBlob());
ImageProcessing::fromFile($dir . '/icc.jpg')->saveToFile($dir . '/icc-out.jpg', 'jpeg');
[$r, $g, $b] = $gd($dir . '/icc-out.jpg');
check('a photo with a colour profile is converted and keeps its colour', $b > 200 && $r < 60, "rgb($r,$g,$b)");
$out = new Imagick($dir . '/icc-out.jpg');
pin('and the saved photo carries no profile', array(), $out->getImageProfiles('icc', false));

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
