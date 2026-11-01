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
 * Pins that mindstellar\image\Processor gives the same result with GdEngine and ImagickEngine:
 * fit sizes, centred padding, all 8 EXIF orientations (ImageMagick also through the shared table),
 * JPEG white behind transparency, PNG alpha, real GIF and WebP files, watermark opacity, failed
 * writes and the pixel guard. Also pins that the container picks GD while ImageMagick is off.
 * Imagick runs only where the extension is loaded.
 *
 * Usage: php tests/image-engine-parity.php
 */

declare(strict_types=1);

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\container\Container;
use mindstellar\image\Engine;
use mindstellar\image\GdEngine;
use mindstellar\image\Image;
use mindstellar\image\ImagickEngine;
use mindstellar\image\Processor;

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

$dir = sys_get_temp_dir() . '/osc-engine-parity-' . getmypid();
@mkdir($dir);

// A PNG drawn by GD, so both engines read the same bytes.
$png = static function (string $name, int $w, int $h, callable $draw) use ($dir): string {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $draw($im, $w, $h);
    $path = "$dir/$name.png";
    imagepng($im, $path);

    return $path;
};

$solid = static fn (int $w, int $h): string => $png("solid-{$w}x{$h}", $w, $h, static function ($im, $w, $h): void {
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 40, 40));
});

// Left half see-through black, right half solid red.
$half = $png('half', 40, 30, static function ($im, $w, $h): void {
    imagefilledrectangle($im, 0, 0, $w / 2 - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, $w / 2, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 40, 40));
});

// A JPEG drawn by $draw and stored with the EXIF orientation given.
$exifJpeg = static function (string $name, int $w, int $h, int $orientation, callable $draw) use ($dir): string {
    $im = imagecreatetruecolor($w, $h);
    $draw($im, $w, $h);
    ob_start();
    imagejpeg($im, null, 95);
    $bytes = (string) ob_get_clean();
    $tiff  = "MM\x00\x2a\x00\x00\x00\x08" . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, $orientation, 0) . pack('N', 0);
    $exif  = "Exif\x00\x00" . $tiff;
    $path  = "$dir/$name.jpg";
    file_put_contents($path, substr($bytes, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($bytes, 2));

    return $path;
};

// A 120x90 JPEG, left half red and right half blue, stored with EXIF Orientation 6 (turn 90 clockwise).
$phone = $exifJpeg('phone', 120, 90, 6, static function ($im): void {
    imagefilledrectangle($im, 0, 0, 59, 89, imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, 60, 0, 119, 89, imagecolorallocate($im, 0, 0, 255));
});

// A 40x20 JPEG per orientation with red, green, blue and yellow corners (top-left, top-right, bottom-left, bottom-right).
$corners = [];
foreach (range(1, 8) as $o) {
    $corners[$o] = $exifJpeg("corners-$o", 40, 20, $o, static function ($im): void {
        imagefilledrectangle($im, 0, 0, 19, 9, imagecolorallocate($im, 255, 0, 0));
        imagefilledrectangle($im, 20, 0, 39, 9, imagecolorallocate($im, 0, 255, 0));
        imagefilledrectangle($im, 0, 10, 19, 19, imagecolorallocate($im, 0, 0, 255));
        imagefilledrectangle($im, 20, 10, 39, 19, imagecolorallocate($im, 255, 255, 0));
    });
}

// The upright size and top-left colour each orientation must give, from the EXIF definitions.
$uprightCorners = [
    1 => '40x20 red', 2 => '40x20 green', 3 => '40x20 yellow', 4 => '40x20 blue',
    5 => '20x40 red', 6 => '20x40 blue', 7 => '20x40 yellow', 8 => '20x40 green',
];

// A white photo and a black mark at half alpha, so multiplied and added opacity differ.
$white = $png('white', 200, 200, static function ($im, $w, $h): void {
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 255, 255, 255));
});
$mark = $png('mark', 50, 50, static function ($im, $w, $h): void {
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 64));
});

// The pixel at x,y of a written file, as [r, g, b, alpha 0-127 with 127 see-through].
$pixel = static function (string $file, int $x, int $y): array {
    $im = imagecreatefromstring((string) file_get_contents($file));
    imagepalettetotruecolor($im);
    $c = imagecolorat($im, $x, $y);

    return [($c >> 16) & 0xff, ($c >> 8) & 0xff, $c & 0xff, ($c >> 24) & 0x7f];
};

