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
 * The updater must refuse a release whose PHP floor is above the server's PHP before it
 * changes any file, reading the floor from the Composer platform check the release ships.
 *
 * Usage: php tests/upgrade-php-floor.php
 */

$site = sys_get_temp_dir() . '/osc-php-floor-site-' . bin2hex(random_bytes(4));
define('ABS_PATH', $site . '/');
define('CONTENT_PATH', $site . '/oc-content/');
define('OSCLASS_VERSION', '7.1.0');
define('OSC_MAINTENANCE_UPGRADE_MARKER', 'upgrade');

require_once __DIR__ . '/lib/harness.php';
require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';

use mindstellar\upgrade\Upgrade;
use mindstellar\upgrade\UpgradePackage;

function __($s)
{
    return $s;
}
function osc_base_url()
{
    return 'http://127.0.0.1/';
}
function osc_purge_page_cache($why)
{
}

$root = sys_get_temp_dir() . '/osc-php-floor-' . bin2hex(random_bytes(4));
mkdir($root . '/oc-includes/vendor/composer', 0755, true);
$check = $root . '/oc-includes/vendor/composer/platform_check.php';

pin('a package with no platform check needs nothing', null, Upgrade::phpTooOld($root, '8.0.30'));

file_put_contents($check, "<?php\n\$issues = array();\n\nif (!(PHP_VERSION_ID >= 80200)) {\n    \$issues[] = 'x';\n}\n");
pin('PHP 8.0 is refused a release needing 8.2', '8.2.0', Upgrade::phpTooOld($root, '8.0.30'));
pin('PHP 8.1 is refused a release needing 8.2', '8.2.0', Upgrade::phpTooOld($root, '8.1.33'));
pin('PHP 8.2 may install it', null, Upgrade::phpTooOld($root, '8.2.0'));
pin('PHP 8.5 may install it', null, Upgrade::phpTooOld($root, '8.5.1'));

file_put_contents($check, "<?php\nif (!(PHP_VERSION_ID >= 80235)) {\n}\n");
pin('a patch-level floor is read too', '8.2.35', Upgrade::phpTooOld($root, '8.2.10'));

$own = dirname(__DIR__) . '/oc-includes/vendor/composer/platform_check.php';
if (is_file($own)) {
    pin('this build states the 8.2 floor', '8.2.0', Upgrade::phpTooOld(dirname(__DIR__), '8.1.33'));
}

harness_section('a full update on too old a PHP changes nothing');

// A release zip, served over HTTP, whose platform check needs PHP 99.
$serve = $root . '/serve';
mkdir($serve . '/shopclass/oc-includes/vendor/composer', 0755, true);
file_put_contents($serve . '/shopclass/index.php', 'the new release');
file_put_contents($serve . '/shopclass/oc-includes/vendor/composer/platform_check.php', "<?php\nif (!(PHP_VERSION_ID >= 990000)) {\n}\n");
$zip = new ZipArchive();
$zip->open($serve . '/release.zip', ZipArchive::CREATE);
$zip->addFile($serve . '/shopclass/index.php', 'shopclass/index.php');
$zip->addFile($serve . '/shopclass/oc-includes/vendor/composer/platform_check.php', 'shopclass/oc-includes/vendor/composer/platform_check.php');
$zip->close();

$port = 18000 + random_int(0, 999);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $serve], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

mkdir($site . '/oc-content', 0755, true);
file_put_contents($site . '/index.php', 'old');
touch($site . '/index.php', time() - 3600);
$package = new class (array(
    's_title'             => 'Shopclass 99',
    's_source_url'        => 'http://127.0.0.1:' . $port . '/release.zip',
    's_new_version'       => '99.0.0',
    's_installed_version' => '7.1.0',
    's_short_name'        => 'shopclass',
    's_target_directory'  => $site . '/',
)) extends UpgradePackage {
    public function afterProcessUpgrade()
    {
        return true;
    }
};

$error = '';
try {
    (new Upgrade($package))->doUpgrade();
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}
proc_terminate($server);
check('the update is refused naming the PHP it needs', strpos($error, 'needs PHP 99.0.0 or newer') !== false);
pin('the site files are untouched', 'old', file_get_contents($site . '/index.php'));
pin('no maintenance flag is left behind', false, file_exists($site . '/.maintenance'));

(new mindstellar\utility\FileSystem())->remove($root);
(new mindstellar\utility\FileSystem())->remove($site);

exit(harness_result());
