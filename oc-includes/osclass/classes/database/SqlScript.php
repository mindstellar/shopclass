<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\database;

/**
 * Splits a multi-statement SQL script into individually executable statements.
 *
 * Schema files (installer/struct.sql), locale mail templates, `.sql` migrations
 * and admin-uploaded imports all arrive as one blob that no single query call can
 * run. This is the one place that turns such a blob into statements, so the
 * installer, the migration runner and the legacy import path all parse alike.
 *
 * Splitting is deliberately naive -- a `;` scan, after block comments are removed
 * and with DELIMITER blocks honoured. It does not parse string literals, so a
 * semicolon inside a quoted value would split in the wrong place. That matches
 * what the import path has always done and what every shipped schema file
 * assumes; a statement needing an embedded `;` belongs in a `.php` migration.
 *
 * @package    Shopclass
 * @subpackage Database
 * @since      5.3.0
 */
final class SqlScript
{
    /**
     * Parse a script into trimmed, individually executable statements.
     *
     * @param string $sql
     *
     * @return string[] Empty when the script holds nothing executable
     */
    public static function statements(string $sql): array
    {
        // Tokens first: the placeholders are themselves block comments, so
        // stripping comments ahead of substitution would erase them.
        $sql = self::substituteTokens($sql);
        $sql = self::stripBlockComments($sql);
        $sql = self::stripLineComments($sql);

        $statements = array();
        foreach (self::split($sql, ';') as $statement) {
            $statement = trim($statement);
            // empty() rather than a '' test, matching the long-standing import
            // behaviour: a fragment of just "0" is not a statement worth running.
            if (!empty($statement)) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * Expand the placeholders schema files are authored with.
     *
     * @param string $sql
     *
     * @return string
     */
    private static function substituteTokens(string $sql): string
    {
        $tokens = array();
        if (defined('DB_TABLE_PREFIX')) {
            $tokens['/*TABLE_PREFIX*/'] = DB_TABLE_PREFIX;
        }
        if (defined('OSCLASS_VERSION')) {
            $tokens['/*OSCLASS_VERSION*/'] = OSCLASS_VERSION;
        }
        if ($tokens === array()) {
            return $sql;
        }

        return str_replace(array_keys($tokens), array_values($tokens), $sql);
    }

    /**
     * @param string $sql
     *
     * @return string
     */
    private static function stripBlockComments(string $sql): string
    {
        return (string)preg_replace('#/\*(?:[^*]*(?:\*(?!/))*)*\*/#', '', $sql);
    }

    /**
     * Remove `--` and `#` comments, leaving their line break in place.
     *
     * Block comments were stripped long before this existed, line comments never
     * were, and callers that read a statement's text rather than just executing it
     * saw the comment as part of it. The schema reconciler takes a table's column
     * list as everything between the first `(` and the last `)` of the statement,
     * so a comment above a CREATE TABLE that merely happened to contain a bracket
     * -- `SUM()`, `(value x 1000000)` -- started the column list early, and the
     * prose that followed was read as column definitions and issued as ALTER TABLE
     * ADD COLUMN. Comments describing the schema are the whole point of writing
     * them, so they are removed here rather than being banned from struct.sql.
     *
     * Quote-aware, because this also parses locale mail templates and dumps
     * uploaded through the admin importer, where `--` inside a string literal is
     * ordinary text and must survive. `--` only opens a comment when whitespace or
     * end-of-input follows it, which is what MySQL requires and what keeps `5--3`
     * intact. Backslash escapes and doubled quotes are both handled.
     *
     * @param string $sql
     *
     * @return string
     */
    private static function stripLineComments(string $sql): string
    {
        $out    = '';
        $length = strlen($sql);
        $quote  = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $out .= $char;
                // Backslash escaping applies inside quoted values, not inside the
                // backtick-quoted identifiers that only double their delimiter.
                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $out .= $sql[++$i];
                    continue;
                }
                if ($char === $quote) {
                    // A doubled delimiter re-opens the literal on the next pass,
                    // which leaves the text between them untouched either way.
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out  .= $char;
                continue;
            }

            $opensComment = $char === '#'
                || ($char === '-'
                    && $i + 1 < $length
                    && $sql[$i + 1] === '-'
                    && ($i + 2 >= $length || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t"
                        || $sql[$i + 2] === "\r" || $sql[$i + 2] === "\n"));

            if ($opensComment) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                // The newline is kept: statements are split on ';' and a comment
                // sitting between two of them must not join their text together.
                $out .= "\n";
                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Split on $delimiter, recursing whenever a DELIMITER directive changes it.
     * The directive itself is consumed rather than emitted.
     *
     * @param string $sql
     * @param string $delimiter
     *
     * @return string[]
     */
    private static function split(string $sql, string $delimiter): array
    {
        if (preg_match('|^(.*)DELIMITER (\S+)\s(.*)$|isU', $sql, $matches)) {
            return array_merge(
                explode($delimiter, $matches[1]),
                self::split($matches[3], $matches[2])
            );
        }

        return explode($delimiter, $sql);
    }
}

/* file end: ./oc-includes/osclass/classes/database/SqlScript.php */
