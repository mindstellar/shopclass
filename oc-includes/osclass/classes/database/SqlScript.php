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
