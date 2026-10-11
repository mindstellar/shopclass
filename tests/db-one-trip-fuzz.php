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
 * Fuzzes the one-trip path of Connection against real prepared statements on the same connection.
 * Seeded, so a failure repeats; each failure prints the case that caused it.
 *
 * Usage:  php tests/db-one-trip-fuzz.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         OSC_FUZZ_SCALE (default 0.25, so the suite stays fast; 1 or more for a full run)
 * The scale sizes only the random cases, so each scale runs its own set; repeat a failure at the same scale.
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_one_trip_fuzz');
$db    = Connection::getInstance();
$raw   = $db->handle();
$t     = DB_TABLE_PREFIX . 't_one_trip_fuzz';

$admin->query("DROP TABLE IF EXISTS $t");
$admin->query("CREATE TABLE $t (id INT AUTO_INCREMENT PRIMARY KEY, b MEDIUMBLOB NULL, s MEDIUMTEXT NULL, d DOUBLE NULL, n BIGINT NULL, KEY (b(16)), KEY (s(16)), KEY (d), KEY (n)) DEFAULT CHARSET=utf8mb4");

mt_srand(20261011);
$scale = getenv('OSC_FUZZ_SCALE');
$scale = is_numeric($scale) && $scale > 0 ? (float) $scale : 0.25;
$cases = static fn (int $n): int => max(1, (int) round($n * $scale));

/**
 * Run a real prepared statement on the raw handle. Rows as a list, an int for a write, or null on error.
 *
 * @return array<int,array<int,mixed>>|int|null
 */
$prepared = static function (string $sql, array $params) use ($raw) {
    try {
        $stmt = $raw->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($params !== array()) {
            $types = '';
            $vals  = array();
            foreach ($params as $p) {
                $types .= \is_int($p) || \is_bool($p) ? 'i' : (\is_float($p) ? 'd' : 's');
                $vals[] = \is_bool($p) ? (int) $p : $p;
            }
            $stmt->bind_param($types, ...$vals);
        }
        if (!$stmt->execute()) {
            return null;
        }
        $result = $stmt->get_result();
        $out    = $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_NUM) : (int) $raw->insert_id;
        $stmt->close();

        return $out;
    } catch (Throwable $e) {
        return null;
    }
};

/** Connection's answer, or null when it threw. */
$oneTrip = static function (callable $fn) {
    try {
        return $fn();
    } catch (Throwable $e) {
        return null;
    }
};

$same = static function ($a, $b): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    if ((string) $a === (string) $b) {
        return true;
    }
    if (\is_float($a) || \is_float($b)) {
        return is_numeric($a) && is_numeric($b) && pack('E', (float) $a) === pack('E', (float) $b);
    }

    return false;
};

$sameRows = static function ($a, $b) use ($same): bool {
    if ($a === null || $b === null) {
        return $a === $b;
    }
    if (\count($a) !== \count($b)) {
        return false;
    }
    foreach ($a as $r => $row) {
        $row   = array_values($row);
        $other = array_values($b[$r]);
        if (\count($row) !== \count($other)) {
            return false;
        }
        foreach ($row as $c => $v) {
            if (!$same($v, $other[$c])) {
                return false;
            }
        }
    }

    return true;
};

$show = static fn ($v): string => \is_string($v) ? 'string:' . bin2hex(substr($v, 0, 60)) : var_export($v, true);

/* ---------------------------------------------------------------- values */

$alphabet = array("'", '"', '`', '\\', '%', '_', '?', ';', '-', '#', '/', '*', "\0", "\r", "\n", "\x1a", ' ', 'a', '0',
    'é', '😀', 'अ', "\u{0301}", "\u{200B}", "\xbf", "\x5c", "\xa5", "\xc0", '--', '/*', '*/', '/*!', "''", '\\\'');

