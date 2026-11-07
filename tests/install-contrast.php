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
 * Pins WCAG contrast of the installer tokens in both themes: 4.5 for text pairs, 3 for the
 * strong rule and the focus ring.
 *
 * DB-free.  Usage: php tests/install-contrast.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/install-css.php';

$rules = ins_parse_css((string) file_get_contents(ins_root() . '/install.css'));

$benches = array('bench', 'bench-warm');
$text = array(
    array('ink', $benches), array('ink-muted', $benches), array('ink-emphasis', $benches), array('heading', $benches),
    array('bronze', array('bench')),
    array('ink-inverse', array('bronze', 'bronze-deep')),
    array('success', array('success-tint')), array('danger', array('danger-tint')), array('warning-deep', array('warning-tint')),
);
$ui = array(array('rule-strong', $benches), array('focus', $benches));

foreach (array('light', 'dark') as $mode) {
    $vars = ins_root_vars($rules, $mode);
    $rgb = static function (string $name) use ($vars): ?array {
        $c = isset($vars['--osc-' . $name]) ? ins_rgba($vars['--osc-' . $name]) : null;

        return $c === null ? null : ins_over($c, array(255, 255, 255, 1.0));
    };
    harness_section($mode . ' contrast');
    foreach (array(array(4.5, $text), array(3.0, $ui)) as [$min, $pairs]) {
        foreach ($pairs as [$fg, $bgs]) {
            foreach ($bgs as $bg) {
                $a = $rgb($fg);
                $b = $rgb($bg);
                $ok = $a !== null && $b !== null && ins_contrast($a, $b) >= $min;
                check($fg . ' on ' . $bg . ' >= ' . $min, $ok, $a && $b ? sprintf('%.2f', ins_contrast($a, $b)) : 'missing token');
            }
        }
    }
}

exit(harness_result());
