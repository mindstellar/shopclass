<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
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
use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\Document;
use mindstellar\billing\DocumentExport;
use mindstellar\billing\DocumentPage;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\event\CarriersListing;
use mindstellar\billing\Invoices;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PackageStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Receipts;
use mindstellar\billing\Shipments;
use mindstellar\billing\Wallet;
use mindstellar\boot\CoreServices;
use mindstellar\currency\Money;
use mindstellar\utility\Utils;

/**
 * The billing section: payment orders and user credit balances.
 *
 * Every screen here is read-mostly. The two things an admin can actually change are
 * settling an order by hand (the offline-payment path, and the escape hatch when a
 * gateway's callback never arrived) and adjusting a balance -- both of which move money,
 * so both go through Billing/Wallet rather than touching a table, and both are recorded
 * in the ledger under the admin's own reason code.
 *
 * The whole section is gated on the billing preference. A site that does not sell
 * anything never sees it.
 *
 * Class CAdminBilling
 */
class CAdminBilling extends AdminSecBaseModel
{
    /** Rows per page across the section. */
    private const PER_PAGE = 25;

    /**
     * Let plugins hook the billing section before anything is dispatched.
     */
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_billing');
    }

    //Business Layer...

    /**
     * Dispatch the requested billing action, after sending the request away when billing
     * is switched off.
     *
     * @return void
     */
    public function doModel()
    {
        parent::doModel();

        // Billing is off by default, and the menu entry is hidden while it is. Someone
        // arriving on a bookmarked or hand-typed URL is sent to the switch rather than
        // shown an empty section that looks broken.
        if (!osc_billing_enabled()) {
            osc_add_flash_warning_message(_m('Billing is switched off. Turn it on to use orders and credits.'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=billing');
        }

        switch ($this->action) {
            case ('order'):
                $this->orderView();
                break;
            case ('receipt'):
                $this->receiptView();
                break;
            case ('order_paid'):
                $this->orderPaidPost();
                break;
            case ('order_ship'):
                $this->orderShipPost();
                break;
            case ('order_processing'):
                $this->orderProcessingPost();
                break;
            case ('order_shipment'):
                $this->orderShipmentPost();
                break;
            case ('order_shipment_tracking'):
                $this->orderShipmentTrackingPost();
                break;
            case ('order_packed'):
                $this->orderPackedPost();
                break;
            case ('order_tracking'):
                $this->orderTrackingPost();
                break;
            case ('order_delivered'):
                $this->orderDeliveredPost();
                break;
            case ('order_cancel'):
                $this->orderCancelPost();
                break;
            case ('order_refund'):
                $this->orderRefundPost();
                break;
            case ('order_refund_gateway'):
                $this->orderRefundGatewayPost();
                break;
            case ('order_refund_part'):
                $this->orderRefundPartPost();
                break;
            case ('documents'):
                $this->documentsView();
                break;
            case ('document'):
                $this->documentView();
                break;
            case ('documents_csv'):
                $this->documentsCsv();
                break;
            case ('credits'):
                $this->creditsView();
                break;
            case ('wallet'):
                $this->walletView();
                break;
            case ('wallet_adjust'):
                $this->walletAdjustPost();
                break;
            case ('packages'):
                $this->packagesView();
                break;
            case ('package'):
                $this->packageView();
                break;
            case ('package_post'):
                $this->packagePost();
                break;
            case ('package_delete'):
                $this->packageDeletePost();
                break;
            default:
                $this->ordersView();
                break;
        }
    }

    /**
     * The orders list, filtered and paged.
     *
     * @return void
     */
    private function ordersView()
    {
        $filters = array(
            'status'  => $this->allowedStatus(Params::getParamString('status')),
            'gateway' => Params::getParamString('gateway'),
            'user_id' => Params::getParamInt('userId'),
            'q'       => mb_substr(trim(Params::getParamString('q')), 0, 100),
            'goods'   => false,
        );
        // "To ship": paid goods orders and unpaid cash on delivery ones, newest first.
        $toShip = osc_is_shop_mode() && Params::getParamInt('toShip') === 1;
        if ($toShip) {
            $filters = array('status' => null) + OrderStore::TO_SHIP + $filters;
        }

        $page   = ListPaging::page();
        $total  = OrderStore::searchCount($filters);
        $offset = ListPaging::start($page, self::PER_PAGE);

        $orders = OrderStore::search($filters, self::PER_PAGE, $offset);

        $this->_exportVariableToView('orders', $orders);
        $this->_exportVariableToView('users', $this->usersFor($orders));
        $this->_exportVariableToView('partlyRefunded', $this->partlyRefundedIn($orders));
        $this->_exportVariableToView('summary', OrderStore::summary($filters, $total));
        $this->_exportVariableToView('gateways', OrderStore::knownGateways());
        $this->_exportVariableToView('filters', $filters);
        $this->_exportVariableToView('toShip', $toShip);
        $this->_exportVariableToView('showDocuments', $this->showDocuments());
        $this->_exportVariableToView('total', $total);
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);

        $this->doView('billing/index.php');
    }

    /**
     * One order, its ledger entries, and whatever can still be done to it. A refused shipping
     * form draws it again with what was typed and the fields named.
     *
     * @param array<string,string> $typed  posted shipping values by field name
     * @param array<string,string> $errors message by field name
     *
     * @return void
     */
    private function orderView(array $typed = array(), array $errors = array())
    {
        $order = OrderStore::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        $gateway = PaymentGatewayRegistry::getInstance()->get($order->getGateway());

        $this->_exportVariableToView('typed', $typed);
        $this->_exportVariableToView('errors', $errors);
        $this->_exportVariableToView('order', $order);
        $this->_exportVariableToView('user', User::getInstance()->findByPrimaryKey($order->getUserId()));
        $this->_exportVariableToView('gateway', $gateway);
        $this->_exportVariableToView('refundable', Billing::refundableGateway($order) !== null);
        $this->_exportVariableToView('dashboardUrl', Billing::dashboardUrl($order));
        $this->_exportVariableToView('entries', $this->ledgerForOrder($order->getId()));
        $this->_exportVariableToView('balance', Wallet::balance($order->getUserId()));
        $this->_exportVariableToView('lines', $order->isGoods() ? OrderLineStore::ofOrder($order->getId()) : array());
        $this->_exportVariableToView('actors', $order->isGoods() ? $this->actorNames($order) : array());
        $this->exportDocuments($order);
        $this->_exportVariableToView('carriers', $order->isGoods() ? CoreServices::dispatcher()->dispatch(new CarriersListing())->names() : array());

        $this->doView('billing/order.php');
    }

    /**
     * The printable receipt for any paid or refunded order.
     *
     * @return void
     */
    private function receiptView()
    {
        $order = OrderStore::find(Params::getParamInt('id'));
        if ($order === null || !Receipts::canView($order, 0, true)) {
            osc_add_flash_error_message(_m('That order has no receipt'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        Receipts::render(
            $order,
            osc_admin_billing_order_url($order->getId()),
            _m('Back to the order')
        );
    }

    /**
     * Settle an order by hand.
     *
     * The offline path (bank transfer, cash) has no callback to wait for, and a gateway
     * whose webhook never arrived leaves an order stuck pending with the money already
     * taken. Both need a person to be able to say "this was paid".
     *
     * This is also the one path allowed to reopen a `failed` order -- a provider
     * retrying payment on the same order after an earlier failure is ordinary, and an
     * admin confirming it by hand is the deliberate act that makes it safe. Nothing
     * about a gateway callback ever grants that; Billing::markPaid()'s $allowFailed
     * is passed true only here.
     *
     * @return void
     */
    private function orderPaidPost()
    {
        osc_csrf_check();

        $order = OrderStore::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        $wasFailed = $order->getStatus() === Order::STATUS_FAILED;

        if (Billing::markPaid($order, $order->getExternalRef(), true)) {
            if ($order->getCredits() === 0) {
                $message = sprintf(_m('Order #%d is marked paid'), $order->getId());
            } elseif ($wasFailed) {
                $message = sprintf(_m('Order #%d was marked failed and is now marked paid; the credits have been added'), $order->getId());
            } else {
                $message = sprintf(_m('Order #%d is marked paid and the credits have been added'), $order->getId());
            }
            osc_add_flash_ok_message($message, 'admin');
        } else {
            // markPaid() is guarded on the order still being pending or failed, so
            // this is the benign double-submit rather than a failure.
            osc_add_flash_warning_message(_m('That order had already been settled'), 'admin');
        }

        $this->redirectTo(osc_admin_billing_order_url($order->getId()));
    }

    /**
     * Mark a paid goods order, or an unpaid cash on delivery one, shipped, with the carrier and
     * tracking the buyer is shown.
     * The tracking address has to be https.
     *
     * @return void
     */
    private function orderShipPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        $back     = $this->orderUrl($order);
        $typed = $this->trackingInput();
        if ($typed === null) {
            return;
        }
        ['carrier' => $carrier, 'tracking' => $tracking, 'trackingUrl' => $url] = $typed;

        if (OrderStore::ship($order->getId(), $carrier, $tracking, $url)) {
            osc_add_flash_ok_message(sprintf(_m('Order #%d is marked shipped'), $order->getId()), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Only a paid order, or an unpaid cash on delivery one, can be marked shipped'), 'admin');
        }

        $this->redirectTo($back);
    }

    /**
     * Correct the tracking of a shipped order. The status does not move.
     *
     * @return void
     */
    private function orderTrackingPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        $typed = $this->trackingInput();
        if ($typed === null) {
            return;
        }
        ['carrier' => $carrier, 'tracking' => $tracking, 'trackingUrl' => $url] = $typed;

        if (OrderStore::setTracking($order->getId(), $carrier, $tracking, $url)) {
            osc_add_flash_ok_message(sprintf(_m('Tracking of order #%d is updated'), $order->getId()), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Only a shipped order can have its tracking changed'), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * Start processing a paid goods order, or a cash on delivery one that is still unpaid.
     *
     * @return void
     */
    private function orderProcessingPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        if (OrderStore::process($order->getId())) {
            osc_add_flash_ok_message(sprintf(_m('Order #%d is being processed'), $order->getId()), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Only a paid order, or an unpaid cash on delivery one, can start processing'), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * Send a parcel of the quantities posted for each item line. The order is shipped when
     * nothing is left open, and is processing until then.
     *
     * @return void
     */
    private function orderShipmentPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        $typed = $this->trackingInput();
        if ($typed === null) {
            return;
        }
        ['carrier' => $carrier, 'tracking' => $tracking, 'trackingUrl' => $url] = $typed;

        $lines = null;
        if (OrderLineStore::itemLines($order->getId()) !== array()) {
            $lines = array();
            foreach (Params::getParamArray('qty') as $lineId => $qty) {
                if (is_scalar($qty) && (int) $qty > 0) {
                    $lines[(int) $lineId] = (int) $qty;
                }
            }
            if ($lines === array()) {
                osc_add_flash_warning_message(_m('Choose how many of at least one item to ship'), 'admin');
                $this->redirectTo($this->orderUrl($order));

                return;
            }
        }

        $n = Shipments::add($order->getId(), $lines, $carrier, $tracking, $url);
        if ($n === null) {
            osc_add_flash_warning_message(_m('That parcel could not be sent. The order may have changed, or a quantity is more than is left to ship'), 'admin');
        } elseif (OrderStore::find($order->getId())?->getStatus() === Order::STATUS_SHIPPED) {
            osc_add_flash_ok_message(sprintf(_m('Order #%d is marked shipped'), $order->getId()), 'admin');
        } else {
            osc_add_flash_ok_message(sprintf(_m('Parcel %1$d of order #%2$d is sent'), $n, $order->getId()), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * Correct the carrier and tracking of one parcel.
     *
     * @return void
     */
    private function orderShipmentTrackingPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        $typed = $this->trackingInput();
        if ($typed === null) {
            return;
        }
        ['carrier' => $carrier, 'tracking' => $tracking, 'trackingUrl' => $url] = $typed;

        if (Shipments::setTracking($order->getId(), Params::getParamInt('n'), $carrier, $tracking, $url)) {
            osc_add_flash_ok_message(sprintf(_m('Tracking of order #%d is updated'), $order->getId()), 'admin');
        } else {
            osc_add_flash_warning_message(_m('That parcel could not be found'), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * Mark an order packed, or clear the mark. A label for staff; the buyer sees nothing.
     *
     * @return void
     */
    private function orderPackedPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        $on = Params::getParamInt('packed') === 1;
        if (Shipments::markPacked($order->getId(), $on)) {
            osc_add_flash_ok_message($on ? _m('The order is marked packed') : _m('The packed mark is removed'), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Only an order that can ship can be marked packed'), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * Who is named in the order's history and parcels, as "admin:4" => "Name".
     *
     * @return array<string,string>
     */
    private function actorNames(Order $order): array
    {
        $names = array();
        $rows  = array_merge($order->history(), $order->shipments(), array($order->packed() ?? array()));
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (($row['by'] ?? '') === 'admin' && $id > 0 && !isset($names['admin:' . $id])) {
                $admin = Admin::getInstance()->findByPrimaryKey($id);
                $name  = is_array($admin) ? trim((string) ($admin['s_name'] ?? '')) : '';
                if ($name === '' && is_array($admin)) {
                    $name = (string) ($admin['s_username'] ?? '');
                }
                $names['admin:' . $id] = $name !== '' ? $name : sprintf(_m('Admin #%d'), $id);
            }
        }

        return $names;
    }

    /**
     * The carrier, tracking and tracking link the ship and edit-tracking forms post. A link that
     * is not https redraws the order with the typed values and null comes back.
     *
     * @return array{carrier:string,tracking:string,trackingUrl:string}|null
     */
    private function trackingInput(): ?array
    {
        $typed = array(
            'carrier'     => Utils::oneLine(Params::getParamString('carrier')),
            'tracking'    => Utils::oneLine(Params::getParamString('tracking')),
            'trackingUrl' => Utils::oneLine(Params::getParamString('trackingUrl')),
        );
        if ($typed['trackingUrl'] !== '' && Receipts::httpsUrl($typed['trackingUrl']) === '') {
            $this->orderView($typed, array('trackingUrl' => _m('The tracking link has to be a full https:// address')));

            return null;
        }

        return $typed;
    }

    /**
     * Mark a shipped goods order delivered.
     *
     * @return void
     */
    private function orderDeliveredPost()
    {
        osc_csrf_check();

        $order = $this->goodsOrder();
        if ($order === null) {
            return;
        }
        if (Billing::deliver($order)) {
            osc_add_flash_ok_message(sprintf(_m('Order #%d is marked delivered'), $order->getId()), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Only a shipped order can be marked delivered'), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * Cancel an order that is still unpaid, of any kind.
     *
     * @return void
     */
    private function orderCancelPost()
    {
        osc_csrf_check();

        $order = OrderStore::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');

            return;
        }
        if (OrderStore::cancel($order->getId())) {
            osc_add_flash_ok_message(sprintf(_m('Order #%d is cancelled'), $order->getId()), 'admin');
        } else {
            osc_add_flash_warning_message(_m('Only an order that is not paid yet can be cancelled'), 'admin');
        }

        $this->redirectTo($this->orderUrl($order));
    }

    /**
     * The goods order the form names, or a redirect to the list when there is none.
     *
     * @return Order|null
     */
    private function goodsOrder(): ?Order
    {
        $order = OrderStore::find(Params::getParamInt('id'));
        if ($order === null || !$order->isGoods()) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');

            return null;
        }

        return $order;
    }

    private function orderUrl(Order $order): string
    {
        return osc_admin_billing_order_url($order->getId());
    }

    /**
     * Record a refund the provider has already made, without asking the provider.
     *
     * @return void
     */
    private function orderRefundPost()
    {
        osc_csrf_check();

        $order = OrderStore::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        if (Billing::refund($order)) {
            osc_add_flash_ok_message(
                sprintf(
                    $order->isGoods()
                        ? _m('Order #%d is recorded as refunded.')
                        : _m('Order #%d is recorded as refunded and the credits have been taken back'),
                    $order->getId()
                ),
                'admin'
            );
        } else {
            osc_add_flash_warning_message(_m('Only a paid order can be refunded'), 'admin');
        }

        $this->redirectTo(osc_admin_billing_order_url($order->getId()));
    }

    /**
     * Ask the order's gateway to refund it. Billing re-reads the order and re-checks the
     * gateway, so nothing from the form is trusted beyond the order id. Billing writes the
     * log rows. POST only: osc_csrf_check() also accepts a token in the query string.
     *
     * @return void
     */
    private function orderRefundGatewayPost()
    {
        $id   = Params::getParamInt('id');
        $back = osc_admin_billing_order_url($id);

        if (Params::getServerParam('REQUEST_METHOD') !== 'POST') {
            $this->redirectTo($back);
        }
        osc_csrf_check();

        $order = OrderStore::find($id);
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        $accepted = false;
        $result   = Billing::refundThroughGateway($order, $accepted);

        if ($result->getOutcome() === CallbackResult::OUTCOME_REFUNDED) {
            osc_add_flash_ok_message(
                sprintf(
                    $order->isGoods()
                        ? _m('Order #%d is refunded.')
                        : _m('Order #%d is refunded and the credits have been taken back'),
                    $order->getId()
                ),
                'admin'
            );
        } elseif ($result->getOutcome() === CallbackResult::OUTCOME_PENDING) {
            $note = $result->getMessage() !== ''
                ? $result->getMessage()
                : _m('The order changes to refunded when the payment provider finishes it.');
            osc_add_flash_info_message(
                sprintf(_m('The refund was sent. %s'), osc_esc_html($note)),
                'admin'
            );
        } elseif ($accepted) {
            osc_add_flash_warning_message(osc_esc_html($result->getMessage()), 'admin');
        } else {
            $reason = $result->getMessage() !== '' ? $result->getMessage() : _m('No reason was given');
            osc_add_flash_error_message(
                sprintf(_m('The refund was not made: %s'), osc_esc_html($reason)),
                'admin'
            );
        }

        $this->redirectTo($back);
    }

    /**
     * What the order page needs for documents and part refunds: the documents, what is left to
     * refund, and for each item line the units not yet refunded.
     */
    private function exportDocuments(Order $order): void
    {
        $id      = $order->getId();
        $docs    = DocumentStore::forOrder($id);
        $invoice = DocumentStore::invoiceOf($id);
        $left    = $invoice === null ? 0 : max(0, $invoice->total - DocumentStore::refundedTotal($id));
        $rows    = array();
        if ($order->isGoods() && $invoice !== null) {
            $refunded = DocumentStore::refundedQty($id);
            foreach (OrderLineStore::itemLines($id) as $line) {
                $open = (int) $line['qty'] - ($refunded[$line['id']] ?? 0);
                if ($open > 0) {
                    $rows[] = array('id' => (int) $line['id'], 'title' => (string) $line['title'], 'open' => $open);
                }
            }
        }
        $this->_exportVariableToView('documents', $docs);
        $this->_exportVariableToView('refundLeft', $left);
        $this->_exportVariableToView('refundRows', $rows);
        $this->_exportVariableToView('partlyRefunded', $order->isPaid() && $order->getStatus() !== Order::STATUS_REFUNDED && DocumentStore::refundedTotal($id) > 0);
        $this->_exportVariableToView('partGateway', Billing::partRefundableGateway($order));
    }

    /**
     * Refund part of a paid goods order: an amount, units of its lines, or both.
     *
     * @return void
     */
    private function orderRefundPartPost()
    {
        $id   = Params::getParamInt('id');
        $back = osc_admin_billing_order_url($id);

        if (Params::getServerParam('REQUEST_METHOD') !== 'POST') {
            $this->redirectTo($back);
        }
        osc_csrf_check();

        $order = OrderStore::find($id);
        if ($order === null || !$order->isGoods()) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        $amount = 0;
        $typed  = Params::getParamString('amount');
        if (trim($typed) !== '') {
            $amount = Money::parse($typed, Money::decimals($order->getCurrency()));
            if ($amount === null || $amount < 1) {
                osc_add_flash_error_message(_m('Enter the amount as a number above zero'), 'admin');
                $this->redirectTo($back);
            }
        }
        $lines = array();
        foreach (Params::getParamArray('qty') as $lineId => $qty) {
            if ((int) $lineId > 0 && is_numeric($qty) && (int) $qty > 0) {
                $lines[(int) $lineId] = (int) $qty;
            }
        }
        $reason = Receipts::plain(Params::getParamString('reason'), 200);

        $result = Billing::refundPart(
            $order,
            (int) $amount,
            $lines,
            $reason,
            Params::getParamInt('restock') === 1,
            Params::getParamInt('via') === 1
        );

        if ($result->getOutcome() === CallbackResult::OUTCOME_REFUNDED) {
            osc_add_flash_ok_message(sprintf(_m('Part of order #%d is refunded.'), $order->getId()), 'admin');
        } elseif ($result->getOutcome() === CallbackResult::OUTCOME_PENDING) {
            osc_add_flash_info_message(
                sprintf(_m('The refund was sent. %s'), osc_esc_html($result->getMessage() !== '' ? $result->getMessage() : _m('The credit note is made.'))),
                'admin'
            );
        } else {
            $reasonText = $result->getMessage() !== '' ? $result->getMessage() : _m('No reason was given');
            osc_add_flash_error_message(sprintf(_m('The refund was not made: %s'), osc_esc_html($reasonText)), 'admin');
        }

        $this->redirectTo($back);
    }

    /**
     * Whether the Invoices tab shows: invoices are on, or some were issued before they were turned off.
     */
    private function showDocuments(): bool
    {
        return Invoices::enabled() || DocumentStore::count(array()) > 0;
    }

    /**
     * The filters of the Invoices list, read from the request.
     *
     * @return array{type:string,from:string,to:string}
     */
    private function documentFilters(): array
    {
        $day  = static fn (string $v): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : '';
        $type = Params::getParamString('type');

        return array(
            'type' => in_array($type, Document::TYPES, true) ? $type : '',
            'from' => $day(Params::getParamString('from')),
            'to'   => $day(Params::getParamString('to')),
        );
    }

    /**
     * Invoices and credit notes, newest first, with a date range and a type filter.
     *
     * @return void
     */
    private function documentsView()
    {
        $filters = $this->documentFilters();
        $page    = ListPaging::page();
        $docs    = DocumentStore::search($filters, self::PER_PAGE, ListPaging::start($page, self::PER_PAGE));

        $this->_exportVariableToView('documents', $docs);
        $this->_exportVariableToView('showDocuments', true);
        $this->_exportVariableToView('filters', $filters);
        $this->_exportVariableToView('total', DocumentStore::count($filters));
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);

        $this->doView('billing/documents.php');
    }

    /**
     * The printable page of one invoice or credit note.
     *
     * @return void
     */
    private function documentView()
    {
        $document = DocumentStore::find(Params::getParamInt('id'));
        if ($document === null) {
            osc_add_flash_error_message(_m('That document does not exist'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=documents');

            return;
        }

        DocumentPage::render($document, osc_admin_billing_order_url($document->orderId), _m('Back to the order'));
    }

    /**
     * The same list as a CSV file, every match (not one page).
     *
     * @return void
     */
    private function documentsCsv()
    {
        $this->sendCsv(DocumentExport::csv($this->documentFilters()), 'invoices-' . date('Y-m-d') . '.csv');
    }

    /**
     * Send a CSV file and end the request.
     */
    protected function sendCsv(string $csv, string $name): void
    {
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    /**
     * Every wallet holding credit.
     *
     * @return void
     */
    private function creditsView()
    {
        $page   = ListPaging::page();
        $total  = Wallet::balanceCount();
        $offset = ListPaging::start($page, self::PER_PAGE);

        $this->_exportVariableToView('wallets', Wallet::balances(self::PER_PAGE, $offset));
        $this->_exportVariableToView('outstanding', Wallet::totalOutstanding());
        $this->_exportVariableToView('total', $total);
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);

        $this->doView('billing/credits.php');
    }

    /**
     * One user's balance and the ledger behind it.
     *
     * @return void
     */
    private function walletView()
    {
        $userId = Params::getParamInt('userId');
        $user   = User::getInstance()->findByPrimaryKey($userId);
        if ($user === null) {
            osc_add_flash_error_message(_m('That user no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=credits');
        }

        $page   = ListPaging::page();
        $total  = Wallet::historyCount($userId);
        $offset = ListPaging::start($page, self::PER_PAGE);

        $this->_exportVariableToView('user', $user);
        $this->_exportVariableToView('balance', Wallet::balance($userId));
        $this->_exportVariableToView('entries', Wallet::history($userId, self::PER_PAGE, $offset));
        $this->_exportVariableToView('total', $total);
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);

        $this->doView('billing/wallet.php');
    }

    /**
     * Add or remove credit by hand.
     *
     * Writes through Wallet like everything else, so the change lands in the ledger with
     * a reason and is visible on the same screen that made it. There is no path here that
     * edits a balance without leaving a record.
     *
     * @return void
     * @throws \mindstellar\database\DbException when the ledger write fails
     */
    private function walletAdjustPost()
    {
        osc_csrf_check();

        $userId = Params::getParamInt('userId');
        $user   = User::getInstance()->findByPrimaryKey($userId);
        if ($user === null) {
            osc_add_flash_error_message(_m('That user no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=credits');
        }

        $back   = osc_admin_base_url(true) . '?page=billing&action=wallet&userId=' . $userId;
        $amount = Params::getParamInt('amount');
        $mode   = Params::getParamString('mode') === 'remove' ? 'remove' : 'add';

        if ($amount <= 0) {
            osc_add_flash_error_message(_m('Enter how many credits to add or remove'), 'admin');
            $this->redirectTo($back);
        }

        if ($mode === 'add') {
            Wallet::credit($userId, $amount, Wallet::REASON_GRANT, null, 'user', $userId);
            osc_add_flash_ok_message(
                sprintf(_m('%d credits added'), $amount),
                'admin'
            );
            $this->redirectTo($back);
        }

        if (!Wallet::debit($userId, $amount, Wallet::REASON_REVOKE, null, 'user', $userId)) {
            osc_add_flash_error_message(
                _m('That is more than the balance holds. Remove no more than the current balance.'),
                'admin'
            );
            $this->redirectTo($back);
        }

        osc_add_flash_ok_message(sprintf(_m('%d credits removed'), $amount), 'admin');
        $this->redirectTo($back);
    }

    /**
     * The package catalogue -- what a buyer can choose at checkout.
     *
     * @return void
     */
    private function packagesView()
    {
        $this->_exportVariableToView('packages', PackageStore::all());
        $this->doView('billing/packages.php');
    }

    /**
     * Add or edit one package.
     *
     * @return void
     */
    private function packageView()
    {
        $id      = Params::getParamInt('id');
        $package = $id > 0 ? PackageStore::find($id) : null;
        if ($id > 0 && $package === null) {
            osc_add_flash_error_message(_m('That package no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
        }

        $this->drawPackage($package);
    }

    /**
     * The package form, with what was typed and the fields a refused save names.
     *
     * @param array|null           $package stored row, null for a new package
     * @param array<string,mixed>  $typed   posted values by field name
     * @param array<string,string> $errors  message by field name
     *
     * @return void
     */
    private function drawPackage(?array $package, array $typed = array(), array $errors = array())
    {
        $this->_exportVariableToView('package', $package);
        $this->_exportVariableToView('typed', $typed);
        $this->_exportVariableToView('errors', $errors);
        $this->doView('billing/package.php');
    }

    /**
     * Create or update a package.
     *
     * The amount arrives as decimal currency and is validated before it is converted
     * to micros -- OrderStore::create() trusts this row completely at checkout, so a bad
     * amount has to be caught here, never there.
     *
     * @return void
     */
    private function packagePost()
    {
        osc_csrf_check();

        $id        = Params::getParamInt('id');
        $name      = trim(Params::getParamString('name'));
        $amountRaw = trim(Params::getParamString('amount'));
        $currency  = strtoupper(trim(Params::getParamString('currency')));
        $credits   = Params::getParamInt('credits');
        $position  = Params::getParamInt('position');
        $enabled   = Params::getParam('enabled') != '' ? 1 : 0;

        $errors = array();
        if ($name === '') {
            $errors['name'] = _m('Enter a name for this package');
        }
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw < 0) {
            $errors['amount'] = _m('Enter a valid amount, 0 or more');
        }
        if ($credits < 1) {
            $errors['credits'] = _m('Credits must be at least 1');
        }
        if (!\mindstellar\currency\CurrencyCode::valid($currency)) {
            $errors['currency'] = _m('Currency must be a 3-letter code, e.g. USD');
        }
        if ($errors !== array()) {
            $typed = array(
                'name'     => $name,
                'amount'   => $amountRaw,
                'currency' => $currency,
                'credits'  => Params::getParamString('credits'),
                'position' => $position,
                'enabled'  => (bool) $enabled,
            );
            $this->drawPackage($id > 0 ? PackageStore::find($id) : null, $typed, $errors);

            return;
        }

        $data = array(
            's_name'     => $name,
            'i_amount'   => Money::toMicros(round((float) $amountRaw, Money::decimals($currency))),
            's_currency' => $currency,
            'i_credits'  => $credits,
            'i_position' => max(0, $position),
            'b_enabled'  => $enabled,
        );

        try {
            if ($id > 0) {
                if (PackageStore::find($id) === null) {
                    osc_add_flash_error_message(_m('That package no longer exists'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
                }
                PackageStore::update($id, $data);
                osc_add_flash_ok_message(_m('Package updated'), 'admin');
            } else {
                PackageStore::create($data);
                osc_add_flash_ok_message(_m('Package created'), 'admin');
            }
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(_m('That package could not be saved'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=package' . ($id > 0 ? '&id=' . $id : ''));
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
    }

    /**
     * Remove a package from the catalogue. Orders already placed against it carry
     * their own copy of the amount and credits, so deleting it never touches history.
     *
     * @return void
     */
    private function packageDeletePost()
    {
        osc_csrf_check();

        $id = Params::getParamInt('id');
        if (PackageStore::delete($id)) {
            osc_add_flash_ok_message(_m('Package deleted'), 'admin');
        } else {
            osc_add_flash_error_message(_m('That package no longer exists'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
    }

    /**
     * Ledger rows attached to one order — the credit it minted, and the reversal if it
     * was refunded.
     *
     * @param int $orderId
     *
     * @return array<int,array<string,mixed>>
     */
    private function ledgerForOrder($orderId)
    {
        return \mindstellar\billing\OrderStore::ledgerRows((int) $orderId);
    }

    /**
     * Micros refunded so far by order id, for the page's paid shop orders that have a credit note.
     *
     * @param Order[] $orders
     *
     * @return array<int,int>
     */
    private function partlyRefundedIn(array $orders): array
    {
        $paid = array();
        foreach ($orders as $order) {
            if ($order->isGoods() && $order->isPaid() && $order->getStatus() !== Order::STATUS_REFUNDED) {
                $paid[] = $order->getId();
            }
        }

        return array_filter(DocumentStore::refundedTotals($paid));
    }

    /**
     * Accounts for a page of orders, keyed by id, fetched once rather than per row.
     *
     * @param Order[] $orders
     *
     * @return array<int,array<string,mixed>>
     */
    private function usersFor(array $orders)
    {
        $ids = array();
        foreach ($orders as $order) {
            $ids[$order->getUserId()] = true;
        }
        if ($ids === array()) {
            return array();
        }

        $rows = \mindstellar\user\UserStore::byIds(array_keys($ids), array('pk_i_id', 's_username', 's_name', 's_email'));

        $byId = array();
        foreach ($rows as $row) {
            $byId[(int) $row['pk_i_id']] = $row;
        }

        return $byId;
    }

    /**
     * Only a status the system actually uses may reach a query.
     *
     * @param string $status
     *
     * @return string the status, or '' when it is not one the system uses
     */
    private function allowedStatus($status)
    {
        return in_array($status, Order::STATUSES, true) ? $status : '';
    }
}

/* file end: ./oc-includes/osclass/classes/controller/admin/CAdminBilling.php */
