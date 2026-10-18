<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\api\controller;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\ListSpec;
use mindstellar\api\read\Pager;
use mindstellar\api\Response;
use mindstellar\api\serializer\OrderSerializer;
use mindstellar\billing\Billing;
use mindstellar\billing\CartPricer;
use mindstellar\billing\GoodsCheckout;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\billing\PaymentGatewayRegistry;
use mindstellar\currency\CurrencyService;

/**
 * The cart quote and the signed-in user's orders. A quote and a placement both go through
 * CartPricer and GoodsCheckout, as the web checkout does, so the request carries variant ids and
 * quantities only: every price, total and stock check is the server's. Every order route reads
 * and writes the caller's own orders; another user's order is a 404.
 */
final class OrdersController
{
    private CartPricer $pricer;

    private GoodsCheckout $checkout;

    private OrderSerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->pricer     = new CartPricer();
        $this->checkout   = new GoodsCheckout($this->pricer);
        $this->serializer = new OrderSerializer($api->links(), $api->listingSerializer(), CurrencyService::make());
    }

    /**
     * `GET cart/quote`: the cart priced from the database. Public, because a guest's cart is
     * priced too; a bad line is a problem in the answer, not an error.
     */
    public function quote(ApiCall $call): Response
    {
        $request = $call->request();
        $lines   = array();
        $raw     = $request->queryString('lines');
        foreach ($raw === '' ? array() : explode(',', $raw) as $pair) {
            [$variant, $qty] = array_map('intval', explode(':', $pair, 2)) + array(0, 0);
            $lines[]         = array('variant' => $variant, 'qty' => $qty);
        }
        if (count($lines) > CartPricer::MAX_LINES) {
            throw ProblemException::field('/lines', 'maxItems', 'at most ' . CartPricer::MAX_LINES . ' lines', 'query');
        }
        $quote = $this->pricer->quote($lines, array(
            'shipping_method' => $request->queryString('shipping'),
            'gateway'         => $request->queryString('gateway'),
            'user_id'         => $call->userId(),
            'locale'          => $request->queryString('locale'),
        ));

        $gateways = array();
        if (osc_billing_enabled()) {
            foreach (PaymentGatewayRegistry::getInstance()->available($quote->currency()) as $id => $gateway) {
                $gateways[] = array('id' => (string) $id, 'name' => $gateway->getName(), 'fee' => $this->pricer->feeFor($quote, (string) $id));
            }
        }

        return Response::ok($this->serializer->quote($quote, $gateways));
    }

    public function index(ApiCall $call): Response
    {
        $userId = $call->userId();
        $pager  = Pager::fromRequest($call->request(), $this->api->cursor(), ListSpec::byId('desc'), 'account/orders');

        return $pager->respond(
            static fn (): array => array_map(
                static fn (Order $order): array => array('pk_i_id' => $order->getId(), 'order' => $order),
                OrderStore::forUser($userId, $pager->limit() + 1, 0, $pager->afterId())
            ),
            null,
            fn (array $page): array => $this->serializer->orders(array_map(static fn (array $row): Order => $row['order'], $page)),
            $this->api->links()
        );
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->serializer->orders(array($this->own($call)))[0]);
    }

    /**
     * `POST account/orders`: price the cart again, write the order and its lines, ask the
     * gateway how to pay. `next` says where the buyer goes.
     */
    public function create(ApiCall $call): Response
    {
        $userId = $call->userId();
        $input  = $call->input();
        $this->api->limiter()->enforce($this->api->ratePolicy()->checkout($userId), 'Too many orders placed in an hour. Try again later.');

        $placed = $this->checkout->place(
            $userId,
            array_values((array) ($input['lines'] ?? array())),
            (array) ($input['address'] ?? array()),
            (string) ($input['shipping_method'] ?? ''),
            (string) ($input['gateway'] ?? ''),
            (string) ($input['note'] ?? '')
        );
        $next = $placed['next'];

        return $this->api->created(
            $call,
            $this->serializer->orders(array($placed['order']))[0],
            'account/orders/' . $placed['order']->getId(),
            array('next' => array('kind' => $next->getKind(), 'payload' => $next->getPayload()))
        );
    }

    /**
     * `POST account/orders/{id}/pay`: ask the gateway again how to pay a pending card order,
     * for a buyer who left its payment page. `next` says where the buyer goes.
     */
    public function pay(ApiCall $call): Response
    {
        $order = $this->own($call);
        if (!$order->canPayAgain()) {
            throw ProblemException::of('conflict', 'Only a pending card order can be paid again.');
        }
        $this->api->limiter()->enforce($this->api->ratePolicy()->checkout($call->userId()), 'Too many payments started in an hour. Try again later.');
        $next = Billing::checkout($order);
        if ($next === null) {
            throw ProblemException::of('conflict', 'That payment method is not available right now.');
        }

        return Response::ok(
            $this->serializer->orders(array(ProblemException::found(OrderStore::find($order->getId()), 'order')))[0],
            200,
            array('next' => array('kind' => $next->getKind(), 'payload' => $next->getPayload()))
        );
    }

    /**
     * `POST account/orders/{id}/cancel`: close a pending goods order. Anything else is a 409.
     */
    public function cancel(ApiCall $call): Response
    {
        $order = $this->own($call);
        if (!$order->buyerMayCancel() || !OrderStore::cancel($order->getId())) {
            throw ProblemException::of('conflict', 'Only a pending bank transfer or cash on delivery order can be cancelled.');
        }

        return Response::ok($this->serializer->orders(array(ProblemException::found(OrderStore::find($order->getId()), 'order')))[0]);
    }

    /**
     * The caller's own order.
     *
     * @throws ProblemException 404 for one that does not exist or belongs to someone else
     */
    private function own(ApiCall $call): Order
    {
        $order = OrderStore::find($call->intArg());

        return ProblemException::found($order !== null && $order->getUserId() === $call->userId() ? $order : null, 'order');
    }
}
