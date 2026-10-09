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

namespace mindstellar\admin\form;

use Cleanup;

/**
 * Tools -> Cleanup: which rules run, how old their matches must be, the batch size, and the
 * listing view statistics. The rules sit in a table, opened and closed by custom rows.
 */
final class CleanupSettingsScreen extends ToolsSettingsScreen
{
    public const PAGE_ID = 'core.tools_cleanup';
    protected const ACTION    = 'cleanup_post';
    protected const FORM_NAME = 'cleanup_form';

    /**
     * What each rule removes, in run order.
     *
     * @return array<string,string>
     */
    public static function descriptions(): array
    {
        return array(
            'reported'          => __('Listings visitors have flagged as spam, unchanged since.'),
            'expired'           => __('Listings past their expiration date.'),
            'inactive_listings' => __('Listings never activated from the confirmation email.'),
            'spam'              => __('Listings marked as spam.'),
            'blocked'           => __('Listings that are disabled/blocked.'),
            'inactive_users'    => __('Accounts never activated from the confirmation email.'),
            'orphan_avatars'    => __('Profile pictures left behind by deleted accounts.'),
        );
    }

    /**
     * A whole number above zero, or $default.
     *
     * @return callable(mixed):int
     */
    private static function positiveOr(int $default): callable
    {
        return static function ($value) use ($default): int {
            $value = (int) $value;

            return $value > 0 ? $value : $default;
        };
    }

    protected static function declareFields(): void
    {
        $labels = Cleanup::ruleLabels();
        $form   = CoreSettings::page(self::PAGE_ID, __('Cleanup'))
            ->custom('rules_open', static function (): void {
                osc_admin_form_section(__('What to remove'), array(
                    'intro' => __('Tick what to clean and how old it must be. Everything removed is gone for good.'),
                ));
                echo '<div class="table-responsive"><table class="table" style="min-width:34rem"><thead><tr>'
                    . '<th>' . osc_esc_html(__('What to remove')) . '</th>'
                    . '<th>' . osc_esc_html(__('Older than')) . '</th>'
                    . '<th class="text-end">' . osc_esc_html(__('Matching now')) . '</th>'
                    . '</tr></thead><tbody>';
            })
                ->set('row', false);

        foreach (self::descriptions() as $rule => $description) {
            $form
                ->custom('rule_open_' . $rule, static function (): void {
                    echo '<tr><td>';
                })
                    ->set('row', false)
                ->checkbox('enabled_' . $rule, $labels[$rule], $description)
                    ->set('row', false)
                    ->set('id', 'enabled_' . $rule)
                    ->default(false)
                ->custom('rule_days_' . $rule, static function (): void {
                    echo '</td><td>';
                })
                    ->set('row', false)
                ->number('days_' . $rule, __('Older than'))
                    ->set('row', false)
                    ->set('min', 1)
                    ->suffix(__('days'))
                    ->default(Cleanup::DEFAULT_DAYS)
                    ->sanitize(self::positiveOr(Cleanup::DEFAULT_DAYS))
                ->custom('rule_close_' . $rule, static function (array $field) use ($rule): void {
                    $days = (int) ($field['values']['days_' . $rule] ?? Cleanup::DEFAULT_DAYS);
                    echo '</td><td class="text-end">'
                        . number_format(Cleanup::getInstance()->countFor($rule, $days > 0 ? $days : Cleanup::DEFAULT_DAYS))
                        . '</td></tr>';
                })
                    ->set('row', false);
        }

        $form
            ->custom('rules_close', static function (): void {
                echo '</tbody></table></div>';
            })
                ->set('row', false)
            ->number('batch_limit', __('Items removed per batch'), __('Cleanup runs in the background, one batch at a time, until nothing matches.'))
                ->set('id', 'batch_limit')
                ->set('min', 1)
                ->default(Cleanup::DEFAULT_BATCH)
                ->sanitize(self::positiveOr(Cleanup::DEFAULT_BATCH))
            ->custom('stats_open', static function (): void {
                osc_admin_form_section(__('Listing statistics'), array(
                    'spaced' => true,
                    'intro'  => __('View counts are written on every page render, so they are the busiest '
                                   . 'write on a large site. Turn them off if you do not use them.'),
                ));
                osc_admin_form_row_open(__('View counting'));
            })
                ->set('row', false)
            ->checkbox('item_views_enabled', __('Count listing views'), __('Report counts are always recorded — moderation depends on them.'))
                ->set('row', false)
                ->set('id', 'item_views_enabled')
                ->default(true)
            ->checkbox(
                'count_bot_views',
                __('Count crawler visits as views'),
                __('Off by default. Search-engine and AI crawlers are usually most of a busy '
                   . "site's traffic, so counting them both inflates the numbers and multiplies "
                   . 'the writes.')
            )
                ->set('row', false)
                ->set('id', 'count_bot_views')
                ->default(false)
            ->custom('stats_close', static function (): void {
                osc_admin_form_row_close();
            })
                ->set('row', false)
            ->number(
                'item_stats_retention_days',
                __('Keep daily statistics history for'),
                __('0 keeps it forever. This is the history behind the statistics charts, which '
                   . 'look back up to ten months; it is a few rows per day for the whole site.')
            )
                ->set('id', 'item_stats_retention_days')
                ->suffix(__('days'))
                ->default(0)
                ->clampMin(0)
            ->register();
    }
}
