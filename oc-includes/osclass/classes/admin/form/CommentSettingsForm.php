<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

/**
 * The comment settings screen.
 *
 * One preference is two controls: 'moderate_comments' holds -1 when moderation is off and
 * otherwise the number of approved comments an author needs before theirs appear. So the
 * switch owns the key and derives its value from the box beside it, and the box itself is
 * stored nowhere -- which is what the hand-written controller did with an if, said in the
 * declaration instead.
 *
 * @package mindstellar\admin\form
 */
final class CommentSettingsForm
{
    public const PAGE_ID = 'core.settings_comments';

    /** What 'moderate_comments' holds while moderation is switched off. */
    public const MODERATION_OFF = '-1';

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $moderated = osc_moderate_comments();

        $form = CoreSettings::page(self::PAGE_ID, __('Comment Settings'));

        $form
            ->group(__('Comment Settings'))
            // Four switches and a number under one label, as the screen has always drawn
            // them: the row is opened and closed around them rather than once per control.
            ->custom('default_row_open', static function () {
                osc_admin_form_row_open(__('Default comment settings'));
            })
                ->set('row', false)
            ->checkbox('enabled_comments', __('Allow people to post comments on listings'))
                ->set('row', false)
            ->checkbox('reg_user_post_comments', __('Users must be registered and logged in to comment'))
                ->set('row', false)
            ->checkbox(
                'enabled_recaptcha_comments',
                __('Require a CAPTCHA to post a comment'),
                __('Needs a CAPTCHA provider configured under Settings &raquo; reCAPTCHA/Turnstile; otherwise no challenge is shown.')
            )
                ->set('row', false)
            ->checkbox('moderate_comments', __('A comment is being held for moderation'))
                ->set('row', false)
                // The key is this switch's, and its value comes from the box below: off is
                // the -1 every reader already understands.
                ->persist(static function ($value, array $values) {
                    return $value ? (string)($values['num_moderate_comments'] ?? '') : self::MODERATION_OFF;
                })
                // The stored form is a count, not a tick, so the control cannot read it back.
                ->writeOnly()
                ->default($moderated != self::MODERATION_OFF)
            ->custom('approved_open', static function () {
                echo '<div class="form-label-checkbox-offset comments_approved">';
            })
                ->set('row', false)
            ->number(
                'num_moderate_comments',
                __('Moderated comments'),
                __('If the value is zero, an administrator must always approve comments')
            )
                ->set('row', false)
                ->prefix(__('Before a comment appears, comment author must have at least'))
                ->suffix(__('previously approved comments'))
                ->required()
                // On screen only while moderation is on. The browser refuses to submit a
                // form holding a required control it cannot show, so Save would look dead.
                ->dependsOn('moderate_comments')
                ->set('min', 0)
                ->sanitize(CoreSettings::wholeNumber())
                // Not a preference of its own: it is where the switch above gets its value.
                ->persist(false)
                ->default($moderated == self::MODERATION_OFF ? 0 : $moderated)
            ->custom('approved_close', static function () {
                echo '</div>';
            })
                ->set('row', false)
            ->custom('default_row_close', static function () {
                osc_admin_form_row_close();
            })
                ->set('row', false)
            ->number('comments_per_page', __('Comments per page'), __('If the value is zero all comments are shown'))
                ->rowLabel(__('Other comment settings'))
                ->prefix(__('Break comments into pages with'))
                ->suffix(__('comments per page'))
                ->required()
                ->set('min', 0)
                // A count, not a measurement: without this a posted "2.5" is stored verbatim.
                ->sanitize(CoreSettings::wholeNumber())
                ->default(0)
            ->group(__('Notifications'))
            ->checkbox('notify_new_comment', __('A new comment is posted'))
                ->rowLabel(__('E-mail admin whenever'))
            ->checkbox('notify_new_comment_user', __("There's a new comment on his listing"))
                ->rowLabel(__('E-mail user whenever'))
            ->register();

        return self::PAGE_ID;
    }

    /**
     * What the view needs to draw the form.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *                                         for the stored ones
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars(?array $values = null): array
    {
        return CoreSettings::vars(
            self::register(),
            'comments_post',
            $values,
            array('name' => 'comments_form')
        );
    }
}
