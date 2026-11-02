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

use mindstellar\billing\DocumentPage;
use mindstellar\billing\ListingCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStock;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;
use mindstellar\currency\Money;

osc_admin_page(array(
    'section' => __('Billing'),
    'title'   => __('Billing'),
));

/** @var \mindstellar\billing\Order $order */
$order   = __get('order');
$entries = __get('entries');
$typed   = is_array(__get('typed')) ? __get('typed') : array();
$errors  = is_array(__get('errors')) ? __get('errors') : array();
// The carrier, tracking number and link boxes, shared by the ship and edit-tracking forms.
$carriers = is_array(__get('carriers')) ? __get('carriers') : array();
$trackingFields = static function (string $prefix, array $values, bool $showError = true) use ($errors, $carriers): void {
    echo '<div class="order-tracking-fields">';
    osc_admin_text(array(
        'id'    => $prefix . '-carrier',
        'name'  => 'carrier',
        'label' => __('Carrier'),
        'value' => (string) ($values['carrier'] ?? ''),
        'attrs' => array('maxlength' => 100) + ($carriers === array() ? array() : array('list' => 'order-carriers', 'autocomplete' => 'off')),
    ));
    if ($carriers !== array()) {
        echo '<div class="order-carrier-chips" role="group" aria-label="' . osc_esc_html(__('Common carriers')) . '">';
        foreach ($carriers as $carrierName) {
            echo '<button type="button" class="order-carrier-chip" data-osc-fill="#' . osc_esc_html($prefix) . '-carrier" data-osc-fill-value="'
                . osc_esc_html((string) $carrierName) . '">' . osc_esc_html((string) $carrierName) . '</button>';
        }
        echo '</div>';
    }
    osc_admin_text(array(
        'id'    => $prefix . '-number',
        'name'  => 'tracking',
        'label' => __('Tracking number'),
        'value' => (string) ($values['tracking'] ?? ''),
        'attrs' => array('maxlength' => 100),
    ));
    osc_admin_text(array(
        'id'    => $prefix . '-url',
        'name'  => 'trackingUrl',
        'label' => __('Tracking link'),
        'value' => (string) ($values['trackingUrl'] ?? ''),
        'error' => $showError ? ($errors['trackingUrl'] ?? '') : '',
        'attrs' => array('maxlength' => 500, 'placeholder' => 'https://'),
        'help'  => __('Optional. Must start with https://'),
    ));
    echo '</div>';
};
$actors  = is_array(__get('actors')) ? __get('actors') : array();
$actorWord = static function (array $row) use ($actors): string {
    $id = (int) ($row['id'] ?? 0);
    switch ($row['by'] ?? '') {
        case 'admin':
            return $actors['admin:' . $id] ?? sprintf(__('Admin #%d'), $id);
        case 'buyer':
            return __('The buyer');
        default:
            return __('The system');
    }
};
$balance = (int)__get('balance');
$refundable = (bool)__get('refundable');
$dashboardUrl = (string) __get('dashboardUrl');
$isGoods    = $order->isGoods();
$canCancel  = $order->isPending() || ($isGoods && $order->awaitsCash() && !($order->getStatus() === Order::STATUS_PROCESSING && $order->shipments() !== array()));
$lines      = is_array(__get('lines')) ? __get('lines') : array();
$fulfil     = $order->fulfilment() ?? array();
$address    = is_array($fulfil['address'] ?? null) ? $fulfil['address'] : array();
$buyer      = is_array($order->meta('_buyer')) ? $order->meta('_buyer') : array();
$short      = OrderStock::short($order);
$shipments  = $order->shipments();
$history    = $order->history();
$shippedQty = $order->shippedQty();
$lineTaxes  = array();
foreach ($lines as $l) {
    $lt = \mindstellar\billing\OrderLineStore::lineTax($l);
    if ($lt !== null) {
        $lineTaxes[(int) $l['id']] = \mindstellar\billing\LineTaxView::describe($lt, $order->getCurrency());
    }
}
$buyerFields = array();
foreach (is_array($order->meta('_fields')) ? $order->meta('_fields') : array() as $f) {
    if (is_array($f) && is_string($f['value'] ?? null) && $f['value'] !== '') {
        $buyerFields[] = (string) ($f['label'] ?? '') . ': ' . $f['value'];
    }
}
$itemLines  = array_values(array_filter($lines, static fn (array $l): bool => $l['kind'] === 'item'));
$lineTitles = array();
foreach ($itemLines as $l) {
    $lineTitles[(int) $l['id']] = (string) $l['title'];
}
$trackUrl   = Receipts::httpsUrl((string) ($fulfil['tracking_url'] ?? ''));
$documents  = is_array(__get('documents')) ? __get('documents') : array();
$refundLeft = (int) __get('refundLeft');
$refundRows = is_array(__get('refundRows')) ? __get('refundRows') : array();
$partly     = (bool) __get('partlyRefunded');
$partGateway = is_object(__get('partGateway')) ? __get('partGateway') : null;
$canRefundPart = $order->isPaid() && $order->getStatus() !== Order::STATUS_REFUNDED && $refundLeft > 0 && $refundRows !== array();
$refundSent = $order->isPaid() && $order->meta(OrderStore::REFUND_REQUESTED) !== null;

