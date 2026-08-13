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
 * How an imported place is recognised as one already stored.
 *
 * Identity is the upstream id. Names are the fallback, and they are the fallback for one
 * situation only: the id came from a source the catalog no longer publishes, so nothing
 * will ever claim the row by id and it would otherwise be retired and re-inserted as a
 * copy of itself. That is what a change of source looks like from in here.
 *
 * The comparison is deliberately loose about capitalisation, accents and punctuation, and
 * deliberately strict about anything it cannot tell apart: India publishes 117 distinct
 * villages called Gopalpur inside one region, and a name that names 117 places names none
 * of them. Matching on it would update one row and retire 116.
 *
 * No database and no network: the two decisions under test are pure.
 *
 * Usage:  php tests/location-matching.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('LIB_PATH', ABS_PATH . 'oc-includes/');

require_once ABS_PATH . 'oc-includes/osclass/classes/location/LocationImporter.php';

use mindstellar\location\LocationImporter;

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

$class = new ReflectionClass(LocationImporter::class);

$normalize = $class->getMethod('normalizeKey');
$normalize->setAccessible(true);
$key = static function (string $value) use ($normalize): string {
    return $normalize->invoke(null, $value);
};

$keysOf = $class->getMethod('comparisonKeys');
$keysOf->setAccessible(true);

/* ---------------------------------------------------------------- *
 * What two names have to have in common to be the same place.
 * ---------------------------------------------------------------- */

check('capitalisation is ignored', $key('Andhra Pradesh') === $key('andhra pradesh'));
check('a slug matches the name it was made from', $key('Andhra Pradesh') === $key('andhra-pradesh'));
check('surrounding space is ignored', $key('  Delhi  ') === $key('Delhi'));
check('repeated inner space is ignored', $key('New   Delhi') === $key('New Delhi'));
check('accents fold to their base letter', $key('Málaga') === $key('Malaga'));
check('apostrophes and hyphens are separators', $key("Villeneuve-d'Ascq") === $key('Villeneuve d Ascq'));
check('digits are kept', $key('Region 9') === 'region 9');
check('a name of only punctuation reduces to nothing', $key('---') === '');
check('an empty name stays empty', $key('') === '');

// The stored name keeps the capitalisation the catalog publishes, because that is what a
// visitor reads; only the comparison is folded. Guard the direction of that: the key is
// not what gets written back.
check('normalising lowercases, so it is a comparison form and not a stored one', $key('Delhi') === 'delhi');

/* ---------------------------------------------------------------- *
 * A row must not make itself ambiguous.
 * ---------------------------------------------------------------- */

// A slug is usually the name with the spaces replaced, so both reduce to one key. Counted
// as two, every row collides with itself, nothing is ever matched, and a change of source
// re-inserts the entire country.
$same = $keysOf->invoke(null, 'Andhra Pradesh', 'andhra-pradesh');
check('a name and the slug made from it count once', count($same) === 1, 'got ' . count($same));

$differs = $keysOf->invoke(null, 'Delhi', 'new-delhi');
check('a slug that differs from the name counts twice', count($differs) === 2, 'got ' . count($differs));

$blank = $keysOf->invoke(null, 'Delhi', '');
check('an absent slug contributes nothing', $blank === array('delhi'));

/* ---------------------------------------------------------------- *
 * Which stored row an incoming one is allowed to take over.
 * ---------------------------------------------------------------- */

$match = $class->getMethod('matchRow');
$match->setAccessible(true);
$importer = $class->newInstanceWithoutConstructor();

$stored = static function (int $id, ?int $sourceId, string $name, string $slug): array {
    return array(
        'pk_i_id' => $id, 'i_source_id' => $sourceId, 's_name' => $name, 's_slug' => $slug,
        'd_coord_lat' => null, 'd_coord_long' => null, 'b_active' => 1,
    );
};

$index = static function (array $rows) use ($class, $importer): array {
    $indexRow = $class->getMethod('indexRow');
    $indexRow->setAccessible(true);
    $all = $bySource = $bySlug = $byName = array();
    foreach ($rows as $row) {
        $indexRow->invokeArgs($importer, array($row, &$all, &$bySource, &$bySlug, &$byName));
    }

    return array($all, $bySource, $bySlug, $byName);
};

