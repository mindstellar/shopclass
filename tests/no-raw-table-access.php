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
 * Table SQL lives with the table's owner. osc_db_table() or Db::table(), a table name built from
 * DB_TABLE_PREFIX, or a t_ table literal joined into SQL may appear only in:
 * classes/model/**, a *Store.php or *Query.php file, a class extending DAO or
 * mindstellar\base\Model, the DB layer (classes/database/**), and the installer and
 * migrations. Anything else needs an ALLOWED entry with a reason; its count is a ceiling.
 *
 * DB-free.  Usage: php tests/no-raw-table-access.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

/**
 * path => [most matches allowed, reason]. A path ending in / covers a directory. Drop an
 * entry once it is clean.
 */
const ALLOWED = array(
    'oc-includes/osclass/classes/job/JobQueue.php'               => array(21, 'is the t_job_queue store; the class name is public API'),
    'oc-includes/osclass/classes/cli/Cli.php'                    => array(3, 'CLI installer: the first admin and site preferences, as install-functions.php'),
    'oc-includes/osclass/classes/privacy/PersonalData.php'       => array(1, 'reads a fixed map of account tables for the data export'),
    'oc-includes/osclass/classes/search/'                        => array(55, 'the search compiler builds listing SQL that plugins extend with raw fragments'),
);

/**
 * The allow-list entry for a path, or null.
 *
 * @return array{0:?int,1:string,2:string}|null ceiling, reason, entry key
 */
function allowed_entry(string $rel): ?array
{
    foreach (ALLOWED as $key => [$max, $reason]) {
        if ($rel === $key || (str_ends_with($key, '/') && str_starts_with($rel, $key))) {
            return array($max, $reason, $key);
        }
    }

    return null;
}

/** A call of osc_db_table() or Db::table(), not its definition. */
const P_BUILDER = '/(?<!function )(?:\bosc_db_table|\bDb::table)\s*\(/';
/** A table name built on the prefix, a t_ literal joined into SQL, or an interpolated prefix. */
const P_TABLE = '/DB_TABLE_PREFIX\s*\.|\.\s*[\'"]t_[a-z]|%st_[a-z]|\{\$\w+\}t_[a-z]/';

/**
 * The source with comments blanked, line numbers kept.
 */
function code_only(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            $out .= str_repeat("\n", substr_count($t[1], "\n"));
        } else {
            $out .= is_array($t) ? $t[1] : $t;
        }
    }

    return $out;
}

/**
 * Where the rule does not apply.
 */
function table_owner(string $rel, string $code): bool
{
    foreach (array('oc-includes/osclass/classes/model/', 'oc-includes/osclass/classes/database/', 'oc-includes/osclass/installer/', 'oc-includes/osclass/classes/migration/') as $dir) {
        if (str_starts_with($rel, $dir)) {
            return true;
        }
    }
    if (in_array($rel, array('oc-includes/osclass/install-functions.php', 'oc-includes/osclass/classes/base/Model.php', 'oc-includes/osclass/helpers/hDatabase.php'), true)) {
        return true;
    }
    if (preg_match('/(Store|Query)\.php$/', $rel) === 1) {
        return true;
    }

    return preg_match('/\bclass\s+\w+\s+extends\s+(?:\\\\?DAO|\\\\?mindstellar\\\\base\\\\Model)\b/', $code) === 1
        || (preg_match('/^use\s+mindstellar\\\\base\\\\Model;/m', $code) === 1 && preg_match('/\bclass\s+\w+\s+extends\s+Model\b/', $code) === 1);
}

/**
 * Matches as "line: text", for one file's comment-free source.
 *
 * @return string[]
 */
function raw_access(string $code): array
{
    $found = array();
    foreach (array(P_BUILDER, P_TABLE) as $pattern) {
        preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$text, $at]) {
            $found[$at] = (substr_count(substr($code, 0, $at), "\n") + 1) . ': ' . trim($text);
        }
    }
    ksort($found);

    return array_values($found);
}

