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
 * Finds writes that go through the inherited DAO from inside a transaction.
 *
 * The legacy DAO reports a failed write by RETURNING FALSE rather than raising. Inside
 * an osc_db_transaction() closure a plain return reads as success, so the transaction
 * commits and every statement before it stands -- which is the opposite of what
 * wrapping the work in a transaction was meant to guarantee. Item::deleteByPrimaryKey()
 * shipped exactly that: the cascade removed a listing's description, images and stats,
 * the parent delete was refused, and the commit went through anyway, leaving a listing
 * that was still there with everything attached to it gone.
 *
 * Grepping for the new query helpers could never have found it. The offending line was
 * `parent::deleteByPrimaryKey($id)` -- an inherited call, with no query in sight and
 * nothing textually legacy about it. What identifies these is not how the call is
 * spelled but where it RESOLVES: a model that does not declare the method inherits
 * DAO's, and DAO's is the one that swallows the error.
 *
 * So this resolves each call against the class that receives it, and reports only the
 * ones inside a transaction. Writes through the inherited DAO elsewhere are ordinary
 * un-migrated code and are counted, not failed on -- outside a transaction, a false
 * return is the caller's business.
 *
 * Line-scanning, not a parser, in the same dependency-free posture as its siblings
 * here. Comments are blanked first: one naming a legacy call is not making one.
 *
 * Usage:
 *   php tools/ci/legacy-dao-in-transaction.php [--json] [<root>]
 *
 * Exits 1 when a write inside a transaction resolves to the inherited DAO.
 */

/** DAO write methods. A read returning false on error cannot silently commit anything. */
const DAO_WRITES = [
    'delete',
    'deleteByPrimaryKey',
    'update',
    'updateByPrimaryKey',
    'insert',
    'insertGetId',
];

$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);
$root = null;
foreach ($args as $arg) {
    if (strpos($arg, '--') !== 0) {
        $root = rtrim($arg, '/');
    }
}
$root = $root ?? getcwd();

$scanDirs = [$root . '/oc-includes/osclass', $root . '/oc-admin'];

/**
 * Blank comments, keeping every byte position and newline so offsets and line numbers
 * still line up with the original.
 */
function stripComments(string $src): string
{
    $blank = static function (array $m): string {
        return preg_replace('/[^\n]/', ' ', $m[0]);
    };

    $src = preg_replace_callback('#/\*.*?\*/#s', $blank, $src);
    $src = preg_replace_callback('#//[^\n]*#', $blank, $src);

    return preg_replace_callback('/^[ \t]*#[^\n]*/m', $blank, $src);
}

/**
 * Byte ranges of every osc_db_transaction( ... ) call, found by matching brackets from
 * the opening one.
 *
 * @return array<int, array{0:int,1:int}>
 */
function transactionRanges(string $src): array
{
    $ranges = [];
    if (!preg_match_all('/osc_db_transaction\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
        return $ranges;
    }

    foreach ($m[0] as $hit) {
        $start = $hit[1] + strlen($hit[0]) - 1;
        $depth = 0;
        for ($i = $start, $len = strlen($src); $i < $len; $i++) {
            if ($src[$i] === '(') {
                $depth++;
            } elseif ($src[$i] === ')') {
                if (--$depth === 0) {
                    $ranges[] = [$hit[1], $i];
                    break;
                }
            }
        }
    }

    return $ranges;
}

/**
 * @return string[]
 */
function phpFiles(array $dirs): array
{
    $files = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) === '.php' && strpos($path, '/vendor/') === false) {
                $files[] = $path;
            }
        }
    }
    sort($files);

    return $files;
}

// Which write methods each DAO-backed model declares for itself. Anything absent is
// inherited, and the inherited one is the one that returns false instead of raising.
$declared = [];
foreach (glob($root . '/oc-includes/osclass/classes/model/*.php') as $file) {
    $src = stripComments((string) file_get_contents($file));
    if (!preg_match('/class\s+(\w+)\s+extends\s+DAO\b/', $src, $m)) {
        continue;
    }
    preg_match_all('/function\s+(\w+)\s*\(/', $src, $fns);
    $declared[$m[1]] = array_flip($fns[1]);
}

$inTransaction = [];
$elsewhere     = 0;

foreach (phpFiles($scanDirs) as $file) {
    $raw = (string) file_get_contents($file);
    $src = stripComments($raw);
    $tx  = transactionRanges($src);
    if (preg_match('/class\s+(\w+)\s+extends\s+DAO\b/', $src, $m)) {
        $owner = $m[1];
    } else {
        $owner = null;
    }

    $record = static function (int $offset, string $what) use ($src, $file, $tx, $root, &$inTransaction, &$elsewhere): void {
        foreach ($tx as $range) {
            if ($offset >= $range[0] && $offset <= $range[1]) {
                $inTransaction[] = [
                    'file' => ltrim(str_replace($root, '', $file), '/'),
                    'line' => substr_count(substr($src, 0, $offset), "\n") + 1,
                    'call' => $what,
                ];

                return;
            }
        }
        $elsewhere++;
    };

    // Model::newInstance()->write(...) — legacy only when the class has no override.
    if (preg_match_all('/(\w+)::newInstance\(\)\s*->\s*(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $class  = $hit[1][0];
            $method = $hit[2][0];
            if (!in_array($method, DAO_WRITES, true) || !isset($declared[$class])) {
                continue;
            }
            if (isset($declared[$class][$method])) {
                continue; // overridden, so not the inherited implementation
            }
            $record($hit[0][1], $class . '::newInstance()->' . $method . '()');
        }
    }

    // parent::write(...) inside a model — always the inherited DAO.
    if (preg_match_all('/parent::(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (!in_array($hit[1][0], DAO_WRITES, true)) {
                continue;
            }
            $record($hit[0][1], 'parent::' . $hit[1][0] . '()' . ($owner ? ' in ' . $owner : ''));
        }
    }
}

if ($json) {
    echo json_encode(
        ['in_transaction' => $inTransaction, 'elsewhere' => $elsewhere],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ), "\n";
} elseif ($inTransaction === []) {
    printf(
        "OK — no write inside a transaction resolves to the inherited DAO (%d such writes elsewhere).\n",
        $elsewhere
    );
} else {
    fwrite(STDERR, "A write inside a transaction resolves to the inherited DAO.\n");
    fwrite(STDERR, "It reports failure by returning false, which reads as success and lets the\n");
    fwrite(STDERR, "transaction commit everything before it. Run the same statement through\n");
    fwrite(STDERR, "osc_db_table(), which raises.\n\n");
    foreach ($inTransaction as $hit) {
        fwrite(STDERR, sprintf("  %s:%d  %s\n", $hit['file'], $hit['line'], $hit['call']));
    }
}

exit($inTransaction === [] ? 0 : 1);