// The view layer hands back '' for anything exported as null, so "absent" has to be
// tested for what it is rather than compared against null. Both of these are genuinely
// optional: the buyer's account may have been deleted, and the plugin that took the
// payment may have been uninstalled since.
$user    = is_array(__get('user')) ? __get('user') : null;
$gateway = is_object(__get('gateway')) ? __get('gateway') : null;
// Payment plugin off or not set up: the screen says so instead of hiding actions.
$gatewayLabel   = $gateway !== null ? $gateway->getName() : $order->getGateway();
$gatewayWarning = '';
if ($gateway === null) {
    $gatewayWarning = sprintf(__('The %s payment plugin is not active.'), $gatewayLabel);
} elseif (!$gateway->isConfigured()) {
    $gatewayWarning = sprintf(__('The %s payment plugin is not set up.'), $gatewayLabel);
}
$pluginsUrl = osc_admin_base_url(true) . '?page=plugins';
$userName = $user !== null ? \mindstellar\user\UserStore::displayName($user) : __('this user');

$base       = osc_admin_base_url(true) . '?page=billing';
$actionUrl  = osc_admin_base_url(true);

$statusWords = array(
    Order::STATUS_PENDING   => __('Pending'),
    Order::STATUS_PAID      => __('Paid'),
    Order::STATUS_PROCESSING => __('Processing'),
    Order::STATUS_SHIPPED   => __('Shipped'),
    Order::STATUS_DELIVERED => __('Delivered'),
    Order::STATUS_FAILED    => __('Failed'),
    Order::STATUS_REFUNDED  => __('Refunded'),
    Order::STATUS_CANCELLED => __('Cancelled'),
);
$statusWord = $statusWords[$order->getStatus()] ?? $order->getStatus();

$reasonWords = array(
    'purchase' => __('Purchase'),
    'spend'    => __('Spent'),
    'refund'   => __('Refund'),
    'grant'    => __('Added by admin'),
    'revoke'   => __('Removed by admin'),
);

$rows = array(
    array(
        'label' => __('Status'),
        'value' => '<span class="osc-status status-' . osc_esc_html($order->getStatus()) . '">'
                   . osc_esc_html($statusWord) . '</span>'
                   . ($partly ? ' <span class="osc-status status-refunded">' . osc_esc_html(__('Partly refunded')) . '</span>' : ''),
        'html'  => true,
    ),
    array('label' => __('Amount'), 'value' => osc_admin_money($order->getAmount(), $order->getCurrency())),
);
if ($isGoods) {
    $rows[] = array('label' => _x('Order', 'purchase order'), 'value' => $order->getLabel());
} else {
    $rows[] = array('label' => __('Credits'), 'value' => number_format($order->getCredits()));
}

$listingId = $order->listingId();
if ($listingId !== null) {
    $rows[] = array(
        'label' => __('Listing'),
        'value' => OrderStore::listingState($listingId) === 'gone'
            ? osc_esc_html($order->getLabel()) . ' <span class="text-muted">' . osc_esc_html(__('(deleted)')) . '</span>'
            : '<a href="' . osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . $listingId) . '">'
              . osc_esc_html($order->getLabel()) . '</a>',
        'html'  => true,
    );
}

if ($user !== null) {
    $rows[] = array(
        'label' => __('User'),
        'value' => '<a href="' . osc_esc_html($base . '&action=wallet&userId=' . $order->getUserId()) . '">'
                   . osc_esc_html($userName) . '</a>'
                   . ' <span class="text-muted">' . osc_esc_html($user['s_email']) . '</span>',
        'html'  => true,
    );
    if (!$isGoods) {
        $rows[] = array('label' => __('Balance now'), 'value' => number_format($balance) . ' ' . __('credits'));
    }
} else {
    $rows[] = array('label' => __('User'), 'value' => __('This account has been deleted'));
}