$values = array(null, true, false, '', '0', ' ', 0, -0, 1, -1, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1,
    2 ** 53, -(2 ** 53), 0.0, -0.0, 0.1, -0.1, 1.5, 1e300, -1e300, 1e-300, 5e-324, 2.2250738585072014e-308,
    1.7976931348623157e308, 0.30000000000000004, 123456789.12345678, 1e15, 1e16, 1e22, 9007199254740993.0,
    PHP_FLOAT_EPSILON, M_PI, (float) PHP_INT_MAX);
foreach (array('1', '-1', '+1', ' 1', '1 ', '007', '1e3', '1E+3', '-1e-3', '.5', '5.', '0' . 'x1A', '1_000', '9223372036854775807',
    '9223372036854775808', '-9223372036854775809', '1e400', 'NaN', 'INF', '  -0.0  ', "1\0", "\t12", '١٢', '1,5') as $n) {
    $values[] = $n;
}
for ($i = 0; $i < $cases(600); $i++) {
    $v = '';
    for ($j = mt_rand(0, 40); $j > 0; $j--) {
        $v .= \chr(mt_rand(0, 255));
    }
    $values[] = $v;
}
for ($i = 0; $i < $cases(600); $i++) {
    $len = mt_rand(1, 50);
    $v   = '';
    for ($j = 0; $j < $len; $j++) {
        $v .= $alphabet[mt_rand(0, \count($alphabet) - 1)];
    }
    $values[] = $v;
}
for ($i = 0; $i < $cases(300); $i++) {
    $chars = '';
    for ($j = mt_rand(1, 20); $j > 0; $j--) {
        $chars .= mb_chr(mt_rand(0, 3) === 0 ? mt_rand(0x300, 0x36F) : mt_rand(0x20, 0x2FFF), 'UTF-8') ?: 'x';
    }
    $values[] = $chars;
}
for ($i = 0; $i < $cases(400); $i++) {
    $values[] = mt_rand(PHP_INT_MIN, PHP_INT_MAX);
    $values[] = mt_rand(-1000, 1000);
}
for ($i = 0; $i < $cases(400); $i++) {
    $values[] = mt_rand() / mt_getrandmax() * 10 ** mt_rand(-320, 308) * (mt_rand(0, 1) ? 1 : -1);
    $values[] = unpack('E', pack('J', mt_rand(0, PHP_INT_MAX) | (mt_rand(0, 1) << 63)))[1];
}
for ($i = 0; $i < $cases(300); $i++) {
    $values[] = str_repeat(' ', mt_rand(0, 2)) . (mt_rand(0, 1) ? '-' : (mt_rand(0, 1) ? '+' : ''))
                . str_repeat('0', mt_rand(0, 3)) . mt_rand(0, 99999) . (mt_rand(0, 1) ? '.' . mt_rand(0, 9999) : '')
                . (mt_rand(0, 1) ? 'e' . mt_rand(-30, 30) : '') . str_repeat(' ', mt_rand(0, 2));
}
$values = array_values(array_filter($values, static fn ($v) => !\is_float($v) || is_finite($v)));

harness_section('values stored in one trip match a prepared statement');

$columns = array('b', 's', 'd', 'n');
$stored  = 0;
$errors  = 0;
$bad     = 0;
$raw->begin_transaction();
foreach ($values as $k => $value) {
    foreach ($columns as $col) {
        if (\is_string($value) && ($col === 'd' || $col === 'n') && !is_numeric(trim($value)) && mt_rand(0, 9) > 0) {
            continue; // text into a number column mostly errors in both; sample it
        }
        $sql = "INSERT INTO $t ($col) VALUES (?)";
        $a   = $oneTrip(static fn () => $db->insertGetId($sql, array($value)));
        $b   = $prepared($sql, array($value));
        if ($a === null || $b === null) {
            if ($a !== $b) {
                $bad++;
                check("value $k into $col: both error or neither", false, 'one trip ' . ($a === null ? 'errored' : 'stored') . ', prepared ' . ($b === null ? 'errored' : 'stored') . ' for ' . $show($value));
            }
            $errors++;
            continue;
        }
        $read = $prepared("SELECT $col FROM $t WHERE id IN (?, ?) ORDER BY id", array($a, $b));
        $ok   = \is_array($read) && \count($read) === 2 && $same($read[0][0], $read[1][0]);
        if ($ok && \is_string($value) && $col === 'b') {
            $ok = $read[0][0] === $value;
        }
        if ($ok && \is_float($value) && $col === 'd') {
            $ok = pack('E', (float) $read[0][0]) === pack('E', $read[1][0]);
        }
        if (!$ok) {
            $bad++;
            check("value $k into $col: same as prepared", false, $show($value) . ' -> one trip ' . $show($read[0][0] ?? null) . ', prepared ' . $show($read[1][0] ?? null));
        }
        $stored++;
    }
}
$raw->commit();
pin('every value stored in one trip matches the prepared one', 0, $bad);
check("($stored stored, $errors both refused, " . \count($values) . ' values)', $stored > \count($values) * 2);

