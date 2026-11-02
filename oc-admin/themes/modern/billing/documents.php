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

use mindstellar\billing\Document;
use mindstellar\billing\DocumentPage;

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Invoices'),
    'help'    => __('Every invoice and credit note issued, newest first. They are never changed or deleted.'),
));

$documents = __get('documents');
$filters   = __get('filters');
$total     = (int) __get('total');
$pageNum   = (int) __get('pageNum');
$perPage   = (int) __get('perPage');

$base       = osc_admin_base_url(true) . '?page=billing';
$params     = array_filter($filters);
$isFiltered = $params !== array();
$csvUrl     = $base . '&' . http_build_query(array('action' => 'documents_csv') + $params);
$typeWords  = array(Document::INVOICE => __('Invoices'), Document::CREDIT_NOTE => __('Credit notes'));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Invoices'), array(array(
        'label' => __('Download CSV'),
        'url'   => $csvUrl,
        'icon'  => 'bi-download',
    ))); ?>

    <?php osc_admin_tab_nav(array(
        array('label' => __('Orders'), 'url' => $base, 'active' => false),
        array('label' => __('Invoices'), 'url' => $base . '&action=documents', 'active' => true),
    )); ?>

    <?php osc_admin_toolbar_open(); ?>
        <form method="get" action="<?php echo osc_esc_html(osc_admin_base_url(true)); ?>" class="osc-filter nocsrf">
            <input type="hidden" name="page" value="billing"/>
            <input type="hidden" name="action" value="documents"/>
            <label class="visually-hidden" for="fType"><?php _e('Type'); ?></label>
            <select id="fType" name="type" class="form-select form-select-sm">
                <option value=""><?php _e('Invoices and credit notes'); ?></option>
                <?php foreach ($typeWords as $value => $word) { ?>
                    <option value="<?php echo osc_esc_html($value); ?>"<?php echo $filters['type'] === $value ? ' selected' : ''; ?>><?php echo osc_esc_html($word); ?></option>
                <?php } ?>
            </select>
            <span class="osc-filter-field">
                <label for="fFrom"><?php _e('From'); ?></label>
                <input type="date" id="fFrom" name="from" class="form-control form-control-sm" value="<?php echo osc_esc_html($filters['from']); ?>"/>
            </span>
            <span class="osc-filter-field">
                <label for="fTo"><?php _e('To'); ?></label>
                <input type="date" id="fTo" name="to" class="form-control form-control-sm" value="<?php echo osc_esc_html($filters['to']); ?>"/>
            </span>
            <button type="submit" class="btn btn-sm btn-primary" title="<?php echo osc_esc_html(__('Filter')); ?>"><i class="bi bi-search"></i></button>
            <?php if ($isFiltered) { ?>
                <a class="btn btn-sm btn-dim osc-filter-reset" href="<?php echo osc_esc_html($base . '&action=documents'); ?>"><?php _e('Reset'); ?></a>
            <?php } ?>
        </form>
        <p class="billing-summary">
            <?php printf(
                osc_esc_html(_n('%s document', '%s documents', $total)),
                '<strong>' . number_format($total) . '</strong>'
            ); ?>
        </p>
    <?php osc_admin_toolbar_close(); ?>

    <?php if (empty($documents)) {
        $empty = array(
            'icon'  => 'bi-receipt',
            'title' => $isFiltered ? __('No documents match this filter') : __('No invoices yet'),
            'text'  => $isFiltered
                ? __('Try a different type or dates.')
                : __('An invoice is made when an order is paid.'),
        );
        if ($isFiltered) {
            $empty['action'] = array('label' => __('Clear filter'), 'url' => $base . '&action=documents');
        }
        osc_admin_empty($empty);
    } else { ?>
        <div class="table-responsive">
            <table class="table billing-documents osc-table-stack">
                <thead>
                <tr>
                    <th scope="col"><?php _e('Number'); ?></th>
                    <th scope="col"><?php _e('Date'); ?></th>
                    <th scope="col"><?php _ex('Order', 'purchase order'); ?></th>
                    <th scope="col"><?php _e('Buyer'); ?></th>
                    <th scope="col" class="col-numeric"><?php _e('Total'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($documents as $doc) {
                    $credit = $doc->isCreditNote();
                    $buyer  = (array) ($doc->data['buyer'] ?? array()); ?>
                    <tr>
                        <td data-col-name="<?php echo osc_esc_html(__('Number')); ?>">
                            <a href="<?php echo osc_esc_html(DocumentPage::adminUrl($doc)); ?>" target="_blank" rel="noopener"><?php echo osc_esc_html($doc->number); ?></a>
                            <?php if ($credit) { ?><span class="text-muted">· <?php _e('Credit note'); ?></span><?php } ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Date')); ?>"><?php echo osc_admin_date($doc->date, true); ?></td>
                        <td data-col-name="<?php echo osc_esc_html(_x('Order', 'purchase order')); ?>">
                            <a class="billing-order-link" href="<?php echo osc_esc_html($base . '&action=order&id=' . $doc->orderId); ?>"><?php echo osc_esc_html('#' . $doc->orderId); ?></a>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Buyer')); ?>"><?php echo osc_esc_html((string) ($buyer['name'] ?? '')); ?></td>
                        <td class="col-numeric" data-col-name="<?php echo osc_esc_html(__('Total')); ?>"><?php echo osc_esc_html(DocumentPage::sign($doc) . osc_admin_money($doc->total, $doc->currency)); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php osc_admin_pager(array(
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $pageNum,
        'base_url' => $base . '&action=documents',
        'params'   => $params,
    )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