harness_section('The patterns');

check('catches a builder call', raw_access("<?php osc_db_table(\$t);") !== array() && raw_access("<?php Db::table(\$t);") !== array());
check('ignores the helper definition', raw_access("<?php function osc_db_table(string \$t) {}") === array());
check('catches a prefixed name', raw_access("<?php \$t = DB_TABLE_PREFIX . 't_item';") !== array());
check('catches a prefix ending a line', raw_access("<?php \$s = 'FROM ' . DB_TABLE_PREFIX\n . 't_item';") !== array());
check('catches a joined literal', raw_access("<?php \$s = 'FROM ' . \$p . 't_item i';") !== array());
check('catches an interpolated prefix', raw_access("<?php \$s = \"FROM {\$p}t_pages\";") !== array());
check('catches a sprintf prefix', raw_access("<?php sprintf('%st_item.b_active', DB_TABLE_PREFIX);") !== array());
check('lets the prefix pass as a value', raw_access("<?php size(\$c, DB_TABLE_PREFIX); \$a = DB_TABLE_PREFIX;") === array());
check('ignores comments', raw_access(code_only("<?php // osc_db_table(DB_TABLE_PREFIX . 't_x')\n/** FROM {\$p}t_x */")) === array());
check('a Store file owns its table', table_owner('oc-includes/osclass/classes/user/UserStore.php', ''));
check('a DAO subclass owns its table', table_owner('oc-includes/osclass/classes/Sitemap.php', 'class Sitemap extends DAO {'));
check('a base Model subclass owns its table', table_owner('x.php', "use mindstellar\\base\\Model;\nfinal class X extends Model {"));
check('a service does not', !table_owner('oc-includes/osclass/classes/user/AccountService.php', 'final class AccountService {'));

harness_section('Core files');

// Tracked and new files; a gitignored local script is not core.
$listed = array();
exec('git -C ' . escapeshellarg(ABS_PATH) . ' ls-files --cached --others --exclude-standard -- "*.php" 2>/dev/null', $listed, $status);
$files = array();
if ($status === 0 && $listed !== array()) {
    foreach ($listed as $rel) {
        if (preg_match('#^(oc-includes/(?!vendor/)|oc-admin/|[^/]+$)#', $rel) === 1 && is_file(ABS_PATH . $rel)) {
            $files[] = ABS_PATH . $rel;
        }
    }
} else {
    $files = glob(ABS_PATH . '*.php') ?: array();
    foreach (array('oc-includes', 'oc-admin') as $root) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . $root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/oc-includes/vendor/')) {
                $files[] = $file->getPathname();
            }
        }
    }
}
sort($files);

$outside = array();
$counts  = array();
foreach ($files as $path) {
    $rel  = substr($path, strlen(ABS_PATH));
    $code = code_only((string) file_get_contents($path));
    if (table_owner($rel, $code)) {
        continue;
    }
    $hits = raw_access($code);
    if ($hits === array()) {
        continue;
    }
    $entry = allowed_entry($rel);
    if ($entry !== null) {
        $counts[$entry[2]] = ($counts[$entry[2]] ?? 0) + count($hits);
    }
    if ($entry === null || ($entry[0] !== null && count($hits) > $entry[0])) {
        foreach ($hits as $hit) {
            $outside[] = $rel . ':' . $hit;
        }
    }
}

check('the scan reads core', count($files) > 500, count($files) . ' files');
pin('no raw table access outside a table owner', array(), $outside);
foreach ($outside as $line) {
    echo '        ' . $line . "\n";
}

$stale = array();
foreach (ALLOWED as $rel => [$max, $reason]) {
    if (!isset($counts[$rel])) {
        $stale[] = $rel;
    }
}
pin('every allow-list entry is still needed', array(), $stale);

exit(harness_result());
