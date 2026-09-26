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
 * Pins the two photo upload checks:
 *
 * - AjaxUploader matches the file extension against the allowed list exactly. It used a
 *   substring search, so "x.pn", "x.jp" and a name with no extension passed.
 * - ItemActions reads the image type from the file, not from the browser. A real photo
 *   sent as application/octet-stream was refused.
 *
 * Usage: php tests/upload-checks.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

function osc_allowed_extension()
{
    return 'png, gif,jpg,jpeg,webp';
}

function osc_plugins_path()
{
    return ABS_PATH . 'oc-content/plugins/';
}

$GLOBALS['imagick'] = false;
function osc_use_imagick()
{
    return $GLOBALS['imagick'];
}

function _m($s)
{
    return $s;
}

$GLOBALS['flashes'] = array();
function osc_add_flash_error_message($msg, $section = 'pubMessages')
{
    $GLOBALS['flashes'][] = $msg;
}

$tmpDir = sys_get_temp_dir() . '/osc-upload-checks-' . getmypid();
@mkdir($tmpDir);

$png = $tmpDir . '/real.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));
$webp = $tmpDir . '/real.webp';
file_put_contents($webp, base64_decode('UklGRh4AAABXRUJQVlA4TBEAAAAvAAAAAAdQiirUo/+BiOh/AAA='));
$text = $tmpDir . '/fake.png';
file_put_contents($text, "<?php echo 'not an image';");

/** Stand-in for the uploaded file object the uploader reads. */
final class FakeUploadedFile
{
    public function __construct(private string $name, private string $source)
    {
    }

    public function getOriginalName()
    {
        return $this->name;
    }

    public function getSize()
    {
        return filesize($this->source);
    }

    public function save($path)
    {
        return copy($this->source, $path);
    }
}

$attempt = static function (string $name, string $source) use ($tmpDir): string {
    $uploader = new AjaxUploader(null, 1024 * 1024);
    $prop     = new ReflectionProperty(AjaxUploader::class, 'file');
    $prop->setAccessible(true);
    $prop->setValue($uploader, new FakeUploadedFile($name, $source));
    $target = $tmpDir . '/' . uniqid('up_', true);
    try {
        $uploader->handleUpload($target);

        return 'ok';
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'too large') !== false) {
            return 'too-big';
        }

        return strpos($e->getMessage(), 'invalid extension') !== false ? 'bad-ext' : 'error';
    } finally {
        @unlink($target);
    }
};

harness_section('AjaxUploader extension check');

pin('photo.png is accepted', 'ok', $attempt('photo.png', $png));
pin('upper-case PHOTO.PNG is accepted', 'ok', $attempt('PHOTO.PNG', $png));
pin('an entry with a space in the list still matches (gif)', 'ok', $attempt('photo.gif', $png));
pin('photo.webp is accepted', 'ok', $attempt('photo.webp', $webp));
pin('a partial extension "pn" is refused', 'bad-ext', $attempt('photo.pn', $png));
pin('a partial extension "jp" is refused', 'bad-ext', $attempt('photo.jp', $png));
pin('a name with no extension is refused', 'bad-ext', $attempt('photo', $png));
pin('photo.php is refused', 'bad-ext', $attempt('photo.php', $png));

harness_section('ItemActions reads the image type from the file');

$actions = (new ReflectionClass(ItemActions::class))->newInstanceWithoutConstructor();
$check   = new ReflectionMethod(ItemActions::class, 'checkAllowedExt');
$check->setAccessible(true);
$files = static fn (string $path, string $type): array => array(
    'error'    => array(UPLOAD_ERR_OK),
    'type'     => array($type),
    'tmp_name' => array($path),
);

check('a real PNG sent as image/png passes', $check->invoke($actions, $files($png, 'image/png')));
check('a real PNG sent as application/octet-stream passes', $check->invoke($actions, $files($png, 'application/octet-stream')));
check('a script sent as image/png is refused', !$check->invoke($actions, $files($text, 'image/png')));
check('a script sent as application/octet-stream is refused', !$check->invoke($actions, $files($text, 'application/octet-stream')));

harness_section('UploadMimes: one allowed list, one way of reading a file');

use mindstellar\storage\UploadMimes;

$allowed = UploadMimes::allowed();
check('the allowed list is derived from the configured extensions', in_array('image/png', $allowed, true));
check('...and carries every mime an extension maps to', in_array('image/jpeg', $allowed, true));
check('a type no configured extension maps to is not on it', !in_array('application/x-php', $allowed, true));

