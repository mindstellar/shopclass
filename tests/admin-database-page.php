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
 * Tools > Database: Repair only offers and runs when something is fixable, and the old
 * Tools URLs (upgrade, backup, database) still resolve.
 *
 * No database. Usage:  php tests/admin-database-page.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\admin\DatabaseTools;
use mindstellar\database\SchemaDoctor;

$finding = static function (string $kind, string $name = 'idx_x'): array {
    return array('table' => 'oc_t_x', 'kind' => $kind, 'name' => $name, 'declared' => '', 'found' => '');
};

harness_section('What Repair can fix');

pin('nothing found: nothing to repair', array(), DatabaseTools::repairable(array()));
pin('extra items only: nothing to repair', array(), DatabaseTools::repairable(array(
    $finding(SchemaDoctor::EXTRA_COLUMN),
    $finding(SchemaDoctor::EXTRA_INDEX),
    $finding(SchemaDoctor::INDEX_COLUMNS),
    $finding(SchemaDoctor::NULLABILITY),
)));
pin('a missing index is repairable', 1, count(DatabaseTools::repairable(array(
    $finding(SchemaDoctor::EXTRA_INDEX),
    $finding(SchemaDoctor::MISSING_INDEX),
))));
foreach (array(SchemaDoctor::MISSING_TABLE, SchemaDoctor::MISSING_COLUMN, SchemaDoctor::MISSING_INDEX, SchemaDoctor::COLUMN_TYPE) as $kind) {
    check("'$kind' is repairable", count(DatabaseTools::repairable(array($finding($kind)))) === 1);
}

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php');
$view       = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/database.php');

pin('Repair allowed: something fixable, nothing waiting', true, DatabaseTools::repairAllowed(array($finding(SchemaDoctor::MISSING_INDEX)), array()));
pin('Repair refused: nothing found', false, DatabaseTools::repairAllowed(array(), array()));
pin('Repair refused: only extra items', false, DatabaseTools::repairAllowed(array($finding(SchemaDoctor::EXTRA_INDEX)), array()));
pin('Repair refused: an update is waiting', false, DatabaseTools::repairAllowed(array($finding(SchemaDoctor::MISSING_INDEX)), array('0099_x.php')));
check('the server checks repairAllowed() before running Repair', (static function (string $c): bool {
    $refuse = strpos($c, '!DatabaseTools::repairAllowed($findings, $pending)');
    $run    = strpos($c, 'SchemaReconciler($conn))->repair()');

    return $refuse !== false && $run !== false && $refuse < $run;
})($controller));
check('the Repair dialog renders only when something is fixable', (bool) preg_match(
    '/elseif \(\$canRepair\) \{ \?>\s*<\?php osc_admin_confirm_dialog\(array\(\s*\'id\'\s*=> \'db-repair-dialog\'/',
    $view
));
check('the Repair button sits in the "Repair can fix these" group only', substr_count($view, "'confirm' => '#db-repair-dialog'") === 1
    && (bool) preg_match("/'repair' => !\\\$hasPending/", $view));

harness_section('Old URLs');

pin('import lands on the Backup and restore page', '?page=tools&action=backup#restore', DatabaseTools::movedTo('import'));
pin('upgrade keeps its own screen', null, DatabaseTools::movedTo('upgrade'));
pin('database keeps its own screen', null, DatabaseTools::movedTo('database'));

foreach (array('upgrade', 'database', 'backup', 'backup_post', 'backup-sql', 'backup-sql_file', 'backup-zip', 'backup-zip_file', 'import', 'import_post') as $action) {
    check("the controller still routes action=$action", (bool) preg_match("/case \\(?'" . preg_quote($action, '/') . "'\\)?:/", $controller));
}

