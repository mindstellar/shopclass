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
 * Tools > Backup and restore: every old backup URL still routes, each action refuses on
 * the demo before it checks the token, a visit forgets nothing until Dismiss is posted,
 * a download takes only a listed name, the browser can no longer name a server folder,
 * the folder is closed on Apache 2.4 and 2.2, a restore asks for the password first, and
 * OSC_DISABLE_WEB_RESTORE hides and refuses restores.
 *
 * No database. Usage:  php tests/admin-backup-page.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\admin\DatabaseTools;
use mindstellar\backup\BackupStore;

$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php');
$view       = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/backup.php');
$database   = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/system-info/database.php');
$sysinfo    = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/system-info.php');
$upgrade    = (string) file_get_contents(ABS_PATH . 'oc-admin/themes/modern/tools/upgrade.php');
$menu       = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php');

/** The body of a case label up to the next case, or of a private method. */
$body = static function (string $name) use ($controller): string {
    if (preg_match("/case \\('" . preg_quote($name, '/') . "'\\):(.*?)(?=\\n\\s*case |\\n\\s*default:)/s", $controller, $m)) {
        return $m[1];
    }
    if (preg_match('/private function ' . preg_quote($name, '/') . '\(.*?\n    \}\n/s', $controller, $m)) {
        return $m[0];
    }

    return '';
};

harness_section('Old URLs');

foreach (array('backup', 'backup_post', 'backup-sql', 'backup-sql_file', 'backup-zip', 'backup-zip_file', 'import', 'import_post',
    'backup_start', 'backup_cancel', 'backup_download', 'backup_delete', 'backup_restore', 'backup_upload', 'backup_dismiss', 'backup_reopen') as $action) {
    check("the controller routes action=$action", (bool) preg_match("/case \\('" . preg_quote($action, '/') . "'\\):/", $controller));
}
pin('import lands on the restore part of the new page', '?page=tools&action=backup#restore', DatabaseTools::movedTo('import'));
pin('backup has its own page again', null, DatabaseTools::movedTo('backup'));
pin('backup_post too', null, DatabaseTools::movedTo('backup_post'));
check('the old backup actions run the new engine', (bool) preg_match(
    "/'backup-sql'\\s*=> array\\('database', 'server'\\),\\s*'backup-sql_file' => array\\('database', 'download'\\),\\s*"
    . "'backup-zip'\\s*=> array\\('files', 'server'\\),\\s*'backup-zip_file' => array\\('files', 'download'\\)/",
    $controller
));
check('import_post still takes the old sql field', strpos($controller, "\$this->backupUpload(\$this->action === 'import_post' ? 'sql' : 'backup_file')") !== false);
check('old links to #backup and #restore on the Database page follow', strpos($sysinfo, "location.hash === '#backup' || location.hash === '#restore'") !== false);
check('old links to #backup-files on the Upgrade page follow', strpos($upgrade, "location.hash === '#backup-files'") !== false);

harness_section('Demo first, then the token');

foreach (array('backupStart', 'backupDownload', 'backupUpload', 'backup_cancel', 'backup_delete', 'backup_restore', 'backup_reopen') as $name) {
    $code  = $body($name);
    $demo  = strpos($code, 'refuseOnDemo(');
    $token = strpos($code, 'osc_csrf_check()');
    check("$name refuses on the demo before it checks the token", $demo !== false && $token !== false && $demo < $token);
}
check('backup_dismiss checks the token', strpos($body('backup_dismiss'), 'osc_csrf_check()') !== false);
$page = $body('backupPage');
check('a plain visit to the page forgets no finished run', $page !== '' && strpos($page, 'clearState') === false
    && strpos($page, 'dismiss') === false && strpos($page, 'osc_add_flash_ok_message') === false);
check('...it shows the run with a Dismiss button that posts', strpos($view, "\$postButton('backup_dismiss', __('Dismiss'))") !== false
    && substr_count($view, "\$postButton('backup_dismiss'") >= 2);
