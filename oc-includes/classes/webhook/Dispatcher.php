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

namespace mindstellar\webhook;

use mindstellar\job\JobQueue;
use mindstellar\utility\Clock;

/**
 * Turns an event into one delivery job per endpoint that subscribes to it.
 *
 * The body `{type, id, timestamp, data}` is built once; `api_webhook_payload` may reshape it
 * per endpoint. A body too big for a job is sent "thin": `data` holds only the resource's
 * id and url, and `thin` is true. So is the event of a resource that is not live, whose
 * `data` is already cut down and marked `live: false`. Each job is keyed by endpoint and message id, so the same
 * event is never queued twice for one endpoint.
 */
final class Dispatcher
{
    /** Room left in a job payload for everything but the body. */
    private const ENVELOPE_BYTES = 1024;

    /** @var \Closure(string, array<string,mixed>, array<string,mixed>): int */
    private \Closure $enqueue;

    /** @var Endpoint[]|null the enabled endpoints, read once per request */
    private ?array $enabled = null;

    /**
     * @param callable(string, array<string,mixed>, array<string,mixed>): int $enqueue osc_job_enqueue()
     */
    public function __construct(
        private WebhookEndpointStore $endpoints,
        private Events $events,
        private Clock $clock,
        callable $enqueue
    ) {
        $this->enqueue = \Closure::fromCallable($enqueue);
    }

    /**
     * osc_job_enqueue(), looked up when a job is queued.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public static function enqueue(string $type, array $payload, array $options): int
    {
        return osc_job_enqueue($type, $payload, $options);
    }

    /**
     * Queue $type for every enabled endpoint that subscribes to it.
     *
     * @param array<string,mixed> $data    the event's `data`
     * @param array<string,mixed> $options thin: the `data` to send when the body is too big
     *                                     (default: its id and url).
     *                                     endpoints: only these endpoint ids.
     *
     * @return string|null the message id, or null when no endpoint wants the event
     * @throws \InvalidArgumentException for a type the catalogue does not have
     */
    public function emit(string $type, array $data, array $options = []): ?string
    {
        if ($type === Events::PING || !$this->events->has($type)) {
            throw new \InvalidArgumentException('Unknown webhook event "' . $type . '". Register it on api_webhook_events first.');
        }
        $targets = $this->only($this->subscribedTo($type), $options['endpoints'] ?? null);

        return $targets === [] ? null : $this->queue($type, $data, $options, $targets, false);
    }

    /**
     * Like emit(), but $data is built only when some endpoint wants the event, so a hook
     * costs one t_key_value read on a site with no endpoints.
     *
     * @param callable(): ?array<string,mixed> $data null when the resource is gone
     */
    public function dispatch(string $type, callable $data): ?string
    {
        $targets = $this->subscribedTo($type);
        if ($targets === []) {
            return null;
        }
        $built = $data();

        return $built === null ? null : $this->queue($type, $built, [], $targets, false);
    }

    /**
     * Queue a `ping` to one endpoint, whatever its state and subscriptions.
     *
     * @return string|null the message id, or null when there is no such endpoint
     */
    public function ping(string $endpointId): ?string
    {
        $endpoint = $this->endpoints->find($endpointId);
        if ($endpoint === null) {
            return null;
        }

        return $this->queue(Events::PING, ['endpoint_id' => $endpoint->id(), 'message' => 'Test event from the site.'], [], [$endpoint], true);
    }

    /**
     * @return Endpoint[]
     */
    private function subscribedTo(string $type): array
    {
        $this->enabled ??= $this->endpoints->enabled();

        return array_values(array_filter($this->enabled, static fn (Endpoint $e): bool => $e->subscribes($type)));
    }

    /**
     * @param Endpoint[]    $endpoints
     * @param mixed         $ids
     *
     * @return Endpoint[]
     */
    private function only(array $endpoints, $ids): array
    {
        if ($ids === null) {
            return $endpoints;
        }
        $ids = array_map('strval', (array) $ids);

        return array_values(array_filter($endpoints, static fn (Endpoint $e): bool => in_array($e->id(), $ids, true)));
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     * @param Endpoint[]          $targets
     */
    private function queue(string $type, array $data, array $options, array $targets, bool $test): string
    {
        $now     = $this->clock->now();
        // The real millisecond, so ids sort in the order the events happened; a test clock
        // set to another second keeps its own.
        $millis  = (int) floor(microtime(true) * 1000);
        $msgId   = Signer::messageId(intdiv($millis, 1000) === $now ? $millis : $now * 1000);
        $payload = ['type' => $type, 'id' => $msgId, 'timestamp' => \mindstellar\database\UtcDatetime::rfc3339($now), 'data' => $data];
        if (($data['live'] ?? null) === false) {
            $payload = ['type' => $type, 'id' => $msgId, 'timestamp' => $payload['timestamp'], 'thin' => true, 'data' => $data];
        }
        $thin    = [
            'type'      => $type,
            'id'        => $msgId,
            'timestamp' => $payload['timestamp'],
            'thin'      => true,
            'data'      => isset($options['thin']) && is_array($options['thin'])
                ? $options['thin']
                : array_intersect_key($data, ['id' => true, 'url' => true]),
        ];
        foreach ($targets as $endpoint) {
            $endpointData = $endpoint->toArray($now);
            $shaped       = osc_apply_filter('api_webhook_payload', $payload, $type, $endpointData);
            $body   = self::json(is_array($shaped) ? $shaped : $payload);
            $job    = ['endpoint_id' => $endpoint->id(), 'msg_id' => $msgId, 'type' => $type, 'body' => $body, 'ts' => $now];
            if ($test) {
                $job['test'] = true;
            }
            if (strlen((string) json_encode($job)) > JobQueue::MAX_PAYLOAD_BYTES - self::ENVELOPE_BYTES) {
                $job['body'] = self::json($thin);
            }
            ($this->enqueue)(Delivery::TYPE, $job, ['unique_key' => 'wh:' . $endpoint->id() . ':' . $msgId]);
        }

        return $msgId;
    }

    /**
     * @param array<string,mixed> $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
