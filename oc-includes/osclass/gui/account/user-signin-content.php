<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Sign-in details: the email address, the username and the password, on one
 * page. Three single-field settings answering one question -- how do I sign in --
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

        <section id="email" class="oe-panel">
            <h2><?php echo osc_esc_html(_m('Email address')); ?></h2>
            <p class="oe-muted"><?php printf(
                osc_esc_html(_m('You currently sign in as %s.')),
                osc_esc_html(osc_logged_user_email())
            ); ?></p>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="change_email_post" />
                <div class="oe-field">
                    <label class="oe-label" for="oe-new-email"><?php
                        echo osc_esc_html(_m('New email address')); ?></label>
                    <input class="oe-input" id="oe-new-email" type="email" name="new_email"
                           autocomplete="email" required aria-describedby="oe-new-email-hint"<?php echo $signinFocus('email'); ?> />
                    <span class="oe-hint" id="oe-new-email-hint"><?php echo osc_esc_html(
                        _m('We send a link to the new address. The change takes effect when you follow it.')
                    ); ?></span>
                </div>
                <div class="oe-actions">
                    <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save')); ?></button>
                </div>
            </form>
        </section>

        <section id="username" class="oe-panel">
            <h2><?php echo osc_esc_html(_m('Username')); ?></h2>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="change_username_post" />
                <div class="oe-field">
                    <label class="oe-label" for="oe-username"><?php
                        echo osc_esc_html(_m('Username')); ?></label>
                    <input class="oe-input" id="oe-username" type="text" name="s_username"
                           autocomplete="username" required aria-describedby="oe-username-hint"<?php echo $signinFocus('username'); ?> />
                    <span class="oe-hint" id="oe-username-hint"><?php echo osc_esc_html(
                        _m('It appears on your public profile and in the address of your listings.')
                    ); ?></span>
                </div>
                <div class="oe-actions">
                    <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save')); ?></button>
                </div>
            </form>
        </section>

        <section id="password" class="oe-panel">
            <h2><?php echo osc_esc_html(_m('Password')); ?></h2>

            <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="change_password_post" />
                <div class="oe-field">
                    <label class="oe-label" for="oe-password"><?php
                        echo osc_esc_html(_m('Current password')); ?></label>
                    <input class="oe-input" id="oe-password" type="password" name="password"
                           autocomplete="current-password" required<?php echo $signinFocus('password'); ?> />
                </div>
                <div class="oe-field">
                    <label class="oe-label" for="oe-new-password"><?php
                        echo osc_esc_html(_m('New password')); ?></label>
                    <input class="oe-input" id="oe-new-password" type="password" name="new_password"
                           autocomplete="new-password" required minlength="6"
                           aria-describedby="oe-new-password-hint" />
                    <span class="oe-hint" id="oe-new-password-hint"><?php echo osc_esc_html(
                        _m('At least six characters.')
                    ); ?></span>
                </div>
                <div class="oe-field">
                    <label class="oe-label" for="oe-new-password2"><?php
                        echo osc_esc_html(_m('Repeat the new password')); ?></label>
                    <input class="oe-input" id="oe-new-password2" type="password" name="new_password2"
                           autocomplete="new-password" required minlength="6" />
                </div>
                <div class="oe-actions">
                    <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Save')); ?></button>
                </div>
            </form>
        </section>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