pin('a real PNG is read as image/png from its bytes', 'image/png', UploadMimes::detect($png));
pin('a file that is not there has no type', '', UploadMimes::detect('/no/such/file'));
pin('an empty path has no type', '', UploadMimes::detect(''));

check('a real PNG is allowed', UploadMimes::isAllowed($png));
pin('a real WebP is read as image/webp from its bytes', 'image/webp', UploadMimes::detect($webp));
check('and is allowed as an image', UploadMimes::isAllowedImage($webp));

// The browser-supplied type is the one thing about an upload nobody should trust, and
// detect() never reads it -- a PHP script keeps its own type whatever it is named.
$script = sys_get_temp_dir() . '/upload-mimes-' . getmypid() . '.png';
file_put_contents($script, "<?php echo 1;");
pin('a script named .png is not read as an image', false, UploadMimes::isAllowed($script));
check('...and its detected type is not an image one', stripos(UploadMimes::detect($script), 'image/') === false);
@unlink($script);

// A file whose type nothing can establish is refused rather than trusted.
$empty = sys_get_temp_dir() . '/upload-mimes-empty-' . getmypid() . '.png';
file_put_contents($empty, '');
pin('an empty file is not an accepted upload', false, UploadMimes::isAllowed($empty));
@unlink($empty);

// A listing photo has to decode, not merely carry an allowed type: with a non-image
// extension configured, application/octet-stream is on the list and any bytes would pass.
$blob = sys_get_temp_dir() . '/upload-mimes-blob-' . getmypid() . '.png';
file_put_contents($blob, random_bytes(200));
check('random bytes are not an allowed image', !UploadMimes::isAllowedImage($blob));
@unlink($blob);

check('a real PNG still is', UploadMimes::isAllowedImage($png));

// A few hundred bytes whose header claims 8000 x 8000: decoding it would take hundreds of MB.
$bomb = $tmpDir . '/bomb.png';
$ihdr = 'IHDR' . pack('NNCCCCC', 8000, 8000, 8, 6, 0, 0, 0);
file_put_contents($bomb, "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)) . str_repeat("\0", 200));
check('an image over the pixel limit is caught from its header', UploadMimes::tooManyPixels($bomb));
check('and is not an allowed image', !UploadMimes::isAllowedImage($bomb));
check('a normal PNG is under the limit', !UploadMimes::tooManyPixels($png));
pin('the uploader refuses it with the size message', 'too-big', $attempt('bomb.png', $bomb));
$GLOBALS['flashes'] = array();
check('the listing photo check refuses it', !$check->invoke($actions, $files($bomb, 'image/png')));
check('and says why', strpos((string) end($GLOBALS['flashes']), 'megapixels') !== false);
$threw = false;
try {
    ImageProcessing::fromFile($bomb);
} catch (RuntimeException $e) {
    $threw = true;
}
check('the image class will not open it either', $threw);

// A GIF whose header says 1 x 1 but whose frame is 2000 x 2000. Only ImageMagick decodes the
// frame, so only it can tell; the limit is lowered so the file stays small.
if (extension_loaded('imagick')) {
    $gif = new Imagick();
    $gif->newImage(2000, 2000, 'white');
    $gif->setImageFormat('gif');
    $bytes = $gif->getImageBlob();
    $bytes = substr_replace($bytes, pack('vv', 1, 1), 6, 4);
    file_put_contents($tmpDir . '/liar.gif', $bytes);
    Plugins::addHook('image_max_pixels', static fn () => 1000000);
    $GLOBALS['imagick'] = true;
    check('with ImageMagick on, a GIF frame larger than its header is caught', UploadMimes::tooManyPixels($tmpDir . '/liar.gif'));
    $threw = false;
    try {
        ImageProcessing::fromFile($tmpDir . '/liar.gif');
    } catch (RuntimeException $e) {
        $threw = true;
    }
    check('and the image class will not open it', $threw);
    $GLOBALS['imagick'] = false;
    check('with GD the header size is what counts', !UploadMimes::tooManyPixels($tmpDir . '/liar.gif'));
}
file_put_contents($tmpDir . '/drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="9000" height="9000"/>');
$threw = false;
try {
    ImageProcessing::fromFile($tmpDir . '/drawing.svg');
} catch (RuntimeException $e) {
    $threw = true;
}
check('an SVG is not opened as a photo', $threw);

array_map('unlink', glob($tmpDir . '/*'));
@rmdir($tmpDir);

exit(harness_result());
