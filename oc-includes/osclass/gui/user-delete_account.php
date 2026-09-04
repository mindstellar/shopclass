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
 * Core's fallback account-delete page -- rendered only when the active theme has
 * no user-delete_account.php of its own. Uses the theme chrome when header.php
 * and footer.php exist, otherwise the shared system page. See CWebUser::doView().
 *
 * Not osc_die(): it discards the output buffer the CSRF filter writes into, so
 * the form would ship without a token and every submission would be rejected.
 */

$heading = _m('Delete your account');
$intro   = _m('This page has not deleted the account yet. Enter your password and click Delete my account. Your listings and messages will be removed. This cannot be undone.');

$themePath = WebThemes::newInstance()->getCurrentThemePath();

if (file_exists($themePath . 'header.php') && file_exists($themePath . 'footer.php')) {
    osc_current_web_theme_path('header.php');
    ?>
    <section class="osc-user-delete-account-page">
        <h1><?php echo osc_esc_html($heading); ?></h1>
        <p><?php echo osc_esc_html($intro); ?></p>
        <?php require __DIR__ . '/user-delete_account-content.php'; ?>
    </section>
    <?php
    osc_current_web_theme_path('footer.php');

    return;
}

ob_start();
require __DIR__ . '/user-delete_account-content.php';
$form = ob_get_clean();

$oscSys = array(
    'title'     => $heading,
    'heading'   => $heading,
    'body'      => '<p>' . osc_esc_html($intro) . '</p>' . $form,
    'bodyHtml'  => true,
    'tone'      => 'danger',
    'role'      => 'main',
    'lang'      => str_replace('_', '-', osc_current_user_locale()),
    'brandName' => osc_page_title(),
);

require ABS_PATH . 'oc-includes/osclass/gui/system-page.php';
