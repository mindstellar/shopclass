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
 * The per-browser switch for new-message notifications, driven by assets/push.js. Prints
 * nothing while web push is off; hidden until the script knows the browser supports it.
 */

if (osc_push_enabled()) {
    $pushAjax   = osc_base_url(true) . '?page=ajax&action=';
    $pushInbox  = osc_messaging_inbox_url();
    $pushKey    = osc_push_public_key();
    // The worker has no translations: its texts ride on its own URL.
    $pushWorker = $pushAjax . 'push_worker&' . http_build_query(array(
        'title'    => osc_page_title(),
        'one'      => _mn('%d new message', '%d new messages', 1),
        'many'     => _mn('%d new message', '%d new messages', 2),
        'fallback' => _m('You have new messages'),
        'signin'   => _m('Sign in to read your messages'),
        'inbox'    => $pushInbox,
        'key'      => $pushKey,
    ), '', '&', PHP_QUERY_RFC3986);
    ?>
    <form class="oe-push nocsrf" method="post" action="<?php echo osc_esc_html(osc_base_url(true)); ?>" hidden
          data-osc-push
          data-key="<?php echo osc_esc_html($pushKey); ?>"
          data-worker-url="<?php echo osc_esc_html($pushWorker); ?>"
          data-subscribe-url="<?php echo osc_esc_html($pushAjax . 'push_subscribe'); ?>"
          data-unsubscribe-url="<?php echo osc_esc_html($pushAjax . 'push_unsubscribe'); ?>"
          data-status-url="<?php echo osc_esc_html($pushAjax . 'push_status'); ?>"
          data-inbox-url="<?php echo osc_esc_html($pushInbox); ?>"
          data-on-label="<?php echo osc_esc_html(_m('Stop notifying this device')); ?>"
          data-off-label="<?php echo osc_esc_html(_m('Notify me on this device')); ?>"
          data-on-note="<?php echo osc_esc_html(_m('New messages show as a notification on this device.')); ?>"
          data-blocked-note="<?php echo osc_esc_html(_m('Notifications are blocked in this browser. Allow them in its site settings first.')); ?>"
          data-error-note="<?php echo osc_esc_html(_m('Notifications could not be changed. Try again.')); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <button class="oe-btn oe-secondary" type="submit"><?php echo osc_esc_html(_m('Notify me on this device')); ?></button>
        <p class="oe-muted" data-osc-push-note aria-live="polite"></p>
    </form>
<?php }
