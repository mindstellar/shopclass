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
 * Pins ImageProcessing::maxPixels(): with GD it is held to what is left of memory_limit (two
 * copies at 5 bytes a pixel, never below 1 million pixels), no limit leaves the image_max_pixels
 * value, and the filter can still lower it. ImageMagick keeps the filter value. pixelCount()
 * still reads the size.
 *
 * Usage: php tests/image-memory.php
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

$limit = static function (string $memory): int {
    ini_set('memory_limit', $memory);

    return ImageProcessing::maxPixels();
};

harness_section('memoryPixels()');

$mb = 1024 * 1024;
pin('128M with nothing in use allows 13.4 million pixels', 13421772, \mindstellar\image\GdEngine::memoryPixels(128 * $mb, 0));
pin('memory in use is taken off first', 10066329, \mindstellar\image\GdEngine::memoryPixels(128 * $mb, 32 * $mb));
pin('a nearly full limit still allows 1 million', 1000000, \mindstellar\image\GdEngine::memoryPixels(128 * $mb, 127 * $mb));
pin('-1 is no limit', PHP_INT_MAX, \mindstellar\image\GdEngine::memoryPixels(-1, 32 * $mb));
pin('0 is no limit', PHP_INT_MAX, \mindstellar\image\GdEngine::memoryPixels(0, 32 * $mb));

harness_section('GD');

pin('128M is held to what is left after the memory in use', true, $limit('128M') < 13421772);
$fileCost = $limit('128M') - ImageProcessing::maxPixels(16 * $mb);
pin('a 16 MB file takes off about 1.7 million more', true, abs($fileCost - 1677721) < 10000);
pin('no limit keeps the 50 million default', 50000000, $limit('-1'));

Plugins::addHook('image_max_pixels', static fn (): int => 1000000);
pin('the filter still lowers it', 1000000, $limit('256M'));

if (extension_loaded('imagick')) {
    harness_section('Imagick');
    $GLOBALS['imagick'] = true;
    pin('memory_limit is not applied', 1000000, $limit('128M'));
}

harness_section('pixelCount()');

$file = sys_get_temp_dir() . '/osc-image-memory-' . getmypid() . '.png';
imagepng(imagecreatetruecolor(30, 20), $file);
pin('a 30x20 image has 600 pixels', 600, ImageProcessing::pixelCount($file));
pin('a file that is not an image has none', 0, ImageProcessing::pixelCount(__FILE__));
unlink($file);

exit(harness_result());