// The opaque area's columns and rows, read along the middle row and column.
$opaqueBox = static function (string $file): string {
    $im   = imagecreatefrompng($file);
    imagepalettetotruecolor($im);
    $span = static function (int $count, callable $alpha): string {
        $hit = array_values(array_filter(range(0, $count - 1), static fn (int $i): bool => $alpha($i) < 64));

        return $hit === [] ? 'none' : $hit[0] . '-' . end($hit);
    };
    $w = imagesx($im);
    $h = imagesy($im);

    return $span($w, static fn (int $x): int => (imagecolorat($im, $x, intdiv($h, 2)) >> 24) & 0x7f)
        . ' x ' . $span($h, static fn (int $y): int => (imagecolorat($im, intdiv($w, 2), $y) >> 24) & 0x7f);
};

$GLOBALS['maxPixels'] = null;
Plugins::addHook('image_max_pixels', static fn ($max) => $GLOBALS['maxPixels'] ?? $max);

$engines = ['GD' => new GdEngine()];
if (extension_loaded('imagick')) {
    $engines['Imagick'] = new ImagickEngine();
}

// The colour name of a pixel in a written file.
$colourName = static function (array $c): string {
    [$r, $g, $b] = $c;

    return match (true) {
        $r > 200 && $g > 200 && $b < 60 => 'yellow',
        $r > 200 && $g < 60 && $b < 60  => 'red',
        $g > 200 && $r < 60 && $b < 60  => 'green',
        $b > 200 && $r < 60 && $g < 60  => 'blue',
        default                         => 'other',
    };
};

$orientEngines = [];
if (function_exists('exif_read_data')) {
    $orientEngines['GD'] = new GdEngine();
}
if (extension_loaded('imagick')) {
    $orientEngines['Imagick table'] = new ImagickEngine(false);
    $orientEngines['Imagick autoOrient'] = new ImagickEngine();
}
foreach ($orientEngines as $name => $engine) {
    harness_section("$name: all 8 orientations");
    $p = new Processor($engine);
    foreach ($corners as $o => $file) {
        $upright = $p->orient($p->open($file));
        $out     = "$dir/corners-$o-" . str_replace(' ', '-', $name) . '.png';
        $p->save($upright, $out, 'png');
        pin("orientation $o is upright", $uprightCorners[$o], $upright->width . 'x' . $upright->height . ' ' . $colourName($pixel($out, 2, 2)));
    }
}

harness_section('GD: encode failure');
$gdImage = (new Processor(new GdEngine()))->open($half);
set_error_handler(static fn (): bool => true);
pin('encode is false when GD throws on the path', false, (new GdEngine())->encode($gdImage, "$dir/bad\0.png", 'png', 82, 6));
restore_error_handler();

$size  = static fn (Image $i): string => $i->width . 'x' . $i->height;
$shade = [];

