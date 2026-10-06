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
 * Edge builds: the build marker and its label, the version order that makes each upgrade run,
 * plugin compatibility on a stamped version, and the built-in updater staying off.
 *
 * No database. Usage: php tests/edge-build.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '6.4.0.202610030200');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

use mindstellar\market\Compatibility;
use mindstellar\upgrade\BuildInfo;
use mindstellar\utility\Utils;

$dir = sys_get_temp_dir() . '/osc-edge-build-' . getmypid();
@mkdir($dir);
$marker = static function (string $name, string $php) use ($dir): string {
    file_put_contents($dir . '/' . $name, $php);

    return $dir . '/' . $name;
};
$edge = $marker('edge.php', "<?php\n\nreturn array('channel' => 'edge', 'revision' => 'a65dcfea6b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e', 'built' => '202610030200');\n");

harness_section('the build marker');

pin('a release ships without the marker', false, is_file(BuildInfo::file()));
pin('no marker: no build info', null, BuildInfo::load($dir . '/missing.php'));
pin('no marker: the version stays as it is', '6.4.0', BuildInfo::label('6.4.0'));
pin('no marker: not edge', false, BuildInfo::isEdge());
BuildInfo::load($edge);
pin('the marker is read', array('channel' => 'edge', 'revision' => 'a65dcfea6b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e', 'built' => '202610030200'), BuildInfo::current());
pin('the label names the channel and the short commit', '6.4.0.202610030200 (edge, a65dcfe)', BuildInfo::label(OSCLASS_VERSION));
pin('edge', true, BuildInfo::isEdge());
BuildInfo::load($marker('bad-rev.php', "<?php return array('channel' => 'edge', 'revision' => '<b>x</b>', 'built' => 'soon');"));
pin('a bad commit or time is dropped, the channel kept', '6.4.0.1 (edge)', BuildInfo::label('6.4.0.1'));
pin('a marker that returns nothing is no marker', null, BuildInfo::load($marker('junk.php', "<?php\n")));
pin('a channel other than edge is no marker', null, BuildInfo::load($marker('chan.php', "<?php return array('channel' => 'nightly');")));
pin('a corrupt marker is no marker', null, BuildInfo::load($marker('corrupt.php', "<?php\n\nreturn array('channel' => 'edge', 'revis")));
pin('and not edge', false, BuildInfo::isEdge());

harness_section('the admin upgrade runs: code version above the stored one');

// AdminSecBaseModel sends the admin to the upgrade when this is true; db:upgrade in the
// container runs whatever the migration ledger lacks either way.
foreach (array(
    'edge to a later edge'             => array('6.4.0.202610030200', '6.4.0.202610020200'),
    'stable to edge'                   => array('6.4.0.202610030200', '6.4.0'),
    'rc to edge'                       => array('6.4.0.rc6.202610022159', '6.4.0.rc6'),
    'edge to the next rc'              => array('6.4.0.rc7', '6.4.0.rc6.202610022159'),
    'edge to its stable release'       => array('6.4.0', '6.4.0.rc6.202610022159'),
    'stable-based edge to next rc'     => array('6.4.1.rc1', '6.4.0.202610030200'),
    'stable-based edge to next stable' => array('6.4.1', '6.4.0.202610030200'),
    'stable to a dev-based edge'       => array('6.4.1.dev.202610030200', '6.4.0'),
    'dev-based edge to a later one'    => array('6.4.1.dev.202610040000', '6.4.1.dev.202610030200'),
    'dev-based edge to the first beta' => array('6.4.1.beta1', '6.4.1.dev.202610030200'),
    'edge across a year'               => array('6.4.0.202701010000', '6.4.0.202612312359'),
) as $label => [$code, $stored]) {
    pin($label . " ($stored -> $code)", true, Utils::versionCompare($code, $stored, 'gt'));
}
pin('stable-based edge back to its own stable is no upgrade: there is no way back', false, Utils::versionCompare('6.4.0', '6.4.0.202610030200', 'gt'));
pin('a word before the stamp would sort below the release, which is why it is digits only', true, Utils::versionCompare('6.4.0.dev.202610030200', '6.4.0', 'lt'));

