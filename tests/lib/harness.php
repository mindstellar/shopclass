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
 * Assertion harness shared by the model characterization tests.
 *
 * The pin/check/report/describe core is the same micro-harness
 * tests/dao-contract.php established; it is extracted here so the per-model
 * tests and the runner share one implementation and one pass/fail tally.
 * dao-contract.php keeps its own copy and stays standalone.
 *
 * Every function is guarded, so including this file after a script that already
 * defined the originals is harmless.
 *
 * Usage: see tests/models/ for the per-model pattern, tests/run-models.php for
 * the aggregate runner.
 */

if (!isset($GLOBALS['okCount'])) {
    $GLOBALS['okCount'] = 0;
}
if (!isset($GLOBALS['failCount'])) {
    $GLOBALS['failCount'] = 0;
}
if (!isset($GLOBALS['failLabels'])) {
    $GLOBALS['failLabels'] = array();
}

if (!function_exists('describe')) {
    /**
     * Render a value as type + content for assertion output.
     *
     * @param mixed $v
     *
     * @return string
     */
    function describe($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return 'bool(' . ($v ? 'true' : 'false') . ')';
        }
        if (is_int($v)) {
            return 'int(' . $v . ')';
        }
        if (is_float($v)) {
            return 'float(' . $v . ')';
        }
        if (is_string($v)) {
            return 'string("' . $v . '")';
        }
        if (is_array($v)) {
            return 'array(' . count($v) . ')';
        }
        if (is_object($v)) {
            return 'object(' . get_class($v) . ')';
        }

        return gettype($v);
    }
}

if (!function_exists('report')) {
    /**
     * Record one assertion result.
     *
     * @param string $label
     * @param bool   $ok
     * @param string $expected
     * @param string $actual
     */
    function report(string $label, bool $ok, string $expected, string $actual): void
    {
        if ($ok) {
            $GLOBALS['okCount']++;
            echo "PASS  $label\n";

            return;
        }

        $GLOBALS['failCount']++;
        $GLOBALS['failLabels'][] = $label;
        echo "FAIL  $label\n        expected: $expected\n        actual:   $actual\n";
    }
}

if (!function_exists('pin')) {
    /**
     * Pin a value with a strict === comparison, reporting type and content.
     *
     * @param string $label
     * @param mixed  $expected
     * @param mixed  $actual
     */
    function pin(string $label, $expected, $actual): void
    {
        $ok = gettype($expected) === gettype($actual) && $expected === $actual;
        report($label, $ok, describe($expected), describe($actual));
    }
}

if (!function_exists('check')) {
    /**
     * Assert a boolean condition, with an optional detail shown on failure.
     *
     * @param string $label
     * @param bool   $ok
     * @param string $detail
     */
    function check(string $label, bool $ok, string $detail = ''): void
    {
        report($label, $ok, 'true', $ok ? 'true' : ('false' . ($detail !== '' ? " ($detail)" : '')));
    }
}

if (!function_exists('all_values_string')) {
    /**
     * True when every value in an assoc row is a string or null, which is what
     * the legacy query path always produced.
     *
     * @param array $row
     *
     * @return bool
     */
    function all_values_string(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && !is_string($v)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('all_rows_string')) {
    /**
     * all_values_string() across a list of rows. Empty list passes.
     *
     * @param array $rows
     *
     * @return bool
     */
    function all_rows_string(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row) || !all_values_string($row)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('harness_section')) {
    /**
     * Print a section header.
     *
     * @param string $title
     */
    function harness_section(string $title): void
    {
        echo "\n== $title ==\n";
    }
}

if (!function_exists('harness_method_signature')) {
    /**
     * Build a stable, comparable signature string for one public method.
     *
     * Pins invariant C2: a converted model must not add, remove, rename or
     * re-type any method a caller or plugin could be relying on. The rendered
     * form covers visibility, static-ness, parameter names, type hints,
     * by-reference, variadics, optionality and the return type.
     *
     * @param string $class
     * @param string $method
     *
     * @return string Signature, or a marker string when the method is absent
     */
    function harness_method_signature(string $class, string $method): string
    {
        if (!class_exists($class)) {
            return "MISSING CLASS $class";
        }
        if (!method_exists($class, $method)) {
            return "MISSING METHOD $class::$method";
        }

        $r     = new ReflectionMethod($class, $method);
        $parts = array();
        foreach ($r->getParameters() as $p) {
            $t = $p->hasType() ? ((string)$p->getType() . ' ') : '';
            $s = $t
                . ($p->isPassedByReference() ? '&' : '')
                . ($p->isVariadic() ? '...' : '')
                . '$' . $p->getName();
            if ($p->isDefaultValueAvailable()) {
                $d = $p->getDefaultValue();
                $s .= ' = ' . (is_array($d) ? 'array' : var_export($d, true));
            } elseif ($p->isOptional()) {
                $s .= ' = <optional>';
            }
            $parts[] = $s;
        }

        $vis = $r->isPublic() ? 'public' : ($r->isProtected() ? 'protected' : 'private');
        $ret = $r->hasReturnType() ? (': ' . (string)$r->getReturnType()) : '';

        return $vis . ($r->isStatic() ? ' static' : '') . ' ' . $method
            . '(' . implode(', ', $parts) . ')' . $ret;
    }
}

if (!function_exists('harness_public_method_map')) {
    /**
     * Signature map of every public method a class exposes, sorted by name.
     * Pins the whole surface at once rather than method by method.
     *
     * @param string $class
     *
     * @return array<string,string>
     */
    function harness_public_method_map(string $class): array
    {
        if (!class_exists($class)) {
            return array('MISSING CLASS' => $class);
        }

        $map = array();
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $map[$m->getName()] = harness_method_signature($class, $m->getName());
        }
        ksort($map);

        return $map;
    }
}

