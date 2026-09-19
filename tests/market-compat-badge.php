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
 * Pins the compatibility badge's words.
 *
 * The badge is the one thing on a package card that answers "can I install this here?", and
 * its tint alone must never carry that answer. Each state says one fact about the running
 * install: what the package needs when it cannot run, how far it was tested when that is
 * behind us, and the version it works with otherwise.
 *
 * Usage: php tests/market-compat-badge.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '6.3.0.beta4');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\market\Compatibility;

$label = static function (string $status, array $info): string {
    return Compatibility::verdictLabel($status, $info);
};

harness_section('the running install is too old');

pin(
    'the package names the version it needs',
    'Needs 6.4 or newer',
    $label(Compatibility::INCOMPATIBLE, array('requires' => '6.4.0', 'tested_up_to' => '6.4'))
);
pin(
    'a PHP floor it cannot meet says so instead',
    'Needs PHP 8.2',
    $label(Compatibility::INCOMPATIBLE, array('requires' => '6.1.0', 'requires_php' => '8.2'))
);
pin(
    'neither declared still refuses in words',
    'Not compatible',
    $label(Compatibility::INCOMPATIBLE, array())
);

harness_section('published, but not tested this far');

pin(
    'how far the author tested',
    'Tested up to 6.2',
    $label(Compatibility::UNTESTED, array('requires' => '6.1.0', 'tested_up_to' => '6.2'))
);
pin(
    'with nothing to quote, it names the running version',
    'Not tested with 6.3',
    $label(Compatibility::UNTESTED, array('requires' => '6.1.0'))
);

harness_section('fine here');

pin(
    'the version it works with is this one',
    'Works with 6.3',
    $label(Compatibility::OK, array('requires' => '6.1.0', 'tested_up_to' => '6.3'))
);

harness_section('the author declared nothing');

pin('says that plainly', 'No version declared', $label(Compatibility::UNDECLARED, array()));

harness_section('badgeLabel() decides the status itself and returns the same words');

pin(
    'an untested package',
    'Tested up to 6.2',
    Compatibility::badgeLabel(array('requires' => '6.1.0', 'tested_up_to' => '6.2'))
);
pin(
    'a package this install is too old for',
    'Needs 6.4 or newer',
    Compatibility::badgeLabel(array('requires' => '6.4.0', 'tested_up_to' => '6.4'))
);

harness_section('both screens read the same helper');

foreach (array(
    'oc-includes/osclass/classes/controller/admin/CAdminPlugins.php',
    'oc-includes/osclass/classes/controller/admin/CAdminAppearance.php',
) as $controller) {
    $src = (string) file_get_contents(ABS_PATH . $controller);
    pin($controller . ' builds both badges from the verdict', 2, substr_count($src, 'Compatibility::verdictLabel('));
    check($controller . ' no longer prints a bare range', strpos($src, 'Compatibility::rangeLabel(') === false);
}

exit(harness_result());
