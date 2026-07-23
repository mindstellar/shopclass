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
 * Characterization pins for the OSCLocale model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * listAllCodes()/listAllEnabled()/findByCode()/deleteLocale() move to the
 * parameterized query layer.
 *
 * listAllEnabled($isBo) is one method that filters on two different flags
 * depending on its argument: b_enabled for the front office (the default),
 * b_enabled_bo for the back office. Both flags are pinned independently below
 * with fixtures where they disagree, so a conversion that swaps one flag for
 * the other fails loudly rather than passing by coincidence.
 *
 * deleteLocale() runs six unconditional deletes and returns only the last
 * one's outcome; the first five (cascading child rows in other tables) have
 * always had their own return values discarded, so a failure on any one of
 * them must not stop the rest from running or change the method's return
 * value. A null code is a special case: DBCommandClass::_where() appends a
 * bare comparison operator with no right-hand side for a null value, which is
 * a SQL syntax error every delete() call converts to bool false, while a real
 * code that simply matches no rows succeeds and reports int 0 — the two
 * outcomes are pinned separately because they are not the same value.
 *
 * insertLocaleInfo() has no query of its own: it calls findByCode() (pinned
 * here) and then the inherited DAO::update()/insert() base methods, out of
 * scope for this conversion. Exercising it end to end is still worthwhile
 * because it depends on findByCode()'s exact return shape — a list of rows,
 * not a single row — and that shape feeds a latent defect: on an existing
 * locale, array_merge() layers the whole numerically-indexed row list on top
 * of the string-keyed $values array instead of merging field-by-field, so
 * checkFieldKeys() rejects the numeric key and the update always reports
 * false. That is pinned as observed behaviour, not fixed, since it is not
 * caused by anything this conversion touches.
 *
 * Usage:  php tests/models/osclocale.php          (standalone, own scratch database)
 *         php tests/run-models.php osclocale      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// deleteLocale() calls osc_run_hook('delete_locale', ...) unconditionally. This
// is the real helper, not a stand-in: Plugins::runHook() is a no-op whenever
// nothing has registered that hook, which is always true here, so requiring it
// carries no extra setup burden.
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hPlugins.php';

$admin = scratchdb_session('osc_models_osclocale');
$table = DB_TABLE_PREFIX . 't_locale';

$model = OSCLocale::newInstance();

/**
 * Insert a locale row with full control over both enabled flags — the shared
 * seed_locale() helper hardcodes both to 1, which cannot exercise a method
 * that filters on one flag while ignoring the other.
 */
$seedLocaleFlags = static function (
    string $code,
    string $name,
    int $enabled,
    int $enabledBo
) use ($admin): void {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_locale
         (pk_c_code, s_name, s_short_name, s_description, s_version, s_author_name,
          s_author_url, s_currency_format, s_date_format, b_enabled, b_enabled_bo)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'sssssssssii',
        array($code, $name, $name, $name, '1.0', 'Mindstellar', 'https://example.test', '$ ', 'd/m/Y', $enabled, $enabledBo)
    );
};

/**
 * Insert one child row into a cascade table, for deleteLocale() fixtures.
 * Each of these tables keys on (some other NOT NULL id, fk_c_locale_code), so
 * $n supplies the other half of the composite primary key — unique per call,
 * never reused across locales, so two rows for the same locale need two
 * different $n values.
 */
$seedChildRow = static function (string $table, string $localeCode, int $n) use ($admin): void {
    switch ($table) {
        case DB_TABLE_PREFIX . 't_category_description':
            seed_exec(
                $admin,
                "INSERT INTO $table (fk_i_category_id, fk_c_locale_code, s_name, s_description, s_slug) VALUES (?, ?, ?, ?, ?)",
                'issss',
                array($n, $localeCode, "name$n", "desc$n", "slug$n")
            );
            break;
        case DB_TABLE_PREFIX . 't_item_description':
            seed_exec(
                $admin,
                "INSERT INTO $table (fk_i_item_id, fk_c_locale_code, s_title, s_description) VALUES (?, ?, ?, ?)",
                'isss',
                array($n, $localeCode, "title$n", "body$n")
            );
            break;
        case DB_TABLE_PREFIX . 't_keywords':
            seed_exec(
                $admin,
                "INSERT INTO $table (s_md5, fk_c_locale_code, s_original_text, s_anchor_text, s_normalized_text) VALUES (?, ?, ?, ?, ?)",
                'sssss',
                array(md5((string) $n), $localeCode, "orig$n", "anchor$n", "norm$n")
            );
            break;
        case DB_TABLE_PREFIX . 't_user_description':
            seed_exec(
                $admin,
                "INSERT INTO $table (fk_i_user_id, fk_c_locale_code) VALUES (?, ?)",
                'is',
                array($n, $localeCode)
            );
            break;
        case DB_TABLE_PREFIX . 't_pages_description':
            seed_exec(
                $admin,
                "INSERT INTO $table (fk_i_pages_id, fk_c_locale_code, s_title) VALUES (?, ?, ?)",
                'iss',
                array($n, $localeCode, "title$n")
            );
            break;
    }
};

