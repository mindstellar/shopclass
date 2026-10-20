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

// One form per tab, so the screen has one Save button at a time. The controller checks the tab.
$tabs = array(
    'akismet'  => array(__('Akismet'), 'akismet', __('Akismet checks new comments and listings for spam. It needs a key from akismet.com.')),
    'captcha'  => array(__('Captcha'), 'captcha', ''),
    'alerts'   => array(__('Search alerts'), 'alerts', __('Search alerts e-mail visitors about new listings that match a saved search. Requiring sign-in stops strangers subscribing other people.')),
    'messages' => array(__('Messages'), 'messages', __('Limits for the forms that send e-mail: contact, contact the seller, contact a user and share a listing.')),
    'limits'   => array(__('Limits'), 'limits', __('How many times one visitor address may use each public form or upload in an hour.')),
    'signin'   => array(__('Sign-in protection'), 'login_throttle', __('After too many failed sign-ins on the site or the admin panel, the address or account is blocked for a while.')),
);
$tab = (string) __get('spam_tab');
[, $form, $help] = $tabs[$tab];

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="spam-setting">
    <?php osc_admin_page_head(__('Spam and bots'));
    osc_admin_tab_nav(array_map(static fn ($key, $spec) => array(
        'label'  => $spec[0],
        'url'    => osc_admin_base_url(true) . '?page=settings&action=spamNbots&tab=' . $key,
        'active' => $key === $tab,
    ), array_keys($tabs), $tabs));

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
    <?php } ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
