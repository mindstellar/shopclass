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

use mindstellar\database\DbException;
use mindstellar\job\JobQueue;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;
use mindstellar\validation\NotFoundException;
use mindstellar\validation\RefusedException;

/**
 * Webhook endpoints as Settings -> API and `/admin/webhooks` manage them: checks what was
 * asked for, then writes through WebhookEndpointStore. A refused request throws with the reason
 * to show. A secret is handed back only by create() and rotate().
 */
final class WebhookService
{
    /** Seconds the replaced secret keeps signing after a rotation. */
    public const ROTATION_OVERLAP = 86400;

    public const MAX_URL         = 2048;
    public const MAX_DESCRIPTION = 255;

    public function __construct(
        private WebhookEndpointStore $endpoints,
        private Events $events,
        private AddressGuard $guard,
        private Dispatcher $dispatcher,
        private Clock $clock
    ) {
    }

    public function events(): Events
    {
        return $this->events;
    }

    /**
     * Every endpoint, newest first.
     *
     * @return Endpoint[]
     */
    public function all(): array
    {
        $all = array_values($this->endpoints->all());
        usort($all, static fn (Endpoint $a, Endpoint $b): int => [$b->created(), $b->id()] <=> [$a->created(), $a->id()]);

        return $all;
    }

    public function find(string $id): ?Endpoint
    {
        return $this->endpoints->find($id);
    }

    /**
     * Add an endpoint.
     *
     * @param string[] $events
     *
     * @return array{0: Endpoint, 1: string} the endpoint and its secret, shown once
     * @throws RefusedException with the reason to show
     */
    public function create(string $url, array $events, string $description = '', bool $enabled = true, ?int $adminId = null): array
    {
        if ($this->endpoints->count() >= WebhookEndpointStore::MAX) {
            throw new RefusedException(sprintf(_m('A site can have %d webhook endpoints. Delete one first.'), WebhookEndpointStore::MAX));
        }
        $url         = $this->url($url);
        $events      = $this->eventList($events);
        $description = $this->description($description);
        $now         = $this->clock->now();
        $secret      = Signer::newSecret();
        $endpoint    = new Endpoint(
            WebhookEndpointStore::newId(),
            $url,
            $events,
            $description,
            $enabled,
            $secret,
            createdBy: $adminId,
            created: $now,
            updated: $now
        );

        return [$this->endpoints->insert($endpoint), $secret];
    }

    /**
     * Change some of an endpoint's settings; null leaves one as it is. Switching an endpoint
     * on clears a pause and its failure count.
     *
     * @param string[]|null $events
     *
     * @throws RefusedException with the reason to show
     */
    public function update(string $id, ?string $url = null, ?array $events = null, ?string $description = null, ?bool $enabled = null): Endpoint
    {
        $this->existing($id);
        $url         = $url === null ? null : $this->url($url);
        $events      = $events === null ? null : $this->eventList($events);
        $description = $description === null ? null : $this->description($description);
        $now         = $this->clock->now();

        // What was not sent is read inside the compare-and-swap, so a change another writer
        // made in between is kept, not overwritten with what this request read first.
        $changed = $this->endpoints->change($id, static function (Endpoint $e) use ($url, $events, $description, $enabled, $now): Endpoint {
            $e = $e->withSettings($url ?? $e->url(), $events ?? $e->events(), $description ?? $e->description(), $now);

            return $enabled === null || $enabled === $e->enabled() ? $e : $e->withEnabled($enabled, $now);
        });
        if ($changed === null) {
            throw new NotFoundException(_m('That webhook endpoint is not in the list any more.'));
        }

        return $changed[1];
    }

    /**
     * A new secret. The old one keeps signing, as a second signature, for ROTATION_OVERLAP.
     *
     * @return array{0: Endpoint, 1: string}
     * @throws RefusedException with the reason to show
     */
    public function rotate(string $id): array
    {
        $this->existing($id);
        $secret  = Signer::newSecret();
        $now     = $this->clock->now();
        $changed = $this->endpoints->change($id, static fn (Endpoint $e): Endpoint => $e->withSecret($secret, $now + self::ROTATION_OVERLAP, $now));
        if ($changed === null) {
            throw new NotFoundException(_m('That webhook endpoint is not in the list any more.'));
        }

        return [$changed[1], $secret];
    }

