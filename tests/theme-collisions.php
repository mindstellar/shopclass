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
 * Pins the child/parent function collision detector.
 *
 * A child's functions.php runs first and the parent's second, so a name they share is
 * `Cannot redeclare` -- a compile-time fatal that no try/catch holds and that leaves the
 * site blank. Nothing could warn about it before, because the only way to find out was
 * to activate the theme.
 *
 * Getting this wrong is worse than not having it. A false alarm tells somebody their
 * working theme is broken; a miss lets the white screen through. So the cases that are
 * *not* collisions are pinned as carefully as the ones that are:
 *
 *  - a name the parent guards is the child overriding it, which is the whole point;
 *  - a method, a closure and an arrow function are not file-scope declarations;
 *  - a name only one of them declares is nobody's problem.
 *
 * DB-free. Usage:  php tests/theme-collisions.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/theme/ThemeFunctions.php';

use mindstellar\theme\ThemeFunctions;

$dir = sys_get_temp_dir() . '/osc-theme-collisions-' . getmypid() . '/';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

/** Write a PHP file and return its path. */
$file = static function (string $name, string $body) use ($dir) {
    $path = $dir . $name;
    file_put_contents($path, "<?php\n" . $body);

    return $path;
};

$names = static function (array $map) {
    $out = array();
    foreach ($map as $name => $guarded) {
        $out[] = $name . ($guarded ? ':guarded' : ':bare');
    }
    sort($out);

    return $out;
};

harness_section('What counts as a declaration');

pin(
    'a plain function',
    array('alpha:bare'),
    $names(ThemeFunctions::declaredIn($file('a.php', "function alpha() { return 1; }\n")))
);
pin(
    'a guarded function',
    array('alpha:guarded'),
    $names(ThemeFunctions::declaredIn($file('b.php', "if (!function_exists('alpha')) {\n    function alpha() {}\n}\n")))
);
pin(
    'several, mixed',
    array('one:guarded', 'three:guarded', 'two:bare'),
    $names(ThemeFunctions::declaredIn($file('c.php', "if (!function_exists('one')) { function one() {} }\n"
        . "function two() {}\n"
        . "if (!function_exists('three')) { function three() {} }\n")))
);
pin(
    'the name is matched case-insensitively, as PHP matches it',
    array('alpha:guarded'),
    $names(ThemeFunctions::declaredIn($file('d.php', "if (!function_exists('Alpha')) { function ALPHA() {} }\n")))
);

harness_section('What is not a file-scope declaration');

pin(
    'a method',
    array(),
    $names(ThemeFunctions::declaredIn($file('e.php', "class Thing { public function alpha() {} }\n")))
);
pin(
    'a method in a trait or interface',
    array(),
    $names(ThemeFunctions::declaredIn($file('f.php', "trait T { public function alpha() {} }\n"
        . "interface I { public function beta(); }\n")))
);
pin(
    'a closure',
    array(),
    $names(ThemeFunctions::declaredIn($file('g.php', "\$f = function () { return 1; };\n")))
);
pin(
    'an arrow function',
    array(),
    $names(ThemeFunctions::declaredIn($file('h.php', "\$f = fn(\$x) => \$x + 1;\n")))
);
pin(
    'a function after a class still counts',
    array('alpha:bare'),
    $names(ThemeFunctions::declaredIn($file('i.php', "class Thing { public function m() {} }\nfunction alpha() {}\n")))
);
pin(
    'a name only mentioned in a runtime check is not a declaration',
    array(),
    $names(ThemeFunctions::declaredIn($file('j.php', "if (function_exists('mb_strlen')) { \$n = 1; }\n")))
);

harness_section('An unreadable file is not an answer');

pin('a path that does not exist', array(), ThemeFunctions::declaredIn($dir . 'nope.php'));
pin('an empty path', array(), ThemeFunctions::declaredIn(''));
pin('a directory', array(), ThemeFunctions::declaredIn($dir));

harness_section('Collisions: only an unguarded parent declaration');

$childOverrides = $file('child1.php', "function shared() { return 'child'; }\n");
$parentGuards   = $file('parent1.php', "if (!function_exists('shared')) { function shared() { return 'parent'; } }\n");
$parentBare     = $file('parent2.php', "function shared() { return 'parent'; }\n");

pin(
    'the parent guards it, so this is an override, not a clash',
    array(),
    ThemeFunctions::collisions($childOverrides, $parentGuards)
);
pin(
    'the parent does not guard it, so both declare and the site dies',
    array('shared'),
    ThemeFunctions::collisions($childOverrides, $parentBare)
);
pin(
    'a name only the child declares is nobody\'s problem',
    array(),
    ThemeFunctions::collisions($file('child2.php', "function only_child() {}\n"), $parentBare)
);
pin(
    'a name only the parent declares is nobody\'s problem',
    array(),
    ThemeFunctions::collisions($file('child3.php', "function only_child() {}\n"),
        $file('parent3.php', "function only_parent() {}\n"))
);
pin(
    'several clashes come back sorted',
    array('bravo', 'delta'),
    ThemeFunctions::collisions(
        $file('child4.php', "function delta() {}\nfunction bravo() {}\nfunction safe() {}\n"),
        $file('parent4.php', "function bravo() {}\nfunction delta() {}\n"
            . "if (!function_exists('safe')) { function safe() {} }\n")
    )
);
pin(
    'a child with no functions.php clashes with nothing',
    array(),
    ThemeFunctions::collisions($dir . 'absent.php', $parentBare)
);

harness_section('The real storefront theme is clean');

$storefront = ABS_PATH . 'oc-content/themes/storefront/functions.php';
if (is_file($storefront)) {
    $declared = ThemeFunctions::declaredIn($storefront);
    $bare     = array_keys(array_filter($declared, static function ($guarded) {
        return $guarded === false;
    }));
    sort($bare);
    check('storefront declares functions at all', count($declared) > 20);
    pin('and guards every one of them', array(), $bare);
} else {
    check('storefront is not installed here, skipping', true);
}

/* Clean up. */
foreach (array_diff(scandir($dir) ?: array(), array('.', '..')) as $entry) {
    @unlink($dir . $entry);
}
@rmdir($dir);

exit(harness_result());
