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
 * After the updater swaps the files, classes first loaded in the same request must come from
 * the new release's Composer maps, so a release can move or add namespaced classes.
 *
 * Usage: php tests/upgrade-new-autoload.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';

use mindstellar\upgrade\Upgrade;

$root = sys_get_temp_dir() . '/osc-new-autoload-' . bin2hex(random_bytes(4));
$composer = $root . '/oc-includes/vendor/composer';
mkdir($composer, 0755, true);
mkdir($root . '/oc-includes/classes/fakenew', 0755, true);
mkdir($root . '/oc-includes/osclass/legacy', 0755, true);

$header = "<?php\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(dirname(\$vendorDir));\n\n";
file_put_contents(
    $composer . '/autoload_psr4.php',
    $header . "return array('mindstellar\\\\fakenew\\\\' => array(\$baseDir . '/oc-includes/classes/fakenew'));\n"
);
file_put_contents(
    $composer . '/autoload_classmap.php',
    $header . "return array('FakeNewLegacyClass' => \$baseDir . '/oc-includes/osclass/legacy/FakeNewLegacyClass.php', 'FakeBothClass' => \$baseDir . '/oc-includes/osclass/legacy/FakeBothClass.php');\n"
);
file_put_contents(
    $root . '/oc-includes/classes/fakenew/Moved.php',
    "<?php\nnamespace mindstellar\\fakenew;\nclass Moved {}\n"
);
file_put_contents($root . '/oc-includes/osclass/legacy/FakeNewLegacyClass.php', "<?php\nclass FakeNewLegacyClass {}\n");
// A class both releases ship: the old loader would give the old copy, the new maps must win.
file_put_contents($root . '/old-FakeBothClass.php', "<?php\nclass FakeBothClass { public const FROM = 'old'; }\n");
file_put_contents($root . '/oc-includes/osclass/legacy/FakeBothClass.php', "<?php\nclass FakeBothClass { public const FROM = 'new'; }\n");
spl_autoload_register(static function (string $class) use ($root): void {
    if ($class === 'FakeBothClass') {
        require $root . '/old-FakeBothClass.php';
    }
});

harness_section('the new release maps are loaded after the swap');
pin('the moved class is unknown before', false, class_exists('mindstellar\fakenew\Moved'));
pin('the legacy class is unknown before', false, class_exists('FakeNewLegacyClass'));
pin('loading the new maps reports success', true, Upgrade::loadNewAutoload($root));
pin('a new PSR-4 class is found', true, class_exists('mindstellar\fakenew\Moved'));
pin('a new classmap class is found', true, class_exists('FakeNewLegacyClass'));
pin('old classes still load', true, class_exists('mindstellar\utility\FileSystem'));
pin('a class both releases ship loads from the new one', 'new', FakeBothClass::FROM);

harness_section('a missing or broken package changes nothing');
$empty = $root . '/empty';
mkdir($empty);
pin('no autoload files is skipped', false, Upgrade::loadNewAutoload($empty));

$broken = $root . '/broken';
mkdir($broken . '/oc-includes/vendor/composer', 0755, true);
file_put_contents($broken . '/oc-includes/vendor/composer/autoload_psr4.php', "<?php\nthrow new Exception('bad');\n");
file_put_contents($broken . '/oc-includes/vendor/composer/autoload_classmap.php', "<?php\nreturn 'not an array';\n");
$threw = false;
try {
    $result = Upgrade::loadNewAutoload($broken);
} catch (Throwable $e) {
    $threw = true;
}
pin('a broken autoload file does not throw', false, $threw);
pin('a broken autoload file reports failure', false, $result ?? null);

(new mindstellar\utility\FileSystem())->remove($root);

exit(harness_result());
