<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\billing\Billing;
use mindstellar\billing\Order;
use mindstellar\billing\Orders;
use mindstellar\billing\Packages;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\billing\Wallet;

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

    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_billing');
    }

    //Business Layer...
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
            case ('order_paid'):
                $this->orderPaidPost();
                break;
            case ('order_refund'):
                $this->orderRefundPost();
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
     */
    private function ordersView()
    {
        $filters = array(
            'status'  => $this->allowedStatus(Params::getParamString('status')),
            'gateway' => Params::getParamString('gateway'),
            'user_id' => Params::getParamInt('userId'),
        );

        $page   = max(1, Params::getParamInt('pageNum'));
        $total  = Orders::searchCount($filters);
        $offset = ($page - 1) * self::PER_PAGE;

        $orders = Orders::search($filters, self::PER_PAGE, $offset);

        $this->_exportVariableToView('orders', $orders);
        $this->_exportVariableToView('users', $this->usersFor($orders));
        $this->_exportVariableToView('summary', Orders::summary($filters));
        $this->_exportVariableToView('gateways', Orders::knownGateways());
        $this->_exportVariableToView('filters', $filters);
        $this->_exportVariableToView('total', $total);
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);

        $this->doView('billing/index.php');
    }

    /**
     * One order, its ledger entries, and whatever can still be done to it.
     */
    private function orderView()
    {
        $order = Orders::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        $gateway = PaymentGatewayRegistry::instance()->get($order->getGateway());

        $this->_exportVariableToView('order', $order);
        $this->_exportVariableToView('user', User::newInstance()->findByPrimaryKey($order->getUserId()));
        $this->_exportVariableToView('gateway', $gateway);
        $this->_exportVariableToView('entries', $this->ledgerForOrder($order->getId()));
        $this->_exportVariableToView('balance', Wallet::balance($order->getUserId()));

        $this->doView('billing/order.php');
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
     */
    private function orderPaidPost()
    {
        osc_csrf_check();

        $order = Orders::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        $wasFailed = $order->getStatus() === Order::STATUS_FAILED;

        if (Billing::markPaid($order, $order->getExternalRef(), true)) {
            osc_add_flash_ok_message(
                $wasFailed
                    ? sprintf(_m('Order #%d was marked failed and is now marked paid; the credits have been added'), $order->getId())
                    : sprintf(_m('Order #%d is marked paid and the credits have been added'), $order->getId()),
                'admin'
            );
        } else {
            // markPaid() is guarded on the order still being pending or failed, so
            // this is the benign double-submit rather than a failure.
            osc_add_flash_warning_message(_m('That order had already been settled'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=order&id=' . $order->getId());
    }

    /**
     * Record a refund the provider has already made. Core never asks a gateway to refund.
     */
    private function orderRefundPost()
    {
        osc_csrf_check();

        $order = Orders::find(Params::getParamInt('id'));
        if ($order === null) {
            osc_add_flash_error_message(_m('That order no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing');
        }

        if (Billing::refund($order)) {
            osc_add_flash_ok_message(
                sprintf(_m('Order #%d is recorded as refunded and the credits have been taken back'), $order->getId()),
                'admin'
            );
        } else {
            osc_add_flash_warning_message(_m('Only a paid order can be refunded'), 'admin');
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=order&id=' . $order->getId());
    }

    /**
     * Every wallet holding credit.
     */
    private function creditsView()
    {
        $page   = max(1, Params::getParamInt('pageNum'));
        $total  = Wallet::balanceCount();
        $offset = ($page - 1) * self::PER_PAGE;

        $this->_exportVariableToView('wallets', Wallet::balances(self::PER_PAGE, $offset));
        $this->_exportVariableToView('outstanding', Wallet::totalOutstanding());
        $this->_exportVariableToView('total', $total);
        $this->_exportVariableToView('pageNum', $page);
        $this->_exportVariableToView('perPage', self::PER_PAGE);

        $this->doView('billing/credits.php');
    }

    /**
     * One user's balance and the ledger behind it.
     */
    private function walletView()
    {
        $userId = Params::getParamInt('userId');
        $user   = User::newInstance()->findByPrimaryKey($userId);
        if ($user === null) {
            osc_add_flash_error_message(_m('That user no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=credits');
        }

        $page   = max(1, Params::getParamInt('pageNum'));
        $total  = Wallet::historyCount($userId);
        $offset = ($page - 1) * self::PER_PAGE;

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
     */
    private function walletAdjustPost()
    {
        osc_csrf_check();

        $userId = Params::getParamInt('userId');
        $user   = User::newInstance()->findByPrimaryKey($userId);
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
     */
    private function packagesView()
    {
        $this->_exportVariableToView('packages', Packages::all());
        $this->doView('billing/packages.php');
    }

    /**
     * Add or edit one package.
     */
    private function packageView()
    {
        $id      = Params::getParamInt('id');
        $package = $id > 0 ? Packages::find($id) : null;
        if ($id > 0 && $package === null) {
            osc_add_flash_error_message(_m('That package no longer exists'), 'admin');
            $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
        }

        $this->_exportVariableToView('package', $package);
        $this->doView('billing/package.php');
    }

    /**
     * Create or update a package.
     *
     * The amount arrives as decimal currency and is validated before it is converted
     * to micros -- Orders::create() trusts this row completely at checkout, so a bad
     * amount has to be caught here, never there.
     */
    private function packagePost()
    {
        osc_csrf_check();

        $id        = Params::getParamInt('id');
        $back      = osc_admin_base_url(true) . '?page=billing&action=package' . ($id > 0 ? '&id=' . $id : '');
        $name      = trim(Params::getParamString('name'));
        $amountRaw = trim(Params::getParamString('amount'));
        $currency  = strtoupper(trim(Params::getParamString('currency')));
        $credits   = Params::getParamInt('credits');
        $position  = Params::getParamInt('position');
        $enabled   = Params::getParam('enabled') != '' ? 1 : 0;

        if ($name === '') {
            osc_add_flash_error_message(_m('Enter a name for this package'), 'admin');
            $this->redirectTo($back);
        }

        if ($amountRaw === '' || !is_numeric($amountRaw) || (float) $amountRaw < 0) {
            osc_add_flash_error_message(_m('Enter a valid amount, 0 or more'), 'admin');
            $this->redirectTo($back);
        }

        if ($credits < 1) {
            osc_add_flash_error_message(_m('Credits must be at least 1'), 'admin');
            $this->redirectTo($back);
        }

        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            osc_add_flash_error_message(_m('Currency must be a 3-letter code, e.g. USD'), 'admin');
            $this->redirectTo($back);
        }

        $data = array(
            's_name'     => $name,
            // Money is entered as decimal currency and stored as micros -- integer
            // maths only, the value is never kept as a float past this line.
            'i_amount'   => (int) round((float) $amountRaw * 1000000),
            's_currency' => $currency,
            'i_credits'  => $credits,
            'i_position' => max(0, $position),
            'b_enabled'  => $enabled,
        );

        try {
            if ($id > 0) {
                if (Packages::find($id) === null) {
                    osc_add_flash_error_message(_m('That package no longer exists'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
                }
                Packages::update($id, $data);
                osc_add_flash_ok_message(_m('Package updated'), 'admin');
            } else {
                Packages::create($data);
                osc_add_flash_ok_message(_m('Package created'), 'admin');
            }
        } catch (InvalidArgumentException $e) {
            osc_add_flash_error_message(_m('That package could not be saved'), 'admin');
            $this->redirectTo($back);
        }

        $this->redirectTo(osc_admin_base_url(true) . '?page=billing&action=packages');
    }

    /**
     * Remove a package from the catalogue. Orders already placed against it carry
     * their own copy of the amount and credits, so deleting it never touches history.
     */
    private function packageDeletePost()
    {
        osc_csrf_check();

        $id = Params::getParamInt('id');
        if (Packages::delete($id)) {
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
     * @return array<int,array>
     */
    private function ledgerForOrder($orderId)
    {
        return osc_db_table(DB_TABLE_PREFIX . 't_billing_ledger')
            ->where('s_ref_type', 'order')
            ->where('i_ref_id', (int) $orderId)
            ->orderBy('pk_i_id', 'ASC')
            ->get();
    }

    /**
     * Accounts for a page of orders, keyed by id, fetched once rather than per row.
     *
     * @param Order[] $orders
     *
     * @return array<int,array>
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

        $rows = osc_db_table(DB_TABLE_PREFIX . 't_user')
            ->select('pk_i_id', 's_username', 's_name', 's_email')
            ->whereIn('pk_i_id', array_keys($ids))
            ->get();

        $byId = array();
        foreach ($rows as $row) {
            $byId[(int) $row['pk_i_id']] = $row;
        }

        return $byId;
    }

    /**
     * Only a status the system actually uses may reach a query.
     *
     * @return string
     */
    private function allowedStatus($status)
    {
        return in_array($status, Order::STATUSES, true) ? $status : '';
    }
}

/* file end: ./oc-includes/osclass/classes/controller/admin/CAdminBilling.php */
