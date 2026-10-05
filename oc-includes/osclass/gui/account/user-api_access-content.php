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
 * The apps signed in to the account through the API, and its personal keys -- markup only.
 * Reads the `api_sessions`, `api_user_keys`, `api_key_scopes` and `api_new_key` view
 * variables. Every form posts user api_access_post with the CSRF token.
 */

$apiSessions  = (array) __get('api_sessions');
$apiUserKeys  = (bool) __get('api_user_keys');
$apiKeyScopes = (array) __get('api_key_scopes');
$apiNewKey    = (string) __get('api_new_key');
$apiDate      = static function ($time): string {
    return $time === null ? '' : (string) osc_format_date(date('Y-m-d H:i:s', (int) strtotime((string) $time)));
};
?>
<div class="oe-account">
    <div class="oe-account-main">
        <?php osc_show_flash_message(); ?>
        <?php osc_run_hook('account_page_before', 'user-api_access'); ?>

        <?php if ($apiNewKey !== '') { ?>
            <section class="oe-panel">
                <div class="oe-field">
                    <label class="oe-label" for="osc-api-new-key"><?php echo osc_esc_html(_m('Your new key')); ?></label>
                    <input class="oe-input" id="osc-api-new-key" type="text" readonly value="<?php echo osc_esc_html($apiNewKey); ?>"
                           aria-describedby="osc-api-new-key-hint" />
                    <p class="oe-hint" id="osc-api-new-key-hint"><?php echo osc_esc_html(
                        _m('Copy it now and keep it secret. It is not shown again.')
                    ); ?></p>
                </div>
            </section>
        <?php } ?>

        <p class="oe-muted"><?php echo osc_esc_html(
            _m('Apps you signed in to with this account, and keys you made for your own scripts. End any you do not recognise.')
        ); ?></p>

        <?php if ($apiSessions === array()) { ?>
            <p class="oe-empty"><?php echo osc_esc_html(_m('No app or key has access.')); ?></p>
        <?php } else {
            foreach ($apiSessions as $apiSession) {
                $apiIsKey = $apiSession['type'] === 'key';
                ?>
                <section class="oe-panel">
                    <h2><?php echo osc_esc_html($apiSession['label'] !== '' ? $apiSession['label'] : ($apiIsKey ? _m('Key') : _m('Signed-in app'))); ?></h2>
                    <p class="oe-meta">
                        <span class="oe-badge paid"><?php echo osc_esc_html($apiIsKey ? _m('Key') : _m('Signed in')); ?></span>
                        <?php if ($apiIsKey) { ?>
                            <span><?php echo osc_esc_html($apiSession['prefix'] . '…'); ?></span>
                        <?php } ?>
                        <span><?php echo osc_esc_html(sprintf(_m('Last used: %s'), $apiDate($apiSession['last_used_at']))); ?></span>
                        <?php if ($apiSession['last_ip'] !== null) { ?>
                            <span><?php echo osc_esc_html(sprintf(_m('From: %s'), $apiSession['last_ip'])); ?></span>
                        <?php } ?>
                        <?php if ($apiSession['expires_at'] !== null) { ?>
                            <span><?php echo osc_esc_html(sprintf(_m('Ends: %s'), $apiDate($apiSession['expires_at']))); ?></span>
                        <?php } ?>
                        <span><?php echo osc_esc_html(implode(', ', $apiSession['scopes'])); ?></span>
                    </p>
                    <div class="oe-meta oe-row-actions">
                        <form class="oe-inline-form nocsrf" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                            <?php echo osc_csrf_token_form(); ?>
                            <input type="hidden" name="page" value="user" />
                            <input type="hidden" name="action" value="api_access_post" />
                            <input type="hidden" name="do" value="end" />
                            <input type="hidden" name="session" value="<?php echo osc_esc_html($apiSession['id']); ?>" />
                            <button class="oe-link-btn oe-danger-link" type="submit"
                                    data-osc-confirm="<?php echo osc_esc_html(_m('End this access? The app or script must sign in again.')); ?>"><?php
                                echo osc_esc_html($apiIsKey ? _m('Revoke key') : _m('Sign out')); ?></button>
                        </form>
                    </div>
                </section>
            <?php }
            if (function_exists('osc_gui_print_confirm_script')) {
                osc_gui_print_confirm_script();
            }
        } ?>

        <?php if ($apiUserKeys && $apiKeyScopes !== array()) { ?>
            <form class="nocsrf" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="page" value="user" />
                <input type="hidden" name="action" value="api_access_post" />
                <input type="hidden" name="do" value="create" />
                <fieldset class="oe-group">
                    <legend><?php echo osc_esc_html(_m('Make a key')); ?></legend>
                    <div class="oe-field">
                        <label class="oe-label" for="osc-api-key-name"><?php echo osc_esc_html(_m('Name')); ?></label>
                        <input class="oe-input" id="osc-api-key-name" type="text" name="name" maxlength="100" required
                               aria-describedby="osc-api-key-name-hint" />
                        <p class="oe-hint" id="osc-api-key-name-hint"><?php echo osc_esc_html(_m('So you know later what uses it.')); ?></p>
                    </div>
                    <div class="oe-field">
                        <label class="oe-label" for="osc-api-key-expires"><?php echo osc_esc_html(_m('Last day it works')); ?></label>
                        <input class="oe-input" id="osc-api-key-expires" type="date" name="expires" required
                               min="<?php echo osc_esc_html(date('Y-m-d', time() + 86400)); ?>"
                               max="<?php echo osc_esc_html(date('Y-m-d', time() + 365 * 86400)); ?>" />
                    </div>
                    <?php foreach ($apiKeyScopes as $apiScope => $apiScopeText) { ?>
                        <label class="oe-check">
                            <input type="checkbox" name="scopes[]" value="<?php echo osc_esc_html((string) $apiScope); ?>" />
                            <?php echo osc_esc_html($apiScopeText !== '' ? $apiScopeText : (string) $apiScope); ?>
                        </label>
                    <?php } ?>
                    <div class="oe-field">
                        <label class="oe-label" for="osc-api-key-password"><?php echo osc_esc_html(_m('Your password')); ?></label>
                        <input class="oe-input" id="osc-api-key-password" type="password" name="password" autocomplete="current-password" required />
                    </div>
                </fieldset>
                <div class="oe-actions">
                    <button class="oe-btn" type="submit"><?php echo osc_esc_html(_m('Make key')); ?></button>
                </div>
            </form>
        <?php } ?>

        <?php osc_run_hook('account_page_after', 'user-api_access'); ?>
    </div>

    <?php require __DIR__ . '/nav.php'; ?>
</div>