check('the status poll checks the token', (bool) preg_match(
    "/case 'backup_status':\\s*osc_csrf_check\\(\\);/",
    (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php')
));

harness_section('Downloads take listed names only');

$dir   = sys_get_temp_dir() . '/osc_backup_page_' . getmypid() . '/backups/';
$store = new BackupStore($dir);
@mkdir($dir . 'sub', 0700, true);
$name   = '2026-09-29-140213-everything-abcdefghijklmnop.zip';
// The same name one folder up and one folder down, where a path could reach.
file_put_contents($dir . '../' . $name, 'outside');
file_put_contents($dir . 'sub/' . $name, 'below');
$upload = 'upload-abcdefghijklmnop.sql';
file_put_contents($dir . $name, 'zip');
file_put_contents($dir . $upload, 'sql');
file_put_contents($dir . 'notes.zip', 'zip');
symlink('/etc/passwd', $dir . '2026-09-29-140213-database-abcdefghijklmnop.zip');
pin('a listed name', $dir . $name, $store->path($name));
foreach (array(
    '../' . $name                                            => 'a path before the name',
    'sub/' . $name                                           => 'a folder in the name',
    $dir . $name                                             => 'an absolute path',
    'notes.zip'                                              => 'a file that is not a backup',
    '.state.json'                                            => 'the state file',
    '2026-09-29-140213-everything-abcdefghijklmnoq.zip'      => 'a backup that is not there',
    '2026-09-29-140213-database-abcdefghijklmnop.zip'        => 'a link with a backup name',
    '2026-09-29-140213-everything-abcdefghijklmnop.zip.part' => 'an unfinished backup',
) as $bad => $what) {
    pin("refused: $what", null, $store->path($bad));
}
check('a download also needs a backup name, not an upload', strpos($body('backupDownload'), 'preg_match(BackupStore::NAME, $name)') !== false);
check('...and its manifest', strpos($body('backupDownload'), '$store->manifest($name)') !== false);
pin('a name carries 16 random characters', 1, preg_match(BackupStore::NAME, BackupStore::newName('database')));
check('...so two made in the same second differ', BackupStore::newName('files') !== BackupStore::newName('files'));
exec('rm -rf ' . escapeshellarg(dirname($dir)));

harness_section('One fixed folder');

check('the browser can no longer name a folder', strpos($controller, 'bck_dir') === false && strpos($view, 'bck_dir') === false
    && strpos($database, 'bck_dir') === false && strpos($upgrade, 'bck_dir') === false);
check('the Database tab has no backup form', strpos($database, 'backup_form') === false && strpos($database, 'import_post') === false);
check('the Upgrade page has no files backup left', strpos($upgrade, "'backup-zip'") === false);
check('the page names the fixed folder', strpos($view, 'BackupStore::FOLDER') !== false);
check('the menu has Backup and restore', strpos($menu, "'tools_backup'") !== false);
check('...and keeps tools_upgrade', strpos($menu, "'tools_upgrade'") !== false);

$base = sys_get_temp_dir() . '/osc_backup_dir_' . getmypid();
$site = $base . '/site';
$out  = $base . '/private';
@mkdir($site . '/oc-content/downloads/backups', 0777, true);
@mkdir($out, 0777, true);
@symlink($site . '/oc-content', $base . '/link');
$refused = static function (string $dir) use ($site): bool {
    return BackupStore::checkFolder($dir, $site)['error'] !== '';
};
check('a path: the site folder is refused', $refused($site));
check('...a folder inside the site is refused', $refused($site . '/oc-content'));
check('...a /.. path that lands inside the site is refused', $refused($out . '/../site/oc-content'));
check('...a link pointing inside the site is refused', $refused($base . '/link'));
check('...a missing folder is refused', $refused($base . '/nowhere'));
check('...an empty value is refused', $refused(''));
check('...the site backups folder is accepted', !$refused($site . '/oc-content/downloads/backups'));
pin('...a folder outside the site is accepted, as its real path', array('dir' => realpath($out) . '/', 'error' => ''), BackupStore::checkFolder($out, $site));
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    chmod($out, 0500);
    check('...a folder that cannot be written is refused', $refused($out));
    chmod($out, 0700);
}
exec('rm -rf ' . escapeshellarg($base));

