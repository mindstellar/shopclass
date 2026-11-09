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
 * The Sign-in, Captcha and Limits tabs of Settings › Security. The tab row is the Security
 * group's; each form -- its route, its fields, their values and the submit row -- is core's,
 * drawn from the declaration the controller saves through.
 */

$forms = __get('spam_forms');

// Tab => heading, form, help.
$tabs = array(
    'signin'  => array(__('Sign-in protection'), 'login_throttle', __('After too many failed sign-ins on the site or the admin panel, the address or account is blocked for a while.')),
    'captcha' => array(__('Captcha'), 'captcha', ''),
    'limits'  => array(__('Limits'), 'limits', __('How many times one visitor address may use each public form or upload in an hour.')),
);
$tab = (string) __get('spam_tab');
[$heading, $form, $help] = $tabs[$tab];

osc_admin_page(array(
    'section' => __('Settings'),
    'heading' => $heading,
    'title'   => $heading,
));

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(array('id' => 'spam-setting')); ?>
    <?php
    if ($tab === 'captcha') { ?>
        <p><?php printf(
            __('Stops bots on the public forms. <a href="%1$s" target="_blank">Get a reCAPTCHA key</a> or '
               . '<a href="%2$s" target="_blank">get a Turnstile key</a>.'),
            'https://www.google.com/recaptcha/admin#whyrecaptcha',
            'https://dash.cloudflare.com/?to=/:account/turnstile'
        ); ?></p>
    <?php } else { ?>
        <p><?php echo osc_esc_html($help); ?></p>
    <?php }
    if ($tab === 'signin' && osc_captcha_enabled()) { ?>
        <p class="text-muted"><?php _e('A captcha is set up, so the per-account limit is off. '
                                       . 'Nobody can lock another person out of their account.'); ?></p>
    <?php }

    osc_admin_settings_form($forms[$form]['id'], array('titles' => false) + $forms[$form]);

    if ($tab === 'signin') { ?>
        <p class="text-muted"><?php printf(
            __('See who is blocked right now, and unblock them, under <a href="%s">Tools > System info > Security</a>.'),
            osc_esc_html(osc_admin_base_url(true) . '?page=tools&action=system-info&tab=security#signin-activity')
        ); ?></p>
        <p class="text-muted"><?php printf(
            __('Two-step sign-in for admins is turned on per account, on <a href="%s">your profile</a>.'),
            osc_esc_html(osc_admin_base_url(true) . '?page=admins&action=edit#two-factor')
        ); ?></p>
    <?php }

    if ($tab === 'limits') {
        osc_admin_section(__('Search alerts'), array(
            'intro' => __('Search alerts e-mail visitors about new listings that match a saved search. Requiring sign-in stops strangers subscribing other people.'),
        ));
        osc_admin_settings_form($forms['alerts']['id'], array('titles' => false) + $forms['alerts']);
        osc_admin_section_close();
    } ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
