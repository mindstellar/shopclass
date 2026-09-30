<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

use Generator;

/**
 * Reads an SQL script from a stream one statement at a time, so a large backup is never
 * held in memory whole.
 *
 * Unlike SqlScript it knows about quoted values: a `;`, `--`, `#` or `/*` inside a string
 * is text. Comments are dropped, the schema tokens are expanded, and a DELIMITER line at
 * the start of a statement changes the delimiter.
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      6.4.0
 */
final class SqlStream
{
    /**
     * Yield each trimmed, executable statement in the stream.
     *
     * @param resource $handle an open, readable stream
     *
     * @return Generator<int,string>
     */
    public static function statements($handle): Generator
    {
        $delimiter = ';';
        $statement = '';
        $quote     = null;
        $comment   = false;
        $tokens    = SqlScript::tokens();

        while (($line = fgets($handle)) !== false) {
            if ($tokens !== array() && strpos($line, '/*') !== false) {
                // Expanded everywhere, quoted values included, as SqlScript does.
                $line = strtr($line, $tokens);
            }
            if ($quote === null && !$comment && $statement === ''
                && preg_match('/^\s*DELIMITER\s+(\S+)/i', $line, $m)
            ) {
                $delimiter = $m[1];
                $statement = '';
                continue;
            }

            $length = strlen($line);
            $i      = 0;
            while ($i < $length) {
                if ($comment) {
                    $end = strpos($line, '*/', $i);
                    if ($end === false) {
                        break;
                    }
                    $comment = false;
                    $i       = $end + 2;
                    continue;
                }

                if ($quote !== null) {
                    // Backslash escapes apply inside values, not inside backtick identifiers.
                    $run        = strcspn($line, $quote === '`' ? '`' : $quote . '\\', $i);
                    $statement .= substr($line, $i, $run);
                    $i         += $run;
                    if ($i >= $length) {
                        break;
                    }
                    if ($line[$i] === '\\') {
                        $statement .= substr($line, $i, 2);
                        $i         += 2;
                        continue;
                    }
                    // A doubled quote simply opens a new literal on the next character.
                    $statement .= $quote;
                    $quote      = null;
                    $i++;
                    continue;
                }

                $run = strcspn($line, "'\"`/-#" . $delimiter[0], $i);
                if ($statement === '') {
                    // Leading whitespace is never kept, so a finished statement needs no copy to trim.
                    $blank = strspn($line, " \t\r\n", $i, $run);
                    $i    += $blank;
                    $run  -= $blank;
                }
                $statement .= substr($line, $i, $run);
                $i         += $run;
                if ($i >= $length) {
                    break;
                }

                $char = $line[$i];
                if (substr_compare($line, $delimiter, $i, strlen($delimiter)) === 0) {
                    $done      = self::finish($statement);
                    $statement = '';
                    $i        += strlen($delimiter);
                    if ($done !== '') {
                        yield $done;
                    }
                    continue;
                }
                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote      = $char;
                    $statement .= $char;
                    $i++;
                    continue;
                }
                if ($char === '/' && ($line[$i + 1] ?? '') === '*') {
                    $comment = true;
                    $i      += 2;
                    continue;
                }
                // `--` opens a comment only when whitespace or the line end follows it.
                if ($char === '#'
                    || ($char === '-' && ($line[$i + 1] ?? '') === '-'
                        && in_array($line[$i + 2] ?? "\n", array(' ', "\t", "\r", "\n"), true))
                ) {
                    if ($statement !== '') {
                        $statement .= "\n";
                    }
                    break;
                }
                $statement .= $char;
                $i++;
            }
        }

        $done      = self::finish($statement);
        $statement = '';
        if ($done !== '') {
            yield $done;
        }
    }

    /**
     * A statement without its trailing whitespace, copied only when there is some. A lone
     * "0" is not a statement, as SqlScript has always treated it.
     *
     * @param string $statement
     *
     * @return string
     */
    private static function finish(string $statement): string
    {
        if ($statement !== '' && strspn($statement, " \t\r\n", -1) === 1) {
            $statement = rtrim($statement);
        }

        return $statement === '0' ? '' : $statement;
    }
}

/* file end: ./oc-includes/osclass/classes/database/SqlStream.php */
