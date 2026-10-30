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

osc_admin_page(array(
    'section' => osc_mode_text(__('Listings'), __('Products')),
    'title'   => __('Messages'),
));

$thread       = (array) __get('thread');
$conversation = (array) $thread['conversation'];
$messages     = (array) $thread['messages'];
$names        = (array) $thread['names'];
$itemId       = (int) __get('item_id');
$itemTitle    = (string) __get('item_title');
$base         = osc_admin_base_url(true) . '?page=messages';
$reported     = (string) $conversation['s_state'] === 'reported';
$states       = array(
    'sent'    => __('Sent'),
    'held'    => __('Held'),
    'removed' => __('Removed'),
);

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head(
    __('Conversation'),
    $reported ? array(
        array('label' => __('Clear report'), 'type' => 'button', 'variant' => 'primary', 'attrs' => array('data-msg-dialog' => 'clearModal')),
        array('label' => __('Close'), 'type' => 'button', 'variant' => 'secondary', 'attrs' => array('data-msg-dialog' => 'closeModal')),
    ) : array()
); ?>
<p>
    <a href="<?php echo osc_esc_html($base . ($reported ? '&action=reported' : '')); ?>">&larr; <?php _e('Back to messages'); ?></a>
</p>
<p>
    <?php if ($itemId > 0) { ?>
        <a href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . $itemId); ?>"><?php echo osc_esc_html($itemTitle); ?></a>
    <?php } else {
        echo osc_esc_html($itemTitle);
    } ?>
    &middot; <?php echo osc_esc_html(sprintf(__('State: %s'), (string) $conversation['s_state'])); ?>
</p>
<div class="table-contains-actions">
    <table class="table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                <th><?php _e('Date'); ?></th>
                <th><?php _e('Sender'); ?></th>
                <th><?php _e('Message'); ?></th>
                <th><?php _e('Status'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($messages as $message) {
                $senderName = (string) ($names[(int) $message['fk_i_sender_id']] ?? ''); ?>
            <tr>
                <td data-col-name="<?php echo osc_esc_html(__('Date')); ?>"><?php echo osc_esc_html(osc_format_date((string) $message['dt_created'], osc_admin_date_format())); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Sender')); ?>"><?php echo osc_esc_html($senderName !== '' ? $senderName : __('Former user')); ?></td>
                <td data-col-name="<?php echo osc_esc_html(__('Message')); ?>"><div style="white-space: pre-wrap;"><?php echo osc_esc_html((string) $message['s_body']); ?></div></td>
                <td data-col-name="<?php echo osc_esc_html(__('Status')); ?>"><span class="osc-status"><?php echo osc_esc_html($states[(string) $message['s_state']] ?? (string) $message['s_state']); ?></span></td>
            </tr>
            <?php }
            if ($messages === array()) {
                osc_admin_table_empty(4, array(
                    'icon'  => 'bi-chat-dots',
                    'title' => __('No messages'),
                ));
            } ?>
        </tbody>
    </table>
</div>
<?php if ($reported) {
    foreach (array(
        array('closeModal', 'close_post', __('Close conversation'), __('Nobody can write in it any more. Both people can still read it.'), __('Close'), 'danger'),
        array('clearModal', 'clear_post', __('Clear report'), __('The conversation opens again and the report is dropped.'), __('Clear'), 'default'),
    ) as [$dialogId, $dialogAction, $dialogTitle, $dialogText, $dialogConfirm, $tone]) {
        osc_admin_confirm_dialog(array(
            'id'      => $dialogId,
            'method'  => 'post',
            'fields'  => array('page' => 'messages', 'action' => $dialogAction, 'id' => (string) (int) $conversation['pk_i_id']),
            'title'   => $dialogTitle,
            'text'    => $dialogText,
            'confirm' => $dialogConfirm,
            'tone'    => $tone,
        ));
    } ?>
<script>
    document.querySelectorAll('[data-msg-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.getElementById(button.dataset.msgDialog).showModal();
        });
    });
</script>
<?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
