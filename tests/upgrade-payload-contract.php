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
 * The upgrade screen and Osclass::upgradeDB() are two halves of one contract, and they
 * are replaced at different moments.
 *
 * An upgrade rewrites the files while a request is already in flight, and an opcode
 * cache can go on serving the previous class after the new templates are on disk. So
 * the screen can be the new one while the answer it receives comes from the old build,
 * and it has to stay truthful across that seam: it reports what it was told, and where
 * it was told nothing it says so rather than inventing an outcome. Reporting "nothing
 * needed changing" at an upgrade that applied twenty updates is worse than saying
 * little, because the owner has no other way to know.
 *
 * Two things are pinned:
 *
 *  - Every key the screen requires is one upgradeDB() actually returns. Dropping or
 *    renaming one on the PHP side leaves the screen reading undefined.
 *  - The screen tolerates their absence, by testing whether the answer itemises its
 *    work at all before concluding that no work was done.
 *
 * The other direction needs no pin: an older screen concatenates data.message and
 * ignores every key it does not know, so new fields cannot disturb it.
 *
 * Usage:  php tests/upgrade-payload-contract.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

$view  = ABS_PATH . 'oc-admin/themes/modern/upgrade/index.php';
$model = ABS_PATH . 'oc-includes/osclass/classes/upgrade/Osclass.php';

$ok = 0;
$failed = 0;

/**
 * @param string $label
 * @param bool   $passed
 * @param string $detail
 */
function check($label, $passed, $detail = '')
{
    global $ok, $failed;
    if ($passed) {
        $ok++;
        echo "PASS  $label\n";
    } else {
        $failed++;
        echo "FAIL  $label" . ($detail !== '' ? "\n        $detail" : '') . "\n";
    }
}

foreach (array($view, $model) as $file) {
    if (!is_readable($file)) {
        fwrite(STDERR, "cannot read $file\n");
        exit(2);
    }
}

$viewSrc  = (string) file_get_contents($view);
$modelSrc = (string) file_get_contents($model);

// Keys the screen reads off the response.
preg_match_all('/\bdata\.([A-Za-z_][A-Za-z0-9_]*)/', $viewSrc, $m);
$read = array_values(array_unique($m[1]));
sort($read);

// Keys upgradeDB() can return, taken from the array literals it hands to json_encode.
$body = '';
if (preg_match('/function upgradeDB\(.*?\n    \}/s', $modelSrc, $fn)) {
    $body = $fn[0];
}
preg_match_all("/'([a-z_]+)'\s*=>/", $body, $m2);
$emitted = array_values(array_unique($m2[1]));
sort($emitted);

echo "screen reads:   " . implode(', ', $read) . "\n";
echo "upgradeDB emits: " . implode(', ', $emitted) . "\n\n";

check('upgradeDB() was located and parsed', $body !== '' && $emitted !== array());
check('the screen reads at least one key', $read !== array());

$missing = array_diff($read, $emitted);
check(
    'every key the screen reads is one upgradeDB() returns',
    $missing === array(),
    $missing === array() ? '' : 'not returned: ' . implode(', ', $missing)
);

// The keys the report is built from. Losing one silently empties a section of the
// screen rather than breaking it, which is why they are named here explicitly.
foreach (array('error', 'message', 'applied', 'repairs') as $key) {
    check("upgradeDB() returns '$key'", in_array($key, $emitted, true));
}

// The seam itself: the screen must decide whether the answer itemises its work before
// concluding none was done, or an older answer reads as "nothing changed".
check(
    'the screen tests whether the answer itemises its work',
    strpos($viewSrc, 'hasOwnProperty') !== false && preg_match('/\bitemised\b/', $viewSrc) === 1,
    'expected a presence test for applied/repairs, not a truthiness test'
);
check(
    'and falls back to the server message when it does not',
    preg_match('/!itemised[\s\S]{0,400}data\.message/', $viewSrc) === 1,
    'expected the !itemised branch to render data.message'
);

// Absent and empty must not collapse into the same branch.
check(
    'an empty list is still reported as nothing changed',
    preg_match('/else if \(!applied\.length && !repairs\.length\)/', $viewSrc) === 1
);

/* ----------------------------------------------------------------------------
 * The permalink table must be recompiled after migrations, not before.
 *
 * Rewrite caches the compiled rules and rebuilds when their stamped version no
 * longer matches the code's -- which is true from the first request after new
 * files land, i.e. possibly before that release's migrations have seeded the
 * preferences new routes are built from. A request that wins that race compiles
 * without them and stamps the new version anyway; the versions then agree, so it
 * never rebuilds, and those routes 404 permanently. rc7's billing permalinks did
 * exactly that. The rebuild belongs in the upgrade, after the migrations, with the
 * preference cache reloaded first -- migrations seed with raw SQL, so the snapshot
 * this request is holding still predates them.
 * ------------------------------------------------------------------------- */
$runPos     = strpos($modelSrc, '$runner->run()');
$rebuildPos = strpos($modelSrc, 'rebuildAndPersistRules()');
$resetPos   = strpos($modelSrc, 'osc_reset_preferences()');

check('upgradeDB() recompiles the permalink table', $rebuildPos !== false);
check(
    '...after the migrations have run, not before',
    $runPos !== false && $rebuildPos !== false && $rebuildPos > $runPos
);
check(
    '...and reloads the preference cache first, since migrations seed with raw SQL',
    $resetPos !== false && $rebuildPos !== false && $resetPos < $rebuildPos
);

echo "\n----------------------------------------\n";
echo "RESULT: $ok passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);

/* file end: ./tests/upgrade-payload-contract.php */
