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
 * The spam-and-bots screen, which is four independent forms rather than one: the Akismet
 * key, the captcha provider and its keys, the search-alert rule, and the sign-in rate
 * limit. Each saves on its own and says its own thing afterwards, so each is its own
 * declaration; only the sign-in limit stores anywhere but the core preference section.
 *
 * None of the keys is masked, deliberately: clearing one is how a provider is switched off,
 * so a blank submission has to mean "clear it" and not "leave it alone".
 *
 * @package mindstellar\admin\form
 */
final class SpamSettingsForm
{
    public const PAGE_AKISMET = 'core.settings_akismet';

    public const PAGE_CAPTCHA = 'core.settings_captcha';

    public const PAGE_ALERTS = 'core.settings_alerts';

    public const PAGE_LOGIN_THROTTLE = 'core.settings_login_throttle';

    /** The sign-in limiter is a security setting and is stored with the others. */
    public const SECURITY_SECTION = 'security';

    /**
     * The Akismet key. The status callout beside it costs a request to Akismet, so it is
     * the drawing controller's to work out and this is handed the answer; the saving one
     * registers the same form without it.
     *
     * @param int|null $status 1 valid, 2 invalid, 3 no key at all
     *
     * @return string the page id
     */
    public static function registerAkismet($status = null): string
    {
        if (osc_settings_page(self::PAGE_AKISMET) !== null) {
            return self::PAGE_AKISMET;
        }

        $alertType = 'error';
        $alertMsg  = '';
        switch ($status) {
            case 1:
                $alertType = 'ok';
                $alertMsg  = __('This key is valid');
                break;
            case 2:
                $alertType = 'error';
                $alertMsg  = __('The key you entered is invalid. Please double-check it');
                break;
            case 3:
                $alertType = 'warning';
                $alertMsg  = sprintf(
                    __('Akismet is disabled, please enter an API key. <a href="%s" target="_blank">(Get your key)</a>'),
                    'http://akismet.com/get/'
                );
                break;
        }

        CoreSettings::page(self::PAGE_AKISMET, __('Akismet'))
            ->text('akismetKey', __('Akismet API Key'))
                ->width('key')
                ->set(
                    'help_html',
                    $alertMsg === ''
                        ? ''
                        : '<span class="callout-' . osc_esc_html($alertType) . '">' . $alertMsg . '</span>'
                )
            ->register();

        return self::PAGE_AKISMET;
    }

    /**
     * The captcha provider and the two pairs of keys. The version is a hidden constant the
     * form posts and the preference is written from.
     *
     * @return string the page id
     */
    public static function registerCaptcha(): string
    {
        if (osc_settings_page(self::PAGE_CAPTCHA) !== null) {
            return self::PAGE_CAPTCHA;
        }

        $provider = osc_captcha_provider_pref();
        // A forced provider (Turnstile/reCAPTCHA) whose keys are blank resolves to 'none',
        // silently disabling captcha site-wide. Warn rather than leave the admin to work it
        // out from the help text.
        $warning = '';
        if (osc_captcha_provider() === 'none' && ($provider === 'turnstile' || $provider === 'recaptcha')) {
            $warning = '<span class="callout-warning">' . osc_esc_html(sprintf(
                __('Captcha is currently off: you forced a provider but its keys are empty. '
                   . 'Enter the %s below to turn captcha on.'),
                $provider === 'turnstile'
                    ? __('Turnstile site key and Turnstile secret key')
                    : __('reCAPTCHA site key and reCAPTCHA secret key')
            )) . '</span>';
        }

        CoreSettings::page(self::PAGE_CAPTCHA, __('Captcha'))
            ->hidden('recaptchaVersion', __('reCAPTCHA version'))
                ->column('recaptcha_version')
                ->default('2')
            ->select('captchaProvider', __('Captcha provider'), array(
                'auto'      => __('Automatic'),
                'turnstile' => __('Cloudflare Turnstile'),
                'recaptcha' => __('Google reCAPTCHA'),
                'none'      => __('None'),
            ))
                ->default('auto')
                ->set(
                    'help_html',
                    osc_esc_html(__('Automatic prefers reCAPTCHA whenever its site and secret keys below are set; clear '
                        . 'both to let Turnstile take over instead. Pick a provider to force it, or None to '
                        . 'turn captchas off.')) . $warning
                )
            ->text('recaptchaPubKey', __('reCAPTCHA site key'))
                ->width('key')
            ->text('recaptchaPrivKey', __('reCAPTCHA secret key'))
                ->width('key')
            ->text('turnstileSiteKey', __('Turnstile site key'), __('From the Cloudflare dashboard &raquo; Turnstile.'))
                ->width('key')
            ->text(
                'turnstileSecretKey',
                __('Turnstile secret key'),
                __('From the Cloudflare dashboard &raquo; Turnstile.')
            )
                ->width('key')
            // Proof rather than a promise: a widget on screen is the active provider
            // answering, which no amount of key-shaped text can stand in for.
            ->custom('captcha_preview', static function () {
                if (!osc_captcha_enabled()) {
                    return;
                }
                osc_admin_form_row_open(
                    __('If you see a captcha widget below, the active provider is configured correctly')
                );
                osc_show_captcha();
                osc_admin_form_row_close();
            })
                ->set('row', false)
            ->register();

        return self::PAGE_CAPTCHA;
    }

