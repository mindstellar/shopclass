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

use mindstellar\base\SettingsScreen;

/**
 * The user settings screen (Users > Settings).
 */
final class UserSettingsScreen extends SettingsScreen
{
    public const PAGE_ID = 'core.settings_users';
    protected const ACTION    = 'settings_post';
    protected const FORM_NAME = 'users_form';

    protected static function declareFields(): void
    {
        CoreSettings::page(self::PAGE_ID, __('User Settings'))
            ->group(__('User Settings'))
            ->custom('settings_row_open', static function () {
                osc_admin_form_row_open(__('Settings'));
            })
                ->set('row', false)
            ->checkbox('enabled_users', __('Users enabled'))
                ->set('row', false)
                ->set('id', 'enabled_users')
            ->checkbox('enabled_user_registration', __('Anyone can register'))
                ->set('row', false)
                ->set('id', 'enabled_user_registration')
            ->checkbox('enabled_user_validation', __('Users need to validate their account'))
                ->set('row', false)
                ->set('id', 'enabled_user_validation')
            ->custom('settings_row_close', static function () {
                osc_admin_form_row_close();
            })
                ->set('row', false)
            ->checkbox('notify_new_user', __('When a new user is registered'))
                ->rowLabel(__('Admin notifications'))
                ->set('id', 'notify_new_user')
            ->text('username_blacklist', __('Username blacklist'), __('List of terms not allowed in usernames, separated by commas'))
                ->set('id', 'username_blacklist')
                ->width('key')
                ->sanitize(static function ($value) {
                    return implode(',', array_map(
                        static fn ($term) => strtolower(trim($term)),
                        explode(',', (string)$value)
                    ));
                })
            ->register();
    }

    /**
     * What the view needs to draw the form. It posts to the users controller.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back
     *
     * @return array<string,mixed>
     */
    public static function formVars(?array $values = null): array
    {
        $vars          = parent::formVars($values);
        $vars['route'] = array('page' => 'users', 'action' => self::ACTION);

        return $vars;
    }
}
