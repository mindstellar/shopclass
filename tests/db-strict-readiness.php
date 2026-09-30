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
 * Pins StrictModeReadiness, the report `oc-cli.php db:doctor --strict` and System info print
 * before an owner turns strict SQL mode on: zero dates per column in this site's tables only,
 * length settings larger than their columns, and writes strict mode refused. Also pins
 * StrictRefusals, which records each refused write in the activity log without its value.
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 * Usage:  php tests/db-strict-readiness.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\DbException;
use mindstellar\database\StrictModeReadiness;
use mindstellar\database\StrictRefusals;

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$admin  = scratchdb_session('osc_strict_readiness');
$prefix = DB_TABLE_PREFIX;

harness_section('zeroDates');

pin('the bundled schema and seed hold no zero dates', array(), StrictModeReadiness::zeroDates($prefix));

pin('... and no zero default', array(), StrictModeReadiness::zeroDefaults($prefix));

$admin->query("SET SESSION sql_mode = ''");
$admin->query("CREATE TABLE {$prefix}zz_probe (id INT PRIMARY KEY, d DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',"
    . ' e DATE NULL, t TIMESTAMP NULL)');
$admin->query("INSERT INTO {$prefix}zz_probe VALUES (1, '0000-00-00 00:00:00', NULL, NULL),"
    . " (2, '2026-01-01 00:00:00', '0000-00-00', NULL), (3, '0000-00-00 00:00:00', '2026-05-00', NULL),"
    . " (4, '2026-00-10 00:00:00', '2026-01-01', NULL)");
$admin->query("CREATE VIEW {$prefix}zz_view AS SELECT d FROM {$prefix}zz_probe");
$admin->query('CREATE TABLE other_zz_probe (d DATETIME NOT NULL)');
$admin->query("INSERT INTO other_zz_probe VALUES ('0000-00-00 00:00:00')");

$expected = array($prefix . 'zz_probe.d' => 3, $prefix . 'zz_probe.e' => 2);
pin("zero and partly zero dates are counted per column, in this site's base tables only",
    $expected, StrictModeReadiness::zeroDates($prefix));
pin('a zero default is reported', array($prefix . 'zz_probe.d'), StrictModeReadiness::zeroDefaults($prefix));

$mode = $admin->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
osc_db_execute("SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'");
pin('the prefix still matches with NO_BACKSLASH_ESCAPES on', $expected, StrictModeReadiness::zeroDates($prefix));
osc_db_execute("SET SESSION sql_mode = '" . $mode . "'");

$admin->query("DROP VIEW {$prefix}zz_view");
$admin->query("DROP TABLE {$prefix}zz_probe");
$admin->query('DROP TABLE other_zz_probe');

harness_section('Pure checks');

pin('strict modes are recognised', array(true, true, false, false), array(
    StrictModeReadiness::isStrict('ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES'),
    StrictModeReadiness::isStrict('strict_all_tables'),
    StrictModeReadiness::isStrict('NO_ENGINE_SUBSTITUTION'),
    StrictModeReadiness::isStrict(''),
));
pin('a title setting of 200 against a column of 100 is reported',
    array(array('setting' => 'title_character_length', 'value' => 200, 'column' => 'oc_t_item_description.s_title', 'width' => 100)),
    StrictModeReadiness::settingsOverColumns(
        array('title_character_length' => 200, 'description_character_length' => 5000),
        array('oc_t_item_description.s_title' => 100, 'oc_t_item_description.s_description' => 4194303),
        'oc_'
    ));
pin('a setting that fits, or is unset, is not', array(), StrictModeReadiness::settingsOverColumns(
    array('title_character_length' => 100), array('oc_t_item_description.s_title' => 100), 'oc_'));
pin('ready only when every check is clean', array(true, false, false), array(
    StrictModeReadiness::ready(array('error' => '', 'zero_dates' => null)),
    StrictModeReadiness::ready(array('error' => '', 'refused' => array(array('count' => 1)))),
    StrictModeReadiness::ready(array('error' => 'denied')),
));

