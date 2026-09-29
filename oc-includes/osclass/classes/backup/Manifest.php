<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\backup;

use mindstellar\database\TablePrefix;

/**
 * The manifest.json inside a backup, and the checks a restore makes against it. Pure:
 * no database, no files.
 */
final class Manifest
{
    public const FORMAT = 1;

    public const WHAT = array('database', 'files', 'everything');

    /** Core tables a legacy dump names, used to read its prefix. */
    private const CORE_TABLES = array('t_preference', 't_item', 't_user', 't_admin', 't_category', 't_locale', 't_country', 't_widget');

    /**
     * A manifest as the engine writes it. Holds no secrets.
     *
     * @param array<string,mixed> $facts what, kind, created, contents, skipped, site_url, php, db_server
     *
     * @return array<string,mixed>
     */
    public static function build(array $facts): array
    {
        return array(
            'format'            => self::FORMAT,
            'shopclass_version' => defined('OSCLASS_VERSION') ? (string) OSCLASS_VERSION : '',
            'db_version'        => (string) ($facts['db_version'] ?? ''),
            'table_prefix'      => defined('DB_TABLE_PREFIX') ? (string) DB_TABLE_PREFIX : '',
            'created'           => (string) ($facts['created'] ?? date('c')),
            'site_url'          => (string) ($facts['site_url'] ?? ''),
            'what'              => (string) ($facts['what'] ?? 'everything'),
            'kind'              => (string) ($facts['kind'] ?? 'backup'),
            'contents'          => (array) ($facts['contents'] ?? array()),
            'skipped'           => (array) ($facts['skipped'] ?? array()),
            'photos_in_bucket'  => $facts['photos_in_bucket'] ?? null,
            'php'               => PHP_VERSION,
            'db_server'         => (string) ($facts['db_server'] ?? ''),
        );
    }

    /**
     * A manifest from its JSON, or null when it is not one of ours.
     *
     * @param string $json
     *
     * @return array<string,mixed>|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || (int) ($data['format'] ?? 0) < 1 || !in_array($data['what'] ?? '', self::WHAT, true)
            || !is_string($data['shopclass_version'] ?? null) || !is_string($data['created'] ?? null)
        ) {
            return null;
        }

        return $data;
    }

    /**
     * Whether a backup may be restored here.
     *
     * $manifest is null for a bare .sql file; then $sqlPrefix is the table prefix its
     * CREATE and INSERT lines name, or null when they use the prefix token.
     *
     * @param array<string,mixed>|null $manifest
     * @param string                   $siteVersion
     * @param string                   $sitePrefix
     * @param string|null              $sqlPrefix
     *
     * @return array{ok:bool,reason:string,migrate:bool,note:string}
     */
    public static function check(?array $manifest, string $siteVersion, string $sitePrefix, ?string $sqlPrefix = null): array
    {
        if ($manifest === null) {
            if ($sqlPrefix !== null && $sqlPrefix !== $sitePrefix) {
                return self::refuse(sprintf(
                    __('This backup uses tables named %1$s. This site uses %2$s. It cannot be restored here.'),
                    $sqlPrefix . '…',
                    $sitePrefix . '…'
                ));
            }

            return array(
                'ok'      => true,
                'reason'  => '',
                'migrate' => true,
                'note'    => __('This file has no version information. Updates run after it, just in case.'),
            );
        }

        $from = (string) $manifest['shopclass_version'];
        if ($from !== '' && version_compare($from, $siteVersion, '>')) {
            return self::refuse(sprintf(
                __('This backup comes from Shopclass %1$s. This site runs %2$s. Update Shopclass first, then restore.'),
                $from,
                $siteVersion
            ));
        }
        $older = $from === '' || version_compare($from, $siteVersion, '<');

        return array(
            'ok'      => true,
            'reason'  => '',
            'migrate' => $older,
            'note'    => $older ? sprintf(__('From Shopclass %s. Updates run after the restore.'), $from !== '' ? $from : '?') : '',
        );
    }

    /**
     * The table prefix an SQL dump names, read from its first CREATE TABLE or INSERT
     * lines. Null when it uses the prefix token or names no core table.
     *
     * @param resource $handle read from its current position; the caller rewinds
     * @param int      $maxBytes
     *
     * @return string|null
     */
    public static function sqlPrefix($handle, int $maxBytes = 8388608): ?string
    {
        $read    = 0;
        $pattern = '/^\s*(?:CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|INSERT\s+INTO\s+)`([^`]+)`/i';
        while ($read < $maxBytes && ($line = fgets($handle, 65536)) !== false) {
            $read += strlen($line);
            if (!preg_match($pattern, $line, $m)) {
                continue;
            }
            if (strpos($m[1], TablePrefix::TOKEN) === 0) {
                return null;
            }
            foreach (self::CORE_TABLES as $table) {
                $len = strlen($table);
                if (strlen($m[1]) >= $len && substr($m[1], -$len) === $table) {
                    return substr($m[1], 0, -$len);
                }
            }
        }

        return null;
    }

    /**
     * @param string $reason
     *
     * @return array{ok:bool,reason:string,migrate:bool,note:string}
     */
    private static function refuse(string $reason): array
    {
        return array('ok' => false, 'reason' => $reason, 'migrate' => false, 'note' => '');
    }
}
