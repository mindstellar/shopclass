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
 * Pins WebP in ImageProcessing: a WebP photo stays WebP with its transparency, a JPEG or PNG
 * can be written as WebP, and resizing keeps the format. Runs with GD, and with Imagick when
 * it is loaded and can write WebP.
 *
 * Usage: php tests/image-webp.php
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

function osc_get_preference($key, $section = 'osclass')
{
    return '';
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

$dir = sys_get_temp_dir() . '/osc-image-webp-' . getmypid();
@mkdir($dir);

// A transparent canvas with an opaque red square in the middle.
$im = imagecreatetruecolor(200, 100);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagefilledrectangle($im, 50, 25, 149, 74, imagecolorallocatealpha($im, 200, 30, 30, 0));
imagewebp($im, $dir . '/alpha.webp', 90);
imagepng($im, $dir . '/alpha.png');
imagejpeg(imagecreatetruecolor(200, 100), $dir . '/photo.jpg', 90);

$read = static function (string $path): array {
    $info = getimagesize($path);
    $gd   = imagecreatefromstring((string) file_get_contents($path));
    $a    = (imagecolorat($gd, 2, 2) >> 24) & 0x7F;

    return array($info['mime'], $info[0] . 'x' . $info[1], $a > 100 ? 'transparent corner' : 'opaque corner');
};

$engines = array();
foreach (array('GD' => false, 'Imagick' => true) as $engine => $imagick) {
    if (ImageProcessing::canWriteWebp($imagick)) {
        $engines[$engine] = $imagick;
    }
}
if ($engines === array()) {
    echo "SKIP  this PHP has no WebP writer\n";
}

foreach ($engines as $engine => $imagick) {
    $GLOBALS['imagick'] = $imagick;
    harness_section($engine);

    $webp = ImageProcessing::fromFile($dir . '/alpha.webp');
    pin('a WebP photo is read as WebP', array('webp', 'image/webp'), array($webp->getExt(), $webp->getMime()));
    $webp->resizeTo(100, 50)->saveToFile($dir . "/out-$engine.webp");
    pin('and saved at its own format keeps its transparency', array('image/webp', '100x50', 'transparent corner'), $read($dir . "/out-$engine.webp"));

    ImageProcessing::fromFile($dir . '/alpha.png')->saveToFile($dir . "/png-$engine.webp", 'webp');
    pin('a PNG written as WebP keeps its transparency', array('image/webp', '200x100', 'transparent corner'), $read($dir . "/png-$engine.webp"));

    ImageProcessing::fromFile($dir . '/photo.jpg')->saveToFile($dir . "/jpg-$engine.webp", 'webp');
    pin('a JPEG can be written as WebP', 'image/webp', $read($dir . "/jpg-$engine.webp")[0]);

    // Resized to a square without Force aspect, a wide photo gets bands above and below.
    ImageProcessing::fromFile($dir . '/photo.jpg')->resizeTo(100, 100)->saveToFile($dir . "/pad-$engine.webp", 'webp');
    pin('a JPEG resized into WebP gets transparent bands', array('image/webp', '100x100', 'transparent corner'), $read($dir . "/pad-$engine.webp"));
    ImageProcessing::fromFile($dir . '/photo.jpg')->resizeTo(100, 100)->saveToFile($dir . "/pad-$engine.jpg", 'jpeg');
    $pad = imagecreatefromjpeg($dir . "/pad-$engine.jpg");
    pin('and into JPEG white ones', 'white', (imagecolorat($pad, 2, 2) & 0xFFFFFF) > 0xF0F0F0 ? 'white' : sprintf('%06x', imagecolorat($pad, 2, 2)));

    ImageProcessing::fromFile($dir . '/alpha.webp')->saveToFile($dir . "/back-$engine.jpg", 'jpeg');
    pin('and a WebP can still be written as JPEG', 'image/jpeg', $read($dir . "/back-$engine.jpg")[0]);
}

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
