<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\location;

use CityStats;
use CountryStats;
use mindstellar\job\Job;
use mindstellar\job\JobQueue;
use mindstellar\job\JobRegistry;
use RegionStats;

/**
 * Recounts the live listings of every country, region and city on the job queue. Counts
 * change as listings do; this pass only corrects drift, so it runs weekly and on demand.
 */
final class LocationRecountJobs
{
    public const TYPE = 'location.recount';

    /** Locations recounted per job run. */
    public const BATCH = 5000;

    /** level => [table, key column] */
    private const LEVELS = array(
        'country' => array('t_country', 'pk_c_code'),
        'region'  => array('t_region', 'pk_i_id'),
        'city'    => array('t_city', 'pk_i_id'),
    );

    public static function register(): void
    {
        JobRegistry::register(self::TYPE, static fn (Job $job) => self::recount($job));
        JobRegistry::describe(self::TYPE, __('Location listing counts'), static fn (array $p): string => (string) ($p['level'] ?? ''));
    }

    /**
     * Queue a full recount unless one is already waiting or running.
     *
     * @return int locations left to count
     */
    public static function queue(): int
    {
        $left = self::pending();
        if ($left > 0) {
            return $left;
        }
        $total = 0;
        foreach (array_keys(self::LEVELS) as $level) {
            $total += self::remaining($level, '');
            osc_job_enqueue(self::TYPE, array('level' => $level, 'after' => ''), array('unique_key' => $level));
        }
        osc_set_preference('location_todo', (string) $total);

        return self::pending();
    }

    /**
     * Locations a queued or running recount has still to count; 0 when none is queued.
     */
    public static function pending(): int
    {
        $left = 0;
        foreach (array(JobQueue::STATUS_PENDING, JobQueue::STATUS_RUNNING) as $status) {
            foreach (JobQueue::getInstance()->page($status, self::TYPE, 10) as $row) {
                $payload = json_decode((string) ($row['s_payload'] ?? ''), true);
                $level   = is_array($payload) ? (string) ($payload['level'] ?? '') : '';
                if (isset(self::LEVELS[$level])) {
                    $left += self::remaining($level, (string) ($payload['after'] ?? ''));
                }
            }
        }

        return $left;
    }

    /**
     * Count one batch of a level, then queue the next batch while the level has more.
     */
    private static function recount(Job $job): void
    {
        $level = (string) $job->get('level', '');
        if (!isset(self::LEVELS[$level])) {
            return;
        }
        $after = (string) $job->get('after', '');
        $ids   = self::nextIds($level, $after);
        if ($ids === array()) {
            return;
        }

        if ($level === 'country') {
            $stats = CountryStats::newInstance();
            foreach ($ids as $code) {
                if ($stats->setNumItems($code, $stats->calculateNumItems($code)) !== true) {
                    throw new \RuntimeException('Could not save the listing count of country ' . $code);
                }
            }
        } else {
            $ids = array_map('intval', $ids);
            $ok  = $level === 'region'
                ? RegionStats::newInstance()->updateAllStats($ids)
                : CityStats::newInstance()->updateAllStats($ids);
            if ($ok !== true) {
                throw new \RuntimeException('Could not save the listing counts of ' . count($ids) . ' ' . $level . ' rows');
            }
        }

        if (count($ids) === self::BATCH) {
            $job->repeat(array('level' => $level, 'after' => (string) end($ids)));
        }
    }

    /**
     * @return string[] the next keys of a level after $after, in key order
     */
    private static function nextIds(string $level, string $after): array
    {
        [$table, $key] = self::LEVELS[$level];
        $q = osc_db_table(DB_TABLE_PREFIX . $table)->select($key)->orderBy($key)->limit(self::BATCH);
        if ($after !== '') {
            $q = $q->where($key, '>', $level === 'country' ? $after : (int) $after);
        }

        return array_map(static fn (array $row): string => (string) $row[$key], $q->get());
    }

    private static function remaining(string $level, string $after): int
    {
        [$table, $key] = self::LEVELS[$level];
        $q = osc_db_table(DB_TABLE_PREFIX . $table);
        if ($after !== '') {
            $q = $q->where($key, '>', $level === 'country' ? $after : (int) $after);
        }

        return $q->count();
    }
}