// A row carrying the previous dataset's id, which the incoming data does not use.
[$rows, $bySource, $bySlug, $byName] = $index(array($stored(7, 4017, 'Andhra Pradesh', 'andhra-pradesh')));

$result = $match->invoke(
    $importer,
    1159,
    'andhra-pradesh',
    'Andhra Pradesh',
    $bySource,
    $bySlug,
    $byName,
    $rows,
    array(),
    array(1159 => true),
    array()
);
check(
    'a row whose id belongs to a retired source is adopted by name',
    $result !== null && (int) $result[0]['pk_i_id'] === 7 && $result[1] === LocationImporter::MATCH_SLUG
);

// The same row, but this import does still carry its id: something else will claim it.
$result = $match->invoke(
    $importer,
    1159,
    'andhra-pradesh',
    'Andhra Pradesh',
    $bySource,
    $bySlug,
    $byName,
    $rows,
    array(),
    array(1159 => true, 4017 => true),
    array()
);
check('a row an incoming id still claims is not stolen by a name', $result === null);

// The id always wins when it is there, whatever the names say.
[$rows, $bySource, $bySlug, $byName] = $index(array(
    $stored(7, 1159, 'Renamed Since', 'renamed-since'),
    $stored(8, 4017, 'Andhra Pradesh', 'andhra-pradesh'),
));
$result = $match->invoke(
    $importer,
    1159,
    'andhra-pradesh',
    'Andhra Pradesh',
    $bySource,
    $bySlug,
    $byName,
    $rows,
    array(),
    array(1159 => true),
    array()
);
check(
    'the id is preferred over a name that points elsewhere',
    $result !== null && (int) $result[0]['pk_i_id'] === 7 && $result[1] === LocationImporter::MATCH_SOURCE
);

// Two stored rows sharing a name: the name identifies neither.
[$rows, $bySource, $bySlug, $byName] = $index(array(
    $stored(11, 501, 'Gopalpur', 'gopalpur'),
    $stored(12, 502, 'Gopalpur', 'gopalpur'),
));
$result = $match->invoke(
    $importer,
    900,
    'gopalpur',
    'Gopalpur',
    $bySource,
    $bySlug,
    $byName,
    $rows,
    array(),
    array(900 => true),
    array()
);
check('a name held by two stored rows matches neither', $result === null);

// One stored row, but the incoming data uses the name twice: still not identifying.
[$rows, $bySource, $bySlug, $byName] = $index(array($stored(11, 501, 'Gopalpur', 'gopalpur')));
$result = $match->invoke(
    $importer,
    900,
    'gopalpur',
    'Gopalpur',
    $bySource,
    $bySlug,
    $byName,
    $rows,
    array(),
    array(900 => true),
    array('gopalpur' => true)
);
check('a name the import itself repeats matches nothing', $result === null);

// An already-claimed row is not handed out twice.
$result = $match->invoke(
    $importer,
    900,
    'gopalpur',
    'Gopalpur',
    $bySource,
    $bySlug,
    $byName,
    $rows,
    array(11 => true),
    array(900 => true),
    array()
);
check('a row already claimed this run is not matched again', $result === null);

// A catalog published before ids existed makes no competing claim.
[$rows, $bySource, $bySlug, $byName] = $index(array($stored(9, 4017, 'Goa', 'goa')));
$result = $match->invoke($importer, null, 'goa', 'Goa', $bySource, $bySlug, $byName, $rows, array(), array(), array());
check('an incoming row with no id may still match by name', $result !== null && (int) $result[0]['pk_i_id'] === 9);

// Accented stored name, plain incoming one.
[$rows, $bySource, $bySlug, $byName] = $index(array($stored(21, 4100, 'Málaga', 'malaga')));
$result = $match->invoke($importer, 5001, 'malaga', 'Malaga', $bySource, $bySlug, $byName, $rows, array(), array(5001 => true), array());
check('accents do not stop a match', $result !== null && (int) $result[0]['pk_i_id'] === 21);

echo "\n----------------------------------------\n";
echo "RESULT: $ok passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);

/* file end: ./tests/location-matching.php */
