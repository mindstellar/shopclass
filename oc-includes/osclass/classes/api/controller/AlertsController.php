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
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\api\serializer\AlertSerializer;
use mindstellar\apiaccess\Credential;
use mindstellar\search\AlertEnvelope;
use mindstellar\search\UserAlerts;

/**
 * The user's saved searches at `/account/alerts`. A new one takes the filters of
 * `GET /listings`; the server builds the alert from them as the search page does and saves
 * it through UserAlerts, as the search page's alert form does.
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

    public function index(ApiCall $call): Response
    {
        $rows = $this->alerts->live((int) $call->credential()->userId());

        return Page::whole(array_map([$this->serializer, 'one'], $rows), $this->api->links(), $call);
    }

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

        $saved = $this->alerts->subscribe($userId, $alert);

        return match ($saved['status']) {
            UserAlerts::EXISTS  => Response::ok($this->serializer->one((array) $saved['alert'])),
            UserAlerts::CREATED => Response::created($this->serializer->one((array) $saved['alert']), $this->api->links()->api('account/alerts/' . (int) ($saved['alert']['pk_i_id'] ?? 0), $call->request()->version())),
            UserAlerts::REFUSED => throw ProblemException::of('forbidden', 'This account cannot save alerts.'),
            UserAlerts::LIMIT   => throw ProblemException::field('/', 'maxItems', sprintf('the account already keeps the most saved searches allowed (%d); delete one first', UserAlerts::maxPerUser())),
            default             => throw ProblemException::of('server_error', 'The alert could not be saved.'),
        };
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->serializer->one($this->own($call->credential(), $call->intArg())));
    }

    public function delete(ApiCall $call): Response
    {
        $this->alerts->unsubscribe((int) $this->own($call->credential(), $call->intArg())['pk_i_id']);

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
        return ProblemException::found($this->alerts->own($id, (int) $credential->userId()), 'alert');
    }
}
