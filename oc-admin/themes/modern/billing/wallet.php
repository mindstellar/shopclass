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

$user    = __get('user');
$balance = (int)__get('balance');
$entries = __get('entries');
$total   = (int)__get('total');
$pageNum = (int)__get('pageNum');
$perPage = (int)__get('perPage');

$userId    = (int)$user['pk_i_id'];
$base      = osc_admin_base_url(true) . '?page=billing';
$actionUrl = osc_admin_base_url(true);
$name      = \mindstellar\user\UserStore::displayName($user);

$reasonWords = array(
    'purchase' => __('Purchase'),
    'spend'    => __('Spent'),
    'refund'   => __('Refund'),
    'grant'    => __('Added by admin'),
    'revoke'   => __('Removed by admin'),
);

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
    'heading' => sprintf(__('Credits — %s'), $name),
    'buttons' => array(
        array('label' => __('All credits'), 'url' => $base . '&action=credits', 'icon' => 'bi-arrow-left'),
        array(
            'label' => __('Edit user'),
            'url'   => osc_admin_base_url(true) . '?page=users&action=edit&id=' . $userId,
            'icon'  => 'bi-person',
        ),
    ),
));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>

    <?php osc_admin_body_open(array('layout' => 'split', 'class' => 'billing-detail-grid')); ?>
        <?php osc_admin_panel_open(__('History')); ?>
            <?php if (empty($entries)) {
                osc_admin_empty(array(
                    'title' => __('Nothing has moved yet'),
                    'text'  => __('Every change to this balance is written down here and never edited, '
                                  . 'so this is the record to check if the balance is ever questioned.'),
                ));
            } else {
                osc_admin_table(array(
                    'reason'    => __('What happened'),
                    'change'    => array('label' => __('Change'), 'numeric' => true),
                    'balance'   => array('label' => __('Balance after'), 'numeric' => true),
                    'reference' => __('Reference'),
                    'date'      => __('Date'),
                ), array_map(static function (array $entry) use ($base, $reasonWords): array {
                    $amount  = (int)$entry['i_amount'];
                    $after   = (int)$entry['i_balance_after'];
                    $refType = $entry['s_ref_type'] ?? '';
                    $refId   = (int)($entry['i_ref_id'] ?? 0);

                    return array(
                        'reason'    => $reasonWords[$entry['s_reason']] ?? $entry['s_reason'],
                        'change'    => ($amount > 0 ? '+' : '') . number_format($amount),
                        'balance'   => array('html' => number_format($after), 'class' => $after < 0 ? 'balance-negative' : ''),
                        'reference' => array('html' => $refType === 'order' && $refId > 0
                            ? '<a href="' . osc_esc_html($base . '&action=order&id=' . $refId) . '">' . osc_esc_html('#' . $refId) . '</a>'
                            : '<span class="text-muted">&mdash;</span>'),
                        'date'      => array('html' => osc_admin_date($entry['dt_date'], true)),
                    );
                }, $entries));
            } ?>

            <?php osc_admin_pager(array(
                'total'    => $total,
                'per_page' => $perPage,
                'page'     => $pageNum,
                'base_url' => $base,
                'params'   => array('action' => 'wallet', 'userId' => $userId),
            )); ?>
        <?php osc_admin_panel_close(); ?>
        <?php osc_admin_body_rail(); ?>
        <?php osc_admin_panel_open(__('Balance'), array('stack' => true)); ?>
            <div>
                <p class="billing-balance<?php echo $balance < 0 ? ' balance-negative' : ''; ?>">
                    <?php echo number_format($balance); ?>
                </p>
                <p class="billing-balance-label"><?php _e('credits'); ?></p>
            </div>
            <?php if ($balance < 0) { ?>
                <p class="panel-subtitle">
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
                <?php osc_admin_form_row_open('', array(
                    'layout'     => 'stacked',
                    'for'        => 'add-amount',
                    'label_html' => '<span class="form-sublabel">' . __('How many credits') . '</span>',
                )); ?>
                    <?php osc_admin_number(array(
                        'row'      => false,
                        'id'       => 'add-amount',
                        'name'     => 'amount',
                        'value'    => '',
                        'min'      => 1,
                        'step'     => 1,
                        'required' => true,
                    )); ?>
                <?php osc_admin_form_row_close(); ?>
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
                <?php osc_admin_form_row_open('', array(
                    'layout'     => 'stacked',
                    'for'        => 'remove-amount',
                    'label_html' => '<span class="form-sublabel">' . __('How many credits') . '</span>',
                )); ?>
                    <?php osc_admin_number(array(
                        'row'      => false,
                        'id'       => 'remove-amount',
                        'name'     => 'amount',
                        'value'    => '',
                        'min'      => 1,
                        'max'      => max(0, $balance),
                        'step'     => 1,
                        'required' => true,
                        'help'     => sprintf(
                            __('No more than the %s credits currently held.'),
                            number_format(max(0, $balance))
                        ),
                    )); ?>
                <?php osc_admin_form_row_close(); ?>
                <button type="submit" class="btn btn-secondary"
                    <?php echo $balance < 1 ? 'disabled' : ''; ?>><?php _e('Remove credits'); ?></button>
            </form>
        <?php osc_admin_panel_close(); ?>
    <?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