$menu = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php');
check('the menu keeps tools_upgrade', strpos($menu, "'tools_upgrade'") !== false);
check('the menu keeps tools_database', strpos($menu, "'tools_database'") !== false);
check('the menu no longer lists Import data', strpos($menu, "'tools_import'") === false);
check('a restore replaces tables through the streamed path', strpos((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/backup/BackupJobs.php'), 'DatabaseTools::restore($conn, $handle, $each, true)') !== false
    && strpos($controller, 'executeScript(') === false);
check('the Database page no longer backs up or restores', strpos($view, "'import_post'") === false && strpos($view, 'id="backup"') === false);

harness_section('Restore upload');

pin('no file field', 'No file was uploaded', DatabaseTools::uploadError(array()));
pin('an array-shaped sql[] field is refused, not a TypeError', 'No file was uploaded', DatabaseTools::uploadError(array(
    'name' => array('a.sql'), 'type' => array(''), 'tmp_name' => array('/tmp/x'), 'error' => array(0), 'size' => array(10),
)));
pin('no file chosen', 'No file was uploaded', DatabaseTools::uploadError(array('tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0)));
foreach (array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE) as $code) {
    check("error $code says the file is too large", strpos(DatabaseTools::uploadError(array('tmp_name' => '', 'error' => $code, 'size' => 0)), 'larger than this server accepts') !== false);
}
pin('a partial upload fails plainly', 'The upload failed. Try again.', DatabaseTools::uploadError(array('tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 5)));
pin('a good upload passes', '', DatabaseTools::uploadError(array('tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 5)));

harness_section('Waiting updates in plain words');

pin('number and extension dropped', 'Job queue', DatabaseTools::label('0042_job_queue.php'));
pin('an SQL migration too', 'Initial schema', DatabaseTools::label('0001_initial_schema.sql'));
pin('an unexpected name is kept', '0001.php', DatabaseTools::label('0001.php'));

$dir = sys_get_temp_dir() . '/osc_migration_titles_' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/0099_link_things.php', "<?php\n/**\n * Long explanation.\n *\n * @title Link things to their owner\n */\nreturn null;\n");
file_put_contents($dir . '/0100_no_title.php', "<?php\n/**\n * No title here.\n */\nreturn null;\n");
file_put_contents($dir . '/0101_sql_step.sql', "-- @title Add a column\nALTER TABLE t ADD c INT;\n");
pin('the @title line is used', 'Link things to their owner', DatabaseTools::title($dir, '0099_link_things.php'));
pin('an SQL migration can carry one too', 'Add a column', DatabaseTools::title($dir, '0101_sql_step.sql'));
pin('no @title: the file name in words', 'No title', DatabaseTools::title($dir, '0100_no_title.php'));
pin('a plugin migration name is kept', 'listing-import_0001_tables.php', DatabaseTools::title($dir, 'listing-import_0001_tables.php'));
array_map('unlink', glob($dir . '/*'));
rmdir($dir);

$core = ABS_PATH . 'oc-includes/osclass/installer/migrations';
pin('0057 reads in plain words', 'Link form submissions to their user', DatabaseTools::title($core, '0057_form_submission_user_fk.php'));
foreach (glob($core . '/005[1-7]_*.php') as $file) {
    check(basename($file) . ' has a title', DatabaseTools::title($core, basename($file)) !== DatabaseTools::label(basename($file)));
}

harness_section('Status facts');

pin('MariaDB is named, its build suffix dropped', array('label' => 'MariaDB 11.8.8', 'supported' => true), DatabaseTools::server('11.8.8-MariaDB-ubu2404'));
pin('the old MariaDB 5.5.5- prefix is skipped', array('label' => 'MariaDB 10.6.12', 'supported' => true), DatabaseTools::server('5.5.5-10.6.12-MariaDB-log'));
pin('MySQL is named', array('label' => 'MySQL 8.0.36', 'supported' => true), DatabaseTools::server('8.0.36'));
pin('below the MySQL floor', false, DatabaseTools::server('5.7.4')['supported']);
pin('below the MariaDB floor', false, DatabaseTools::server('10.1.48-MariaDB')['supported']);
pin('an empty server string says nothing', array('label' => '', 'supported' => true), DatabaseTools::server(''));
pin('sizes read in words', array('0 B', '1.5 KB', '63.9 MB'), array(DatabaseTools::bytes(0), DatabaseTools::bytes(1536), DatabaseTools::bytes(67003187)));

exit(harness_result());

/* file end: ./tests/admin-database-page.php */
