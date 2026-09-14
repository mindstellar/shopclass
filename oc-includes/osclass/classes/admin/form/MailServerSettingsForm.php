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
 * The mail-server settings screen.
 *
 * The password is the only control here that is not simply what it looks like: it renders
 * masked, so the stored value never reaches the page as text, and a blank box means "leave
 * it as it was" rather than "clear it" -- declared as a persist callable that writes nothing
 * for an empty value, which is what the hand-written controller did by reading the old
 * password back and writing it again.
 *
 * @package mindstellar\admin\form
 */
final class MailServerSettingsForm
{
    public const PAGE_ID = 'core.settings_mailserver';

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

        // apache_mod_loaded() exists only under mod_php, so on any other SAPI the answer is
        // "cannot tell" rather than a call that is not there.
        $sslWarning = '';
        if (!function_exists('apache_mod_loaded')) {
            $sslWarning = __('Cannot be sure that Apache Module <b>mod_ssl</b> is loaded.');
        } elseif (!@\apache_mod_loaded('mod_ssl')) {
            $sslWarning = __('Apache Module <b>mod_ssl</b> is not loaded');
        }

        CoreSettings::page(self::PAGE_ID, __('Mail Settings'))
            ->select('mailserver_type', __('Server type'), array(
                'custom' => __('Custom Server'),
                'gmail'  => __('GMail Server'),
            ))
            ->text('mailserver_host', __('Hostname'))
            ->email('mailserver_mail_from', __('Mail from'))
            ->text('mailserver_name_from', __('Name from'))
            ->number('mailserver_port', __('Server port'))
                ->set('min', 0)
                ->set('max', 65535)
                ->default(0)
            ->text('mailserver_username', __('Username'))
            ->secret('mailserver_password', __('Password'), __('Leave blank to keep the current password.'))
                ->set('masked', true)
                ->set('reveal', true)
                // Masked, so the stored value is read back only to know there is one -- the
                // control draws a placeholder, never the password.
                ->writeOnly(false)
                ->persist(static fn ($value) => $value === '' ? null : $value)
            ->select('mailserver_ssl', __('Encryption'), array(
                ''    => __('None'),
                'ssl' => 'SSL',
                'tls' => 'TLS',
            ))
                ->set(
                    'help_html',
                    $sslWarning === '' ? '' : '<span class="callout-warning">' . $sslWarning . '</span>'
                )
            ->checkbox('mailserver_auth', __('SMTP authentication enabled'))
                ->rowLabel(__('SMTP'))
            ->checkbox('mailserver_pop', __('Use POP before SMTP'))
                ->rowLabel(__('POP'))
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
            'mailserver_post',
            $values,
            array('name' => 'settings_form')
        );
    }
}
