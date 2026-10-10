<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\model\KeyValue;

/**
 * When each cron schedule (HOURLY, DAILY, WEEKLY) last ran and next runs, kept in the
 * `cron` group of t_key_value as JSON {"last": ..., "next": ...} in site time.
 */
class Cron
{
    public const KV_GROUP = 'cron';

    /**
     * @var Cron
     */
    private static $instance;

    /**
     * Return the shared Cron model instance, creating it on first use.
     *
     * @return \Cron
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @deprecated 7.0.0 Use getInstance(); it returns the shared instance, not a new one.
     */
    public static function newInstance()
    {
        return self::getInstance();
    }

    /**
     * Return crons by type
     *
     * @param string $type
     *
     * @return array<string,string>|false e_type, d_last_exec and d_next_exec, or false when no cron of that type exists
     */
    public function getCronByType($type)
    {
        $read = $this->read((string) $type);

        return $read === null ? false : $read['row'];
    }

    /**
     * Every schedule, as getCronByType() returns each.
     *
     * @return array<int,array<string,string>>
     */
    public function listAll()
    {
        $rows = array();
        foreach ((new KeyValue())->group(self::KV_GROUP) as $type => $stored) {
            $row = self::row((string) $type, $stored['value']);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Track a schedule again as never run, when it has no readable times.
     *
     * @param string $type HOURLY, DAILY or WEEKLY
     */
    public function restore(string $type): void
    {
        try {
            if ($this->read($type) === null) {
                (new KeyValue())->set(self::KV_GROUP, $type, self::encode('1000-01-01 00:00:00', '1000-01-01 00:00:00'));
            }
        } catch (\mindstellar\database\DbException | \InvalidArgumentException $e) {
            error_log('Cron schedule ' . $type . ' not restored: ' . $e->getMessage());
        }
    }

    /**
     * Take a due run: move the schedule on, but only if it still says what the caller read.
     * Of two requests (or servers) that saw the same run due, only one gets true.
     *
     * @param string $type     HOURLY, DAILY or WEEKLY
     * @param string $seenNext d_next_exec as the caller read it
     * @param string $lastExec
     * @param string $nextExec
     *
     * @return bool
     */
    public function claim(string $type, string $seenNext, string $lastExec, string $nextExec): bool
    {
        try {
            $read = $this->read($type);
            if ($read === null || $read['row']['d_next_exec'] !== $seenNext) {
                return false;
            }

            // The update matches only the exact value read, so a run claimed in between wins.
            return (new KeyValue())->update(self::KV_GROUP, $type, self::encode($lastExec, $nextExec), null, null, null, $read['value']) === 1;
        } catch (\mindstellar\database\DbException | \InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * @return array{value:string,row:array<string,string>}|null
     */
    private function read(string $type): ?array
    {
        try {
            $stored = (new KeyValue())->get(self::KV_GROUP, $type);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        $row = $stored === null ? null : self::row($type, $stored['value']);

        return $row === null ? null : array('value' => (string) $stored['value'], 'row' => $row);
    }

    /**
     * @return array<string,string>|null
     */
    private static function row(string $type, ?string $value): ?array
    {
        $times = json_decode((string) $value, true);
        if (!is_array($times) || !isset($times['last'], $times['next'])) {
            return null;
        }

        return array('e_type' => $type, 'd_last_exec' => (string) $times['last'], 'd_next_exec' => (string) $times['next']);
    }

    private static function encode(string $last, string $next): string
    {
        return (string) json_encode(array('last' => $last, 'next' => $next));
    }
}

/* file end: ./oc-includes/osclass/model/Cron.php */