harness_section('plugin compatibility on an edge build');

$status = static fn (array $info, string $core) => Compatibility::evaluate($info, $core, '8.3.0')['status'];
pin('Requires 6.4.0 works on a stable-based edge', Compatibility::OK, $status(array('requires' => '6.4.0'), '6.4.0.202610030200'));
pin('Requires 6.4.0 works on an rc-based edge', Compatibility::OK, $status(array('requires' => '6.4.0'), '6.4.0.rc6.202610022159'));
pin('Requires 6.4.1 works on a dev-based edge of 6.4.1', Compatibility::OK, $status(array('requires' => '6.4.1'), '6.4.1.dev.202610030200'));
pin('Requires 6.4.1 is still refused on a 6.4.0 edge', Compatibility::INCOMPATIBLE, $status(array('requires' => '6.4.1'), '6.4.0.202610030200'));
pin('stable 6.4.0 is untouched', Compatibility::OK, $status(array('requires' => '6.4.0'), '6.4.0'));
pin('stable 6.4.0 still refuses 6.4.1', Compatibility::INCOMPATIBLE, $status(array('requires' => '6.4.1'), '6.4.0'));
pin('rc6 still works for 6.4.0', Compatibility::OK, $status(array('requires' => '6.4.0'), '6.4.0.rc6'));
pin('only a 12-digit stamp counts', Compatibility::INCOMPATIBLE, $status(array('requires' => '6.4.0'), '6.4.0.rc6.20261003'));
pin('Tested up to 6.4 covers an edge of 6.4', Compatibility::OK, $status(array('requires' => '6.0.0', 'tested_up_to' => '6.4'), '6.4.0.rc6.202610022159'));

harness_section('the built-in updater is off on edge');

putenv('OSC_DISABLE_SELF_UPDATE');
BuildInfo::load($dir . '/missing.php');
pin('a release keeps the updater', false, osc_self_update_disabled());
BuildInfo::load($edge);
pin('an edge build turns it off', true, osc_self_update_disabled());
putenv('OSC_DISABLE_SELF_UPDATE=0');
pin('even when the environment says otherwise', true, osc_self_update_disabled());
putenv('OSC_DISABLE_SELF_UPDATE');

$ajax      = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/admin/ajax/UpdateAjax.php');
$functions = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php');
$footer    = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/functions.php');
$upgrade   = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/upgrade.php');
$auto      = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/upgrade/AutoSecurityUpdate.php');

check('the version check asks GitHub nothing on edge', (bool) preg_match(
    "/function checkVersion\\(\\): void\\s*\\{\\s*if \\(BuildInfo::isEdge\\(\\)\\) \\{\\s*AjaxResponse::json\\([^;]+;\\s*return;\\s*\\}/",
    $ajax
));
check('the toolbar offers no new version on edge', strpos($functions, "getPreference('update_core_available') && !\\mindstellar\\upgrade\\BuildInfo::isEdge()") !== false);
check('the admin footer does not poll on edge', strpos($footer, '> (24 * 3600) && !\\mindstellar\\upgrade\\BuildInfo::isEdge()') !== false);
check('the automatic security install stops when updates are off', strpos($auto, '|| osc_self_update_disabled()) {') !== false);
check('the in-app upgrade refuses when updates are off', strpos($ajax, 'if (osc_self_update_disabled()) {') !== false
    && substr_count($ajax, '$result = self::refusal();') === 2);
$edgeAt = strpos($upgrade, 'if (BuildInfo::isEdge()) {');
check('the update screen says how an edge build updates, ahead of the container message', $edgeAt !== false
    && strpos($upgrade, "__('This is an edge build. Update it by pulling the :edge image.')", $edgeAt) !== false
    && $edgeAt < strpos($upgrade, '} elseif ($selfUpdateOff) {'));

array_map('unlink', glob($dir . '/*.php'));
@rmdir($dir);

exit(harness_result());
