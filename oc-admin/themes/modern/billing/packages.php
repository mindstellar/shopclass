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
    'help'    => __('What a buyer can choose at checkout — a price, a currency and how many '
                    . 'credits it mints. Checkout reads all three from here; nothing about the '
                    . 'price ever comes from the browser.'),
));

$packages = __get('packages');
$base     = osc_admin_base_url(true) . '?page=billing';
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Packages'), array(
        array('label' => __('Add package'), 'url' => $base . '&action=package', 'icon' => 'bi-plus-lg', 'variant' => 'primary'),
    )); ?>

    <?php if (empty($packages)) {
        osc_admin_empty(array(
            'icon'   => 'bi-box-seam',
            'title'  => __('No packages yet'),
            'text'   => __('A package is what a buyer picks at checkout. Add one to start selling credits.'),
            'action' => array('label' => __('Add package'), 'url' => $base . '&action=package'),
        ));
    } else { ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                <tr>
                    <th scope="col"><?php _e('Name'); ?></th>
                    <th scope="col" class="col-numeric"><?php _e('Price'); ?></th>
                    <th scope="col" class="col-numeric"><?php _e('Credits'); ?></th>
                    <th scope="col"><?php _e('Status'); ?></th>
                    <th scope="col"><?php _e('Actions'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($packages as $package) {
                    $editUrl = $base . '&action=package&id=' . $package['pk_i_id']; ?>
                    <tr>
                        <td>
                            <a href="<?php echo osc_esc_html($editUrl); ?>">
                                <?php echo osc_esc_html($package['s_name']); ?>
                            </a>
                        </td>
                        <td class="col-numeric">
                            <?php echo osc_esc_html(osc_admin_money($package['i_amount'], $package['s_currency'])); ?>
                        </td>
                        <td class="col-numeric"><?php echo number_format((int) $package['i_credits']); ?></td>
                        <td>
                            <?php osc_admin_status(
                                $package['b_enabled'] ? 'active' : 'inactive',
                                $package['b_enabled'] ? __('Enabled') : __('Disabled')
                            ); ?>
                        </td>
                        <td>
                            <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($editUrl); ?>">
                                <?php _e('Edit'); ?>
                            </a>
                            <button type="button" class="btn btn-danger btn-sm"
                                    data-osc-dialog-open="#package-delete-<?php echo (int) $package['pk_i_id']; ?>">
                                <?php _e('Delete'); ?>
                            </button>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <?php foreach ($packages as $package) { ?>
            <dialog id="package-delete-<?php echo (int) $package['pk_i_id']; ?>" class="osc-dialog osc-dialog-danger">
                <form method="post" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>">
                    <input type="hidden" name="page" value="billing"/>
                    <input type="hidden" name="action" value="package_delete"/>
                    <input type="hidden" name="id" value="<?php echo (int) $package['pk_i_id']; ?>"/>
                    <div class="osc-dialog-body">
                        <p class="osc-dialog-title">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <?php printf(
                                osc_esc_html(__('Delete "%s"?')),
                                osc_esc_html($package['s_name'])
                            ); ?>
                        </p>
                        <p class="osc-dialog-text">
                            <?php _e('This only removes it from checkout. Orders already placed against it '
                                     . 'keep their own record of what was paid.'); ?>
                        </p>
                    </div>
                    <div class="osc-dialog-actions">
                        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                        <button type="submit" class="btn btn-danger btn-sm"><?php _e('Delete'); ?></button>
                    </div>
                </form>
            </dialog>
        <?php } ?>
    <?php } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