foreach ($engines as $name => $engine) {
    $p = new Processor($engine);

    harness_section("$name: fit sizes");

    pin('a small logo keeps its size with force aspect and no upscale', '240x80', $size($p->resizeTo($p->open($solid(240, 80)), 1600, 1600, true, false)));
    pin('a large logo scales down in proportion', '800x200', $size($p->resizeTo($p->open($solid(1200, 300)), 800, 800, true, false)));
    pin('a tall logo scales down in proportion', '200x800', $size($p->resizeTo($p->open($solid(300, 1200)), 800, 800, true, false)));
    pin('padding keeps the target canvas', '640x480', $size($p->resizeTo($p->open($solid(240, 80)), 640, 480, false, true)));
    pin('force aspect with upscale crops the canvas to the scaled height', '640x214', $size($p->resizeTo($p->open($solid(240, 80)), 640, 480, true, true)));

    harness_section("$name: padding is centred");

    $padded = "$dir/padded-$name.png";
    $p->save($p->resizeTo($p->open($solid(240, 80)), 640, 480, false, false), $padded, 'png');
    pin('a small image sits centred at its own size', '200-439 x 200-279', $opaqueBox($padded));

    harness_section("$name: orientation");

    if ($name === 'GD' && !function_exists('exif_read_data')) {
        echo "SKIP  GD reads no orientation without the exif extension\n";
    } else {
        $upright = $p->orient($p->open($phone));
        pin('a sideways phone photo is turned upright', '90x120', $size($upright));
        $out = "$dir/upright-$name.png";
        $p->save($upright, $out, 'png');
        $top    = $pixel($out, 45, 10);
        $bottom = $pixel($out, 45, 110);
        pin('the stored left side is now the top', 'red blue', ($top[0] > 200 && $top[2] < 60 ? 'red' : 'other') . ' ' . ($bottom[2] > 200 && $bottom[0] < 60 ? 'blue' : 'other'));
        pin('turning it again changes nothing', '90x120', $size($p->orient($upright)));
    }

    harness_section("$name: formats");

    $jpeg = "$dir/half-$name.jpg";
    $p->save($p->open($half), $jpeg, 'JPG');
    pin('a JPEG starts with the JPEG marker', "\xff\xd8", substr((string) file_get_contents($jpeg), 0, 2));
    $behind = $pixel($jpeg, 5, 15);
    pin('a JPEG is white where the image was see-through', true, min($behind[0], $behind[1], $behind[2]) > 240);

    $kept = "$dir/half-$name.png";
    $p->save($p->open($half), $kept, 'png');
    pin('a PNG keeps the see-through half', 127, $pixel($kept, 5, 15)[3]);
    pin('a PNG keeps the solid half', 0, $pixel($kept, 35, 15)[3]);

    $gif = "$dir/half-$name.gif";
    $p->save($p->open($half), $gif, 'gif');
    pin('a GIF is a real GIF', 'GIF8', substr((string) file_get_contents($gif), 0, 4));

    pin('an upper-case extension is read in lower case', 'png', $p->format('PNG'));
    pin('an unknown extension is written as JPEG', 'jpeg', $p->format('bmp'));
    pin('a PNG photo saves as PNG by default', 'png', $p->defaultExt($p->open($half)));
    if ($engine->canWrite('webp')) {
        $webp = "$dir/half-$name.webp";
        $p->save($p->open($half), $webp, 'webp');
        $head = (string) file_get_contents($webp);
        pin('a WebP is a real WebP', 'RIFF WEBP', substr($head, 0, 4) . ' ' . substr($head, 8, 4));
    } else {
        echo "SKIP  $name cannot write WebP\n";
    }

    harness_section("$name: watermark opacity");

    $stamped = $p->watermark($p->open($white), $p->open($mark), '200x200', 'mm', 50);
    $wm      = "$dir/wm-$name.png";
    $p->save($stamped, $wm, 'png');
    $shade[$name] = $pixel($wm, 100, 100)[0];
    // Half-alpha black at 50% opacity covers a quarter: 255 * 0.75 = 191.
    pin('the opacity multiplies the mark\'s own alpha', true, abs($shade[$name] - 191) <= 4);
    pin('the photo outside the mark is untouched', 255, $pixel($wm, 10, 10)[0]);

    harness_section("$name: failures");

    set_error_handler(static fn (): bool => true);
    pin('save is false when the folder is missing', false, $p->save($p->open($half), "$dir/missing/x.png", 'png'));
    restore_error_handler();

    $GLOBALS['maxPixels'] = 100;
    $refused              = '';
    try {
        $p->open($solid(40, 30));
    } catch (RuntimeException $e) {
        $refused = $e->getMessage();
    }
    pin('a photo over the pixel limit is refused', true, str_contains($refused, 'too many pixels'));
    $GLOBALS['maxPixels'] = null;
}

if (count($shade) === 2) {
    harness_section('GD and Imagick agree');
    pin('the watermarked pixel is the same within 4 levels', true, abs($shade['GD'] - $shade['Imagick']) <= 4);
}

harness_section('Container');

$GLOBALS['imagick'] = false;
harness_reset_container();
pin('the engine is GD while ImageMagick is off', GdEngine::class, get_class(Container::root()->get(Engine::class)));
pin('the processor uses that engine', GdEngine::class, get_class(Container::root()->get(Processor::class)->engine()));
if (extension_loaded('imagick')) {
    $GLOBALS['imagick'] = true;
    harness_reset_container();
    pin('the engine is ImageMagick when it is on', ImagickEngine::class, get_class(Container::root()->get(Engine::class)));
}

array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

exit(harness_result());
