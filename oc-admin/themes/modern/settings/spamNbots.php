<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The chrome around the four declared spam-and-bots forms. Each form -- its route, its
 * fields, their values and the submit row -- is core's, drawn from the declaration the
 * controller saves through. The button that clears recorded sign-in attempts stores
 * nothing and is still its own little form here.
 */

$forms = __get('spam_forms');

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Spam and bots'),
    'help'    => __('Keep spammers from publishing on your site by configuring reCAPTCHA and Akismet. '
                    . 'Be careful: in order to use these services, you must register on their sites first and follow their instructions.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="spam-setting">
    <?php osc_admin_page_head(__('Spam and bots')); ?>
    <div id="akismet-settings">
        <?php osc_admin_form_section(__('Akismet')); ?>
        <p><?php _e("Akismet is a hosted web service that saves you time by automatically detecting comment and trackback spam. "
                    . "It's hosted on our servers, but we give you access to it through plugins and our API."); ?></p>
        <?php osc_admin_settings_form($forms['akismet']['id'], $forms['akismet']); ?>
    </div>
    <div id="recaptcha-settings" class="separate-top">
        <?php osc_admin_form_section(__('Captcha')); ?>
        <p><?php printf(
            __('Protect your site from automated abuse with Google reCAPTCHA or Cloudflare Turnstile. '
                           . '<a href="%1$s" target="_blank">Get a reCAPTCHA key</a> or '
                           . '<a href="%2$s" target="_blank">get a Turnstile key</a>.'),
            'https://www.google.com/recaptcha/admin#whyrecaptcha',
            'https://dash.cloudflare.com/?to=/:account/turnstile'
        ); ?></p>
        <?php osc_admin_settings_form($forms['captcha']['id'], $forms['captcha']); ?>
    </div>
    <div id="alerts-settings" class="separate-top">
        <?php osc_admin_form_section(__('Search alerts')); ?>
        <p><?php _e('Search alerts email visitors when new listings match a saved search. Requiring login before '
                    . 'subscribing prevents anonymous email harvesting and confirmation-email abuse through the alert endpoint.'); ?></p>
        <?php osc_admin_settings_form($forms['alerts']['id'], $forms['alerts']); ?>
    </div>
    <div id="login-throttle-settings" class="separate-top">
        <?php osc_admin_form_section(__('Sign-in protection')); ?>
        <p><?php _e('Failed sign-ins and password-reset requests are counted per visitor address and per account '
                    . 'name. Passing a limit refuses further attempts until the older ones age out of the window, '
                    . 'which is what stops a stolen password list being tried one guess at a time.'); ?></p>
        <p><?php _e('The account limit is skipped while a captcha provider is configured above, because every '
                    . 'attempt already has to solve one. Without that, an attacker could hold someone else\'s '
                    . 'account shut simply by failing against it.'); ?></p>
        <?php osc_admin_settings_form($forms['login_throttle']['id'], $forms['login_throttle']); ?>
        <?php osc_admin_form_open(array(
            'name'   => 'settings_form',
            'page'   => 'settings',
            'action' => 'login_throttle_reset',
        )); ?>
                <?php osc_admin_field(array(
                    'type'   => 'custom',
                    'label'  => __('Clear recorded attempts'),
                    'help'   => __('Lets anyone currently refused try again straight away, including you.'),
                    'render' => static function () {
                        osc_admin_action_button(array(
                            'label' => __('Clear now'),
                            'type'  => 'submit',
                            'attrs' => array('id' => 'submit_login_throttle_reset'),
                        ));
                    },
                )); ?>
            <?php osc_admin_form_close(); ?>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