harness_section('a WHERE on each value finds the same rows as a prepared statement');

$bad   = 0;
$tries = 0;
foreach ($values as $k => $value) {
    foreach ($columns as $col) {
        if ($value === null || (\is_string($value) && ($col === 'd' || $col === 'n') && !is_numeric(trim($value)))) {
            continue;
        }
        if ($k % 10 !== 0 && ($col === 'b' || $col === 's') && !\is_string($value)) {
            continue; // a number against a text column scans the whole table; sample it
        }
        if ($col === 'n' && \is_float($value) && abs($value) >= 2 ** 53) {
            continue; // A prepared double against BIGINT misses even its own row past 2^53; one trip finds it.
        }
        $sql = "SELECT id FROM $t WHERE $col = ? ORDER BY id";
        $a   = $oneTrip(static fn () => array_map(static fn ($r) => array((int) $r['id']), $db->select($sql, array($value))));
        $b   = $prepared($sql, array($value));
        $tries++;
        if (!$sameRows($a, $b)) {
            $bad++;
            check("WHERE $col = value $k", false, $show($value) . ' -> one trip ' . json_encode($a) . ', prepared ' . json_encode($b));
        }
    }
}
pin("every WHERE matches ($tries run)", 0, $bad);

/* ---------------------------------------------------------- SQL templates */

// %u in a piece becomes a fresh alias number, so no two result columns share a name.
$pieces = array(
    "'lit'", "'a''b'", "'a\\'b'", "'tail\\\\'", "'x\\\\' , '?' AS u%u", "'?'", "'a\\'?'", '"dq"', '"a""b"', '"?"', '"a\\"?"',
    "'--'", "'#'", "'/*'", "'*/'", "N'n?'", "_utf8mb4'u?'", "X'41'", "'\\\\'", "'\\''", "'it''s ?'",
);
$names    = array('`c%u?`', '`a``b%u`', '`x%u`', '`?%u`', '`--%u`', '`/*%u`', '`#%u`', "`%u'`", '`%u\\`', '"%u?"');
$comments = array('/* ? */', '/*! ? AS u%u, */', '/*!80000 ? AS u%u, */', '/*+ ? */', '/*M! ? AS u%u, */', "-- ?\n", "--\t?\n", "--\x7f?\n",
    "--\r?\n", "--\x0b?\n", "# ?\n", '/**/', "-- '\n", "# '\n", "/* ' */", "/* \" */", "-- \"\n", '/* */ ? AS u%u,',
    '/*!80000 ? + */');
$payloads = array("x', (SELECT 'pwn') AS pwn, '", "x\\', (SELECT 'pwn') AS pwn, '", '" OR 1=1 -- ', "\\' -- ",
    "\xbf\\' OR 1 #", "?'?\"?`?", "/*!*/ 'pwn'", "*/ 'pwn' /*", "\n'pwn'", 42, -7, 1.5, null, true, '007', '');

