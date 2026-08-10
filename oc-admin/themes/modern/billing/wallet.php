<?php if (!defined('OC_ADMIN')) {
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

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
));

$user    = __get('user');
$balance = (int)__get('balance');
$entries = __get('entries');
$total   = (int)__get('total');
$pageNum = (int)__get('pageNum');
$perPage = (int)__get('perPage');

$userId    = (int)$user['pk_i_id'];
$base      = osc_admin_base_url(true) . '?page=billing';
$actionUrl = osc_admin_base_url(true);
$name      = $user['s_username'] ?: $user['s_name'];

$reasonWords = array(
    'purchase' => __('Purchase'),
    'spend'    => __('Spent'),
    'refund'   => __('Refund'),
    'grant'    => __('Added by admin'),
    'revoke'   => __('Removed by admin'),
);
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(
        sprintf(__('Credits — %s'), $name),
        array(
            array('label' => __('All credits'), 'url' => $base . '&action=credits', 'icon' => 'bi-arrow-left'),
            array(
                'label' => __('Edit user'),
                'url'   => osc_admin_base_url(true) . '?page=users&action=edit&id=' . $userId,
                'icon'  => 'bi-person',
            ),
        )
    ); ?>

    <div class="billing-detail-grid">
        <div>
            <?php osc_admin_panel_open(__('History'), array('flush' => !empty($entries))); ?>
            <?php if (empty($entries)) {
                osc_admin_empty(array(
                    'icon'  => 'bi-clock-history',
                    'title' => __('Nothing has moved yet'),
                    'text'  => __('Every change to this balance is written down here and never edited, '
                                  . 'so this is the record to check if the balance is ever questioned.'),
                ));
            } else { ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col"><?php _e('What happened'); ?></th>
                            <th scope="col" class="col-numeric"><?php _e('Change'); ?></th>
                            <th scope="col" class="col-numeric"><?php _e('Balance after'); ?></th>
                            <th scope="col"><?php _e('Reference'); ?></th>
                            <th scope="col"><?php _e('Date'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $entry) {
                            $amount   = (int)$entry['i_amount'];
                            $refType  = $entry['s_ref_type'] ?? '';
                            $refId    = (int)($entry['i_ref_id'] ?? 0); ?>
                            <tr>
                                <td><?php echo osc_esc_html(
                                    $reasonWords[$entry['s_reason']] ?? $entry['s_reason']
                                ); ?></td>
                                <td class="col-numeric">
                                    <?php echo osc_esc_html(($amount > 0 ? '+' : '') . number_format($amount)); ?>
                                </td>
                                <td class="col-numeric<?php echo (int)$entry['i_balance_after'] < 0
                                    ? ' balance-negative' : ''; ?>">
                                    <?php echo number_format((int)$entry['i_balance_after']); ?>
                                </td>
                                <td>
                                    <?php if ($refType === 'order' && $refId > 0) { ?>
                                        <a href="<?php echo osc_esc_html($base . '&action=order&id=' . $refId); ?>">
                                            <?php echo osc_esc_html('#' . $refId); ?>
                                        </a>
                                    <?php } else { ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php } ?>
                                </td>
                                <td><?php echo osc_esc_html(osc_format_date($entry['dt_date'])); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
            <?php osc_admin_panel_close(); ?>

            <?php osc_admin_pager(array(
                'total'    => $total,
                'per_page' => $perPage,
                'page'     => $pageNum,
                'base_url' => $base,
                'params'   => array('action' => 'wallet', 'userId' => $userId),
            )); ?>
        </div>

        <div>
            <?php osc_admin_panel_open(__('Balance')); ?>
                <p class="billing-balance<?php echo $balance < 0 ? ' balance-negative' : ''; ?>">
                    <?php echo number_format($balance); ?>
                </p>
                <p class="billing-balance-label"><?php _e('credits'); ?></p>
                <?php if ($balance < 0) { ?>
                    <p class="panel-subtitle mb-0">
                        <?php _e('This balance is below zero, usually after a refund of credits that had '
                                 . 'already been spent. Nothing more can be spent until it is back above zero.'); ?>
                    </p>
                <?php } ?>
            <?php osc_admin_panel_close(); ?>

            <?php osc_admin_panel_open(__('Add credits')); ?>
                <p class="panel-subtitle">
                    <?php printf(
                        osc_esc_html(__('Give %s credits without a payment — to settle something off-site, '
                                        . 'or to put right a mistake.')),
                        osc_esc_html($name)
                    ); ?>
                </p>
                <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                    <input type="hidden" name="page" value="billing"/>
                    <input type="hidden" name="action" value="wallet_adjust"/>
                    <input type="hidden" name="userId" value="<?php echo $userId; ?>"/>
                    <input type="hidden" name="mode" value="add"/>
                    <div class="form-row">
                        <label for="add-amount"><?php _e('How many credits'); ?></label>
                        <input type="number" min="1" step="1" required class="form-control" id="add-amount"
                               name="amount" value=""/>
                    </div>
                    <button type="submit" class="btn btn-submit"><?php _e('Add credits'); ?></button>
                </form>
            <?php osc_admin_panel_close(); ?>

            <?php osc_admin_panel_open(__('Remove credits')); ?>
                <p class="panel-subtitle">
                    <?php _e('Take credits back off this account. You can add them again afterwards, and '
                             . 'both changes stay in the history above.'); ?>
                </p>
                <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                    <input type="hidden" name="page" value="billing"/>
                    <input type="hidden" name="action" value="wallet_adjust"/>
                    <input type="hidden" name="userId" value="<?php echo $userId; ?>"/>
                    <input type="hidden" name="mode" value="remove"/>
                    <div class="form-row">
                        <label for="remove-amount"><?php _e('How many credits'); ?></label>
                        <input type="number" min="1" step="1" max="<?php echo max(0, $balance); ?>" required
                               class="form-control" id="remove-amount" name="amount" value=""/>
                        <div class="help-block">
                            <?php printf(
                                osc_esc_html(__('No more than the %s credits currently held.')),
                                number_format(max(0, $balance))
                            ); ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-secondary"
                        <?php echo $balance < 1 ? 'disabled' : ''; ?>><?php _e('Remove credits'); ?></button>
                </form>
            <?php osc_admin_panel_close(); ?>
        </div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