$store = new BackupStore(sys_get_temp_dir() . '/osc_backup_protect_' . getmypid());
pin('the folder is made and closed', true, $store->protect());
$htaccess = (string) file_get_contents($store->dir() . '.htaccess');
check('...with an .htaccess denying everyone on Apache 2.4, inside its module check', (bool) preg_match(
    '#<IfModule mod_authz_core\.c>\s*Require all denied\s*</IfModule>#',
    $htaccess
), $htaccess);
check('...and on Apache 2.2', (bool) preg_match('#<IfModule !mod_authz_core\.c>\s*Order allow,deny\s*Deny from all\s*</IfModule>#', $htaccess), $htaccess);
check('...with no bare Require line that breaks Apache 2.2', !preg_match('#^Require#m', $htaccess), $htaccess);
$plain = sys_get_temp_dir() . '/osc_protect_folder_' . getmypid();
@mkdir($plain);
\mindstellar\utility\FileSystem::protectFolder($plain);
pin('the purifier cache and the backups folder get the same .htaccess', $htaccess, (string) file_get_contents($plain . '/.htaccess'));
exec('rm -rf ' . escapeshellarg($plain));
check('...and the purifier cache uses the shared helper', strpos((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/security/PurifierCache.php'), 'FileSystem::protectFolder($dir)') !== false);
check('...an empty index.php', is_file($store->dir() . 'index.php'));
check('...and the probe file', is_file($store->dir() . BackupStore::PROBE));
pin('...readable by the site only', '750', substr(sprintf('%o', fileperms($store->dir())), -3));
$store->saveState(array('status' => 'running'));
pin('the state file is private', '600', substr(sprintf('%o', fileperms($store->dir() . '.state.json')), -3));
exec('rm -rf ' . escapeshellarg($store->dir()));

harness_section('A restore asks for the password again');

$restore = $body('backup_restore');
$verify  = strpos($restore, 'AdminReauth::verify(');
$start   = strpos($restore, 'BackupManager::startRestore(');
check('backup_restore checks the password before it starts anything', $verify !== false && $start !== false && $verify < $start);
check('...with the posted password and code', strpos($restore, "Params::getParamString('password', false, false)") !== false
    && strpos($restore, "Params::getParamString('code')") !== false);
check('...and a refusal leaves before startRestore', (bool) preg_match(
    '/if \(\$reauth !== \'\'\) \{[^}]*redirectTo\([^}]*break;\s*\}/s',
    substr($restore, 0, (int) $start)
));
check('an uploaded file reaches a restore only through backup_restore', strpos($body('backupUpload'), 'startRestore') === false
    && substr_count($controller, 'BackupManager::startRestore(') === 1);
check('the dialog has a password field', strpos($view, "'name'     => 'password'") !== false && strpos($view, "'type'     => 'secret'") !== false);
check('...and a code field when 2FA is on', (bool) preg_match("/if \\(\\\$twoStep\\) \\{.*?'name'     => 'code'/s", $view));
check('the page learns whether 2FA is on', strpos($body('backupPage'), 'AdminTwoFactor::enabled($me)') !== false);
check('a refusal is shown inside the dialog', strpos($view, 'osc_esc_html($reauth)') !== false);

harness_section('OSC_DISABLE_WEB_RESTORE');

$switch = static function (string $env, string $define = ''): string {
    $code = 'define("ABS_PATH", ' . var_export(ABS_PATH, true) . ');' . $define
        . 'require ABS_PATH . "oc-includes/osclass/utils.php"; echo var_export(osc_web_restore_disabled(), true);';

    return (string) shell_exec(($env !== '' ? 'OSC_DISABLE_WEB_RESTORE=' . escapeshellarg($env) . ' ' : 'env -u OSC_DISABLE_WEB_RESTORE ')
        . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
};
pin('unset: restore is on', 'false', $switch(''));
pin('the environment turns it off', 'true', $switch('1'));
pin('the constant turns it off', 'true', $switch('', 'define("OSC_DISABLE_WEB_RESTORE", true);'));
pin('the constant wins over the environment', 'false', $switch('1', 'define("OSC_DISABLE_WEB_RESTORE", false);'));

foreach (array('backup_restore', 'backupUpload') as $name) {
    check("$name refuses when restore is off", strpos($body($name), 'refuseRestoreOff()') !== false);
}
$off = strpos($restore, 'refuseRestoreOff()');
check('...before the password is checked', $off !== false && $verify !== false && $off < $verify);
check('the refusal checks the switch', strpos($body('refuseRestoreOff'), 'if (!osc_web_restore_disabled())') !== false);
check('no confirm dialog is built when restore is off', strpos($body('backupPage'), "if (\$name !== '' && !\$busy && !osc_web_restore_disabled())") !== false);
check('backups still start when restore is off', strpos($body('backupStart'), 'restore_disabled') === false
    && strpos($body('backupStart'), 'refuseRestoreOff') === false);
check('the page hides the Restore… buttons', (bool) preg_match("/if \\(\\\$locked && !\\\$noWeb\\) \\{.*?\\} elseif \\(!\\\$noWeb\\) \\{\\s*osc_admin_action_button\\(array\\(\\s*'label' => __\\('Restore…'\\)/s", $view));
check('...and the upload form, showing the off line instead', (bool) preg_match(
    "/<\\?php if \\(\\\$noWeb\\) \\{ \\?>\\s*<p[^>]*><\\?php echo osc_esc_html\\(\\\$offLine\\); \\?><\\/p>\\s*<\\?php \\} else \\{ \\?>.*?backup_upload.*?<\\?php \\} \\?>\\s*<\\/div>/s",
    $view
));
check('...in the owner\'s words', strpos($view, "__('Restore is turned off on this site. Use the command line.')") !== false);

$nginx = (string) file_get_contents(ABS_PATH . '.docker/prod/nginx.conf');
check('the production nginx closes the downloads folder', (bool) preg_match('#location \^~ /oc-content/downloads/ \{\s*deny all;#', $nginx));

exit(harness_result());

/* file end: ./tests/admin-backup-page.php */
