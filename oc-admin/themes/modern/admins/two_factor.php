<?php if (!defined('OC_ADMIN')) {
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

use mindstellar\security\AdminTwoFactor;
use mindstellar\security\Totp;

$admin = __get('admin');
if (!is_array($admin) || !isset($admin['pk_i_id'])) {
    return;
}
$own     = (int)$admin['pk_i_id'] === osc_logged_admin_id();
$enabled = AdminTwoFactor::enabled($admin);
if (!$own && (!$enabled || osc_is_moderator())) {
    return;
}
$session = Session::newInstance();
$setup   = $own && !$enabled ? (string)$session->_get('admin2faSetup') : '';
$codes   = $own ? $session->_get('admin2faCodes') : null;
$session->_drop('admin2faCodes');
$action  = osc_admin_base_url(true);
$codeBox = static function () {
    echo '<input type="text" name="code" class="input-medium" inputmode="numeric" autocomplete="one-time-code" required'
         . ' placeholder="' . osc_esc_html(__('Code')) . '"> ';
};
?>
<div class="settings-user two-factor" id="two-factor">
    <?php osc_admin_page_head(__('Two-step sign-in')); ?>
    <?php if (is_array($codes) && $codes !== array()) { ?>
        <p><strong><?php _e('Your backup codes. Keep them somewhere safe: each works once, and they are shown only now.'); ?></strong></p>
        <pre><?php echo osc_esc_html(implode("\n", $codes)); ?></pre>
    <?php } ?>

    <?php if (!$own) { ?>
        <p><?php _e('This admin signs in with a code from an app. Turn it off if they have lost their phone and their backup codes.'); ?></p>
        <form method="post" action="<?php echo $action; ?>">
            <input type="hidden" name="page" value="admins">
            <input type="hidden" name="action" value="2fa_off">
            <input type="hidden" name="id" value="<?php echo (int)$admin['pk_i_id']; ?>">
            <button type="submit" class="btn btn-dim"><?php _e('Turn off'); ?></button>
        </form>
    <?php } elseif ($enabled) { ?>
        <p><?php _e('On. You sign in with your password and a code from your authenticator app.'); ?></p>
        <form method="post" action="<?php echo $action; ?>">
            <input type="hidden" name="page" value="admins">
            <?php $codeBox(); ?>
            <button type="submit" name="action" value="2fa_codes" class="btn btn-dim"><?php _e('New backup codes'); ?></button>
            <button type="submit" name="action" value="2fa_off" class="btn btn-dim"><?php _e('Turn off'); ?></button>
        </form>
    <?php } elseif ($setup !== '') { ?>
        <?php $uri = Totp::uri($setup, (string)$admin['s_username'], osc_page_title()); ?>
        <p><?php _e('Scan this code with an authenticator app, then type the 6-digit code it shows.'); ?></p>
        <div id="two-factor-qr" data-uri="<?php echo osc_esc_html($uri); ?>"></div>
        <p><?php _e('Or type this key into the app:'); ?> <code><?php echo osc_esc_html(trim(chunk_split($setup, 4, ' '))); ?></code></p>
        <form method="post" action="<?php echo $action; ?>">
            <input type="hidden" name="page" value="admins">
            <input type="hidden" name="action" value="2fa_enable">
            <?php $codeBox(); ?>
            <button type="submit" class="btn btn-submit"><?php _e('Turn on'); ?></button>
        </form>
        <?php osc_enqueue_script('qrcode-generator'); ?>
        <?php osc_add_hook('admin_footer', static function () { ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var box = document.getElementById('two-factor-qr');
                    if (!box || typeof qrcode !== 'function') { return; }
                    var qr = qrcode(0, 'M');
                    qr.addData(box.getAttribute('data-uri'));
                    qr.make();
                    box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 4, scalable: false });
                });
            </script>
        <?php }, 10); ?>
    <?php } else { ?>
        <p><?php _e('Off. Turn it on to sign in with your password and a code from an authenticator app.'); ?></p>
        <form method="post" action="<?php echo $action; ?>">
            <input type="hidden" name="page" value="admins">
            <input type="hidden" name="action" value="2fa_setup">
            <button type="submit" class="btn btn-submit"><?php _e('Set up'); ?></button>
        </form>
    <?php } ?>
</div>
