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

use mindstellar\security\ActionThrottle;

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
final class SpamSettingsScreen
{
    public const PAGE_AKISMET = 'core.settings_akismet';

    public const PAGE_CAPTCHA = 'core.settings_captcha';

    public const PAGE_ALERTS = 'core.settings_alerts';

    public const PAGE_LOGIN_THROTTLE = 'core.settings_login_throttle';

    /** Links allowed in a message, and the report link in mail to members. */
    public const PAGE_MESSAGES = 'core.settings_messages';

    /** Hourly limits on the public forms and uploads. */
    public const PAGE_LIMITS = 'core.settings_limits';

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
     * Whether a visitor must be signed in before subscribing to a search alert, and how many
     * saved searches one user may keep.
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
            ->number(
                'alerts_max_per_user',
                __('Saved searches per user'),
                __('A user with this many cannot save another until one is deleted. 0 means no limit.')
            )
                ->clampMin(0)
                ->default(\mindstellar\search\UserAlerts::DEFAULT_MAX_PER_USER)
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
                __('Block sign-ins after too many failures')
            )
                ->rowLabel(__('Sign-in limits'))
                ->default(true)
            ->number(
                'login_throttle_window',
                __('Count failures from the last'),
                __('A block ends once the failures are older than this.')
            )
                ->clampMin(1)
                ->suffix(__('minutes'))
                ->default(15)
            ->number(
                'login_throttle_max_ip',
                __('Failures per IP address'),
                __('Keep this high: many people can share one address at an office or on mobile.')
            )
                ->clampMin(1)
                ->default(20)
            ->number(
                'login_throttle_max_account',
                __('Failures per account'),
                __('From any address. This catches guessing spread over many addresses.')
            )
                ->clampMin(1)
                ->default(10)
            ->number(
                'login_attempt_retention_days',
                __('Keep records for'),
                __('The daily task deletes older records. 0 keeps them forever.')
            )
                ->clampMin(0)
                ->suffix(__('days'))
                ->default(7)
            ->register();

        return self::PAGE_LOGIN_THROTTLE;
    }

    /**
     * The contact and share forms: how many links a message may carry, and the
     * "Report the sender" link added to mail a member receives.
     *
     * @return string the page id
     */
    public static function registerMessages(): string
    {
        if (osc_settings_page(self::PAGE_MESSAGES) !== null) {
            return self::PAGE_MESSAGES;
        }

        CoreSettings::page(self::PAGE_MESSAGES, __('Messages'))
            ->number(
                'message_max_links',
                __('Links allowed in a message'),
                __('Applies to the contact form, contact the seller, contact a user and share a listing. 0 allows none.')
            )
                ->clampMin(0)
                ->default(1)
            ->number(
                'message_max_length',
                __('Longest message'),
                __('Longer messages are refused. 0 allows any length.')
            )
                ->clampMin(0)
                ->suffix(__('characters'))
                ->default(5000)
            ->checkbox(
                'message_report_link',
                __('Add a "Report the sender" link to messages members receive')
            )
                ->rowLabel(__('Report link'))
                ->default(true)
            ->number(
                'message_report_days',
                __('A report blocks the sender for'),
                __('The sender cannot send messages for this long. Sign-in and posting still work. You can lift it under Users → Ban rules.')
            )
                ->clampMin(1)
                ->suffix(__('days'))
                ->default(30)
            ->register();

        return self::PAGE_MESSAGES;
    }

    /**
     * Hourly limits per visitor address for the public forms. The defaults come from
     * ActionThrottle, so the numbers live in one place.
     *
     * @return string the page id
     */
    public static function registerLimits(): string
    {
        if (osc_settings_page(self::PAGE_LIMITS) !== null) {
            return self::PAGE_LIMITS;
        }

        // In the same order as ActionThrottle::DEFAULT_LIMITS.
        $labels = array_combine(array_keys(ActionThrottle::DEFAULT_LIMITS), array(
            __('Comments'),
            __('Photo uploads from guests'),
            __('Form submissions'),
            __('Contact the site'),
            __('Contact a seller'),
            __('Contact a user'),
            __('Send to a friend'),
            __('Search alert sign-ups'),
        ));

        $page = CoreSettings::page(self::PAGE_LIMITS, __('Limits'));
        foreach ($labels as $context => $label) {
            $help = $context === \mindstellar\comment\CommentPolicy::LIMIT_CONTEXT
                ? __('Per signed-in user, or per address for guests, per hour. 0 means no limit.')
                : __('Per visitor address, per hour. 0 means no limit.');
            $page->number('throttle_' . $context, $label, $help)
                ->clampMin(0)
                ->default(ActionThrottle::DEFAULT_LIMITS[$context]);
        }
        $page->register();

        return self::PAGE_LIMITS;
    }

    /**
     * What the view needs to draw all six forms, keyed by the div each one sits in.
     *
     * @param int|null                 $akismetStatus what Akismet said about the stored key:
     *                                                1 valid, 2 invalid, 3 no key at all
     * @param string                   $rejected      the page id a refused save belongs to,
     *                                                if any
     * @param array<string,mixed>|null $values        that page's submitted values
     *
     * @return array<string,array<string,mixed>> view variables per div: 'akismet', 'captcha',
     *         'alerts', 'login_throttle', 'messages', 'limits'
     */
    public static function formVars($akismetStatus = null, string $rejected = '', ?array $values = null): array
    {
        $forms = array(
            'akismet'         => array(self::registerAkismet($akismetStatus), 'akismet_post', 'submit_akismet'),
            'captcha'         => array(self::registerCaptcha(), 'recaptcha_post', 'submit_recaptcha'),
            'alerts'          => array(self::registerAlerts(), 'alerts_post', 'submit_alerts'),
            'login_throttle'  => array(self::registerLoginThrottle(), 'login_throttle_post', 'submit_login_throttle'),
            'messages'        => array(self::registerMessages(), 'messages_post', 'submit_messages'),
            'limits'          => array(self::registerLimits(), 'limits_post', 'submit_limits'),
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
