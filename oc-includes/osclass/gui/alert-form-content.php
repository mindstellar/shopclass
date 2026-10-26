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
 * Save this search: core emails the subscriber when a new listing matches.
 *
 * Every hidden field comes from AlertForm -- the search that is being subscribed
 * to is encoded there, so hand-writing them would mean re-implementing core's own
 * encoding. A signed-in visitor posts no address at all: core reads it from the
 * session and ignores anything sent.
 *
 * Marked `nocsrf` deliberately. The form is printed inside a results page that a
 * proxy may cache, so a per-visitor token baked into it would either leak between
 * visitors or be stale; core validates the alert on its own terms instead.
 */

require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';
?>
<?php if (function_exists('osc_search_alert_subscribed') && osc_search_alert_subscribed()) { ?>
    <p class="oe-muted"><?php echo osc_esc_html(_m('You are subscribed to this search.')); ?></p>
<?php } else { ?>
    <form class="oe-alert-form nocsrf" data-osc-alert-form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
        <input type="hidden" name="page" value="search" />
        <input type="hidden" name="action" value="alert_post" />
        <?php AlertForm::alert_hidden(); ?>
        <?php AlertForm::user_id_hidden(); ?>

        <?php if (osc_is_web_user_logged_in()) { ?>
            <?php AlertForm::email_hidden(); ?>
        <?php } else { ?>
            <?php osc_gui_field('oe-alert-email', _m('Email me when something new matches'), '<input class="oe-input" id="oe-alert-email" type="email" name="alert_email"'
                . ' autocomplete="email" required />'); ?>
        <?php } ?>

        <?php osc_gui_actions(array(array('label' => _m('Save this search'), 'style' => 'secondary'))); ?>
    </form>
<?php } ?>
