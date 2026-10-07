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
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\read\ListingSearch;
use mindstellar\api\Response;
use mindstellar\api\serializer\AlertSerializer;
use mindstellar\apiaccess\Credential;
use mindstellar\search\AlertEnvelope;
use mindstellar\search\UserAlerts;

/**
 * The user's saved searches at `/account/alerts`. A new one takes the filters of
 * `GET /listings`; the server builds the alert from them as the search page does and saves
 * it through osc_subscribe_alert(), so it shows on the account's alerts page too.
 */
final class AlertsController
{
    private AlertSerializer $serializer;

    private CategoryCatalog $categories;

    private UserAlerts $alerts;

    public function __construct(private ApiServices $api)
    {
        $this->categories = $api->listingReader()->categories();
        $this->serializer = new AlertSerializer();
        $this->alerts     = new UserAlerts();
    }

    /**
     * GET /account/alerts
     */
    public function index(ApiCall $call): Response
    {
        $rows = $this->alerts->live((int) $call->credential()->userId());

        return Response::collection(array_map([$this->serializer, 'one'], $rows));
    }

    /**
     * POST /account/alerts
     */
    public function create(ApiCall $call): Response
    {
        $request = $call->request();

        $userId  = (int) $call->credential()->userId();
        $filters = (array) ($request->input()['filters'] ?? []);
        $search  = $request->withQuery(array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $filters));
        $values  = ListingSearch::params($search, $this->categories, $this->api->locale($search));
        $alert   = AlertEnvelope::fromValues($values, $values);
        if ($alert === '' || !AlertEnvelope::validate($alert)) {
            throw ProblemException::field('/filters', 'minProperties', 'must name at least one filter');
        }

        $existing = $this->alerts->matching($alert, $userId);
        if ($existing !== []) {
            return Response::ok($this->serializer->one($existing[0]));
        }
        $result = osc_subscribe_alert(base64_encode((string) osc_encrypt_alert($alert)), '');
        $saved  = $this->alerts->matching($alert, $userId);
        if ($result !== 1 || $saved === []) {
            throw $result === -1
                ? ProblemException::of('forbidden', 'This account cannot save alerts.')
                : ProblemException::of('server_error', 'The alert could not be saved.');
        }

        return Response::created($this->serializer->one($saved[0]), $this->api->links()->api('account/alerts/' . (int) $saved[0]['pk_i_id']));
    }

    /**
     * GET /account/alerts/{id}
     */
    public function show(ApiCall $call): Response
    {
        return Response::ok($this->serializer->one($this->own($call->credential(), (int) $call->arg('id'))));
    }

    /**
     * DELETE /account/alerts/{id}
     */
    public function delete(ApiCall $call): Response
    {
        $this->alerts->unsubscribe((int) $this->own($call->credential(), (int) $call->arg('id'))['pk_i_id']);

        return Response::noContent();
    }

    /**
     * One of the caller's live alerts.
     *
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function own(Credential $credential, int $id): array
    {
        $alert = $this->alerts->own($id, (int) $credential->userId());
        if ($alert === null) {
            throw ProblemException::of('not_found', 'No such alert.');
        }

        return $alert;
    }
}
