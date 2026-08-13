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

/*
 * Normalising into the cache.
 *
 * The cached manifest is a file holding only what an import reads, because the preference
 * row it replaced was loaded on every request — front-end page views included — to spare
 * an admin screen a network call. Everything the normaliser drops is therefore something
 * no longer available later, and everything it keeps is paid for on every read.
 */
$norm = LocationCatalog::normalizeManifest($manifest);

check('normalising keeps the release version', $norm['version'] === '2026-08-13');
check('normalising keeps one row per country', count($norm['countries']) === 1);

$mt = $norm['countries'][0];
check('the country code survives', $mt['code'] === 'MT');
check('the country name survives', $mt['name'] === 'Malta');
check('the streaming file survives', $mt['data'] === 'data/MT.ndjson');
check('the whole-file form survives', $mt['json'] === 'json/MT.json');
check('the data checksum survives', $mt['sha'] === str_repeat('a', 64));

// The published manifest describes four formats per country with a checksum and a byte
// count for each; two are read. Carrying the rest is pure per-read cost.
check(
    'formats this install cannot import are dropped',
    !isset($mt['csv']) && !isset($mt['bytes']) && !isset($mt['id']) && !isset($mt['slug'])
);

// On this catalog the two checksums are the same string, and storing it twice for every
// country is a sixth of the cached file for nothing.
check('a checksum equal to the data checksum is not stored twice', !isset($mt['dsha']));

$legacyNorm = LocationCatalog::normalizeManifest($legacyManifest);
check('the older manifest normalises to the same shape', $legacyNorm['countries'][0]['code'] === 'MT');
check('the older manifest keeps its file name', $legacyNorm['countries'][0]['json'] === 'MT-Malta.json');

// The older catalog published the two forms as independent files, so the checksum that
// verifies the streamed download is not the one that marks the installed version.
$twoHash = LocationCatalog::normalizeManifest(array(
    'locations' => array(
        array(
            's_country_code'   => 'MT',
            's_country_name'   => 'Malta',
            's_file_name'      => 'MT-Malta.json',
            's_sha256'         => str_repeat('b', 64),
            's_file_ndjson'    => 'MT-Malta.ndjson',
            's_sha256_ndjson'  => str_repeat('c', 64),
        ),
    ),
));
check('two differing checksums are both kept', $twoHash['countries'][0]['sha'] === str_repeat('b', 64)
    && $twoHash['countries'][0]['dsha'] === str_repeat('c', 64));

// A country with no code cannot be imported or matched against the database.
$junk = LocationCatalog::normalizeManifest(array('countries' => array(
    array('name' => 'Nowhere'),
    'not-an-entry',
    array('code' => 'MT', 'name' => 'Malta'),
)));
check('entries without a code are dropped', count($junk['countries']) === 1);
check('a manifest with no countries normalises to an empty list', LocationCatalog::normalizeManifest(array())['countries'] === array());

echo "\n----------------------------------------\n";
echo "RESULT: $ok passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);

/* file end: ./tests/location-catalog-shapes.php */