$rows[] = array(
    'label' => __('Payment method'),
    'value' => $gateway !== null
        ? $gateway->getName()
        // The plugin that took this payment is not active. The order still has to
        // be readable and reconcilable, so the stored id stands in for the missing name.
        : sprintf(__('%s (plugin not active)'), $order->getGateway()),
);
$rows[] = array(
    'label' => __('Reference'),
    'value' => $order->getExternalRef() ?: __('None yet'),
    'mono'  => (bool)$order->getExternalRef(),
);
if (Receipts::available($order)) {
    $receiptSent = $order->meta(OrderStore::RECEIPT_SENT);
    $rows[] = array(
        'label' => __('Receipt'),
        'value' => '<a href="' . osc_esc_html($base . '&action=receipt&id=' . $order->getId()) . '" target="_blank" rel="noopener">'
                   . osc_esc_html(__('View receipt')) . '</a>'
                   . ($receiptSent !== null
                       ? ' <span class="text-muted">' . osc_esc_html(__('e-mailed')) . ' '
                         . osc_admin_date((string) $receiptSent, true) . '</span>'
                       : ''),
        'html'  => true,
    );
}
if ($dashboardUrl !== '') {
    $rows[] = array(
        'label' => __('Provider'),
        'value' => '<a href="' . osc_esc_html($dashboardUrl) . '" target="_blank" rel="noopener noreferrer">'
                   . osc_esc_html(__('View payment'))
                   . ' <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>',
        'html'  => true,
    );
}
if ($isGoods && ($fulfil['label'] ?? '') !== '') {
    $rows[] = array('label' => __('Delivery'), 'value' => (string) $fulfil['label']);
}
if ($isGoods && ($fulfil['shipped_at'] ?? null) !== null) {
    $rows[] = array('label' => __('Shipped'), 'value' => osc_admin_date((string) $fulfil['shipped_at'], true), 'html' => true);
    $carrier = trim((string) ($fulfil['carrier'] ?? '') . ' ' . (string) ($fulfil['tracking'] ?? ''));
    if ($carrier !== '' || $trackUrl !== '') {
        $rows[] = array(
            'label' => __('Tracking'),
            'value' => ($trackUrl !== ''
                ? '<a href="' . osc_esc_html($trackUrl) . '" target="_blank" rel="noopener noreferrer">'
                  . osc_esc_html($carrier !== '' ? $carrier : __('Track parcel')) . '</a>'
                : osc_esc_html($carrier)),
            'html'  => true,
        );
    }
}
if ($isGoods && $order->packed() !== null) {
    $packed = $order->packed();
    $rows[] = array(
        'label' => __('Packed'),
        'value' => osc_admin_date((string) $packed['at'], true) . ' <span class="text-muted">' . osc_esc_html($actorWord($packed)) . '</span>',
        'html'  => true,
    );
}
if ($isGoods && ($fulfil['delivered_at'] ?? null) !== null) {
    $rows[] = array('label' => __('Delivered'), 'value' => osc_admin_date((string) $fulfil['delivered_at'], true), 'html' => true);
}
$rows[] = array('label' => __('Created'), 'value' => osc_admin_date($order->getDate(), true), 'html' => true);
if ($order->getPaidDate() !== null) {
    $rows[] = array('label' => __('Paid'), 'value' => osc_admin_date($order->getPaidDate(), true), 'html' => true);
}
// Keys starting with "_" belong to core, and keys starting with the gateway id and "_" are the plugin's own notes.
$gatewayPrefix = $order->getGateway() . '_';
foreach ($order->getMeta() as $key => $value) {
    if (is_scalar($value) && strpos((string) $key, '_') !== 0 && strpos((string) $key, $gatewayPrefix) !== 0) {
        $rows[] = array('label' => (string)$key, 'value' => (string)$value);
    }
}
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(
        sprintf(__('Order #%d'), $order->getId()),
        array(array('label' => __('Back to orders'), 'url' => $base, 'icon' => 'bi-arrow-left'))
    ); ?>

    <div class="billing-detail-grid">
        <div>
            <?php osc_admin_panel_open(_x('Order', 'purchase order'), array('class' => 'billing-detail-summary')); ?>
                <?php osc_admin_definition($rows); ?>
            <?php osc_admin_panel_close(); ?>

            <?php if ($documents !== array()) { ?>
                <?php osc_admin_panel_open(__('Documents')); ?>
                    <div class="table-responsive">
                        <table class="table order-documents osc-table-stack">
                            <thead>
                            <tr>
                                <th scope="col"><?php _e('Number'); ?></th>
                                <th scope="col"><?php _e('Date'); ?></th>
                                <th scope="col" class="col-numeric"><?php _e('Total'); ?></th>
                                <th scope="col"><span class="visually-hidden"><?php _e('View'); ?></span></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($documents as $doc) {
                                $credit = $doc->isCreditNote(); ?>
                                <tr>
                                    <td data-col-name="<?php echo osc_esc_html(__('Number')); ?>">
                                        <?php echo osc_esc_html($doc->number); ?>
                                        <?php if ($credit) { ?><span class="text-muted">· <?php _e('Credit note'); ?></span><?php } ?>
                                    </td>
                                    <td data-col-name="<?php echo osc_esc_html(__('Date')); ?>"><?php echo osc_admin_date($doc->date, true); ?></td>
                                    <td class="col-numeric" data-col-name="<?php echo osc_esc_html(__('Total')); ?>"><?php echo osc_esc_html(DocumentPage::sign($doc) . osc_admin_money($doc->total, $doc->currency)); ?></td>
                                    <td><a href="<?php echo osc_esc_html(DocumentPage::adminUrl($doc)); ?>" target="_blank" rel="noopener"><?php _e('View'); ?></a></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php $publishFailed = $order->meta(OrderStore::PUBLISH_FAILED);
            if ($listingId !== null && is_array($publishFailed)) { ?>
                <div class="callout-warning callout-block mb-3">
                    <div>
                        <?php if (OrderStore::listingState($listingId) === 'live') {
                            echo osc_esc_html(sprintf(__('Paid, but the listing could not be published (%1$s, %2$s). It was published later.'), ListingCheckout::failureReason((string) ($publishFailed['reason'] ?? '')), (string) ($publishFailed['at'] ?? '')));
                        } else {
                            echo osc_esc_html(sprintf(__('Paid, but the listing could not be published: %1$s (%2$s). The credits are in the buyer\'s wallet.'), ListingCheckout::failureReason((string) ($publishFailed['reason'] ?? '')), (string) ($publishFailed['at'] ?? '')));
                        } ?>
                    </div>
                </div>
            <?php } ?>

            <?php if ($isGoods) { ?>
                <?php if ($short !== array()) { ?>
                    <div class="callout-warning callout-block mb-3">
                        <div>
                            <span class="osc-status status-failed"><?php _e('Oversold'); ?></span>
                            <?php _e('Stock ran out after this order was paid. Short:'); ?>
                            <?php
                            $shortWords = array();
                            foreach ($lines as $line) {
                                if (isset($short[$line['id']])) {
                                    $shortWords[] = sprintf(__('%1$s (%2$d short)'), $line['title'], (int) $short[$line['id']]);
                                }
                            }
                            echo osc_esc_html(implode(', ', $shortWords));
                            ?>
                        </div>
                    </div>
                <?php } ?>
                <?php osc_admin_panel_open(__('Items')); ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col"><?php _e('Item'); ?></th>
                                <th scope="col"><?php _e('SKU'); ?></th>
                                <th scope="col" class="col-numeric"><?php _e('Qty'); ?></th>
                                <th scope="col" class="col-numeric"><?php _e('Unit'); ?></th>
                                <?php if ($lineTaxes !== array()) { ?>
                                    <th scope="col" class="col-numeric"><?php _e('Tax'); ?></th>
                                <?php } ?>
                                <th scope="col" class="col-numeric"><?php _e('Amount'); ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($lines as $line) {
                                $isItem  = $line['kind'] === 'item';
                                $options = array();
                                foreach ((array) $line['options'] as $name => $value) {
                                    $options[] = $name . ': ' . $value;
                                } ?>
                                <tr>
                                    <td>
                                        <?php if ($isItem && $line['item_id'] !== null) { ?>
                                            <a href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=items&action=item_edit&id=' . (int) $line['item_id']); ?>"><?php echo osc_esc_html($line['title']); ?></a>
                                        <?php } else { ?>
                                            <?php echo osc_esc_html($line['title']); ?>
                                        <?php } ?>
                                        <?php if ($options !== array()) { ?>
                                            <br><span class="text-muted"><?php echo osc_esc_html(implode(', ', $options)); ?></span>
                                        <?php } ?>
                                        <?php if (($lineTaxes[(int) $line['id']]['code'] ?? '') !== '') { ?>
                                            <br><span class="text-muted"><?php echo osc_esc_html(__('Tax code') . ' ' . $lineTaxes[(int) $line['id']]['code']); ?></span>
                                        <?php } ?>
                                    </td>
                                    <td><?php echo osc_esc_html((string) $line['sku']); ?></td>
                                    <td class="col-numeric"><?php echo $isItem ? (int) $line['qty'] : ''; ?></td>
                                    <td class="col-numeric"><?php echo $isItem ? osc_esc_html(osc_admin_money($line['unit'], $order->getCurrency())) : ''; ?></td>
                                    <?php if ($lineTaxes !== array()) {
                                        $lt = $lineTaxes[(int) $line['id']] ?? null; ?>
                                        <td class="col-numeric"><?php if ($lt !== null) {
                                            echo osc_esc_html($lt['rate_text'] . ' · ' . ($lt['included'] ? sprintf(__('%s incl.'), $lt['amount_text']) : $lt['amount_text']));
                                        } ?></td>
                                    <?php } ?>
                                    <td class="col-numeric"><?php echo osc_esc_html($line['kind'] === 'tax' && ($line['meta']['included'] ?? false) === true
                                        ? sprintf(__('%s (included)'), osc_admin_money($line['unit'], $order->getCurrency()))
                                        : osc_admin_money($line['amount'], $order->getCurrency())); ?></td>
                                </tr>
                            <?php } ?>
                            <tr>
                                <th scope="row" colspan="<?php echo $lineTaxes !== array() ? 5 : 4; ?>"><?php _e('Total'); ?></th>
                                <td class="col-numeric"><strong><?php echo osc_esc_html(osc_admin_money($order->getAmount(), $order->getCurrency())); ?></strong></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                <?php osc_admin_panel_close(); ?>

                <?php osc_admin_panel_open(__('Delivery address and buyer')); ?>
                    <p class="mb-0">
                        <?php echo implode('<br>', array_map('osc_esc_html', Receipts::addressLines($address))); ?>
                    </p>
                    <p class="panel-subtitle mt-3 mb-0">
                        <?php echo osc_esc_html(trim((string) ($buyer['name'] ?? ''))); ?>
                        <?php if (($buyer['email'] ?? '') !== '') { ?>
                            · <a href="mailto:<?php echo osc_esc_html(rawurlencode((string) $buyer['email'])); ?>"><?php echo osc_esc_html((string) $buyer['email']); ?></a>
                        <?php } ?>
                        <?php if (($buyer['phone'] ?? '') !== '') { ?>
                            · <?php echo osc_esc_html((string) $buyer['phone']); ?>
                        <?php } ?>
                    </p>
                    <?php if ($buyerFields !== array()) { ?>
                        <p class="panel-subtitle mt-1 mb-0"><?php echo implode('<br>', array_map('osc_esc_html', $buyerFields)); ?></p>
                    <?php } ?>
                <?php osc_admin_panel_close(); ?>

                <?php if ($shipments !== array()) { ?>
                    <?php osc_admin_panel_open(__('Shipments')); ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th scope="col"><?php _e('Parcel'); ?></th>
                                    <th scope="col"><?php _e('Items'); ?></th>
                                    <th scope="col"><?php _e('Tracking'); ?></th>
                                    <th scope="col"><?php _e('Sent'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($shipments as $shipment) {
                                    $n        = (int) ($shipment['n'] ?? 0);
                                    $parcelUrl = Receipts::httpsUrl((string) ($shipment['url'] ?? ''));
                                    $carrierWord = trim((string) ($shipment['carrier'] ?? '') . ' ' . (string) ($shipment['tracking'] ?? ''));
                                    $words    = array();
                                    foreach ((array) ($shipment['lines'] ?? array()) as $sent) {
                                        $words[] = (int) ($sent['qty'] ?? 0) . ' × ' . ($lineTitles[(int) ($sent['line'] ?? 0)] ?? __('Item'));
                                    } ?>
                                    <tr>
                                        <td>#<?php echo $n; ?></td>
                                        <td><?php echo osc_esc_html(implode(', ', $words)); ?></td>
                                        <td>
                                            <?php if ($parcelUrl !== '') { ?>
                                                <a href="<?php echo osc_esc_html($parcelUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo osc_esc_html($carrierWord !== '' ? $carrierWord : __('Track parcel')); ?></a>
                                            <?php } else { ?>
                                                <?php echo osc_esc_html($carrierWord); ?>
                                            <?php } ?>
                                        </td>
                                        <td><?php echo osc_admin_date((string) ($shipment['at'] ?? ''), true); ?>
                                            <br><span class="text-muted"><?php echo osc_esc_html($actorWord($shipment)); ?></span></td>
                                    </tr>
                                    <?php if (in_array($order->getStatus(), array(Order::STATUS_PROCESSING, Order::STATUS_SHIPPED), true)) { ?>
                                    <tr>
                                        <td colspan="4">
                                            <details>
                                                <summary><?php echo osc_esc_html(sprintf(__('Edit tracking of parcel %d'), $n)); ?></summary>
                                                <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>" class="mt-3">
                                                    <input type="hidden" name="page" value="billing"/>
                                                    <input type="hidden" name="action" value="order_shipment_tracking"/>
                                                    <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                                                    <input type="hidden" name="n" value="<?php echo $n; ?>"/>
                                                    <?php $trackingFields('parcel' . $n, ($errors !== array() && (int) \Params::getParamInt('n') === $n ? $typed : array()) + array(
                                                        'carrier'     => (string) ($shipment['carrier'] ?? ''),
                                                        'tracking'    => (string) ($shipment['tracking'] ?? ''),
                                                        'trackingUrl' => (string) ($shipment['url'] ?? ''),
                                                    ), $errors !== array() && (int) \Params::getParamInt('n') === $n); ?>
                                                    <button type="submit" class="btn btn-submit"><?php _e('Save tracking'); ?></button>
                                                </form>
                                            </details>
                                        </td>
                                    </tr>
                                    <?php } ?>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php osc_admin_panel_close(); ?>
                <?php } ?>

                <?php osc_admin_panel_open(__('History')); ?>
                    <?php if ($history === array()) { ?>
                        <p class="panel-subtitle mb-0"><?php _e('No history kept before 7.1'); ?></p>
                    <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th scope="col"><?php _e('Date'); ?></th>
                                    <th scope="col"><?php _e('Change'); ?></th>
                                    <th scope="col"><?php _e('By'); ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach (array_reverse($history) as $entry) { ?>
                                    <tr>
                                        <td><?php echo osc_admin_date((string) ($entry['at'] ?? ''), true); ?></td>
                                        <td><?php echo osc_esc_html(sprintf(
                                            __('%1$s to %2$s'),
                                            $statusWords[$entry['from'] ?? ''] ?? (string) ($entry['from'] ?? ''),
                                            $statusWords[$entry['to'] ?? ''] ?? (string) ($entry['to'] ?? '')
                                        )); ?></td>
                                        <td><?php echo osc_esc_html($actorWord($entry)); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } ?>
                <?php osc_admin_panel_close(); ?>
            <?php } else { ?>
            <?php osc_admin_form_section(__('Credit movements')); ?>
            <?php } ?>
            <?php if ($isGoods) {
                // A goods order mints no credits, so there is no ledger to show.
            } elseif (empty($entries)) {
                osc_admin_empty(array(
                    'icon'  => 'bi-arrow-left-right',
                    'title' => __('No credits moved yet'),
                    'text'  => __('Credits are added the moment this order is paid.'),
                ));
            } else { ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col"><?php _e('What happened'); ?></th>
                            <th scope="col" class="col-numeric"><?php _e('Change'); ?></th>
                            <th scope="col" class="col-numeric"><?php _e('Balance after'); ?></th>
                            <th scope="col"><?php _e('Date'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $entry) {
                            $amount = (int)$entry['i_amount']; ?>
                            <tr>
                                <td><?php echo osc_esc_html(
                                    $reasonWords[$entry['s_reason']] ?? $entry['s_reason']
                                ); ?></td>
                                <td class="col-numeric">
                                    <?php echo osc_esc_html(($amount > 0 ? '+' : '') . number_format($amount)); ?>
                                </td>
                                <td class="col-numeric"><?php echo number_format((int)$entry['i_balance_after']); ?></td>
                                <td><?php echo osc_admin_date($entry['dt_date'], true); ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } ?>
        </div>

        <div>
            <?php if ($carriers !== array()) {
                echo '<datalist id="order-carriers">';
                foreach ($carriers as $carrierName) {
                    echo '<option value="' . osc_esc_html((string) $carrierName) . '"></option>';
                }
                echo '</datalist>';
            } ?>
            <?php if ($order->canProcess()) { ?>
                <?php osc_admin_panel_open(__('Processing'), array('class' => 'billing-detail-next')); ?>
                    <p class="panel-subtitle"><?php _e('Start processing when you begin to pick and pack this order.'); ?></p>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_processing"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <button type="submit" class="btn btn-submit"><?php _e('Start processing'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($order->canShip()) { ?>
                <?php osc_admin_panel_open($shipments === array() ? __('Ship this order') : __('Send another parcel'), array('class' => 'billing-detail-next')); ?>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>"
                          id="order-ship-form" data-osc-confirm-empty="#order-ship-dialog">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_shipment"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <?php if ($itemLines !== array()) { ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                    <tr>
                                        <th scope="col"><?php _e('Item'); ?></th>
                                        <th scope="col" class="col-numeric"><?php _e('Sent'); ?></th>
                                        <th scope="col" class="col-numeric"><?php _e('Send now'); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($itemLines as $line) {
                                        $sentQty = (int) ($shippedQty[(int) $line['id']] ?? 0);
                                        $open    = max(0, (int) $line['qty'] - $sentQty); ?>
                                        <tr>
                                            <td><?php echo osc_esc_html((string) $line['title']); ?></td>
                                            <td class="col-numeric text-nowrap"><?php echo osc_esc_html(sprintf(__('%1$d of %2$d'), $sentQty, (int) $line['qty'])); ?></td>
                                            <td class="col-numeric">
                                                <input type="number" class="form-control" min="0" max="<?php echo $open; ?>"
                                                       name="qty[<?php echo (int) $line['id']; ?>]" value="<?php echo $open; ?>"
                                                       aria-label="<?php echo osc_esc_html(sprintf(__('Quantity of %s to send now'), (string) $line['title'])); ?>"<?php echo $open === 0 ? ' disabled' : ''; ?>/>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } ?>
                        <?php $trackingFields('ship', $typed, (int) \Params::getParamInt('n') === 0); ?>
                        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html($shipments === array() ? __('Mark shipped') : __('Send parcel')); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>

                <?php osc_admin_panel_open(__('Packed')); ?>
                    <p class="panel-subtitle"><?php echo osc_esc_html($order->packed() !== null
                        ? __('This order is marked packed. The buyer gets no mail about it.')
                        : __('A label for staff while you pack. The buyer gets no mail, but the API shows when it was packed.')); ?></p>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_packed"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <input type="hidden" name="packed" value="<?php echo $order->packed() !== null ? 0 : 1; ?>"/>
                        <button type="submit" class="btn btn-dim"><?php echo osc_esc_html($order->packed() !== null ? __('Clear packed mark') : __('Mark packed')); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($isGoods && $order->getStatus() === Order::STATUS_SHIPPED) { ?>
                <?php osc_admin_panel_open(__('Delivery'), array('class' => 'billing-detail-next')); ?>
                    <p class="panel-subtitle"><?php echo osc_esc_html($order->awaitsCash()
                        ? __('Mark this delivered once the courier has collected the cash. That also marks it paid.')
                        : __('Mark this delivered once the parcel has arrived.')); ?></p>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_delivered"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <button type="submit" class="btn btn-submit"><?php _e('Mark delivered'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($isGoods && $order->getStatus() === Order::STATUS_SHIPPED && $shipments === array()) { ?>
                <?php osc_admin_panel_open(__('Edit tracking')); ?>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_tracking"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <?php $trackingFields('track', $typed + array(
                            'carrier'     => (string) ($fulfil['carrier'] ?? ''),
                            'tracking'    => (string) ($fulfil['tracking'] ?? ''),
                            'trackingUrl' => (string) ($fulfil['tracking_url'] ?? ''),
                        )); ?>
                        <button type="submit" class="btn btn-submit"><?php _e('Save tracking'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($order->isPending()) { ?>
                <?php osc_admin_panel_open(__('Settle this order')); ?>
                    <p class="panel-subtitle">
                        <?php if ($isGoods) {
                            _e('Mark this paid when the money has arrived: a bank transfer, or cash on delivery.');
                        } else {
                            printf(
                                osc_esc_html(__('Marking this paid adds %s credits to the account straight away. '
                                                . 'Do it when the money has arrived but the payment method could not '
                                                . 'tell us — a bank transfer, or a callback that never came through.')),
                                number_format($order->getCredits())
                            );
                        } ?>
                    </p>
                    <?php if ($gatewayWarning !== '') { ?>
                        <div class="callout-warning callout-block mb-3">
                            <div>
                                <?php echo osc_esc_html($gatewayWarning . ' ' . __('The payment may still come through '
                                    . 'once it is back. Check the provider before you mark this paid by hand.')); ?>
                                <a href="<?php echo osc_esc_html($pluginsUrl); ?>"><?php _e('Open Plugins'); ?></a>
                            </div>
                        </div>
                    <?php } ?>
                    <form method="post" action="<?php echo osc_esc_html($actionUrl); ?>">
                        <input type="hidden" name="page" value="billing"/>
                        <input type="hidden" name="action" value="order_paid"/>
                        <input type="hidden" name="id" value="<?php echo (int)$order->getId(); ?>"/>
                        <button type="submit" class="btn btn-submit"><?php _e('Mark as paid'); ?></button>
                    </form>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($canCancel) { ?>
                <?php osc_admin_panel_open(__('Cancel this order')); ?>
                    <p class="panel-subtitle"><?php echo osc_esc_html($isGoods
                        ? __('Close an order that will not be paid. Stock held for it goes back on sale.')
                        : __('Close an order that will not be paid.')); ?></p>
                    <button type="button" class="btn btn-dim" data-osc-dialog-open="#order-cancel-dialog"><?php _e('Cancel order'); ?></button>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php osc_admin_panel_open(__('About this order')); ?>
                <p class="panel-subtitle mb-0">
                    <?php _e('An order records one attempt to pay. Shopclass stores what was bought and what '
                             . 'happened to it; the payment plugin handles the money itself and never gives '
                             . 'Shopclass the card details.'); ?>
                </p>
            <?php osc_admin_panel_close(); ?>

            <?php /* Last in the column, and the only red on the screen. A refund is the one
                     action here that takes something away, so it does not get to be the first
                     thing an admin's eye lands on. */ ?>
            <?php if ($canRefundPart) { ?>
                <?php osc_admin_panel_open(__('Refund part')); ?>
                    <p class="panel-subtitle">
                        <?php echo osc_esc_html(sprintf(
                            __('Refund an amount or some of the units. A credit note is made. Up to %s is left to refund.'),
                            osc_admin_money($refundLeft, $order->getCurrency())
                        )); ?>
                    </p>
                    <button type="button" class="btn btn-dim"
                            data-osc-dialog-open="#order-refund-part-dialog"><?php _e('Refund part'); ?></button>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>

            <?php if ($order->isPaid()) { ?>
                <?php osc_admin_panel_open(__('Refund')); ?>
                    <?php if ($refundable) { ?>
                        <p class="panel-subtitle">
                            <?php printf(
                                osc_esc_html($isGoods
                                    ? __('Refund this payment through %s. The buyer gets the money back.')
                                    : __('Refund this payment through %s. The buyer gets the money back '
                                         . 'and the credits are taken back.')),
                                osc_esc_html($gateway->getName())
                            ); ?>
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-danger"
                                    data-osc-dialog-open="#order-refund-gateway-dialog"><?php _e('Refund'); ?></button>
                            <button type="button" class="btn btn-dim"
                                    data-osc-dialog-open="#order-refund-dialog"><?php _e('Record a refund'); ?></button>
                        </div>
                        <p class="panel-subtitle mt-3 mb-0">
                            <?php _e('Already refunded at the provider? Record it instead, so nothing is sent twice.'); ?>
                        </p>
                    <?php } else { ?>
                        <?php if ($gatewayWarning !== '') { ?>
                            <div class="callout-warning callout-block mb-3">
                                <div>
                                    <?php echo osc_esc_html($gatewayWarning . ' ' . __('This order cannot be refunded '
                                        . 'from here. Turn the plugin on, or refund it in the provider\'s dashboard '
                                        . 'and then use Record a refund.')); ?>
                                    <a href="<?php echo osc_esc_html($pluginsUrl); ?>"><?php _e('Open Plugins'); ?></a>
                                </div>
                            </div>
                        <?php } ?>
                        <?php if ($refundSent) { ?>
                            <p class="panel-subtitle">
                                <?php printf(
                                    osc_esc_html(__('A refund was sent to the payment provider on %s, but it was not '
                                                    . 'recorded here. Check the provider\'s dashboard, then record it.')),
                                    osc_esc_html((string) $order->meta(OrderStore::REFUND_REQUESTED))
                                ); ?>
                            </p>
                        <?php } ?>
                        <p class="panel-subtitle">
                            <?php _e('Record a refund you have already made through your payment provider. '
                                     . 'Shopclass never asks the provider for the money back — it only writes down '
                                     . 'that you did.'); ?>
                        </p>
                        <button type="button" class="btn btn-danger"
                                data-osc-dialog-open="#order-refund-dialog"><?php _e('Record a refund'); ?></button>
                    <?php } ?>
                <?php osc_admin_panel_close(); ?>
            <?php } ?>
        </div>
    </div>

    <?php if ($refundable) {
        osc_admin_confirm_dialog(array(
            'id'      => 'order-refund-gateway-dialog',
            'url'     => $actionUrl,
            'fields'  => array(
                'page'   => 'billing',
                'action' => 'order_refund_gateway',
                'id'     => (int) $order->getId(),
            ),
            'title'   => sprintf(__('Refund order #%d?'), $order->getId()),
            'text'    => $isGoods
                ? sprintf(
                    __('%1$s sends %2$s back to %3$s. This cannot be undone.'),
                    $gateway->getName(),
                    osc_admin_money($order->getAmount(), $order->getCurrency()),
                    $userName
                )
                : sprintf(
                    __('%1$s sends %2$s back to the buyer, and %3$s credits are taken back from %4$s. '
                       . 'This cannot be undone.'),
                    $gateway->getName(),
                    osc_admin_money($order->getAmount(), $order->getCurrency()),
                    number_format($order->getCredits()),
                    $userName
                ),
            'confirm' => __('Refund'),
        ));
    }
    if ($canRefundPart) {
        $decimals = Money::decimals($order->getCurrency());
        ob_start(); ?>
        <div class="order-refund-part-fields">
            <?php osc_admin_text(array(
                'id'    => 'refund-part-amount',
                'name'  => 'amount',
                'label' => sprintf(__('Amount (%s)'), $order->getCurrency()),
                'attrs' => array('inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => Money::fromMicros($refundLeft, $decimals)),
                'help'  => $lineTaxes !== array()
                    ? __('Leave empty to refund the chosen units with their own tax.')
                    : __('Leave empty to refund the chosen units with their share of tax.'),
            )); ?>
            <?php foreach ($refundRows as $row) {
                osc_admin_number(array(
                    'id'    => 'refund-part-qty-' . $row['id'],
                    'name'  => 'qty[' . $row['id'] . ']',
                    'label' => sprintf(__('%1$s, units (up to %2$d)'), $row['title'], $row['open']),
                    'attrs' => array('min' => 0, 'max' => $row['open'], 'step' => 1, 'placeholder' => '0'),
                ));
            } ?>
            <?php osc_admin_text(array(
                'id'    => 'refund-part-reason',
                'name'  => 'reason',
                'label' => __('Reason'),
                'attrs' => array('maxlength' => 200),
                'help'  => __('Printed on the credit note.'),
            )); ?>
            <?php osc_admin_checkbox(array(
                'id'      => 'refund-part-restock',
                'name'    => 'restock',
                'label'   => __('Put the units back in stock'),
                'checked' => $shippedQty === array(),
                'help'    => __('Off for units that were shipped and are not coming back.'),
            )); ?>
            <?php if ($partGateway !== null) {
                osc_admin_checkbox(array(
                    'id'      => 'refund-part-via',
                    'name'    => 'via',
                    'label'   => sprintf(__('Send the money back through %s'), $partGateway->getName()),
                    'checked' => true,
                    'help'    => __('Off to only record a refund you have already made.'),
                ));
            } else { ?>
                <p class="panel-subtitle mb-0"><?php _e('Nothing is sent to the payment provider. Refund the money there, this records it.'); ?></p>
            <?php } ?>
        </div>
        <?php
        osc_admin_confirm_dialog(array(
            'id'        => 'order-refund-part-dialog',
            'url'       => $actionUrl,
            'fields'    => array(
                'page'   => 'billing',
                'action' => 'order_refund_part',
                'id'     => (int) $order->getId(),
            ),
            'title'     => sprintf(__('Refund part of order #%d'), $order->getId()),
            'text'      => __('This cannot be undone.'),
            'body_html' => (string) ob_get_clean(),
            'confirm'   => __('Refund part'),
        ));
    }
    if ($canCancel) {
        osc_admin_confirm_dialog(array(
            'id'      => 'order-cancel-dialog',
            'url'     => $actionUrl,
            'fields'  => array(
                'page'   => 'billing',
                'action' => 'order_cancel',
                'id'     => (int) $order->getId(),
            ),
            'title'   => sprintf(__('Cancel order #%d?'), $order->getId()),
            'text'    => __('The buyer is not charged. This cannot be undone.'),
            'confirm' => __('Cancel order'),
        ));
    }
    if ($order->canShip()) {
        osc_admin_confirm_dialog(array(
            'id'           => 'order-ship-dialog',
            'tone'         => 'neutral',
            'confirm_form' => 'order-ship-form',
            'title'        => sprintf(__('Ship order #%d without a tracking number?'), $order->getId()),
            'text'         => __('The buyer will see the order as shipped with no carrier or tracking. You can add them later.'),
            'confirm'      => __('Mark shipped'),
        ));
    }
    if ($order->isPaid()) {
        osc_admin_confirm_dialog(array(
            'id'      => 'order-refund-dialog',
            'url'     => $actionUrl,
            'fields'  => array(
                'page'   => 'billing',
                'action' => 'order_refund',
                'id'     => (int) $order->getId(),
            ),
            'title'   => sprintf(__('Record a refund for order #%d?'), $order->getId()),
            'text'    => $isGoods
                ? __('This records that the money was given back. Nothing is sent to the payment provider.')
                : sprintf(
                    __('This takes %1$s credits back from %2$s. If they have already spent '
                       . 'them their balance will go below zero, and they will not be able to '
                       . 'spend again until it is back up.'),
                    number_format($order->getCredits()),
                    $userName
                ),
            'confirm' => __('Record the refund'),
        ));
    } ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
