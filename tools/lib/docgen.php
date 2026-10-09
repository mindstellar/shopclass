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
 * Shared by the doc generators in tools/: writes a generated block between two markers in
 * a doc page, or with --check only reports whether the page is out of date.
 */

/**
 * Put $block in place of the text from $begin to $end in $path, and return the exit code.
 *
 * $block must start with $begin and end with $end. $tool is the command that regenerates
 * the page, named in the stale message.
 *
 * @param array<int,string> $argv
 */
function docgen_splice(string $path, string $begin, string $end, string $block, string $tool, array $argv, string $wrote): int
{
    $name    = substr($path, strpos($path, 'docs/'));
    $current = (string) file_get_contents($path);
    $a       = strpos($current, $begin);
    $b       = strpos($current, $end);
    if ($a === false || $b === false || $b < $a) {
        fwrite(STDERR, 'Markers missing or out of order in ' . $name . "\n");

        return 1;
    }
    $updated = substr($current, 0, $a) . $block . substr($current, $b + strlen($end));

    if (in_array('--check', $argv, true)) {
        if ($updated !== $current) {
            fwrite(STDERR, $name . ' is stale. Run: php ' . $tool . "\n");

            return 1;
        }
        echo basename($name) . " matches the source.\n";

        return 0;
    }

    file_put_contents($path, $updated);
    echo $wrote . "\n";

    return 0;
}
