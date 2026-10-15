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
 * Pins that a Browse card describes the version Install would fetch: on a 6.4 site a plugin
 * whose newest release needs 7.0 shows its 6.4 release, not a blocked 7.0 one.
 *
 * Usage: php tests/market-browse-offer.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '6.4.7');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\market\Compatibility;
use mindstellar\market\PackageIndex;

$browseRow = new ReflectionMethod(PackageIndex::class, 'browseRow');
$browseRow->setAccessible(true);
$row = static fn (array $versions): array => $browseRow->invoke(null, array('slug' => 'listing-import', 'version' => '0.3.0'), $versions);

$v030 = array('version' => '0.3.0', 'requires' => '7.0', 'requires_php' => '8.0', 'tested' => '7.0');
$v021 = array('version' => '0.2.1', 'requires' => '6.4.0', 'requires_php' => '8.0', 'tested' => '6.4');

harness_section('a newer release needs a newer core');
$card = $row(array($v030, $v021));
pin('the card shows the release this site can run', '0.2.1', $card['version']);
check('...and does not block it', $card['compatibility']['blocked'] === false, $card['compatibility']['status']);

harness_section('no release runs here');
$card = $row(array($v030));
pin('the card keeps the newest release', '0.3.0', $card['version']);
pin('...and blocks it', array(Compatibility::INCOMPATIBLE, true), array($card['compatibility']['status'], $card['compatibility']['blocked']));

harness_section('no release data');
pin('the index row is kept', '0.3.0', $row(array())['version']);

exit(harness_result());
