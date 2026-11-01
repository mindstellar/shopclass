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
 * Pins how ImageProcessing reads EXIF with GD: a phone photo is turned upright, a broken EXIF
 * block raises no warning, a JPEG whose EXIF read fails still opens, and a server without the
 * exif extension still opens JPEGs. Also
 * pins that show() prints the image under both engines, WebP included.
 *
 * Usage: php tests/image-orient.php
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

// Child run: open a JPEG on a PHP started without exif_read_data().
if (($argv[1] ?? '') === '--open') {
    $img = ImageProcessing::fromFile($argv[2])->autoRotate();
    echo (function_exists('exif_read_data') ? 'exif ' : 'no-exif ') . $img->getWidth() . 'x' . $img->getHeight();
    exit(0);
}

$dir = sys_get_temp_dir() . '/osc-image-orient-' . getmypid();
@mkdir($dir);

// A 120x90 JPEG with the given bytes as its APP1 (EXIF) block.
$jpeg = static function (string $name, string $app1) use ($dir): string {
    $im = imagecreatetruecolor(120, 90);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
    ob_start();
    imagejpeg($im);
    $bytes = (string) ob_get_clean();
    $path  = $dir . '/' . $name;
    file_put_contents($path, substr($bytes, 0, 2) . "\xff\xe1" . pack('n', strlen($app1) + 2) . $app1 . substr($bytes, 2));

    return $path;
};

$tiff  = "MM\x00\x2a\x00\x00\x00\x08" . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, 6, 0) . pack('N', 0);
$phone = $jpeg('phone.jpg', "Exif\x00\x00" . $tiff);
$bad   = $jpeg('bad.jpg', "Exif\x00\x00MM\x00\x2a\x00\x00\xff\xff\xff\xff" . str_repeat("\xff", 16));

harness_section('GD: EXIF');

if (function_exists('exif_read_data')) {
    $img = ImageProcessing::fromFile($phone)->autoRotate();
    pin('a phone photo stored on its side is turned upright', '90x120', $img->getWidth() . 'x' . $img->getHeight());
}

$warnings = array();
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    if ((error_reporting() & $no) !== 0) {
        $warnings[] = $msg;
    }

    return true;
});
$img = ImageProcessing::fromFile($bad)->autoRotate();
restore_error_handler();
pin('a broken EXIF block raises no warning', array(), $warnings);
pin('and the photo is kept as it is', '120x90', $img->getWidth() . 'x' . $img->getHeight());

if (function_exists('exif_read_data')) {
    // A marker of length 1 before the scan makes exif_read_data() return false; GD reads past it.
    $plain = (string) file_get_contents($jpeg('plain.jpg', 'x'));
    $sos   = (int) strpos($plain, "\xff\xda");
    $failed = $dir . '/exif-false.jpg';
    file_put_contents($failed, substr($plain, 0, $sos) . "\xff\xe1\x00\x01" . substr($plain, $sos));
    pin('a JPEG whose EXIF read fails is the test case', false, @exif_read_data($failed));
    $img = ImageProcessing::fromFile($failed);
    pin('it still opens', '120x90', $img->getWidth() . 'x' . $img->getHeight());
    pin('and its EXIF is an empty array', array(), (new ReflectionProperty($img, 'exif'))->getValue($img));
}

$cmd = escapeshellarg(PHP_BINARY) . ' -d disable_functions=exif_read_data -d display_errors=stderr '
    . escapeshellarg(__FILE__) . ' --open ' . escapeshellarg($phone) . ' 2>&1';
pin('without the exif extension a JPEG still opens', 'no-exif 120x90', trim((string) shell_exec($cmd)));

harness_section('show()');

$png = $dir . '/flat.png';
$im  = imagecreatetruecolor(40, 30);
imagepng($im, $png);
$webp = $dir . '/flat.webp';
imagewebp($im, $webp);

$shown = static function (string $file): string {
    // The CLI has sent output already, so header() warns; only the printed bytes matter here.
    set_error_handler(static fn (int $no, string $msg): bool => str_starts_with($msg, 'Cannot modify header'));
    ob_start();
    ImageProcessing::fromFile($file)->show();
    $bytes = (string) ob_get_clean();
    restore_error_handler();

    return substr($bytes, 0, 4) . (substr($bytes, 8, 4) === 'WEBP' ? ' WEBP' : '');
};

$engines = array('GD' => false);
if (extension_loaded('imagick')) {
    $engines['Imagick'] = true;
}
foreach ($engines as $engine => $imagick) {
    $GLOBALS['imagick'] = $imagick;
    pin($engine . ' prints a PNG', "\x89PNG", $shown($png));
    pin($engine . ' prints a JPEG', "\xff\xd8\xff", substr($shown($phone), 0, 3));
    if (ImageProcessing::canWriteWebp($imagick)) {
        pin($engine . ' prints a WebP', 'RIFF WEBP', $shown($webp));
    }
}

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
