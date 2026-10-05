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

use mindstellar\admin\ListPaging;

// System info > Security, below the verdict and the facts: failed sign-ins, with Unblock.
$activity = (array) ($env['signins'] ?? array());
$length   = ListPaging::length(20);
$page     = ListPaging::page();
$rows     = array_slice($activity, ListPaging::start($page, $length), $length);
$demo     = defined('DEMO');
$contexts = array(
    'admin'          => __('Admin panel'),
    'web'            => __('Website'),
    'api'            => __('Apps signing in through the API'),
    'admin-recover'  => __('Admin password reset'),
    'web-recover'    => __('Website password reset'),
    'restore_reauth' => __('Password check before a restore'),
);
?>
    <section class="sysinfo-group" id="signin-activity">
        <?php osc_admin_form_section(__('Failed sign-ins right now'), array(
            'spaced' => true,
            'intro'  => sprintf(
                __('Addresses and accounts with failures in the last %d minutes. Unblock one to let it try again at once.'),
                osc_login_throttle_window()
            ),
        )); ?>
        <?php if ($rows === array()) { ?>
            <p class="text-muted"><?php _e('No failed sign-ins. Nobody is blocked.'); ?></p>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table sysinfo-list">
                    <thead>
                    <tr>
                        <th><?php _e('IP address or account'); ?></th>
                        <th class="text-end"><?php _e('Failures'); ?></th>
                        <th><?php _e('Blocked until'); ?></th>
                        <th><span class="visually-hidden"><?php _e('Actions'); ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) {
                        $isIp   = $row['kind'] === 'ip';
                        $fields = $isIp
                            ? array('ip' => $row['ip'])
                            : array('context' => $row['context'], 'account' => $row['account']); ?>
                        <tr>
                            <td class="sysinfo-list-main">
                                <strong><?php echo osc_esc_html($isIp ? $row['ip'] : $row['account']); ?></strong>
                                <span class="sysinfo-list-sub"><?php echo osc_esc_html($isIp
                                    ? __('IP address')
                                    : sprintf(__('Account, %s'), $contexts[$row['context']] ?? $row['context'])); ?></span>
                            </td>
                            <td class="text-end sysinfo-list-meta"><?php printf(
                                osc_esc_html(_n('%d failure', '%d failures', (int) $row['failures'])),
                                (int) $row['failures']
                            ); ?></td>
                            <td class="sysinfo-list-meta"><?php echo $row['blocked']
                                ? osc_admin_when($row['until'])
                                : osc_esc_html(__('not blocked')); ?></td>
                            <td class="text-end sysinfo-list-actions">
                                <?php osc_admin_form_open(array(
                                    'page'       => 'settings',
                                    'action'     => 'login_throttle_unblock',
                                    'horizontal' => false,
                                    'class'      => 'd-inline',
                                    'fields'     => $fields,
                                )); ?>
                                    <button type="submit" class="btn btn-sm btn-secondary"<?php echo $demo ? ' disabled' : ''; ?>><?php
                                        echo osc_esc_html($row['blocked'] ? __('Unblock') : __('Reset')); ?></button>
                                <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php osc_admin_pagination(array(
                'aRows'                => $rows,
                'iTotalDisplayRecords' => count($activity),
                'iDisplayLength'       => $length,
            )); ?>
            <div class="sysinfo-bulk">
                <?php osc_admin_form_open(array('page' => 'settings', 'action' => 'login_throttle_reset', 'horizontal' => false, 'class' => 'd-inline')); ?>
                    <?php osc_admin_action_button(array(
                        'label' => __('Unblock everyone'),
                        'type'  => 'submit',
                        'attrs' => array('id' => 'submit_login_throttle_reset') + ($demo ? array('disabled' => 'disabled') : array()),
                    )); ?>
                <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
                <span class="sysinfo-bulk-note"><?php _e('Deletes every recorded failure, yours too.'); ?></span>
            </div>
        <?php } ?>
    </section>
