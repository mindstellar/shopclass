<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Sign-in details: the email address, the username and the password, on one
 * page, and signing out of all devices. Three single-field settings answering one question -- how do I sign in --
 * and one destination to find rather than three near-identical ones.
 *
 * Each section keeps its own form and posts to its own existing action, so the
 * controllers, their CSRF checks and their flash messages are untouched.
 *
 * All three original routes still resolve here, and the one that was asked for
 * gets autofocus on its first field: the browser scrolls it into view and puts
 * the caret in it, so an old bookmark lands where it used to. A fragment cannot
 * do that -- nothing server-side can add one to the current URL -- and this
 * needs no script.
 *
 * Deleting the account is deliberately NOT here: it is destructive and
 * irreversible, and nothing dangerous should sit a misclick away from changing
 * an email address.
 */

// Which of the three the visitor actually asked for.
$folioSignin = 'email';
if (osc_is_change_password_page()) {
    $folioSignin = 'password';
} elseif (osc_is_change_username_page()) {
    $folioSignin = 'username';
}
$signinFocus = static function (string $section) use ($folioSignin): string {
    return $section === $folioSignin ? ' autofocus' : '';
};
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-signin'); ?>

        <?php osc_gui_panel_open(_m('Email address'), '', 'email'); ?>
            <p class="oe-muted"><?php printf(
                osc_esc_html(_m('You currently sign in as %s.')),
                osc_esc_html(osc_logged_user_email())
            ); ?></p>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="change_email_post" />
                <?php osc_gui_field('oe-new-email', _m('New email address'), static function () use ($signinFocus) { ?>
                    <input class="oe-input" id="oe-new-email" type="email" name="new_email"
                           autocomplete="email" required aria-describedby="oe-new-email-hint"<?php echo $signinFocus('email'); ?> />
                <?php }, _m('We send a link to the new address. The change takes effect when you follow it.')); ?>
                <?php osc_gui_actions(array(array('label' => _m('Save')))); ?>
            </form>
        <?php osc_gui_panel_close(); ?>

        <?php osc_gui_panel_open(_m('Username'), '', 'username'); ?>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="change_username_post" />
                <?php osc_gui_field('oe-username', _m('Username'), static function () use ($signinFocus) { ?>
                    <input class="oe-input" id="oe-username" type="text" name="s_username"
                           autocomplete="username" required aria-describedby="oe-username-hint oe-username-status"
                           data-username-check="<?php echo osc_esc_html(osc_base_url(true) . '?page=ajax&action=check_username_availability'); ?>"<?php echo $signinFocus('username'); ?> />
                    <span class="oe-hint" id="oe-username-hint"><?php echo osc_esc_html(
                        _m('It appears on your public profile and in the address of your listings.')
                    ); ?></span>
                    <span class="oe-hint" id="oe-username-status" role="status" aria-live="polite"></span>
                <?php }); ?>
                <?php osc_gui_actions(array(array('label' => _m('Save')))); ?>
            </form>
        <?php osc_gui_panel_close(); ?>

        <?php osc_gui_panel_open(_m('Password'), '', 'password'); ?>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="change_password_post" />
                <?php osc_gui_field('oe-password', _m('Current password'), static function () use ($signinFocus) { ?>
                    <input class="oe-input" id="oe-password" type="password" name="password"
                           autocomplete="current-password" required<?php echo $signinFocus('password'); ?> />
                <?php }); ?>
                <?php osc_gui_field('oe-new-password', _m('New password'), static function () { ?>
                    <input class="oe-input" id="oe-new-password" type="password" name="new_password"
                           autocomplete="new-password" required minlength="<?php echo \mindstellar\user\AccountService::MIN_PASSWORD_LENGTH; ?>"
                           aria-describedby="oe-new-password-hint" />
                <?php }, sprintf(_m('At least %d characters.'), \mindstellar\user\AccountService::MIN_PASSWORD_LENGTH)); ?>
                <?php osc_gui_field('oe-new-password2', _m('Repeat the new password'), static function () { ?>
                    <input class="oe-input" id="oe-new-password2" type="password" name="new_password2"
                           autocomplete="new-password" required minlength="<?php echo \mindstellar\user\AccountService::MIN_PASSWORD_LENGTH; ?>" data-match="new_password"
                           data-match-message="<?php echo osc_esc_html(_m('The two passwords do not match.')); ?>" />
                <?php }); ?>
                <?php osc_gui_actions(array(array('label' => _m('Save')))); ?>
            </form>
        <?php osc_gui_panel_close(); ?>

        <?php osc_gui_panel_open(_m('Sign out of all devices'), '', 'sign-out-all'); ?>
            <p class="oe-muted"><?php echo osc_esc_html(
                _m('Ends every sign-in to this account: other browsers and phones, apps and personal API keys, and this browser too.')
            ); ?></p>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="sign_out_all_post" />
                <?php osc_gui_field('oe-sign-out-password', _m('Current password'), '<input class="oe-input" id="oe-sign-out-password" type="password" name="password"'
                    . ' autocomplete="current-password" required />'); ?>
                <?php osc_gui_actions(array(array('label' => _m('Sign out everywhere')))); ?>
            </form>
        <?php osc_gui_panel_close(); ?>

        <?php osc_run_hook('account_page_after', 'user-signin'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
