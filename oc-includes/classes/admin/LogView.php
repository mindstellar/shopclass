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

namespace mindstellar\admin;

/**
 * Display-only helpers for the activity log: plain section and action labels, tidy details,
 * and folding of the rows one action wrote. Nothing here changes what t_log stores.
 */
final class LogView
{
    /** Actions that are a side effect of another action on the same object. */
    private const FOLLOW_UPS = array('deleteResourcesFromHD', 'deleteResource', 'delete resource', 'delete resource backtrace');

    /**
     * Plain label for a section code; an unknown code becomes readable words.
     */
    public static function sectionLabel(string $code): string
    {
        $labels = array(
            'item'         => __('Listing'),
            'itemActions'  => __('Listing'),
            'user'         => __('User'),
            'jobs'         => __('Scheduled jobs'),
            'billing'      => __('Billing'),
            'ban'          => __('Spam and bans'),
            'media'        => __('Media'),
            'ajax'         => __('Photos'),
            'language'     => __('Language'),
            'ghostbuster'  => __('Ghost buster'),
        );

        return $labels[$code] ?? self::readable($code);
    }

    /**
     * Plain label for an action code; an unknown code becomes readable words.
     */
    public static function actionLabel(string $code): string
    {
        $labels = array(
            /* translators: Activity log action label, as in "Deleted" a category. */
            'add'                       => __('Added'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'edit'                      => __('Edited'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'delete'                    => __('Deleted'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'register'                  => __('Registered'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'activate'                  => __('Activated'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'deactivate'                => __('Deactivated'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'enable'                    => __('Enabled'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'disable'                   => __('Disabled'),
            'spam'                      => __('Marked as spam'),
            'unspam'                    => __('Spam mark removed'),
            'premium'                   => __('Made premium'),
            'unpremium'                 => __('Premium removed'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'publish'                   => __('Published'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'bump'                      => __('Bumped'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'run'                       => __('Ran'),
            'backup'                    => __('Backed up'),
            'cleanup'                   => __('Cleaned up'),
            'gave_up'                   => __('Gave up'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'paid'                      => __('Paid'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'refunded'                  => __('Refunded'),
            'refund_gateway'            => __('Refunded at the gateway'),
            /* translators: Activity log action label, as in "Deleted" a category. */
            'report'                    => __('Reported'),
            'deleteimage'               => __('Deleted photo'),
            'deleteResource'            => __('Deleted photo'),
            'delete resource'           => __('Deleted photo'),
            'deleteResourcesFromHD'     => __('Deleted photos'),
            'delete resource backtrace' => __('Photo delete trace'),
            'delete_alerts'             => __('Deleted alerts'),
            'save_failed'               => __('Save failed'),
        );

        return $labels[$code] ?? self::readable($code);
    }

    /**
     * Details text for display: surrounding spaces and stray commas removed.
     */
    public static function cleanDetails(string $text): string
    {
        return trim((string) preg_replace('/^[\s,;]+|[\s,;]+$/u', '', $text));
    }

    /**
     * Group consecutive rows from one action: same who, same object id (above 0), same second.
     * Each group is array('head' => row, 'more' => rows); the head is the row that is not a
     * follow-up of another (such as a photo clean-up after a delete) when there is one.
     *
     * @param array<int,array<string,mixed>> $logs Rows in display order
     *
     * @return array<int,array{head:array<string,mixed>,more:array<int,array<string,mixed>>}>
     */
    public static function fold(array $logs): array
    {
        $runs = array();
        $prev = null;
        foreach ($logs as $row) {
            $key = (int) $row['fk_i_id'] > 0
                ? $row['dt_date'] . '|' . $row['s_who'] . '|' . $row['fk_i_who_id'] . '|' . $row['fk_i_id']
                : null;
            if ($key !== null && $key === $prev) {
                $runs[count($runs) - 1][] = $row;
            } else {
                $runs[] = array($row);
            }
            $prev = $key;
        }

        $groups = array();
        foreach ($runs as $run) {
            $headAt = 0;
            foreach ($run as $i => $row) {
                if (!in_array($row['s_action'], self::FOLLOW_UPS, true)) {
                    $headAt = $i;
                    break;
                }
            }
            $head = $run[$headAt];
            unset($run[$headAt]);
            $groups[] = array('head' => $head, 'more' => array_values($run));
        }

        return $groups;
    }

    /**
     * 'deleteResourcesFromHD' or 'gave_up' as "Delete resources from hd" style words.
     */
    private static function readable(string $code): string
    {
        $words = strtolower(trim((string) preg_replace('/(?<=[a-z])(?=[A-Z])|[_\s]+/', ' ', $code)));

        return $words === '' ? $code : ucfirst($words);
    }
}
