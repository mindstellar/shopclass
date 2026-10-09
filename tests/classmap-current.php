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
 * Every class, interface, trait and enum under oc-includes/osclass is in Composer's committed
 * class map. A class with no namespace loads only from it; run `composer dump-autoload` and
 * commit oc-includes/vendor/composer/ after adding one.
 *
 * DB-free.  Usage: php tests/classmap-current.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

/**
 * The fully qualified names a PHP file declares.
 *
 * @return string[]
 */
function declared_names(string $source): array
{
    $tokens    = token_get_all($source);
    $namespace = '';
    $names     = array();
    $count     = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        if ($tokens[$i][0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                $namespace .= is_array($tokens[$j]) ? trim($tokens[$j][1]) : '';
            }
            continue;
        }
        $kinds = array(T_CLASS, T_INTERFACE, T_TRAIT) + (defined('T_ENUM') ? array(3 => T_ENUM) : array());
        if (!in_array($tokens[$i][0], $kinds, true)) {
            continue;
        }
        $before = $i - 1;
        while ($before > 0 && is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
            $before--;
        }
        if (is_array($tokens[$before] ?? null) && in_array($tokens[$before][0], array(T_DOUBLE_COLON, T_NEW), true)) {
            continue; // Foo::class, or an anonymous class
        }
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_STRING) {
            $names[] = ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j][1];
        }
    }

    return $names;
}

$map     = require ABS_PATH . 'oc-includes/vendor/composer/autoload_classmap.php';
$missing = array();
$seen    = 0;
$it      = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-includes/osclass', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    foreach (declared_names((string) file_get_contents($file->getPathname())) as $name) {
        $seen++;
        if (!isset($map[$name])) {
            $missing[] = $name . ' (' . substr($file->getPathname(), strlen(ABS_PATH)) . ')';
        }
    }
}

harness_section('The scan');
pin('a namespaced class and a global one are found', array('mindstellar\base\ActionMap', 'CWebItem'), array(
    declared_names("<?php\nnamespace mindstellar\\base;\ntrait ActionMap {}\n")[0] ?? null,
    declared_names("<?php\nclass CWebItem extends BaseModel { function f() { return self::class; } }\n")[0] ?? null,
));
check('the scan reads core', $seen > 500, $seen . ' declarations');

harness_section('The class map');
pin('every declaration is in the class map (run composer dump-autoload)', array(), $missing);

exit(harness_result());
