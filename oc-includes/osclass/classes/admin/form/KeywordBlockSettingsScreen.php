<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\base\SettingsScreen;

/**
 * The moderation switches at the head of the keyword blocklist screen: whether the filter
 * runs, what a match does, and when reports hide a listing on their own.
 *
 * The blocklist itself is a table of rows with its own list, add and import forms, and none
 * of that is declared here -- this is the preference form, and only that.
 *
 * @package mindstellar\admin\form
 */
final class KeywordBlockSettingsScreen extends SettingsScreen
{
    public const PAGE_ID = 'core.settings_keyword_block';
    protected const ACTION    = 'keyword_block_prefs_post';
    protected const FORM_NAME = 'keyword_block_prefs_form';

    protected static function declareFields(): void
    {
        CoreSettings::page(self::PAGE_ID, __('Keyword blocklist'))
            ->checkbox('keyword_spam_enabled', __('Check new and edited listings against the keyword blocklist below'))
                ->rowLabel(__('Keyword filter'))
            ->checkbox(
                'keyword_spam_hard_block',
                __('Reject the listing outright instead of quarantining it for review'),
                __('Off by default: a match is quarantined (flagged spam and hidden) so it can be '
                   . 'reviewed and reversed. Turn this on to reject the post before it is ever saved.')
            )
                ->rowLabel(__('On a match'))
            ->checkbox(
                'report_autoblock',
                __('Automatically hide a listing once enough distinct visitors have reported it')
            )
                ->rowLabel(__('Report auto-block'))
            ->number(
                'report_threshold',
                __('Report threshold'),
                __('Number of distinct reporters (one vote per person) that auto-hides a listing.')
            )
                ->clampMin(1)
                ->suffix(__('reporters'))
                ->default(0)
            ->checkbox(
                'enabled_recaptcha_reports',
                __('Require a CAPTCHA to report a listing'),
                __('Needs a CAPTCHA provider configured under Settings &raquo; reCAPTCHA/Turnstile; '
                   . 'otherwise no challenge is shown.')
            )
                ->rowLabel(__('Report CAPTCHA'))
            ->register();
    }
}