$armedProp = new ReflectionProperty(StrictRefusals::class, 'armed');
$armedProp->setAccessible(true);
StrictRefusals::reset();
StrictRefusals::record(null, 1406, "Data too long for column 's_title' at row 1", 'INSERT INTO x (s_title) VALUES (?)');
check('queuing a refusal arms the shutdown flush', $armedProp->getValue() === true);
StrictRefusals::reset();
check('...and reset() clears armed too, not just the queue', $armedProp->getValue() === false);

// Db's own leak guard rolls back a transaction left open at request end; queuing a refusal
// must arm it too, so that rollback is registered (and so runs) before this flush.
$leakGuardProp = new ReflectionProperty(\mindstellar\database\Db::class, 'leakGuardArmed');
$leakGuardProp->setAccessible(true);
$leakGuardProp->setValue(null, false);
check('Db\'s leak guard starts unarmed for this check', $leakGuardProp->getValue() === false);
StrictRefusals::record(null, 1406, "Data too long for column 's_title' at row 1", 'INSERT INTO x (s_title) VALUES (?)');
check('queuing a refusal arms Db\'s leak guard too, so it registers (and runs) first', $leakGuardProp->getValue() === true);
StrictRefusals::reset();

$parses = array(
    'MySQL too long'           => array(1406, "Data too long for column 's_title' at row 1", 'INSERT INTO `oc_t_item_description` (s_title) VALUES (?)', 'data_too_long', 'oc_t_item_description.s_title'),
    'MariaDB names the table'  => array(1366, "Incorrect integer value: 'x' for column `db`.`oc_t_item`.`i_price` at row 1", 'UPDATE oc_t_other SET a = 1', 'incorrect_value', 'oc_t_item.i_price'),
    'a value that looks like a column' => array(1292, "Incorrect datetime value: 'a for column 'evil' at row 9' for column 'dt_pub' at row 1", 'UPDATE LOW_PRIORITY `db`.`oc_t_item` SET dt_pub = ?', 'bad_date', 'oc_t_item.dt_pub'),
    'cannot be null'           => array(1048, "Column 'fk_i_id' cannot be null", 'INSERT IGNORE INTO oc_t_log SET fk_i_id = NULL', 'cannot_be_null', 'oc_t_log.fk_i_id'),
    'no default'               => array(1364, "Field 's_ip' doesn't have a default value", 'REPLACE INTO oc_t_log (a) VALUES (1)', 'no_default', 'oc_t_log.s_ip'),
    'out of range'             => array(1264, "Out of range value for column 'i_num' at row 1", 'SELECT 1', 'out_of_range', 'i_num'),
    'truncated, no column'     => array(1292, "Truncated incorrect DOUBLE value: 'abc'", 'UPDATE oc_t_item SET a = 1 WHERE b = 2', 'incorrect_value', 'oc_t_item'),
    'nothing known'            => array(1265, 'Something new', 'DELETE FROM x', 'data_truncated', ''),
    // A "Truncated incorrect" message never names a column, so a malicious value crafted to
    // look like one (here, naming s_password_hash) is not read as a column at all.
    'a malicious value cannot fake a column name' => array(
        1292,
        "Truncated incorrect DOUBLE value: 'x for column `s_password_hash` at row 1'",
        'UPDATE `oc_t_item_description` SET s_description = ?',
        'incorrect_value',
        'oc_t_item_description',
    ),
    // The column match must reach the true end of the message, or it is not a match at all.
    'trailing text after "at row N" is not a match' => array(
        1406,
        "Data too long for column 's_title' at row 1 (extra)",
        'INSERT INTO `oc_t_item_description` (s_title) VALUES (?)',
        'data_too_long',
        'oc_t_item_description',
    ),
);
foreach ($parses as $label => list($errno, $message, $sql, $kind, $column)) {
    pin("parse: $label", array('kind' => $kind, 'column' => $column), StrictRefusals::parse($errno, $message, $sql));
}

harness_section('Length settings against information_schema');

