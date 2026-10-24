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
    'section' => __('Listings'),
    'title'   => __('Messages'),
    'help'    => __('Messages held by the spam checks wait here for you to release or remove them. Conversations that a user reported can be closed or cleared.'),
));

$tab    = (string) __get('tab');
$rows   = (array) __get('rows');
$older  = __get('older');
$counts = (array) __get('counts');
$base   = osc_admin_base_url(true) . '?page=messages';

$reasons = array(
    'spam'      => __('Spam'),
    'scam'      => __('Scam'),
    'offensive' => __('Offensive'),
    'other'     => __('Other'),
);
$userLink = static function ($id, $name): string {
    return '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=users&action=edit&id=' . (int) $id) . '">'
        . osc_esc_html($name !== '' ? $name : __('Former user')) . '</a>';
};
$itemLink = static function ($id, $title): string {
    return (int) $id > 0
        ? '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . (int) $id) . '">' . osc_esc_html($title) . '</a>'
        : osc_esc_html($title);
};
$banUrl = static function (string $email): string {
    return osc_admin_base_url(true) . '?page=users&action=create_ban_rule&email=' . urlencode($email);
};

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head(__('Messages')); ?>
<?php osc_admin_tab_nav(array(
    array('label' => __('Held'), 'url' => $base, 'active' => $tab === 'held', 'count' => (int) ($counts['held'] ?? 0)),
    array('label' => __('Reported'), 'url' => $base . '&action=reported', 'active' => $tab === 'reported', 'count' => (int) ($counts['reported'] ?? 0)),
)); ?>
<div class="relative">
    <div class="table-contains-actions">
        <?php if ($tab === 'held') { ?>
        <table class="table" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th><?php _e('Date'); ?></th>
                    <th><?php _e('Sender'); ?></th>
                    <th><?php _e('Listing'); ?></th>
                    <th><?php _e('Message'); ?></th>
                    <th><?php _e('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    $body = (string) $row['s_body']; ?>
                <tr>
                    <td data-col-name="<?php echo osc_esc_html(__('Date')); ?>"><?php echo osc_esc_html(osc_format_date((string) $row['dt_created'], osc_admin_date_format())); ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Sender')); ?>">
                        <?php echo $userLink($row['fk_i_sender_id'], (string) $row['sender_name']); ?>
                        <?php if (!osc_is_moderator() && (string) $row['sender_email'] !== '') { ?>
                            <br /><a href="<?php echo osc_esc_html($banUrl((string) $row['sender_email'])); ?>"><?php _e('Ban sender'); ?></a>
                        <?php } ?>
                    </td>
                    <td data-col-name="<?php echo osc_esc_html(__('Listing')); ?>"><?php echo $itemLink($row['item_id'], (string) $row['item_title']); ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Message')); ?>">
                        <?php if (mb_strlen($body) > 600) { ?>
                            <div style="white-space: pre-wrap;"><?php echo osc_esc_html(mb_substr($body, 0, 600)); ?>&hellip;</div>
                            <details><summary><?php _e('Show all'); ?></summary><div style="white-space: pre-wrap;"><?php echo osc_esc_html($body); ?></div></details>
                        <?php } else { ?>
                            <div style="white-space: pre-wrap;"><?php echo osc_esc_html($body); ?></div>
                        <?php } ?>
                    </td>
                    <td data-col-name="<?php echo osc_esc_html(__('Actions')); ?>">
                        <button type="button" class="btn btn-sm btn-submit" data-msg-dialog="releaseModal" data-id="<?php echo (int) $row['pk_i_id']; ?>"><?php _e('Release'); ?></button>
                        <button type="button" class="btn btn-sm btn-dim" data-msg-dialog="removeModal" data-id="<?php echo (int) $row['pk_i_id']; ?>"><?php _e('Remove'); ?></button>
                        <?php if (!empty($row['conversation']['pk_i_id'])) { ?>
                            <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($base . '&action=thread&id=' . (int) $row['conversation']['pk_i_id']); ?>"><?php _e('View'); ?></a>
                        <?php } ?>
                    </td>
                </tr>
                <?php }
                if ($rows === array()) {
                    osc_admin_table_empty(5, array(
                        'icon'  => 'bi-chat-dots',
                        'title' => __('No held messages'),
                        'text'  => __('Messages that the spam checks hold back show up here.'),
                    ));
                } ?>
            </tbody>
        </table>
        <?php } else { ?>
        <table class="table" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th><?php _e('Date'); ?></th>
                    <th><?php _e('Listing'); ?></th>
                    <th><?php _e('Buyer'); ?></th>
                    <th><?php _e('Seller'); ?></th>
                    <th><?php _e('Reported by'); ?></th>
                    <th><?php _e('Actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) {
                    $reason = (string) ($row['s_report_reason'] ?? ''); ?>
                <tr>
                    <td data-col-name="<?php echo osc_esc_html(__('Date')); ?>"><?php echo osc_esc_html(osc_format_date((string) $row['dt_last_message'], osc_admin_date_format())); ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Listing')); ?>"><?php echo $itemLink($row['item_id'], (string) $row['item_title']); ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Buyer')); ?>"><?php echo $userLink($row['fk_i_buyer_id'], (string) $row['buyer_name']); ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Seller')); ?>"><?php echo $userLink($row['fk_i_seller_id'], (string) $row['seller_name']); ?></td>
                    <td data-col-name="<?php echo osc_esc_html(__('Reported by')); ?>">
                        <?php echo $userLink($row['fk_i_reporter_id'], (string) $row['reporter_name']); ?>
                        <?php if ($reason !== '') { ?><br /><span class="osc-status"><?php echo osc_esc_html($reasons[$reason] ?? $reason); ?></span><?php } ?>
                    </td>
                    <td data-col-name="<?php echo osc_esc_html(__('Actions')); ?>">
                        <a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html($base . '&action=thread&id=' . (int) $row['pk_i_id']); ?>"><?php _e('View'); ?></a>
                        <button type="button" class="btn btn-sm btn-dim" data-msg-dialog="closeModal" data-id="<?php echo (int) $row['pk_i_id']; ?>"><?php _e('Close'); ?></button>
                        <button type="button" class="btn btn-sm btn-submit" data-msg-dialog="clearModal" data-id="<?php echo (int) $row['pk_i_id']; ?>"><?php _e('Clear'); ?></button>
                    </td>
                </tr>
                <?php }
                if ($rows === array()) {
                    osc_admin_table_empty(6, array(
                        'icon'  => 'bi-flag',
                        'title' => __('No reported conversations'),
                        'text'  => __('Conversations that a user reports show up here.'),
                    ));
                } ?>
            </tbody>
        </table>
        <?php } ?>
    </div>
    <?php if ($older) { ?>
        <p><a class="btn btn-sm btn-dim" href="<?php echo osc_esc_html((string) $older); ?>"><?php _e('Older'); ?></a></p>
    <?php } ?>
