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
 * The save-this-search field, rendered by osc_alert_form() inside whatever the
 * theme's results page puts around it. One input on a core-owned contract; a
 * theme that shipped this file was carrying a field it did not define.
 */
?>
<div class="oe-field">
    <label class="oe-label" for="alert_email"><?php
        echo osc_esc_html(_m('Email me when something new matches')); ?></label>
    <input class="oe-input" id="alert_email" type="email" name="alert_email" autocomplete="email"
           value="<?php echo osc_esc_html(osc_logged_user_email()); ?>" />
</div>
