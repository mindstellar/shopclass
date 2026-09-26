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
$codeBox = static function (string $label, string $help = '') {
    echo '<div class="two-factor-field"><label for="two-factor-code">' . osc_esc_html($label) . '</label>'
         . '<input type="text" id="two-factor-code" name="code" class="input-text two-factor-code" inputmode="numeric"'
         . ' autocomplete="one-time-code" maxlength="10" required>'
         . ($help !== '' ? '<div class="help-box">' . osc_esc_html($help) . '</div>' : '') . '</div>';
};
?>
<div class="two-factor" id="two-factor">
    <?php osc_admin_panel_open(__('Two-step sign-in')); ?>
    <p class="two-factor-status">
        <?php osc_admin_status($enabled ? 'active' : 'inactive', $enabled ? __('On') : __('Off')); ?>
        <span><?php $enabled
            ? ($own ? _e('You sign in with your password and a code from your authenticator app.')
                : _e('This admin signs in with a code from an authenticator app.'))
            : _e('Add a second step to sign-in: a 6-digit code from an authenticator app on your phone.'); ?></span>
    </p>

    <?php if (is_array($codes) && $codes !== array()) { ?>
        <div class="two-factor-backup">
            <div class="callout-warning callout-block"><?php _e('Save these backup codes now. Each one signs you in once if you lose your phone. They are not shown again.'); ?></div>
            <ol class="two-factor-codes" id="two-factor-codes">
                <?php foreach ($codes as $backup) { ?>
                    <li><code class="osc-mono"><?php echo osc_esc_html($backup); ?></code></li>
                <?php } ?>
            </ol>
            <div class="two-factor-actions">
                <button type="button" class="btn btn-dim" data-two-factor="copy"><?php _e('Copy'); ?></button>
                <button type="button" class="btn btn-dim" data-two-factor="download"><?php _e('Download'); ?></button>
            </div>
        </div>
    <?php } ?>

    <?php if (!$own) { ?>
        <p class="help-box"><?php _e('Turn it off if they have lost their phone and their backup codes.'); ?></p>
        <form method="post" action="<?php echo $action; ?>" class="two-factor-actions">
            <input type="hidden" name="page" value="admins">
            <input type="hidden" name="action" value="2fa_off">
            <input type="hidden" name="id" value="<?php echo (int)$admin['pk_i_id']; ?>">
            <button type="submit" class="btn btn-red"><?php _e('Turn off'); ?></button>
        </form>
    <?php } elseif ($enabled) { ?>
        <form method="post" action="<?php echo $action; ?>" class="two-factor-form">
            <input type="hidden" name="page" value="admins">
            <?php $codeBox(__('Current code'), __('Type a code from your app, or a backup code, to use either button. Lost both? Another admin can turn it off for you.')); ?>
            <div class="two-factor-actions">
                <button type="submit" name="action" value="2fa_codes" class="btn btn-dim"><?php _e('New backup codes'); ?></button>
                <button type="submit" name="action" value="2fa_off" class="btn btn-red"><?php _e('Turn off'); ?></button>
            </div>
        </form>
    <?php } elseif ($setup !== '') { ?>
        <?php $uri = Totp::uri($setup, (string)$admin['s_username'], osc_page_title()); ?>
        <div class="two-factor-setup">
            <div class="two-factor-qr" id="two-factor-qr" data-uri="<?php echo osc_esc_html($uri); ?>" role="img" aria-label="<?php echo osc_esc_html(__('QR code for your authenticator app')); ?>"></div>
            <ol class="two-factor-steps">
                <li><?php _e('Open your authenticator app and scan the QR code.'); ?></li>
                <li>
                    <?php _e("Can't scan it? Type this key into the app instead:"); ?>
                    <span class="two-factor-key">
                        <code class="osc-mono" id="two-factor-key"><?php echo osc_esc_html(trim(chunk_split($setup, 4, ' '))); ?></code>
                        <button type="button" class="btn btn-dim btn-mini" data-two-factor="copy-key"><?php _e('Copy'); ?></button>
                    </span>
                </li>
                <li>
                    <form method="post" action="<?php echo $action; ?>" class="two-factor-form">
                        <input type="hidden" name="page" value="admins">
                        <input type="hidden" name="action" value="2fa_enable">
                        <?php $codeBox(__('Type the 6-digit code the app shows')); ?>
                        <div class="two-factor-actions">
                            <button type="submit" class="btn btn-submit"><?php _e('Turn on'); ?></button>
                        </div>
                    </form>
                </li>
            </ol>
        </div>
        <?php osc_enqueue_script('qrcode-generator'); ?>
    <?php } else { ?>
        <form method="post" action="<?php echo $action; ?>" class="two-factor-actions">
            <input type="hidden" name="page" value="admins">
            <input type="hidden" name="action" value="2fa_setup">
            <button type="submit" class="btn btn-submit"><?php _e('Set up'); ?></button>
        </form>
    <?php } ?>
    <?php osc_admin_panel_close(); ?>
</div>
<?php osc_add_hook('admin_footer', static function () { ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var box = document.getElementById('two-factor-qr');
            if (box && typeof qrcode === 'function') {
                var qr = qrcode(0, 'M');
                qr.addData(box.getAttribute('data-uri'));
                qr.make();
                box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
            }
            var root = document.getElementById('two-factor');
            if (!root) { return; }
            var done = function (button) {
                var label = button.textContent;
                button.textContent = <?php echo json_encode(__('Copied')); ?>;
                setTimeout(function () { button.textContent = label; }, 1500);
            };
            root.addEventListener('click', function (event) {
                var button = event.target.closest('[data-two-factor]');
                if (!button) { return; }
                var what = button.getAttribute('data-two-factor');
                var text = what === 'copy-key'
                    ? document.getElementById('two-factor-key').textContent.replace(/\s+/g, '')
                    : Array.prototype.map.call(document.querySelectorAll('#two-factor-codes code'), function (c) { return c.textContent; }).join('\n');
                if (what === 'download') {
                    var link = document.createElement('a');
                    link.href = URL.createObjectURL(new Blob([text + '\n'], { type: 'text/plain' }));
                    link.download = 'backup-codes.txt';
                    link.click();
                    URL.revokeObjectURL(link.href);
                } else if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () { done(button); });
                }
            });
        });
    </script>
<?php }, 10); ?>
