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
        <?php osc_admin_form_section(__('Sign-in protection'), array(
            'intro' => __('After too many failed sign-ins, Shopclass blocks that address or account for a '
                          . 'while. This stops password guessing on the site and the admin panel.'),
        )); ?>
        <?php if (osc_captcha_enabled()) { ?>
            <p class="text-muted"><?php _e('A captcha is set up above, so the per-account limit is off. '
                                           . 'Nobody can lock another person out of their account.'); ?></p>
        <?php } ?>
        <?php osc_admin_settings_form($forms['login_throttle']['id'], $forms['login_throttle']); ?>

        <?php
        $throttle = __get('login_throttle_activity') ?: array('aRows' => array());
        $contexts = array('admin' => __('Admin panel'), 'web' => __('Website'));
        $rows     = $throttle['aRows'];

        osc_admin_form_section(__('Failed sign-ins right now'), array(
            'spaced' => true,
            'intro'  => sprintf(
                __('Addresses and accounts with failures in the last %d minutes. Unblock one to let it try again at once.'),
                osc_login_throttle_window()
            ),
        ));
        if ($rows === array()) { ?>
            <p class="text-muted"><?php _e('No failed sign-ins. Nobody is blocked.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table" style="min-width:40rem">
                    <thead>
                    <tr>
                        <th class="col-status"><?php _e('State'); ?></th>
                        <th><?php _e('IP address or account'); ?></th>
                        <th class="text-end"><?php _e('Failures'); ?></th>
                        <th><?php _e('Block ends'); ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        $isIp   = $row['kind'] === 'ip';
                        $fields = $isIp
                            ? array('ip' => $row['ip'])
                            : array('context' => $row['context'], 'account' => $row['account']); ?>
                        <tr>
                            <td class="col-status"><?php $row['blocked']
                                ? osc_admin_status('failed', __('Blocked'))
                                : osc_admin_status('pending', __('Counting')); ?></td>
                            <td>
                                <?php echo osc_esc_html($isIp ? $row['ip'] : $row['account']); ?>
                                <div class="text-muted small"><?php echo osc_esc_html($isIp
                                    ? __('IP address')
                                    : sprintf(__('Account, %s'), $contexts[$row['context']] ?? $row['context'])); ?></div>
                            </td>
                            <td class="text-end"><?php echo (int) $row['failures']; ?></td>
                            <td><?php echo $row['blocked'] ? osc_admin_when($row['until']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                            <td class="text-end">
                                <?php osc_admin_form_open(array(
                                    'page'       => 'settings',
                                    'action'     => 'login_throttle_unblock',
                                    'horizontal' => false,
                                    'class'      => 'd-inline',
                                    'fields'     => $fields,
                                )); ?>
                                    <button type="submit" class="btn btn-sm btn-secondary"><?php
                                        echo $row['blocked'] ? __('Unblock') : __('Reset'); ?></button>
                                <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php osc_admin_pagination($throttle);
        }

        osc_admin_form_open(array(
            'name'   => 'settings_form',
            'page'   => 'settings',
            'action' => 'login_throttle_reset',
        )); ?>
                <?php osc_admin_field(array(
                    'type'   => 'custom',
                    'label'  => __('Unblock everyone'),
                    'help'   => __('Deletes every recorded failure, yours too.'),
                    'render' => static function () {
                        osc_admin_action_button(array(
                            'label' => __('Unblock everyone'),
                            'type'  => 'submit',
                            'attrs' => array('id' => 'submit_login_throttle_reset'),
                        ));
                    },
                )); ?>
            <?php osc_admin_form_close(); ?>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
