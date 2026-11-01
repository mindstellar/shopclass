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
 * Pins the ImageProcessing shim: it reports itself deprecated since 7.1.0 through the
 * d_function_run hook, once per photo opened and once per static helper, names a replacement in
 * mindstellar\image, tags the class and every public method @deprecated 7.1.0, and gives the
 * same answers as the Processor it hands each step to.
 *
 * Usage: php tests/image-shim.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\image\Formats;
use mindstellar\image\Processor;

function osc_use_imagick()
{
    return false;
}
function osc_force_aspect_image()
{
    return false;
}
function osc_get_preference($key, $section = 'osclass')
{
    return '';
}

$fired = array();
Plugins::addHook('d_function_run', static function ($function, $replacement, $version) use (&$fired): void {
    $fired[] = "$function|$version|$replacement";
});

$dir  = sys_get_temp_dir() . '/osc-image-shim-' . getmypid();
@mkdir($dir);
$file = $dir . '/photo.png';
imagepng(imagecreatetruecolor(30, 20), $file);

harness_section('deprecation');

$fired = array();
$img   = ImageProcessing::fromFile($file);
pin('fromFile() reports itself once', array('ImageProcessing::fromFile|7.1.0|mindstellar\image\Processor::open()'), $fired);

$fired = array();
new ImageProcessing($file);
pin('the constructor reports itself', array('ImageProcessing::__construct|7.1.0|mindstellar\image\Processor::open()'), $fired);

$fired = array();
$img->resizeTo(10, 10)->autoRotate()->saveToFile($dir . '/out.png');
pin('working on an open photo reports nothing more', array(), $fired);

$statics = array(
    'parseSize'                    => array('640x480'),
    'maxPixels'                    => array(),
    'memoryPixels'                 => array(1024, 0),
    'pixelCount'                   => array($file),
    'imageInfo'                    => array($file),
    'usesImagick'                  => array(),
    'canWriteWebp'                 => array(false),
    'watermarkOpacity'             => array(50),
    'createWatermarkImageFromText' => null,
);
foreach ($statics as $method => $args) {
    if ($args === null) {
        continue;
    }
    $fired = array();
    ImageProcessing::$method(...$args);
    check("$method() reports itself with a mindstellar\\image replacement", count($fired) === 1
        && str_starts_with($fired[0], "ImageProcessing::$method|7.1.0|mindstellar\\image\\"), implode(', ', $fired));
}

harness_section('@deprecated tags');

$class = new ReflectionClass(ImageProcessing::class);
check('the class is tagged', str_contains((string) $class->getDocComment(), '@deprecated 7.1.0 Use mindstellar\image\\'));
$untagged = array();
foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    if (in_array($method->getName(), array('__clone', '__destruct'), true)) {
        continue;
    }
    if (!str_contains((string) $method->getDocComment(), '@deprecated 7.1.0 Use mindstellar\image\\')) {
        $untagged[] = $method->getName();
    }
}
pin('every public method is tagged', array(), $untagged);
$statics['createWatermarkImageFromText'] = array();
$static = array_map(static fn (ReflectionMethod $m): string => $m->getName(), array_filter(
    $class->getMethods(ReflectionMethod::IS_PUBLIC),
    static fn (ReflectionMethod $m): bool => $m->isStatic() && $m->getName() !== 'fromFile'
));
sort($static);
$listed = array_keys($statics);
sort($listed);
pin('every static helper is checked above', $listed, $static);

harness_section('same answers as the new layer');

$p = Processor::site();
pin('pixelCount() is Processor::pixels()', $p->pixels($file), ImageProcessing::pixelCount($file));
pin('Processor::pixels() of a file that is not a photo is 0', 0, $p->pixels(__FILE__));
pin('maxPixels() is Processor::maxPixels()', $p->maxPixels(), ImageProcessing::maxPixels());
pin('watermarkOpacity() is Processor::opacity()', array(100, 10, 55, 100), array_map(array(Processor::class, 'opacity'), array('', 3, '55', 140)));
pin('WATERMARK_OPACITY_MIN is Processor::OPACITY_MIN', Processor::OPACITY_MIN, ImageProcessing::WATERMARK_OPACITY_MIN);
pin('getExt() and getMime() of a PNG', 'png image/png', $img->getExt() . ' ' . $img->getMime());
pin('Formats::mime() reads jpg as JPEG', 'image/jpeg', Formats::mime('JPG'));
pin('Formats::extension() of each photo type', array('jpg', 'png', 'gif', 'webp', null), array_map(
    array(Formats::class, 'extension'),
    array('image/jpeg', 'IMAGE/PNG', 'image/gif', 'image/webp', 'image/svg+xml')
));

array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

exit(harness_result());