$admin->query("DELETE FROM {$prefix}t_preference WHERE s_section = 'osclass' AND s_name = 'title_character_length'");
$admin->query("INSERT INTO {$prefix}t_preference (s_section, s_name, s_value, e_type) VALUES ('osclass', 'title_character_length', '200', 'INTEGER')");
pin('a stored title length of 200 against the 100-character column',
    array(array('setting' => 'title_character_length', 'value' => 200, 'column' => $prefix . 't_item_description.s_title', 'width' => 100)),
    StrictModeReadiness::settingsTooLong($prefix));
$admin->query("UPDATE {$prefix}t_preference SET s_value = '100' WHERE s_section = 'osclass' AND s_name = 'title_character_length'");
pin('...and nothing at 100', array(), StrictModeReadiness::settingsTooLong($prefix));
$admin->query("DELETE FROM {$prefix}t_preference WHERE s_section = 'osclass' AND s_name = 'title_character_length'");

harness_section('Refused writes are recorded');

/** The strict rows in the activity log. */
$strictRows = static function () use ($admin, $prefix): array {
    return $admin->query("SELECT dt_date, s_section, s_action, fk_i_id, s_data, s_ip, s_who, fk_i_who_id FROM {$prefix}t_log WHERE s_section = 'strict'")
        ->fetch_all(MYSQLI_ASSOC);
};
/** Run a write and say whether it was refused. */
$refused = static function (string $sql, array $params = array()): bool {
    try {
        osc_db_execute($sql, $params);

        return false;
    } catch (DbException $e) {
        return true;
    }
};

