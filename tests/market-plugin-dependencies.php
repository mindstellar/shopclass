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
 * Pins PluginDependencies: the state of each plugin a theme asks for, that a missing
 * required plugin holds the theme back, that recommended ones can be skipped, that only a
 * slug in the theme's manifest is installed, and that theme_activate fires once, when live.
 *
 * DB-free.  Usage: php tests/market-plugin-dependencies.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '7.0.0');

$GLOBALS['prefs'] = array();
$GLOBALS['fired'] = array();

function osc_set_preference($key, $value)
{
    $GLOBALS['prefs'][] = array($key, $value);
}

function osc_run_hook($hook, ...$args)
{
    $GLOBALS['fired'][] = array($hook, $args);
}

function osc_package_installs_disabled()
{
    return false;
}

function osc_themes_path()
{
    return $GLOBALS['themesDir'];
}

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\market\PluginDependencies as Deps;
use mindstellar\market\VersionConstraint;

$entry = static fn (string $v, string $requires = '6.0') => array(
    'version' => $v, 'requires' => $requires, 'requires_php' => '8.0', 'tested' => '7.0',
    'url' => 'https://github.com/x/' . $v . '.zip', 'sha256' => str_repeat('a', 64),
);

$installed = array(
    'stripe'   => array('name' => 'Stripe', 'version' => '1.4.0', 'enabled' => true),
    'tax'      => array('name' => 'Tax', 'version' => '1.0.0', 'enabled' => false),
    'shipping' => array('name' => 'Shipping', 'version' => '0.9.0', 'enabled' => true),
    'old-only' => array('name' => 'Old', 'version' => '1.0.0', 'enabled' => true),
);
$versions = array(
    'stripe'        => array($entry('1.4.0')),
    'digital-goods' => array($entry('2.2.0'), $entry('2.1.0')),
    'shipping'      => array($entry('1.1.0'), $entry('1.0.0')),
    'old-only'      => array($entry('1.0.0')),
    'future'        => array($entry('3.0.0', '9.0')),
);
$index = array('digital-goods' => array('name' => 'Digital Goods', 'short_description' => 'Downloads after payment.'));

$deps = new Deps($installed, $versions, $index);

harness_section('version constraints');
pin('a bare version is a minimum', array(true, false), array(VersionConstraint::satisfies('1.3', '1.2'), VersionConstraint::satisfies('1.1', '1.2')));
pin('>= and <', array(true, false), array(VersionConstraint::satisfies('1.2.0', '>=1.2.0'), VersionConstraint::satisfies('2.0', '<2.0')));
pin('= is exact', array(true, false), array(VersionConstraint::satisfies('1.0.0', '=1.0'), VersionConstraint::satisfies('1.0.1', '=1.0')));
pin('^ stays below the next major', array(true, false), array(VersionConstraint::satisfies('1.9', '^1.2'), VersionConstraint::satisfies('2.0', '^1.2')));
pin('^0.x stays below the next minor', array(true, false), array(VersionConstraint::satisfies('0.3.5', '^0.3'), VersionConstraint::satisfies('0.4.0', '^0.3')));
pin('~ stays below the next minor of a patch', array(true, false), array(VersionConstraint::satisfies('1.2.9', '~1.2.3'), VersionConstraint::satisfies('1.3.0', '~1.2.3')));
pin('empty accepts any version', true, VersionConstraint::satisfies('0.1', ''));
pin('no version meets a constraint', false, VersionConstraint::satisfies('', '>=1.0'));
pin('a suffix in the constraint counts', array(true, false), array(VersionConstraint::satisfies('1.2.0-rc1', '>=1.2.0-beta'), VersionConstraint::satisfies('1.2.0-alpha', '>=1.2.0-beta')));
pin('four parts are read', array(true, false), array(VersionConstraint::satisfies('1.2.3.5', '>=1.2.3.4'), VersionConstraint::satisfies('1.2.3.3', '>=1.2.3.4')));
pin('a constraint the manifest refuses is met by nothing', array(false, false), array(VersionConstraint::isValid('>=1.2 junk'), VersionConstraint::satisfies('9.0', '>=1.2 junk')));

harness_section('resolver states');
$state = static fn (string $slug, string $c = '') => $deps->row($slug, $c, true);
pin('installed and on: active', array(Deps::ACTIVE, 'none'), array($state('stripe', '>=1.2')['status'], $state('stripe', '>=1.2')['action']));
pin('installed and off: inactive, activate', array(Deps::INACTIVE, 'activate'), array($state('tax')['status'], $state('tax')['action']));
$missing = $state('digital-goods', '>=2.1');
pin('not on disk, in catalog: missing, install', array(Deps::MISSING, 'install', '2.2.0'), array($missing['status'], $missing['action'], $missing['offer']['version']));
pin('...named from the catalog', array('Digital Goods', 'Downloads after payment.'), array($missing['name'], $missing['description']));
$old = $state('shipping', '>=1.0');
pin('installed but too old: update to the best match', array(Deps::TOO_OLD, 'update', '1.1.0'), array($old['status'], $old['action'], $old['offer']['version']));
$stuck = $state('old-only', '>=2.0');
pin('too old with nothing newer: no action, says why', array(Deps::TOO_OLD, 'none'), array($stuck['status'], $stuck['action']));
check('...with a message', $stuck['message'] !== '');
$none = $state('nowhere');
pin('not on disk, not in catalog', array(Deps::NOT_IN_CATALOG, 'none', ''), array($none['status'], $none['action'], $none['manual_url']));
$future = $state('future');
pin('only releases this core cannot run: incompatible', array(Deps::INCOMPATIBLE, 'none'), array($future['status'], $future['action']));
check('...with the manual zip link', $future['manual_url'] === 'https://github.com/x/3.0.0.zip');

