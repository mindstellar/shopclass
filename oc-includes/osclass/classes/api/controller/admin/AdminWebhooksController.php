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

namespace mindstellar\api\controller\admin;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\Links;
use mindstellar\utility\Clock;
use mindstellar\webhook\Endpoint;
use mindstellar\webhook\WebhookService;

/**
 * `/admin/webhooks` and `/admin/webhook-events`: the webhooks of Settings -> API, through the
 * same WebhookService. An endpoint's secret is in the answer that makes or rotates it, and
 * nowhere else.
 */
final class AdminWebhooksController
{
    private WebhookService $webhooks;
    private Links $links;
    private Clock $clock;

    public function __construct(private ApiServices $api)
    {
        $this->webhooks = $api->webhooks()->service();
        $this->links = $api->links();
        $this->clock = $api->clock();
    }

    public function index(ApiCall $call): Response
    {
        return Page::whole(array_map(fn (Endpoint $e): array => $e->toArray($this->clock->now()), $this->webhooks->all()), $this->links, $call);
    }

    public function show(ApiCall $call): Response
    {
        return Response::ok($this->endpoint($call->arg('webhook'))->toArray($this->clock->now()));
    }

    public function create(ApiCall $call): Response
    {
        $input = $call->input();
        [$endpoint, $secret] = $this->webhooks->create(
            (string) $input['url'],
            array_map('strval', (array) $input['events']),
            (string) ($input['description'] ?? ''),
            (bool) ($input['enabled'] ?? true),
            $call->credential()->adminId()
        );

        return $this->api->created($call, $endpoint->toArray($this->clock->now(), $secret), 'admin/webhooks/' . $endpoint->id());
    }

    public function update(ApiCall $call): Response
    {
        $id    = $this->endpoint($call->arg('webhook'))->id();
        $input = $call->input();
        $endpoint = $this->webhooks->update(
            $id,
            array_key_exists('url', $input) ? (string) $input['url'] : null,
            array_key_exists('events', $input) ? array_map('strval', (array) $input['events']) : null,
            array_key_exists('description', $input) ? (string) $input['description'] : null,
            array_key_exists('enabled', $input) ? (bool) $input['enabled'] : null
        );

        return Response::ok($endpoint->toArray($this->clock->now()));
    }

    public function delete(ApiCall $call): Response
    {
        $this->webhooks->delete($this->endpoint($call->arg('webhook'))->id());

        return Response::noContent();
    }

    /**
     * A new secret; the old one keeps signing
     * for a day, as a second signature.
     */
    public function rotate(ApiCall $call): Response
    {
        [$endpoint, $secret] = $this->webhooks->rotate($this->endpoint($call->arg('webhook'))->id());

        return Response::ok($endpoint->toArray($this->clock->now(), $secret));
    }

    public function test(ApiCall $call): Response
    {
        $msgId = $this->webhooks->test($this->endpoint($call->arg('webhook'))->id());

        return Response::ok(['message_id' => $msgId, 'type' => 'ping'], 202);
    }

    public function deliveries(ApiCall $call): Response
    {
        $endpoint = $this->endpoint($call->arg('webhook'));
        $limit    = $call->request()->queryInt('limit', 25);
        $rows     = array_map(static fn (array $d): array => [
            'job_id'      => $d['job_id'],
            'message_id'  => $d['message_id'],
            'type'        => $d['type'],
            'status'      => $d['status'],
            'attempts'    => $d['attempts'],
            'last_error'  => $d['last_error'],
            'test'        => $d['test'],
            'created_at'  => Format::timestamp($d['created']),
            'next_run_at' => Format::timestamp($d['next_run']),
        ], $this->webhooks->deliveries($endpoint->id(), $limit));

        return Response::ok([
            'endpoint'   => $endpoint->toArray($this->clock->now()),
            'deliveries' => $rows,
        ]);
    }

    public function events(ApiCall $call): Response
    {
        $out = [];
        foreach ($this->webhooks->events()->all() as $type => $spec) {
            $out[] = ['type' => $type, 'description' => $spec['description'], 'schema' => $spec['schema'] === '' ? null : $spec['schema']];
        }

        return Page::whole($out, $this->links, $call);
    }

    /**
     * @throws ProblemException 404
     */
    private function endpoint(string $id): Endpoint
    {
        return ProblemException::found($this->webhooks->find($id), 'webhook endpoint');
    }
}
