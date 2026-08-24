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

$maintenance = file_exists(osc_base_path() . '.maintenance');
$lockout     = osc_maintenance_lockout_enabled();
$message     = osc_sanitize_maintenance_message(
    (string)osc_get_preference(OSC_MAINTENANCE_PREF_MESSAGE, OSC_MAINTENANCE_PREF_SECTION)
);

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Maintenance'),
    'help'    => __('Put a banner on the site while you work, or take the public site down with HTTP 503. Signed-in admins always stay in.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="backup-setting">
    <div id="backup-settings">
        <?php osc_admin_page_head(__('Maintenance')); ?>
        <form>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <?php _e('Maintenance mode is switched by a <code>.maintenance</code> file in the install root. While it is on, signed-in admins can still use the site. Everyone else either sees the banner below, or (if lockout is on) an HTTP 503 page.'); ?>
                        <div class="<?php echo $maintenance ? 'callout-danger' : 'callout-success'; ?>">
                            <?php printf(__('Maintenance mode is: <strong>%s</strong>'),
                                ($maintenance ? __('ON') : __('OFF'))); ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <input type="button"
                               value="<?php echo($maintenance ? osc_esc_html(__('Disable maintenance mode'))
                                   : osc_esc_html(__('Enable maintenance mode'))); ?>"
                               onclick="window.location.href='<?php echo osc_admin_base_url(true);
                                ?>?page=tools&amp;action=maintenance&amp;mode=<?php
                               echo ($maintenance ? 'off' : 'on') . '&amp;' . osc_csrf_token_url();
?>';" class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>

        <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="maintenance"/>
            <input type="hidden" name="mode" value="save"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label-checkbox">
                            <input type="checkbox" id="maintenance_lockout" name="maintenance_lockout" value="1"
                                <?php echo $lockout ? 'checked="checked"' : ''; ?> />
                            <label for="maintenance_lockout"><?php _e('Block the public site (HTTP 503)'); ?></label>
                        </div>
                        <div class="help-box">
                            <?php _e('Checked is the historical behaviour: visitors cannot use the site and receive HTTP 503. Unchecked, they keep using the site and see the message below as a banner. This preference is kept when you turn maintenance off, so the next time you turn it on the same choice applies.'); ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <label for="maintenance_message"><?php _e('Message'); ?></label>
                        <textarea id="maintenance_message" name="maintenance_message" rows="4" cols="60"
                                  maxlength="<?php echo (int)OSC_MAINTENANCE_MESSAGE_MAX; ?>"><?php
                            echo osc_esc_html($message); ?></textarea>
                        <div class="help-box">
                            <?php _e('Shown on the banner, and on the 503 page when lockout is on. Plain text only (HTML is stripped). Leave blank for the default copy.'); ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Save settings')); ?></button>
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