    /**
     * Delete an endpoint and the deliveries still waiting for it.
     *
     * @throws RefusedException with the reason to show
     */
    public function delete(string $id): void
    {
        $this->existing($id);
        $this->endpoints->delete($id);
        try {
            $this->deliveryRows($id)->where('s_status', '<>', JobQueue::STATUS_RUNNING)->delete();
        } catch (DbException $e) {
            // The worker drops a delivery whose endpoint is gone, so a left-over row is harmless.
        }
    }

    /**
     * Queue a `ping` to the endpoint, sent once even while it is switched off.
     *
     * @return string the message id
     * @throws RefusedException with the reason to show
     */
    public function test(string $id): string
    {
        $this->existing($id);
        $msgId = $this->dispatcher->ping($id);
        if ($msgId === null) {
            throw new NotFoundException(_m('That webhook endpoint is not in the list any more.'));
        }

        return $msgId;
    }

    /**
     * The deliveries still on the job queue for an endpoint, newest first: waiting, being
     * sent, or given up after the last try. Sent ones leave the queue.
     *
     * @return array<int,array{job_id:int, message_id:string, type:string, status:string, attempts:int, last_error:?string, created:?int, next_run:?int, test:bool}>
     */
    public function deliveries(string $id, int $limit = 25): array
    {
        try {
            $rows = $this->deliveryRows($id)->orderBy('pk_i_id', 'DESC')->limit(max(1, min(100, $limit)))->get();
        } catch (DbException $e) {
            return [];
        }
        $time = static fn ($v): ?int => $v === null || $v === '' ? null : (strtotime((string) $v) ?: null);
        $out  = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['s_payload'], true);
            $payload = is_array($payload) ? $payload : [];
            $out[]   = [
                'job_id'     => (int) $row['pk_i_id'],
                'message_id' => (string) ($payload['msg_id'] ?? ''),
                'type'       => (string) ($payload['type'] ?? ''),
                'status'     => (string) $row['s_status'],
                'attempts'   => (int) $row['i_attempts'],
                'last_error' => $row['s_last_error'] === null ? null : (string) $row['s_last_error'],
                'created'    => $time($row['dt_created'] ?? null),
                'next_run'   => $time($row['dt_next_run'] ?? null),
                'test'       => !empty($payload['test']),
            ];
        }

        return $out;
    }

    /**
     * @throws RefusedException
     */
    private function existing(string $id): Endpoint
    {
        $endpoint = $this->endpoints->find($id);
        if ($endpoint === null) {
            throw new NotFoundException(_m('That webhook endpoint is not in the list any more.'));
        }

        return $endpoint;
    }

    private function deliveryRows(string $id): \mindstellar\database\QueryBuilder
    {
        return osc_db_table(JobQueue::getInstance()->table())
            ->where('s_type', Delivery::TYPE)
            ->like('s_payload', '"endpoint_id":"' . $id . '"');
    }

    /**
     * @throws RefusedException
     */
    private function url(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RefusedException(_m('Enter the full web address of the endpoint, such as https://example.com/webhooks.'));
        }
        $check = $this->guard->check($url);
        if (!$check['ok']) {
            throw new RefusedException(sprintf(_m('That address cannot be used: %s'), (string) ($check['error'] ?? '')));
        }

        return $url;
    }

    /**
     * @param string[] $events
     *
     * @return string[]
     * @throws RefusedException
     */
    private function eventList(array $events): array
    {
        $events = array_values(array_unique(array_filter(array_map('strval', $events), static fn (string $e): bool => $e !== '')));
        if ($events === []) {
            throw new RefusedException(_m('Choose at least one event.'));
        }
        $unknown = array_diff($events, $this->events->subscribable());
        if ($unknown !== []) {
            throw new RefusedException(sprintf(_m('Unknown events: %s'), implode(', ', $unknown)));
        }

        return $events;
    }

    /**
     * @throws RefusedException
     */
    private function description(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new RefusedException(sprintf(_m('The description is too long. Use %d characters or fewer.'), self::MAX_DESCRIPTION));
        }

        return $description;
    }
}
