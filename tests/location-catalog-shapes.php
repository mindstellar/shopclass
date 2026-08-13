<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The two catalog documents, told apart without a network call.
 *
 * The location catalog is reached through a pointer that names the current release. Both
 * documents carry a `countries` key — the manifest's is the list of countries, the
 * pointer's is how many there are — so deciding which is which on the presence of that
 * key reads the pointer as a manifest and then finds an integer where the list belongs.
 *
 * That has happened twice, and both times a cached manifest hid it: everything worked
 * until the cache went cold, and then the catalog simply could not be read. The check is
 * cheap to get wrong and expensive to notice, which is why it is pinned here rather than
 * left to be caught by importing something.
 *
 * No database and no network: these are fixtures shaped like the real documents.
 *
 * Usage:  php tests/location-catalog-shapes.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require_once ABS_PATH . 'oc-includes/osclass/classes/location/LocationCatalog.php';

use mindstellar\location\LocationCatalog;

$ok = 0;
$failed = 0;

function check(string $label, bool $passed, string $detail = ''): void
{
    global $ok, $failed;
    if ($passed) {
        $ok++;
        echo "PASS  $label\n";
    } else {
        $failed++;
        echo "FAIL  $label" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

/* The pointer, as published: note `countries` is a COUNT here. */
$pointer = array(
    'bytes'       => 1892639788,
    'countries'   => 255,
    'license'     => 'CC0-1.0',
    'manifest'    => 'releases/2026-08-13/manifest.json',
    'regions'     => 4401,
    'released'    => '2026-08-13T14:29:20+00:00',
    'settlements' => 1745357,
    'version'     => '2026-08-13',
);

/* The manifest it names: `countries` is the LIST. */
$manifest = array(
    'version'   => '2026-08-13',
    'license'   => 'CC0-1.0',
    'countries' => array(
        array(
            'code'   => 'MT',
            'name'   => 'Malta',
            'files'  => array('data' => 'data/MT.ndjson', 'json' => 'json/MT.json'),
            'sha256' => array('data' => str_repeat('a', 64)),
        ),
    ),
);

/* The catalog this replaced, still readable by a mirror. */
$legacyManifest = array(
    'locations' => array(
        array('s_country_code' => 'MT', 's_country_name' => 'Malta', 's_file_name' => 'MT-Malta.json'),
    ),
);

check('the pointer is recognised despite carrying a countries COUNT', LocationCatalog::isPointerDocument($pointer));
check('the manifest is not mistaken for a pointer', !LocationCatalog::isPointerDocument($manifest));
check('the older manifest is not mistaken for a pointer', !LocationCatalog::isPointerDocument($legacyManifest));

// A manifest that also named a manifest would still be a manifest: it carries the list,
// which is the thing that settles it.
check(
    'a document carrying both a list and a manifest key is a manifest',
    !LocationCatalog::isPointerDocument($manifest + array('manifest' => 'somewhere/else.json'))
);

// Guard the specific regression: presence-based tests pass this and type-based ones do not.
check(
    'a pointer whose count is zero is still a pointer',
    LocationCatalog::isPointerDocument(array('manifest' => 'a/b.json', 'countries' => 0))
);

check('a document naming nothing is not a pointer', !LocationCatalog::isPointerDocument(array('countries' => array())));
check(
    'a manifest key that is not a path does not make a pointer',
    !LocationCatalog::isPointerDocument(array('manifest' => array('nested' => true)))
);

echo "\n----------------------------------------\n";
echo "RESULT: $ok passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);

/* file end: ./tests/location-catalog-shapes.php */