    /**
     * Whether a visitor must be signed in before subscribing to a search alert.
     *
     * @return string the page id
     */
    public static function registerAlerts(): string
    {
        if (osc_settings_page(self::PAGE_ALERTS) !== null) {
            return self::PAGE_ALERTS;
        }

        CoreSettings::page(self::PAGE_ALERTS, __('Search alerts'))
            ->checkbox('alerts_require_login', __('Only logged-in users can subscribe to search alerts'))
                ->rowLabel(__('Require login for alerts'))
            ->register();

        return self::PAGE_ALERTS;
    }

    /**
     * The sign-in rate limit. Every limit is floored rather than refused: a zero would read
     * as "no attempt allowed" and shut the form for everyone, including whoever typed it,
     * and turning the limiter off is the switch above and not a zero.
     *
     * @return string the page id
     */
    public static function registerLoginThrottle(): string
    {
        if (osc_settings_page(self::PAGE_LOGIN_THROTTLE) !== null) {
            return self::PAGE_LOGIN_THROTTLE;
        }

        CoreSettings::page(self::PAGE_LOGIN_THROTTLE, __('Sign-in protection'), self::SECURITY_SECTION)
            ->checkbox(
                'login_throttle_enabled',
                __('Count failed attempts and refuse further ones past the limits below')
            )
                ->rowLabel(__('Limit sign-in attempts'))
                ->default(true)
            ->number(
                'login_throttle_window',
                __('Window'),
                __('How far back failures are counted, and so how long a refusal lasts.')
            )
                ->clampMin(1)
                ->suffix(__('minutes'))
                ->default(15)
            ->number(
                'login_throttle_max_ip',
                __('Attempts per address'),
                __('Failures from one visitor address, across every account it tried. Keep this '
                   . 'generous: an office or mobile network is many people behind one address.')
            )
                ->clampMin(1)
                ->default(20)
            ->number(
                'login_throttle_max_account',
                __('Attempts per account'),
                __('Failures against one account name, from anywhere. This is what catches guessing '
                   . 'spread across many addresses.')
            )
                ->clampMin(1)
                ->default(10)
            ->number(
                'login_attempt_retention_days',
                __('Keep records for'),
                __('Pruned by the daily cron. Only the window above affects the limits; the rest is '
                   . 'history. 0 keeps everything.')
            )
                ->clampMin(0)
                ->suffix(__('days'))
                ->default(7)
            ->register();

        return self::PAGE_LOGIN_THROTTLE;
    }

    /**
     * What the view needs to draw all four forms, keyed by the div each one sits in.
     *
     * @param int|null                 $akismetStatus what Akismet said about the stored key:
     *                                                1 valid, 2 invalid, 3 no key at all
     * @param string                   $rejected      the page id a refused save belongs to,
     *                                                if any
     * @param array<string,mixed>|null $values        that page's submitted values
     *
     * @return array<string,array<string,mixed>> view variables per div: 'akismet', 'captcha',
     *         'alerts', 'login_throttle'
     */
    public static function formVars($akismetStatus = null, string $rejected = '', ?array $values = null): array
    {
        $forms = array(
            'akismet'         => array(self::registerAkismet($akismetStatus), 'akismet_post', 'submit_akismet'),
            'captcha'         => array(self::registerCaptcha(), 'recaptcha_post', 'submit_recaptcha'),
            'alerts'          => array(self::registerAlerts(), 'alerts_post', 'submit_alerts'),
            'login_throttle'  => array(self::registerLoginThrottle(), 'login_throttle_post', 'submit_login_throttle'),
        );

        $vars = array();
        foreach ($forms as $key => $form) {
            [$pageId, $action, $buttonId] = $form;
            $vars[$key] = CoreSettings::vars(
                $pageId,
                $action,
                $pageId === $rejected ? $values : null,
                array(
                    'name'    => 'settings_form',
                    'actions' => array(
                        array('label' => __('Save changes'), 'type' => 'submit', 'attrs' => array('id' => $buttonId)),
                    ),
                )
            );
        }

        return $vars;
    }
}