$resolved = $deps->resolve(array(
    'required'    => array('stripe' => '', 'digital-goods' => ''),
    'recommended' => array('stripe' => '', 'tax' => ''),
));
pin('resolve splits required and recommended', array(array('stripe', 'digital-goods'), array('tax')), array(
    array_column($resolved['required'], 'slug'),
    array_column($resolved['recommended'], 'slug'),
));

harness_section('activation is held, then goes live');
$needs = array('required' => array('digital-goods' => '>=2.0'), 'recommended' => array());
pin('held while a required plugin is missing', 'held', $deps->activateTheme('aisle', $needs, true));
pin('...no preference written', array(), $GLOBALS['prefs']);
pin('...no theme_activate', array(), $GLOBALS['fired']);

$after = new Deps($installed + array('digital-goods' => array('name' => 'Digital Goods', 'version' => '2.2.0', 'enabled' => true)), $versions, $index);
pin('live once it is active', 'live', $after->activateTheme('aisle', $needs, false));
pin('...the theme preference is set', array(array('theme', 'aisle')), $GLOBALS['prefs']);
pin('theme_activate fires once, with the theme', array(array('theme_activate', array('aisle'))), $GLOBALS['fired']);

harness_section('recommended plugins');
$soft = array('required' => array('stripe' => ''), 'recommended' => array('tax' => ''));
check('a missing recommended plugin shows the screen first', $deps->holds($soft, false));
check('...and can be skipped once seen', !$deps->holds($soft, true));
pin('missingRequired ignores recommended ones', array(), $deps->missingRequired($soft));

harness_section('installing one plugin');
$calls   = array();
$fetcher = static function (string $mode, string $slug, array $entry) use (&$calls) {
    $calls[] = array('fetch', $mode, $slug, $entry['version']);

    return array('ok' => true, 'message' => '');
};
$switcher = static function (string $slug) use (&$calls) {
    $calls[] = array('switch', $slug);

    return null;
};
$live = new Deps($installed, $versions, $index, null, null, $fetcher, $switcher);
$manifest = array('required' => array('digital-goods' => '>=2.0'), 'recommended' => array('tax' => ''));

$r = $live->ensure($manifest, 'stripe');
pin('a slug outside the manifest is refused', false, $r['ok']);
pin('...and nothing runs', array(), $calls);

$r = $live->ensure($manifest, 'digital-goods');
pin('a missing plugin is fetched then switched on', array(array('fetch', 'install', 'digital-goods', '2.2.0'), array('switch', 'digital-goods')), $calls);
pin('...and reports active', array(true, Deps::ACTIVE), array($r['ok'], $r['status']));

$calls = array();
$live->ensure($manifest, 'tax');
pin('an off plugin is only switched on', array(array('switch', 'tax')), $calls);

$failing = new Deps($installed, $versions, $index, null, null, static fn () => array('ok' => false, 'message' => 'The plugins folder is not writable.'), $switcher);
$r = $failing->ensure($manifest, 'digital-goods');
pin('a failed download says why and links the zip', array(false, 'https://github.com/x/2.2.0.zip'), array($r['ok'], $r['manual_url']));
check('...keeping the installer message', strpos($r['message'], 'not writable') !== false, $r['message']);

$r = $live->ensure(array('required' => array('nowhere' => '')), 'nowhere');
pin('a plugin not in the market is refused with what to do', array(false, Deps::NOT_IN_CATALOG), array($r['ok'], $r['status']));

harness_section('theme manifest');
$GLOBALS['themesDir'] = sys_get_temp_dir() . '/osc-deps-' . bin2hex(random_bytes(4)) . '/';
mkdir($GLOBALS['themesDir'] . 'aisle', 0777, true);
file_put_contents($GLOBALS['themesDir'] . 'aisle/index.php', "<?php\n/*\nTheme Name: Aisle\nVersion: 1.0\n*/\n");
file_put_contents($GLOBALS['themesDir'] . 'aisle/shopclass.json', '{"plugins":{"required":{"stripe":">=1.2"},"recommended":{"tax":""}}}');
pin('an installed theme lists its plugins', array('required' => array('stripe' => '>=1.2'), 'recommended' => array('tax' => '')), Deps::themeRequirements('aisle'));
pin('a theme that is not installed is null', null, Deps::themeRequirements('../aisle'));
array_map('unlink', glob($GLOBALS['themesDir'] . 'aisle/*'));
rmdir($GLOBALS['themesDir'] . 'aisle');
rmdir($GLOBALS['themesDir']);

exit(harness_result());