$template = static function () use ($pieces, $names, $comments): string {
    $pick  = static fn (array $list): string => $list[mt_rand(0, \count($list) - 1)];
    $exprs = array();
    for ($j = mt_rand(1, 5); $j > 0; $j--) {
        $e = match (mt_rand(0, 6)) {
            0, 1    => '?',
            2       => $pick($pieces),
            3       => 'CONCAT(' . $pick($pieces) . ', ?)',
            4       => '? = ' . $pick($pieces),
            5       => $pick($comments) . ' ?',
            default => $pick(array('--?', '- -?')),
        };
        $exprs[] = $e . ' AS ' . $pick($names);
    }
    $sql = 'SELECT ' . implode(', ', $exprs);
    if (mt_rand(0, 2) === 0) {
        $sql .= ' ' . $pick($comments);
    }
    if (mt_rand(0, 1) === 0) {
        $sql .= "\nFROM DUAL WHERE ? IS NOT NULL OR 1";
    }
    $u = 0;

    return (string) preg_replace_callback('/%u/', static function () use (&$u): string {
        return (string) ++$u;
    }, $sql);
};

$fuzzSql = static function (string $mode, int $count) use ($db, $raw, $prepared, $oneTrip, $sameRows, $template, $payloads): array {
    $bad = 0;
    $ran = 0;
    for ($i = 0; $i < $count; $i++) {
        $sql = $template();
        try {
            $stmt  = $raw->prepare($sql);
            $slots = $stmt ? $stmt->param_count : -1;
            if ($stmt) {
                $stmt->close();
            }
        } catch (Throwable $e) {
            $slots = -1;
        }
        if ($slots < 0) {
            // The server refuses the SQL; one trip must not run it with any number of values.
            for ($n = 0; $n <= substr_count($sql, '?'); $n++) {
                $vals = array_fill(0, $n, 1);
                $a    = $oneTrip(static fn () => $db->select($sql, $vals));
                if ($a !== null && $n > 0) {
                    $bad++;
                    check("$mode: SQL the server cannot prepare ran in one trip with $n values", false, json_encode($sql));
                    break;
                }
            }
            continue;
        }
        $vals = array();
        for ($n = 0; $n < $slots; $n++) {
            $vals[] = $payloads[mt_rand(0, \count($payloads) - 1)];
        }
        $a = $oneTrip(static fn () => $db->select($sql, $vals));
        $b = $prepared($sql, $vals);
        $ran++;
        $badUtf8 = array_filter($vals, static fn ($v) => \is_string($v) && !mb_check_encoding($v, 'UTF-8')) !== array();
        if ($b === null && $badUtf8 && stripos($sql, "N'") !== false) {
            continue; // A prepared statement refuses to convert bytes that are not UTF-8 to N''; one trip compares them.
        }
        if (!$sameRows($a, $b)) {
            $bad++;
            check("$mode: same result as prepared", false, json_encode($sql) . ' values ' . json_encode($vals, JSON_INVALID_UTF8_SUBSTITUTE)
                  . ' -> one trip ' . json_encode($a, JSON_INVALID_UTF8_SUBSTITUTE) . ', prepared ' . json_encode($b, JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    return array($bad, $ran);
};

$mode = (string) $raw->query('SELECT @@SESSION.sql_mode')->fetch_row()[0];
foreach (array('default' => $mode, 'NO_BACKSLASH_ESCAPES' => $mode . ',NO_BACKSLASH_ESCAPES', 'ANSI_QUOTES' => $mode . ',ANSI_QUOTES') as $name => $set) {
    harness_section("SQL templates, $name");
    $raw->query("SET SESSION sql_mode = '" . $raw->real_escape_string(ltrim($set, ',')) . "'");
    [$bad, $ran] = $fuzzSql($name, $cases(1500));
    pin("every template gives the prepared result ($ran ran)", 0, $bad);
    check('most templates ran', $ran > $cases(1500) / 3);
}
$raw->query("SET SESSION sql_mode = '" . $raw->real_escape_string($mode) . "'");
pin('sql_mode is restored', $mode, (string) $raw->query('SELECT @@SESSION.sql_mode')->fetch_row()[0]);

$admin->query("DROP TABLE IF EXISTS $t");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
