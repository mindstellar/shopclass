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
 * Pins which release zip the core updater downloads and which folder in it it installs
 * from. Releases carry shopclass_v*.zip (shopclass/) and osclass_v*.zip (osclass/); the
 * second must keep working for updaters that know only that name.
 *
 * Usage: php tests/upgrade-release-zip.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '6.4.0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\upgrade\Osclass;
use mindstellar\upgrade\Upgrade;
use mindstellar\upgrade\Plugin;
use mindstellar\upgrade\UpgradePackage;

$asset = static function (string $name): array {
    return array('name' => $name, 'browser_download_url' => 'https://example.test/' . $name);
};
$pick = static function (array $names) use ($asset) {
    return Osclass::selectReleaseAssetUrl(array_map($asset, $names));
};

pin(
    'the shopclass zip wins, whatever the order',
    'https://example.test/shopclass_v6.4.0.zip',
    $pick(array('deprecated-api.json', 'osclass_v6.4.0.zip', 'package-lint.php', 'shopclass_v6.4.0.zip'))
);
pin(
    'an older release with only the osclass zip still updates',
    'https://example.test/osclass_v6.3.0.zip',
    $pick(array('shopclass-package-ci.tar.gz', 'osclass_v6.3.0.zip'))
);
pin(
    'the osclass zip beats any other zip',
    'https://example.test/osclass_v6.4.0.zip',
    $pick(array('other.zip', 'osclass_v6.4.0.zip'))
);
pin('any zip is the last resort', 'https://example.test/other.zip', $pick(array('notes.txt', 'other.zip')));
pin('no zip, no download', null, $pick(array('deprecated-api.json')));

$core = (new ReflectionClass(Osclass::class))->newInstanceWithoutConstructor();
pin('the core package unpacks from shopclass/ or osclass/', array('shopclass', 'osclass'), $core->getFolderNames());

$dir = sys_get_temp_dir() . '/osc-release-zip-' . getmypid();
$make = static function (string $path): void {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, '<?php');
};
$make("$dir/new/shopclass/index.php");
$make("$dir/old/osclass/index.php");
$make("$dir/flat/index.php");
$make("$dir/other/wordpress/index.php");

pin('a shopclass/ zip installs from shopclass/', "$dir/new/shopclass", Upgrade::packageRoot("$dir/new", $core->getFolderNames()));
pin('an osclass/ zip installs from osclass/', "$dir/old/osclass", Upgrade::packageRoot("$dir/old", $core->getFolderNames()));
pin('a zip with files at the top installs from the top', "$dir/flat", Upgrade::packageRoot("$dir/flat", $core->getFolderNames()));
pin('any other folder is refused', null, Upgrade::packageRoot("$dir/other", $core->getFolderNames()));
$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(UpgradePackage::class, 's_short_name'))->setValue($plugin, 'my-plugin');
$make("$dir/plugin/my-plugin/index.php");
pin('any other package unpacks from its own folder', array('my-plugin'), $plugin->getFolderNames());
pin('and from no other', null, Upgrade::packageRoot("$dir/new", $plugin->getFolderNames()));
pin('its own folder is found', "$dir/plugin/my-plugin", Upgrade::packageRoot("$dir/plugin", $plugin->getFolderNames()));

exec('rm -rf ' . escapeshellarg($dir));

exit(harness_result());
