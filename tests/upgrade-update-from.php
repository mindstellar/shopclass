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
 * A release can need a newer site first (OSCLASS_UPDATE_FROM). The updater then offers a
 * release in between, says which version to reach, and refuses the zip of the newer one.
 *
 * Usage: php tests/upgrade-update-from.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\upgrade\ReleaseChannel;
use mindstellar\upgrade\Upgrade;

$release = static fn (string $tag, string $from = '') => array(
    'tag_name' => $tag,
    'body'     => "Notes.\n" . ($from !== '' ? "\nUpdate from $from or newer.\n" : '') . "\nFull changelog: x\n",
);
$list = array($release('7.0.9'), $release('7.1.5'), $release('7.2.0', '7.1.0'), $release('7.1.0'));

pin('the line is read', '7.1.0', ReleaseChannel::updateFrom($release('7.2.0', '7.1.0')));
pin('no line means any version', '', ReleaseChannel::updateFrom($release('7.1.5')));
pin('a line that is not exact is not read', '', ReleaseChannel::updateFrom(array('body' => 'Update from 7.1 or newer.')));

pin('a 7.0 site is offered 7.1 first', '7.1.5', ReleaseChannel::pick($list, ReleaseChannel::STABLE, '7.0.8')['tag_name'] ?? null);
pin('and told 7.2 needs 7.1.0 first', array('version' => '7.2.0', 'from' => '7.1.0'), ReleaseChannel::waiting($list, ReleaseChannel::STABLE, '7.0.8'));
pin('a 7.1 site is offered 7.2', '7.2.0', ReleaseChannel::pick($list, ReleaseChannel::STABLE, '7.1.0')['tag_name'] ?? null);
pin('and nothing waits', null, ReleaseChannel::waiting($list, ReleaseChannel::STABLE, '7.1.0'));
pin('a 7.1 dev build is not 7.1.0 yet', '7.1.5', ReleaseChannel::pick($list, ReleaseChannel::STABLE, '7.1.0.dev2')['tag_name'] ?? null);
pin('without a site version the rule is off', '7.2.0', ReleaseChannel::pick($list, ReleaseChannel::STABLE)['tag_name'] ?? null);
pin('a site that can take nothing yet is told why', array('version' => '7.2.0', 'from' => '7.1.0'), ReleaseChannel::waiting(array($release('7.2.0', '7.1.0')), ReleaseChannel::STABLE, '7.0.8'));

$base = sys_get_temp_dir() . '/osc-update-from-' . bin2hex(random_bytes(4));
mkdir($base . '/oc-includes/osclass', 0755, true);
$constants = static fn (string $from) => file_put_contents(
    $base . '/oc-includes/osclass/default-constants.php',
    "<?php\nif (!defined('OSCLASS_UPDATE_FROM')) {\n    define('OSCLASS_UPDATE_FROM', '$from');\n}\n"
);
$constants('7.1.0');
pin('the zip refuses a 7.0 site', '7.1.0', Upgrade::versionNeeded($base, '7.0.8'));
pin('the zip takes a 7.1 site', null, Upgrade::versionNeeded($base, '7.1.0'));
$constants('');
pin('an empty value takes any site', null, Upgrade::versionNeeded($base, '6.0.0'));
unlink($base . '/oc-includes/osclass/default-constants.php');
pin('a plugin or theme package needs nothing', null, Upgrade::versionNeeded($base, '6.0.0'));
(new mindstellar\utility\FileSystem())->remove($base);

$shipped = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/default-constants.php');
pin('core defines the constant', 1, preg_match("/define\\('OSCLASS_UPDATE_FROM', '(\\d+\\.\\d+\\.\\d+)?'\\)/", $shipped));
$ci = (string) file_get_contents(ABS_PATH . '.github/workflows/build.yml');
pin('the release build writes the line the updater reads', true, str_contains($ci, "printf '\\n\\nUpdate from %s or newer.\\n' \"\$FROM\""));

exit(harness_result());
