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
 * The sample photo pack: a zip matching the checksum gives the sample listings photos, a wrong
 * checksum or an unsafe zip gives none and a warning, and no temp file is left.
 *
 * Usage:  php tests/models/install-sample-photos.php       (standalone, own scratch database)
 *         php tests/run-models.php install-sample-photos   (as part of the suite)
 */

use mindstellar\install\SamplePhotos;

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_install_sample_photos');
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return UPLOADS_PATH;
    }
}
foreach (array('hItems', 'hUtils', 'hKv', 'hFields') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}
require_once ABS_PATH . 'oc-includes/osclass/install-functions.php';

$p     = DB_TABLE_PREFIX;
$prefs = array('moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'admin_language' => 'en_US',
    'title_character_length' => '100', 'description_character_length' => '5000', 'currency' => 'USD',
    'maxSizeKb' => '2048', 'allowedExt' => 'png,gif,jpg,jpeg,webp', 'dimThumbnail' => '240x200', 'dimPreview' => '480x340', 'dimNormal' => '640x480');
$fresh = static function () use ($admin, $prefs): void {
    scratchdb_truncate_all($admin);
    seed_locale($admin);
    seed_currency($admin);
    foreach ($prefs as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
    $_SERVER['REMOTE_ADDR'] = '192.0.2.71';
};
$count  = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];
$photos = static fn (): int => $count("SELECT COUNT(*) FROM {$p}t_resource WHERE s_owner_type = 'item'");

$root = sys_get_temp_dir() . '/sample-photos-' . getmypid() . '/';
$work = $root . 'work/';
mkdir($work, 0755, true);
ob_start();
imagewebp(imagecreatetruecolor(640, 480));
$webp = (string) ob_get_clean();
$zip  = static function (string $name, array $entries) use ($root): string {
    $path = $root . $name;
    $z    = new ZipArchive();
    $z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $data) {
        $z->addFromString($entry, $data);
    }
    $z->close();

    return $path;
};
$empty = static fn (): bool => (array_diff((array) scandir($work), array('.', '..'))) === array();

$good = $zip('good.zip', array('car-hatchback.webp' => $webp, 'bike-trek.webp' => $webp, 'LICENSE.txt' => 'CC0'));
$sha  = (string) hash_file('sha256', $good);

harness_section('a zip that matches the checksum');
$fresh();
$pack = new SamplePhotos($good, $sha, SamplePhotos::URL, $work);
check('reports success', install_seed_site('classifieds', true, $pack) === true);
check('26 listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 26);
check('two listings have a photo', $photos() === 2);
check('no warning', !$pack->missing());
check('the temp folder is gone', $empty());
check('the local zip is kept', is_file($good));

harness_section('a wrong checksum');
$fresh();
$pack = new SamplePhotos($good, str_repeat('0', 64), SamplePhotos::URL, $work);
install_seed_site('classifieds', true, $pack);
check('26 listings', $count("SELECT COUNT(*) FROM {$p}t_item") === 26);
check('no photos', $photos() === 0);
check('the installer warns', $pack->missing());
check('the temp folder is gone', $empty());

harness_section('unsafe or empty packs');
$packs = array(
    'a ../ path'       => $zip('up.zip', array('car-hatchback.webp' => $webp, '../evil.webp' => $webp)),
    'no webp files'    => $zip('txt.zip', array('car-hatchback.jpg' => $webp, 'notes.txt' => 'x')),
    'a fake webp'      => $zip('fake.zip', array('car-hatchback.webp' => '<?php echo 1;')),
    'an oversize file' => $zip('big.zip', array('car-hatchback.webp' => $webp . random_bytes(2200000))),
);
$link = $root . 'link.zip';
$z    = new ZipArchive();
$z->open($link, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('car-hatchback.webp', $webp);
$z->addFromString('bike-trek.webp', '/etc/passwd');
$z->setExternalAttributesName('bike-trek.webp', ZipArchive::OPSYS_UNIX, (0120777 << 16));
$z->close();
$packs['a symlink entry'] = $link;
$many = array('car-hatchback.webp' => $webp);
for ($i = 1; $i <= 200; $i++) {
    $many['extra-' . $i . '.txt'] = 'x';
}
$packs['201 entries']  = $zip('many.zip', $many);
$packs['a corrupt zip'] = $root . 'corrupt.zip';
file_put_contents($packs['a corrupt zip'], substr((string) file_get_contents($good), 0, 200));
foreach ($packs as $label => $path) {
    $pack = new SamplePhotos($path, (string) hash_file('sha256', $path), SamplePhotos::URL, $work);
    check($label . ' is rejected', $pack->fetch() === null);
    $pack->clean();
    check($label . ' leaves no temp files', $empty());
}
check('nothing was written beside the work folder', !is_file($root . 'evil.webp'));

harness_section('only root webp files are written');
$path   = $zip('mixed.zip', array('car-hatchback.webp' => $webp, 'img/bike-trek.webp' => $webp, '__MACOSX/._car-hatchback.webp' => 'x', 'notes.txt' => 'x', 'bike-trek.jpg' => $webp));
$pack   = new SamplePhotos($path, (string) hash_file('sha256', $path), SamplePhotos::URL, $work);
$folder = (string) $pack->fetch();
check('the pack is used', $folder !== '' && is_file($folder . 'car-hatchback.webp'));
check('a file in a sub folder is skipped', !is_dir($folder . 'img'));
check('__MACOSX is skipped', !is_dir($folder . '__MACOSX'));
check('a text file is skipped', !is_file($folder . 'notes.txt'));
check('a non-webp name is skipped', !is_file($folder . 'bike-trek.jpg'));
$pack->clean();
check('clean() leaves no temp files', $empty());

harness_section('downloads');
// A local plain-http server holds the good zip, so only the https check stops the download.
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
fclose($sock);
$server = proc_open(
    array(PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root),
    array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'a'), 2 => array('file', '/dev/null', 'a')),
    $pipes
);
for ($i = 0; $i < 100 && !($up = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)); $i++) {
    usleep(30000);
}
check('the local server started', (bool) $up);
$pack = new SamplePhotos(null, $sha, 'http://127.0.0.1:' . $port . '/good.zip', $work);
check('plain http is refused', $pack->fetch() === null);
proc_terminate($server);
proc_close($server);
$pack = new SamplePhotos(null, $sha, 'https://packages.invalid/x.zip', $work);
check('a host off the allow list is refused', $pack->fetch() === null);
putenv('OSC_DISABLE_PACKAGE_INSTALLS=1');
check('package installs off means no photos', (new SamplePhotos($good, $sha, SamplePhotos::URL, $work))->fetch() === null);
putenv('OSC_DISABLE_PACKAGE_INSTALLS');
check('no temp files', $empty());

foreach (glob(UPLOADS_PATH . '0/*') ?: array() as $file) {
    unlink($file);
}
@rmdir(UPLOADS_PATH . '0');
(new \mindstellar\utility\FileSystem())->remove($root);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
