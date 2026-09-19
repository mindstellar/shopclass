<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins that "keep the photo at its full size" re-encodes rather than copies.
 *
 * A copy stored the upload byte for byte, so anything appended after the image survived
 * on disk under an image extension. Both upload paths now run the file back through
 * ImageProcessing, which writes only pixels.
 *
 * DB-free.  Usage: php tests/keep-original-reencode.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

if (!function_exists('osc_get_preference')) {
    function osc_get_preference($key, $section = 'osclass')
    {
        return '';
    }
}

// GD, so the assertions hold on a box without Imagick as well as one with it.
if (!function_exists('osc_use_imagick')) {
    function osc_use_imagick()
    {
        return false;
    }
}

/** The filters saveToFile() consults; no plugin is loaded here. */
if (!class_exists('Plugins')) {
    class Plugins
    {
        public static function applyFilter($hook, $content = '', ...$args)
        {
            return $content;
        }
    }
}

$dir = sys_get_temp_dir() . '/koreenc-' . getmypid();
@mkdir($dir, 0777, true);

// A real 40x30 JPEG, then a marker appended after its end. That is the shape of a
// polyglot: still a valid image to every decoder, carrying arbitrary trailing bytes.
$im = imagecreatetruecolor(40, 30);
imagefilledrectangle($im, 0, 0, 39, 29, imagecolorallocate($im, 10, 120, 200));
$clean = $dir . '/clean.jpg';
imagejpeg($im, $clean, 90);
imagedestroy($im);

$marker = '<?php /*SHOPCLASS_TRAILING_MARKER*/ ?>';
$poly   = $dir . '/poly.jpg';
file_put_contents($poly, file_get_contents($clean) . $marker);

harness_section('The fixture is a real polyglot');

check('the appended bytes are in the source file', strpos(file_get_contents($poly), $marker) !== false);
check('and it still decodes as an image', is_array(@getimagesize($poly)), 'getimagesize refused it');

harness_section('A copy keeps the trailing bytes (the old behaviour)');

$copied = $dir . '/copied.jpg';
copy($poly, $copied);
check('copy() carries the marker through', strpos(file_get_contents($copied), $marker) !== false);

harness_section('Re-encoding drops them');

$out = $dir . '/original.jpg';
ImageProcessing::fromFile($poly)->autoRotate()->saveToFile($out, 'jpg');

$written = file_get_contents($out);
check('the re-encoded file exists and is not empty', $written !== false && $written !== '');
pin('no trailing bytes survive', false, strpos($written, $marker) !== false);
check('it is still a valid image', is_array(@getimagesize($out)), 'getimagesize refused the output');

$size = @getimagesize($out);
pin('the full size is kept, not resized', array(40, 30), array($size[0] ?? 0, $size[1] ?? 0));

harness_section('Both upload paths re-encode');

foreach (array(
    'oc-includes/osclass/classes/actions/ItemActions.php',
    'oc-includes/osclass/classes/storage/ResourceUploader.php',
) as $file) {
    $src = file_get_contents(ABS_PATH . $file);
    // The raw upload temp must never be handed to osc_copy(). Copying a derived path
    // ($tmpName . '_normal') is fine -- that file was written by ImageProcessing.
    check(
        $file . ' never copies the raw upload anywhere',
        preg_match('/osc_copy\(\s*\$tmp(Name|File)\s*,/', $src) !== 1,
        'osc_copy() is still handed the raw upload temp'
    );
    check(
        $file . ' re-encodes the original instead',
        strpos($src, "_original.' . \$extension") !== false
        && preg_match('/ImageProcessing::fromFile\(\$tmp\w*\)->autoRotate\(\)\s*\n?\s*->?\s*saveToFile/', $src) === 1,
        'no ImageProcessing::fromFile(...)->autoRotate()->saveToFile() found'
    );
}

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