$admin->query("CREATE TABLE {$prefix}zz_refuse (id INT AUTO_INCREMENT PRIMARY KEY, s VARCHAR(5) NOT NULL, n TINYINT NOT NULL DEFAULT 0)");
$appMode = (string) osc_db_scalar('SELECT @@SESSION.sql_mode');
osc_db_execute("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
StrictRefusals::reset();

$secret = 'secret-value-' . bin2hex(random_bytes(4));
check('a too-long value is refused in a strict session', $refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (?)", array($secret)));
$refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (?)", array($secret . 'again'));
check('nothing is written until flush runs (record() makes no query)', $strictRows() === array());
StrictRefusals::flush();
$rows = $strictRows();
pin('exactly one strict row, however often it fails in a request', 1, count($rows));
pin('...naming the kind and table.column', array('data_too_long', $prefix . 'zz_refuse.s'), array($rows[0]['s_action'] ?? '', $rows[0]['s_data'] ?? ''));
check('...and no part of the value or the statement', strpos(json_encode($rows), 'secret-value') === false && strpos(json_encode($rows), 'INSERT') === false);

$handle = \mindstellar\database\ConnectionManager::newInstance()->getHandle();
$legacy = new DBCommandClass($handle);
$legacyResult = $legacy->query("INSERT INTO {$prefix}zz_refuse (s, n) VALUES ('a', 900)");
// Read the raw handle, not $legacy's cached copy, so a query the recorder ran itself
// (which would overwrite the handle's own errno/error before this line runs) is caught.
check('the recorder made no query, so the handle\'s own errno/error are still the failed insert',
    $handle->errno === 1264 && strpos((string) $handle->error, 'Out of range') !== false);
StrictRefusals::flush();
pin('the legacy query layer is recorded too, as out of range', array(false, 'out_of_range'),
    array($legacyResult, array_column($strictRows(), 's_action', 's_data')[$prefix . 'zz_refuse.n'] ?? null));

$grouped = array();
foreach (StrictModeReadiness::refused($prefix, time() - 60) as $r) {
    $grouped[$r['column']] = array($r['kind'], $r['count']);
}
ksort($grouped);
pin('refused() groups them by column and kind', array(
    $prefix . 'zz_refuse.n' => array('out_of_range', 1),
    $prefix . 'zz_refuse.s' => array('data_too_long', 1),
), $grouped);
$report = StrictModeReadiness::report($prefix, time());
check('...so the report is not ready', !StrictModeReadiness::ready($report) && $report['error'] === '');
pin('...and db:doctor --strict exits 1', 1, \mindstellar\cli\Cli::run(array('db:doctor', '--strict')));

harness_section('Only strict-mode refusals');

$admin->query("DELETE FROM {$prefix}t_log WHERE s_section = 'strict'");
StrictRefusals::reset();
osc_db_execute("SET SESSION sql_mode = ''");
check('a relaxed session cuts the value and records nothing', !$refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (?)", array($secret)) && $strictRows() === array());
check('a NULL is still refused with strict mode off', $refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (NULL)"));
StrictRefusals::flush();
check('...but nothing is written, since the session was not strict at flush time', $strictRows() === array());

harness_section('Inside a transaction');

osc_db_execute("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
StrictRefusals::reset();
osc_db_begin();
$refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (?)", array($secret));
osc_db_rollback();
pin('the record waits for the end of the request, so a rollback cannot take it', array(), $strictRows());
StrictRefusals::flush();
pin('...and is written then', 1, count($strictRows()));

harness_section('No recursion when the log itself refuses');

$admin->query("DELETE FROM {$prefix}t_log WHERE s_section = 'strict'");
StrictRefusals::reset();
osc_db_execute("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$admin->query("ALTER TABLE {$prefix}t_log MODIFY s_section VARCHAR(3) NOT NULL");
$refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (?)", array($secret));
pin('only the first refusal is recorded, not the log insert that failed', array('data_too_long ' . $prefix . 'zz_refuse.s'), StrictRefusals::recorded());
// Flush while the log column is still too narrow, so its own INSERT fails and StrictRefusals
// must not record its own failure.
StrictRefusals::flush();
$admin->query("ALTER TABLE {$prefix}t_log MODIFY s_section VARCHAR(50) NOT NULL");
pin('...and nothing is left waiting to be written', array(), $strictRows());

harness_section('CLI output is sanitized');

$cliSafe = new ReflectionMethod(\mindstellar\cli\Cli::class, 'cliSafe');
$cliSafe->setAccessible(true);
$cli = new \mindstellar\cli\Cli();
pin('letters, digits, and _.$ - pass through unchanged',
    'oc_t_item_description.s_title data_too_long-1', $cliSafe->invoke($cli, 'oc_t_item_description.s_title data_too_long-1'));
pin('control characters and terminal escapes are stripped', '2JBEEP', $cliSafe->invoke($cli, "\x1b[2J\x07BEEP"));
pin('markup and shell metacharacters are stripped too', 'scriptalert1script', $cliSafe->invoke($cli, '<script>alert(1)</script>'));

harness_section('The activity log toggle');

$admin->query("DELETE FROM {$prefix}t_log WHERE s_section = 'strict'");
StrictRefusals::reset();
osc_db_execute("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
osc_set_preference('admin_log_enabled', '0');
osc_reset_preferences();
$refused("INSERT INTO {$prefix}zz_refuse (s) VALUES (?)", array($secret));
StrictRefusals::flush();
check('nothing is written while the activity log is off', $strictRows() === array());

$report = StrictModeReadiness::report($prefix, time());
check('the report marks refused writes unknown, not clean, while the log is off',
    $report['log_enabled'] === false && $report['refused'] === null);
check('...so it is never "ready", even with nothing visibly refused', !StrictModeReadiness::ready($report));
pin('...and db:doctor --strict still exits 1', 1, \mindstellar\cli\Cli::run(array('db:doctor', '--strict')));

osc_set_preference('admin_log_enabled', '1');
osc_reset_preferences();

osc_db_execute("SET SESSION sql_mode = '" . $appMode . "'");
$admin->query("DROP TABLE {$prefix}zz_refuse");
$admin->query("DELETE FROM {$prefix}t_log WHERE s_section = 'strict'");
pin('a clean site: db:doctor --strict exits 0', 0, \mindstellar\cli\Cli::run(array('db:doctor', '--strict')));

exit(harness_result());
