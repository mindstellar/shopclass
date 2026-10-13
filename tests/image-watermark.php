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
 * Pins the watermark: drawn for the normal image size, scaled down with smaller sizes, never
 * over 40% of a photo's width or height, and left off when too small to read. Each size gets
 * its own mark. Also its nine places and tiling, its opacity, and outlined text with no box.
 * Runs with GD, and with Imagick when it is loaded.
 *
 * Usage: php tests/image-watermark.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\storage\ResourceUploader;

$dir = sys_get_temp_dir() . '/osc-watermark-' . getmypid();
@mkdir($dir);

$GLOBALS['imagick'] = false;
$GLOBALS['place']   = 'br';
$GLOBALS['opacity'] = 100;
$GLOBALS['text']    = '';
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
    return $GLOBALS['text'] !== '';
}
function osc_is_watermark_image()
{
    return $GLOBALS['text'] === '';
}
function osc_watermark_text()
{
    return $GLOBALS['text'];
}
function osc_watermark_text_color()
{
    return '#ffffff';
}
function osc_watermark_opacity()
{
    return $GLOBALS['opacity'];
}
function osc_watermark_place()
{
    return $GLOBALS['place'];
}
function osc_keep_original_image()
{
    return false;
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

// The text options and the drawn text's file name, kept in memory instead of the database.
class Preference
{
    private static ?Preference $instance = null;
    /** @var array<string,string> */
    private array $values = array('watermark_text_options' => '{"background_color":"#000000"}');

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function get($key)
    {
        return $this->values[$key] ?? '';
    }

    public function replace($key, $value)
    {
        $this->values[$key] = (string)$value;
    }

    public function toArray()
    {
        return $this->values;
    }
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

// The red value of one pixel: 255 is the white photo, 0 a solid black mark.
$red = static function (string $file, int $x, int $y): int {
    $im = imagecreatefrompng($file);
    imagepalettetotruecolor($im);

    return imagecolorat($im, $x, $y) >> 16 & 0xFF;
};

// The share of the pixels inside the dark box that are dark.
$filled = static function (string $file) use ($box): float {
    if (!preg_match('/^(\d+)x(\d+)@(\d+),(\d+)$/', $box($file), $m)) {
        return 0.0;
    }
    $im = imagecreatefrompng($file);
    imagepalettetotruecolor($im);
    $dark = 0;
    for ($x = (int)$m[3]; $x < $m[3] + $m[1]; $x++) {
        for ($y = (int)$m[4]; $y < $m[4] + $m[2]; $y++) {
            $dark += (imagecolorat($im, $x, $y) >> 16 & 0xFF) < 128 ? 1 : 0;
        }
    }

    return $dark / ($m[1] * $m[2]);
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
    $GLOBALS['place'] = 'tc';
    pin('top centre', '200x30@220,10', $stamp(640, 480));
    $GLOBALS['place'] = 'bc';
    pin('bottom centre', '200x30@220,440', $stamp(640, 480));
    $GLOBALS['place'] = 'ml';
    pin('middle left', '200x30@10,225', $stamp(640, 480));
    $GLOBALS['place'] = 'mr';
    pin('middle right', '200x30@430,225', $stamp(640, 480));

    $GLOBALS['place'] = 'tile';
    $stamp(640, 480);
    $tiles = $box($dir . '/out.png');
    check('a tiled mark reaches across the photo: ' . $tiles, (bool)preg_match('/^(6[0-3]\d|640)x(4[0-7]\d|480)@/', $tiles));
    $share = $filled($dir . '/out.png');
    check('but leaves most of it clear: ' . round($share, 2), $share > 0.05 && $share < 0.5);
    $GLOBALS['place'] = 'br';

    harness_section($engine . ': opacity and text');

    $GLOBALS['opacity'] = 50;
    $stamp(640, 480);
    $half = $red($dir . '/out.png', 530, 455);
    check('a mark at 50% opacity lets half the photo through: ' . $half, $half >= 118 && $half <= 138);
    $GLOBALS['opacity'] = 100;
    $stamp(640, 480);
    pin('at 100% it is solid', 0, $red($dir . '/out.png', 530, 455));

    $GLOBALS['text'] = 'I I';
    $stamp(640, 480);
    $text = $filled($dir . '/out.png');
    check('white text is drawn with a dark outline: ' . $box($dir . '/out.png'), $text > 0);
    check('and no solid box behind it: ' . round($text, 2), $text < 0.8);
    $GLOBALS['text'] = '';

    harness_section($engine . ': every size');

    $normal = ImageProcessing::fromFile($photo(640, 480));
    $copy   = clone $normal;
    $copy->doSiteWatermark()->saveToFile($dir . '/copy.png', 'png');
    $normal->saveToFile($dir . '/clean.png', 'png');
    pin('stamping a copy leaves the original clean', 'none', $box($dir . '/clean.png'));
    $GLOBALS['place'] = 'br';

    $files = array('' => $dir . '/n.png', '_preview' => $dir . '/p.png', '_thumbnail' => $dir . '/t.png');
    $sizes = array($files['_preview'] => '480x360', $files['_thumbnail'] => '240x180');
    $write = static function (bool $mark, array $unmarked = array()) use ($photo, $files, $sizes): void {
        ResourceUploader::writeSizes(ImageProcessing::fromFile($photo(640, 480)), '640x480', $files[''], $sizes, 'png', $mark, false, $unmarked);
    };
    $write(true);
    pin('the normal size is stamped', '200x30@430,440', $box($files['']));
    pin('the preview gets its own scaled mark', '150x23@323,330', $box($files['_preview']));
    pin('a size not listed as unmarked gets its own scaled mark', '75x11@161,165', $box($files['_thumbnail']));

    $write(true, array($files['_thumbnail']));
    pin('the thumbnail listed as unmarked stays clean; the others are stamped', '200x30@430,440|150x23@323,330|none', implode('|', array_map($box, $files)));

    $write(false);
    pin('no sizes are stamped when the mark is off', 'none|none|none', implode('|', array_map($box, $files)));

    // A listing photo upload, as PhotoService processes it: the thumbnail stays clean.
    $process = new ReflectionMethod(\mindstellar\listing\PhotoService::class, 'process');
    $process->setAccessible(true);
    $upload = $dir . '/upload-' . ($GLOBALS['imagick'] ? 'imagick' : 'gd');
    copy($photo(640, 480), $upload);
    $made = $process->invoke(null, $upload)['files'];
    pin('a listing photo upload marks the normal and preview sizes but not the thumbnail', '200x30@430,440|150x23@323,330|none', implode('|', array_map($box, $made)));
}

harness_section('text drawing');

// The outlined text, drawn by one library into a PNG: white text, black outline, see-through.
$drawn = static function (string $method) use ($dir, $box, $filled): string {
    $draw = new ReflectionMethod(ImageProcessing::class, $method);
    $draw->setAccessible(true);
    $file = $dir . '/text-' . $method . '.png';
    $ok   = $draw->invoke(null, $file, 'I I', 30, LIB_PATH . 'assets/fonts/open-sans/OpenSans-Regular.ttf', '#ffffff', '#000000', 3);
    if (!$ok) {
        return 'not drawn';
    }
    $im     = imagecreatefrompng($file);
    $corner = imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha'];
    imagepalettetotruecolor($im);

    return 'drawn, corner alpha ' . $corner . ', outline ' . ($box($file) === 'none' ? 'missing' : 'present');
};
pin('GD draws the outlined text on a see-through background', 'drawn, corner alpha 127, outline present', $drawn('drawTextGd'));
if (extension_loaded('imagick')) {
    pin('so does ImageMagick, for a GD built without FreeType', 'drawn, corner alpha 127, outline present', $drawn('drawTextImagick'));
}

$draw = new ReflectionMethod(ImageProcessing::class, 'drawTextGd');
$draw->setAccessible(true);
pin('GD refuses a font it cannot read', false, $draw->invoke(null, $dir . '/x.png', 'I', 30, $dir . '/missing.ttf', '#fff', '#000', 1));
$draw = new ReflectionMethod(ImageProcessing::class, 'drawTextImagick');
$draw->setAccessible(true);
pin('and so does ImageMagick', false, $draw->invoke(null, $dir . '/x.png', 'I', 30, $dir . '/missing.ttf', '#fff', '#000', 1));

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
