<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
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

/**
 * The chrome around the six declared spam-and-bots forms, one per tab. Each form -- its route, its
 * fields, their values and the submit row -- is core's, drawn from the declaration the
 * controller saves through.
 */

$forms = __get('spam_forms');

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Spam and bots'),
    'help'    => __('Keep spammers from publishing on your site by configuring reCAPTCHA and Akismet. '
                    . 'Be careful: in order to use these services, you must register on their sites first and follow their instructions.'),
));

// One form per tab, so the screen has one Save button at a time.
$tabs = array(
    'akismet'  => __('Akismet'),
    'captcha'  => __('Captcha'),
    'alerts'   => __('Search alerts'),
    'messages' => __('Messages'),
    'limits'   => __('Limits'),
    'signin'   => __('Sign-in protection'),
);
$tab = (string) __get('spam_tab');
$tab = isset($tabs[$tab]) ? $tab : 'akismet';

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="spam-setting">
    <?php osc_admin_page_head(__('Spam and bots')); ?>
    <ul class="osc-tabnav settings-tabs">
        <?php foreach ($tabs as $key => $label) { ?>
            <li>
                <a<?php echo $key === $tab ? ' class="is-active" aria-current="page"' : ''; ?>
                    href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=settings&action=spamNbots&tab=' . $key); ?>"><?php echo osc_esc_html($label); ?></a>
            </li>
        <?php } ?>
    </ul>
    <?php if ($tab === 'akismet') { ?>
    <div id="akismet-settings">
        <p><?php _e('Akismet checks new comments for spam. It needs a key from akismet.com.'); ?></p>
        <?php osc_admin_settings_form($forms['akismet']['id'], $forms['akismet']); ?>
    </div>
    <?php } elseif ($tab === 'captcha') { ?>
    <div id="recaptcha-settings">
        <p><?php printf(
            __('Stops bots on the public forms. <a href="%1$s" target="_blank">Get a reCAPTCHA key</a> or '
                           . '<a href="%2$s" target="_blank">get a Turnstile key</a>.'),
            'https://www.google.com/recaptcha/admin#whyrecaptcha',
            'https://dash.cloudflare.com/?to=/:account/turnstile'
        ); ?></p>
        <?php osc_admin_settings_form($forms['captcha']['id'], $forms['captcha']); ?>
    </div>
    <?php } elseif ($tab === 'alerts') { ?>
    <div id="alerts-settings">
        <p><?php _e('Search alerts e-mail visitors about new listings that match a saved search. Requiring sign-in stops strangers subscribing other people.'); ?></p>
        <?php osc_admin_settings_form($forms['alerts']['id'], $forms['alerts']); ?>
    </div>
    <?php } elseif ($tab === 'messages') { ?>
    <div id="messages-settings">
        <p><?php _e('Limits for the forms that send e-mail: contact, contact the seller, contact a user and share a listing.'); ?></p>
        <?php osc_admin_settings_form($forms['messages']['id'], $forms['messages']); ?>
    </div>
    <?php } elseif ($tab === 'limits') { ?>
    <div id="limits-settings">
        <p><?php _e('How many times one visitor address may use each public form or upload in an hour.'); ?></p>
        <?php osc_admin_settings_form($forms['limits']['id'], $forms['limits']); ?>
    </div>
    <?php } else { ?>
    <div id="login-throttle-settings">
        <p><?php _e('After too many failed sign-ins, the address or account is blocked for a while.'); ?></p>
        <?php if (osc_captcha_enabled()) { ?>
            <p class="text-muted"><?php _e('A captcha is set up, so the per-account limit is off. '
                                           . 'Nobody can lock another person out of their account.'); ?></p>
        <?php } ?>
        <?php osc_admin_settings_form($forms['login_throttle']['id'], $forms['login_throttle']); ?>

        <p class="text-muted"><?php printf(
            __('See who is blocked right now, and unblock them, under <a href="%s">Tools > System info > Security</a>.'),
            osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=system-info&tab=security#signin-activity')
        ); ?></p>
    </div>
    <?php } ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