</div>
<?php
$dialogs = array(
    array('releaseModal', 'release_post', __('Release message'), __('The recipient will get this message and the new-message e-mail.'), __('Release'), false),
    array('removeModal', 'remove_post', __('Remove message'), __('The message is dropped and is never delivered.'), __('Remove'), true),
    array('closeModal', 'close_post', __('Close conversation'), __('Nobody can write in it any more. Both people can still read it.'), __('Close'), true),
    array('clearModal', 'clear_post', __('Clear report'), __('The conversation opens again and the report is dropped.'), __('Clear'), false),
);
foreach ($dialogs as [$dialogId, $dialogAction, $dialogTitle, $dialogText, $dialogConfirm, $dialogDanger]) {
    osc_admin_confirm_dialog(array(
        'id'      => $dialogId,
        'method'  => 'post',
        'fields'  => array('page' => 'messages', 'action' => $dialogAction, 'id' => ''),
        'title'   => $dialogTitle,
        'text'    => $dialogText,
        'confirm' => $dialogConfirm,
        'tone'    => $dialogDanger ? 'danger' : 'default',
    ));
} ?>
<script>
    document.querySelectorAll('[data-msg-dialog]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.getElementById(button.dataset.msgDialog);
            dialog.querySelector('input[name="id"]').value = button.dataset.id;
            dialog.showModal();
        });
    });
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
