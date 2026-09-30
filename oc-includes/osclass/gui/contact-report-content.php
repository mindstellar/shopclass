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
 * "Report the sender" confirm page -- markup only. The GET page bans nothing; the
 * button posts report_post. CSRF is injected on shutdown for forms not marked nocsrf.
 */
$reportDone   = (bool) __get('report_done');
$reportToken  = (string) __get('report_token');
$reportSender = (string) __get('report_sender');
$reportDays   = (int) __get('report_days');
?>
<div class="oe-report-sender">
    <?php osc_show_flash_message(); ?>

    <?php if ($reportDone) { ?>
        <p><?php echo osc_esc_html(sprintf(
            _m('Thank you. The sender cannot send messages on this site for %d days.'),
            $reportDays
        )); ?></p>
        <p><a href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(_m('Go to the home page')); ?></a></p>
    <?php } elseif ($reportToken === '') { ?>
        <p><?php echo osc_esc_html(_m('This report link is not valid or has expired.')); ?></p>
    <?php } else { ?>
        <p><?php echo osc_esc_html(sprintf(
            _m('Report %1$s? They will not be able to send messages on this site for %2$d days. The site admin can see and undo this.'),
            $reportSender,
            $reportDays
        )); ?></p>
        <form action="<?php echo osc_esc_html(osc_base_url(true)); ?>" method="post">
            <input type="hidden" name="page" value="contact" />
            <input type="hidden" name="action" value="report_post" />
            <input type="hidden" name="t" value="<?php echo osc_esc_html($reportToken); ?>" />
            <div class="oe-actions">
                <button class="oe-btn oe-btn-danger" type="submit"><?php echo osc_esc_html(_m('Report the sender')); ?></button>
                <a class="oe-muted" href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(_m('Cancel')); ?></a>
            </div>
        </form>
    <?php } ?>
</div>
