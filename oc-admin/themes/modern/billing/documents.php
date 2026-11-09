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

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Invoices'),
    'help'    => __('Every invoice and credit note issued, newest first. They are never changed or deleted.'),
    'heading' => __('Invoices'),
    'buttons' => array(array(
        'label' => __('Download CSV'),
        'url'   => $csvUrl,
        'icon'  => 'bi-download',
    )),
));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(); ?>

    <?php osc_admin_tab_nav(array(
        array('label' => __('Orders'), 'url' => $base, 'active' => false),
        array('label' => __('Invoices'), 'url' => $base . '&action=documents', 'active' => true),
    )); ?>

    <?php osc_admin_toolbar_open(); ?>
        <?php osc_admin_list_filter(array(
            'page'   => 'billing',
            'hidden' => array('action' => 'documents'),
            'active' => $isFiltered,
            'reset'  => $base . '&action=documents',
            'submit' => __('Filter'),
            'fields' => array(
                array(
                    'type'        => 'select',
                    'name'        => 'type',
                    'id'          => 'fType',
                    'label'       => __('Type'),
                    'value'       => (string) $filters['type'],
                    'placeholder' => __('Invoices and credit notes'),
                    'options'     => $typeWords,
                ),
                array('type' => 'date', 'name' => 'from', 'id' => 'fFrom', 'label' => __('From'), 'value' => (string) $filters['from']),
                array('type' => 'date', 'name' => 'to', 'id' => 'fTo', 'label' => __('To'), 'value' => (string) $filters['to']),
            ),
        )); ?>
        <p class="billing-summary">
            <?php printf(
                osc_esc_html(_n('%s document', '%s documents', $total)),
                '<strong>' . number_format($total) . '</strong>'
            ); ?>
        </p>
    <?php osc_admin_toolbar_close(); ?>

    <?php if (empty($documents)) {
        $empty = array(
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
        <?php
        $tableRows = array();
        foreach ($documents as $doc) {
            $credit = $doc->isCreditNote();
            $buyer  = (array) ($doc->data['buyer'] ?? array());
            $tableRows[] = array(
                'number' => array('render' => static function () use ($doc, $credit): void { ?>
                    <a href="<?php echo osc_esc_html(DocumentPage::adminUrl($doc)); ?>" target="_blank" rel="noopener"><?php echo osc_esc_html($doc->number); ?></a>
                    <?php if ($credit) { ?><span class="text-muted">· <?php _e('Credit note'); ?></span><?php }
                }),
                'date'  => array('html' => osc_admin_date($doc->date, true)),
                'order' => array('render' => static function () use ($doc, $base): void { ?>
                    <a class="billing-order-link" href="<?php echo osc_esc_html($base . '&action=order&id=' . $doc->orderId); ?>"><?php echo osc_esc_html('#' . $doc->orderId); ?></a>
                <?php }),
                'buyer' => (string) ($buyer['name'] ?? ''),
                'total' => DocumentPage::sign($doc) . osc_admin_money($doc->total, $doc->currency),
            );
        }
        osc_admin_table(array(
            'number' => __('Number'),
            'date'   => __('Date'),
            'order'  => _x('Order', 'purchase order'),
            'buyer'  => __('Buyer'),
            'total'  => array('label' => __('Total'), 'numeric' => true),
        ), $tableRows, array('class' => 'billing-documents', 'stack' => true));
        ?>
    <?php } ?>

    <?php osc_admin_pager(array(
        'total'    => $total,
        'per_page' => $perPage,
        'page'     => $pageNum,
        'base_url' => $base . '&action=documents',
        'params'   => $params,
    )); ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