$countRowsForLocale = static function (string $table, string $localeCode) use ($admin): int {
    $stmt = $admin->prepare("SELECT COUNT(*) c FROM $table WHERE fk_c_locale_code = ?");
    $stmt->bind_param('s', $localeCode);
    $stmt->execute();
    $c = (int) $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    return $c;
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale: public surface');

pin(
    'listAllCodes signature is unchanged',
    'public listAllCodes()',
    harness_method_signature('OSCLocale', 'listAllCodes')
);
pin(
    'listAllEnabled signature is unchanged',
    'public listAllEnabled($isBo = false, $indexedByPk = false)',
    harness_method_signature('OSCLocale', 'listAllEnabled')
);
pin(
    'findByCode signature is unchanged',
    'public findByCode($code)',
    harness_method_signature('OSCLocale', 'findByCode')
);
pin(
    'deleteLocale signature is unchanged',
    'public deleteLocale($locale)',
    harness_method_signature('OSCLocale', 'deleteLocale')
);
pin(
    'insertLocaleInfo signature is unchanged',
    "public insertLocaleInfo(\$aLocale, \$localeCode = '')",
    harness_method_signature('OSCLocale', 'insertLocaleInfo')
);
pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('OSCLocale', 'newInstance'));
check('OSCLocale still extends DAO', is_subclass_of('OSCLocale', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_c_code', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array(
        'pk_c_code', 's_name', 's_short_name', 's_description', 's_version', 's_direction',
        's_author_name', 's_author_url', 's_currency_format', 's_dec_point', 's_thousands_sep',
        'i_num_dec', 's_date_format', 's_stop_words', 'b_enabled', 'b_enabled_bo',
    ),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct', 'deleteLocale', 'findByCode', 'insertLocaleInfo',
        'listAllCodes', 'listAllEnabled', 'newInstance',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('OSCLocale')),
        array(
            '__construct', 'newInstance', 'listAllCodes', 'listAllEnabled',
            'findByCode', 'deleteLocale', 'insertLocaleInfo',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * listAllCodes() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale::listAllCodes — empty table');

pin('no rows at all returns an empty array', array(), $model->listAllCodes());

harness_section('OSCLocale::listAllCodes — several locales, any enabled state');

$seedLocaleFlags('en_US', 'English', 1, 1);
$seedLocaleFlags('es_ES', 'Spanish', 0, 0);

$codes = $model->listAllCodes();
check('both codes come back regardless of enabled state', is_array($codes), describe($codes));
pin('exactly two codes', 2, count($codes));
$sorted = $codes;
sort($sorted);
pin('both codes are present', array('en_US', 'es_ES'), $sorted);
check('every value is a string (C4)', all_values_string(array_combine(array_keys($codes), $codes)), describe($codes));

/* ----------------------------------------------------------------------------
 * listAllEnabled() — the return ledger, and which flag each call filters on.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale::listAllEnabled — front office vs back office flags disagree');

// Fixture where insertion order (aa, bb, cc), pk order and s_name order all
// disagree with each other, so a filter swap AND a missing/wrong ORDER BY
// both fail loudly rather than passing by coincidence.
scratchdb_truncate_all($admin);
$seedLocaleFlags('aa_AA', 'Zeta', 1, 0);   // front-office only
$seedLocaleFlags('bb_BB', 'Mango', 1, 1);  // both
$seedLocaleFlags('cc_CC', 'Alpha', 0, 1);  // back-office only

// Both come back ordered by s_name ASC (Mango < Zeta, Alpha < Mango) rather
// than insertion/pk order (aa, bb, cc) — see the dedicated ordering pins below.
$feRows = $model->listAllEnabled(false);
check('front-office call returns an array', is_array($feRows), describe($feRows));
pin('front-office (b_enabled) matches aa_AA and bb_BB, not cc_CC', array('bb_BB', 'aa_AA'), array_column($feRows, 'pk_c_code'));

$boRows = $model->listAllEnabled(true);
pin('back-office (b_enabled_bo) matches bb_BB and cc_CC, not aa_AA', array('cc_CC', 'bb_BB'), array_column($boRows, 'pk_c_code'));

harness_section('OSCLocale::listAllEnabled — row shape and ordering');

pin(
    'each row carries exactly the sixteen schema columns',
    array(
        'pk_c_code', 's_name', 's_short_name', 's_description', 's_version', 's_direction',
        's_author_name', 's_author_url', 's_currency_format', 's_dec_point', 's_thousands_sep',
        'i_num_dec', 's_date_format', 's_stop_words', 'b_enabled', 'b_enabled_bo',
    ),
    array_keys($feRows[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($feRows), describe($feRows));
pin('b_enabled round-trips as a string, not an int (C4)', '1', $feRows[0]['b_enabled']);

// s_name ASC: 'Mango' < 'Zeta', so bb_BB comes before aa_AA — the reverse of
// both insertion order and pk order for this pair.
pin('front-office rows come back ordered by s_name ASC, not insertion/pk order', array('bb_BB', 'aa_AA'), array_column($feRows, 'pk_c_code'));
// s_name ASC: 'Alpha' < 'Mango', so cc_CC comes before bb_BB — again the
// reverse of insertion/pk order for this pair.
pin('back-office rows come back ordered by s_name ASC, not insertion/pk order', array('cc_CC', 'bb_BB'), array_column($boRows, 'pk_c_code'));

harness_section('OSCLocale::listAllEnabled — indexed by primary key');

$indexed = $model->listAllEnabled(false, true);
check('the result is keyed by pk_c_code, not a list', array_key_exists('aa_AA', $indexed) && array_key_exists('bb_BB', $indexed), describe($indexed));
pin('the row under each key is that locale\'s row', 'Mango', $indexed['bb_BB']['s_name']);
pin('a non-indexed call is still a plain list', array(0, 1), array_keys($model->listAllEnabled(false)));

harness_section('OSCLocale::listAllEnabled — no match');

scratchdb_truncate_all($admin);
$seedLocaleFlags('de_DE', 'German', 0, 0);
pin('nothing enabled front-office returns an empty array', array(), $model->listAllEnabled(false));
pin('nothing enabled back-office returns an empty array', array(), $model->listAllEnabled(true));
pin('nothing enabled, indexed, still returns an empty array', array(), $model->listAllEnabled(false, true));

/* ----------------------------------------------------------------------------
 * findByCode() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale::findByCode — a match');

scratchdb_truncate_all($admin);
$seedLocaleFlags('en_US', 'English', 1, 1);
$seedLocaleFlags('es_ES', 'Spanish', 1, 1);

$rows = $model->findByCode('en_US');
check('the result is a LIST of rows, not a single row', is_array($rows) && array_keys($rows) === array(0), describe($rows));
pin('exactly one row for a primary-key lookup', 1, count($rows));
pin('the row carries the requested code', 'en_US', $rows[0]['pk_c_code']);
pin('s_name round-trips', 'English', $rows[0]['s_name']);
check('every value in the row is a string or null (C4)', all_values_string($rows[0]), describe($rows[0]));

harness_section('OSCLocale::findByCode — a second code is matched independently');

pin('es_ES resolves to its own row', 'Spanish', $model->findByCode('es_ES')[0]['s_name']);

harness_section('OSCLocale::findByCode — no match');

pin('an unknown code returns an empty array', array(), $model->findByCode('xx_XX'));

harness_section('OSCLocale::findByCode — malformed lookup');

/* Passing null builds a comparison with no right-hand side, so the query
 * fails at the driver — the same quirk pinned for Cron::getCronByType(null)
 * and CityArea::findByName(null). It converges with the plain no-match case
 * here: both return an empty array. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null code returns an empty array rather than raising', array(), $model->findByCode(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * deleteLocale() — the return ledger and the cascade it performs.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale::deleteLocale — deletes across every related table');

scratchdb_truncate_all($admin);
$seedLocaleFlags('en_US', 'English', 1, 1);
$seedLocaleFlags('es_ES', 'Spanish', 1, 1);

$cascadeTables = array(
    DB_TABLE_PREFIX . 't_category_description',
    DB_TABLE_PREFIX . 't_item_description',
    DB_TABLE_PREFIX . 't_keywords',
    DB_TABLE_PREFIX . 't_user_description',
    DB_TABLE_PREFIX . 't_pages_description',
);
$nextChildId = 1;
foreach ($cascadeTables as $t) {
    $seedChildRow($t, 'en_US', $nextChildId++);
    $seedChildRow($t, 'en_US', $nextChildId++); // two rows per table for the target locale
    $seedChildRow($t, 'es_ES', $nextChildId++); // one row that must survive
}

$ret = $model->deleteLocale('en_US');
pin('deleting the matched locale row reports int 1, not bool true', 1, $ret);
foreach ($cascadeTables as $t) {
    pin("both en_US rows are gone from $t", 0, $countRowsForLocale($t, 'en_US'));
    pin("the es_ES row in $t survives untouched", 1, $countRowsForLocale($t, 'es_ES'));
}
pin('the locale row itself is gone', array(), $model->findByCode('en_US'));
pin('a different locale is untouched', 1, count($model->findByCode('es_ES')));

harness_section('OSCLocale::deleteLocale — no matching locale row');

pin('deleting a code with no matching row returns int 0, not bool false', 0, $model->deleteLocale('xx_XX'));

harness_section('OSCLocale::deleteLocale — malformed lookup (null code)');

/* A null code fails at the driver for every one of the six deletes; that
 * failure has always been swallowed by the first five (their own return
 * value was already discarded) and surfaces as bool false only through the
 * last one, which is what deleteLocale() itself returns. A non-null code
 * that simply matches nothing instead succeeds and reports int 0 (pinned
 * just above) — the two are deliberately not the same value. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null locale code returns bool false rather than raising', false, $model->deleteLocale(null));
error_reporting($prevLevel);
pin('nothing was deleted by the malformed call', 1, count($model->findByCode('es_ES')));

/* ----------------------------------------------------------------------------
 * insertLocaleInfo() — exercised end to end because its behaviour depends on
 * findByCode()'s exact return shape.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale::insertLocaleInfo — bad input');

pin('a non-array argument returns bool false', false, $model->insertLocaleInfo('not-an-array'));

harness_section('OSCLocale::insertLocaleInfo — fresh locale');

scratchdb_truncate_all($admin);
$freshLocale = array(
    'locale_code'     => 'it_IT',
    'name'            => 'Italian',
    'short_name'      => 'Italian',
    'description'     => 'Italian locale',
    'version'         => '1.0',
    'direction'       => 'ltr',
    'author_name'     => 'Mindstellar',
    'author_url'      => 'https://example.test',
    'currency_format' => '%s €',
    'date_format'     => 'd/m/Y',
);
$insertRet = $model->insertLocaleInfo($freshLocale);
pin('a fresh locale insert returns bool true', true, $insertRet);
$stored = $model->findByCode('it_IT');
pin('exactly one row now exists for the new code', 1, count($stored));
pin('b_enabled is forced to 0 on a fresh import, regardless of caller input', '0', $stored[0]['b_enabled']);
pin('b_enabled_bo is forced to 1 on a fresh import', '1', $stored[0]['b_enabled_bo']);

harness_section('OSCLocale::insertLocaleInfo — an existing locale never actually updates');

/* findByCode() returns a LIST of rows (index 0 => the row), not the row
 * itself. insertLocaleInfo() unsets $existingLocale['s_version'] (a no-op:
 * that key lives at $existingLocale[0]['s_version'], not at the top level)
 * and array_merge()s $values with that list, which layers a numeric key 0
 * holding the whole nested row on top of the string-keyed $values. DAO::
 * update()'s checkFieldKeys() rejects that numeric key, so the update always
 * reports false — the row is left exactly as it was. This is pinned as
 * observed behaviour: it predates this conversion and is not caused by it. */
$before = $model->findByCode('it_IT')[0];
$updateRet = $model->insertLocaleInfo($freshLocale);
pin('calling it again on the same code returns bool false', false, $updateRet);
pin('the stored row is unchanged', $before, $model->findByCode('it_IT')[0]);

/* ----------------------------------------------------------------------------
 * Query cost.
 * ------------------------------------------------------------------------- */
harness_section('OSCLocale: query cost');

pin('one listAllCodes() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listAllCodes();
}));
pin('one listAllEnabled() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listAllEnabled();
}));
pin('one findByCode() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByCode('it_IT');
}));
pin('one deleteLocale() call on a matching code costs six queries (5 cascades + 1 own row)', 6, harness_query_count(static function () use ($model) {
    $model->deleteLocale('nonexistent-code-cost-probe');
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/osclocale.php */
