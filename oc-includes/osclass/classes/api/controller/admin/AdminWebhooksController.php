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

use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;
use mindstellar\api\serializer\Links;
use mindstellar\apiaccess\Credential;
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

    /**
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        return Response::collection(array_map(fn (Endpoint $e): array => $e->toArray($this->clock->now()), $this->webhooks->all()));
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->endpoint($args['webhook'])->toArray($this->clock->now()));
    }

    /**
     * POST /admin/webhooks
     *
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $input = $request->input();
        [$endpoint, $secret] = $this->webhooks->create(
            (string) $input['url'],
            array_map('strval', (array) $input['events']),
            (string) ($input['description'] ?? ''),
            (bool) ($input['enabled'] ?? true),
            $credential->adminId()
        );

        return Response::created($endpoint->toArray($this->clock->now(), $secret), $this->links->api('admin/webhooks/' . $endpoint->id()));
    }

    /**
     * PATCH /admin/webhooks/{webhook}
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $id    = $this->endpoint($args['webhook'])->id();
        $input = $request->input();
        $endpoint = $this->webhooks->update(
            $id,
            array_key_exists('url', $input) ? (string) $input['url'] : null,
            array_key_exists('events', $input) ? array_map('strval', (array) $input['events']) : null,
            array_key_exists('description', $input) ? (string) $input['description'] : null,
            array_key_exists('enabled', $input) ? (bool) $input['enabled'] : null
        );

        return Response::ok($endpoint->toArray($this->clock->now()));
    }

    /**
     * DELETE /admin/webhooks/{webhook}
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        $this->webhooks->delete($this->endpoint($args['webhook'])->id());

        return Response::noContent();
    }

    /**
     * POST /admin/webhooks/{webhook}/rotate-secret: a new secret; the old one keeps signing
     * for a day, as a second signature.
     *
     * @param array<string,string> $args
     */
    public function rotate(Request $request, Credential $credential, array $args): Response
    {
        [$endpoint, $secret] = $this->webhooks->rotate($this->endpoint($args['webhook'])->id());

        return Response::ok($endpoint->toArray($this->clock->now(), $secret));
    }

    /**
     * POST /admin/webhooks/{webhook}/test: queue a `ping`.
     *
     * @param array<string,string> $args
     */
    public function test(Request $request, Credential $credential, array $args): Response
    {
        $msgId = $this->webhooks->test($this->endpoint($args['webhook'])->id());

        return Response::ok(['message_id' => $msgId, 'type' => 'ping'], 202);
    }

    /**
     * GET /admin/webhooks/{webhook}/deliveries
     *
     * @param array<string,string> $args
     */
    public function deliveries(Request $request, Credential $credential, array $args): Response
    {
        $endpoint = $this->endpoint($args['webhook']);
        $limit    = (int) ($request->query()['limit'] ?? 25);
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

    /**
     * GET /admin/webhook-events
     *
     * @param array<string,string> $args
     */
    public function events(Request $request, Credential $credential, array $args): Response
    {
        $out = [];
        foreach ($this->webhooks->events()->all() as $type => $spec) {
            $out[] = ['type' => $type, 'description' => $spec['description'], 'schema' => $spec['schema'] === '' ? null : $spec['schema']];
        }

        return Response::collection($out);
    }

    /**
     * @throws ProblemException 404
     */
    private function endpoint(string $id): Endpoint
    {
        $endpoint = $this->webhooks->find($id);
        if ($endpoint === null) {
            throw ProblemException::of('not_found', 'No such webhook endpoint.');
        }

        return $endpoint;
    }
}