if (!function_exists('harness_questions')) {
    /**
     * Read the session's cumulative statement counter off the singleton handle.
     *
     * @return int
     */
    function harness_questions(): int
    {
        $db  = DBConnectionClass::newInstance()->getOsclassDb();
        $res = $db->query("SHOW SESSION STATUS LIKE 'Questions'");
        if (!$res) {
            return -1;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return (int)($row['Value'] ?? -1);
    }
}

if (!function_exists('harness_query_count')) {
    /**
     * Count the statements $fn sends to the server.
     *
     * Reads SHOW SESSION STATUS 'Questions' either side of the callable on the
     * singleton connection, so it needs no instrumentation in production code
     * and counts both legacy and parameterized queries — they share one handle.
     * The probe's own cost is measured at call time and subtracted, rather than
     * assumed.
     *
     * Counts ARE comparable across a conversion: MySQL's Questions counter
     * excludes COM_STMT_PREPARE, so a prepared statement contributes exactly one
     * (the execute), the same as a legacy query(). tests/db-typed-rows.php pins
     * this. An N+1 assertion may therefore pin an absolute count, not merely a
     * growth rate — though harness_assert_no_n_plus_1() is still the more robust
     * form, since it cannot be fooled by a fixture that happens to have one row.
     *
     * @param callable $fn
     *
     * @return int Statements attributable to $fn, or -1 if the probe failed
     */
    function harness_query_count(callable $fn): int
    {
        $a = harness_questions();
        $b = harness_questions();
        if ($a < 0 || $b < 0) {
            return -1;
        }
        $overhead = $b - $a;

        $before = harness_questions();
        $fn();
        $after = harness_questions();
        if ($before < 0 || $after < 0) {
            return -1;
        }

        return ($after - $before) - $overhead;
    }
}

if (!function_exists('harness_assert_no_n_plus_1')) {
    /**
     * Assert that a callable's query count does not grow with the row count.
     *
     * $run receives the fixture size and must exercise the code path over
     * exactly that many rows. Two sizes are measured; a batched implementation
     * costs the same for both, an N+1 costs roughly (large - small) more.
     *
     * @param string   $label
     * @param callable $run   fn(int $n): void
     * @param int      $small
     * @param int      $large
     */
    function harness_assert_no_n_plus_1(string $label, callable $run, int $small = 2, int $large = 8): void
    {
        $qSmall = harness_query_count(static function () use ($run, $small) {
            $run($small);
        });
        $qLarge = harness_query_count(static function () use ($run, $large) {
            $run($large);
        });

        report(
            $label,
            $qSmall >= 0 && $qLarge >= 0 && $qSmall === $qLarge,
            "same count at n=$small and n=$large",
            "n=$small -> $qSmall queries, n=$large -> $qLarge queries"
        );
    }
}

if (!function_exists('harness_result')) {
    /**
     * Print the tally. Returns the process exit code.
     *
     * @return int
     */
    function harness_result(): int
    {
        echo "\n----------------------------------------\n";
        echo "RESULT: {$GLOBALS['okCount']} passed, {$GLOBALS['failCount']} failed\n";
        if ($GLOBALS['failCount'] > 0) {
            foreach ($GLOBALS['failLabels'] as $l) {
                echo "  FAILED: $l\n";
            }
        }

        return $GLOBALS['failCount'] === 0 ? 0 : 1;
    }
}

/* file end: ./tests/lib/harness.php */
