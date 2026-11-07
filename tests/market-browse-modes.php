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
 * Pins that Catalog keeps a package's site types and that Browse marks only a package made
 * for one site type; an undeclared package is never marked, so it is never hidden.
 *
 * Usage: php tests/market-browse-modes.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '7.1.0');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

use mindstellar\market\Catalog;
use mindstellar\market\PackageIndex;

$sanitize = new ReflectionMethod(Catalog::class, 'sanitizeIndex');
$sanitize->setAccessible(true);
$catalog = (new ReflectionClass(Catalog::class))->newInstanceWithoutConstructor();
$clean   = static fn (array $extra): array => $sanitize->invoke($catalog, array(array('slug' => 'pkg') + $extra))['pkg'];

$browseRow = new ReflectionMethod(PackageIndex::class, 'browseRow');
$browseRow->setAccessible(true);
$only = static fn (array $extra): string => $browseRow->invoke(null, $clean($extra), array())['mode_only'];

harness_section('Catalog keeps modes');
pin('a valid list is kept', array('classifieds', 'shop'), $clean(array('modes' => array('classifieds', 'shop')))['modes']);
pin('junk is dropped', array('shop'), $clean(array('modes' => array('shop', 'bogus', 7, null, 'shop')))['modes']);
pin('a non-list becomes empty', array(), $clean(array('modes' => 'shop'))['modes']);
pin('no modes becomes empty', array(), $clean(array())['modes']);
pin('declared must be a real true', array(false, true), array($clean(array('modes_declared' => 'yes'))['modes_declared'], $clean(array('modes_declared' => true))['modes_declared']));

harness_section('Browse marks only a package made for one site type');
pin('shop only', 'shop', $only(array('modes' => array('shop'))));
pin('classifieds only, declared', 'classifieds', $only(array('modes' => array('classifieds'), 'modes_declared' => true)));
pin('classifieds without a declaration is the default, so unmarked', '', $only(array('modes' => array('classifieds'))));
pin('no modes at all is unmarked', '', $only(array()));
pin('both is unmarked', '', $only(array('modes' => array('classifieds', 'shop'), 'modes_declared' => true)));

exit(harness_result());
