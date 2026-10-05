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

use mindstellar\job\Job;
use mindstellar\job\JobRetry;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;

/**
 * The `api.webhook_deliver` job: one signed POST of one event to one endpoint.
 *
 * A 2xx answer finishes the job and clears the endpoint's failure count. Anything else
 * counts a failure and throws, so the job queue retries on RetrySchedule and dead-letters
 * the job after its last try. A 410 Gone switches the endpoint off at once and ends the job.
 * The failure that makes PAUSE_AFTER in a row switches the endpoint off and e-mails the site once.
 * A delivery for an endpoint that is gone or off is dropped. A test delivery is sent once, whatever the endpoint's state, and is not counted.
 */
final class Delivery
{
    public const TYPE = 'api.webhook_deliver';

    /** @var \Closure(Endpoint): void */
    private \Closure $notify;

    /**
     * @param callable(Endpoint): void $notify told once when an endpoint is paused
     */
    public function __construct(
        private WebhookEndpointStore $endpoints,
        private Transport $transport,
        private AddressGuard $guard,
        private Clock $clock,
        callable $notify
    ) {
        $this->notify = \Closure::fromCallable($notify);
    }

    /**
     * Register the handler; called from `register_jobs`.
     */
    public static function register(): void
    {
        osc_job_register_handler(self::TYPE, static function (Job $job): void {
            WebhookServices::site()->delivery()->run($job->payload(), $job->attempts() + 1);
        });
        osc_job_describe(self::TYPE, __('Send a webhook'), static function (array $payload): string {
            return (string) ($payload['type'] ?? '') . ' -> ' . (string) ($payload['endpoint_id'] ?? '');
        });
    }

    /**
     * Send one queued delivery.
     *
     * @param array<string,mixed> $payload endpoint_id, msg_id, type, body, test
     * @param int                 $attempt 1 for the first try
     *
     * @return TransportResult|null what the endpoint answered; null when nothing was sent
     * @throws \RuntimeException when the delivery failed and should be retried
     */
    public function run(array $payload, int $attempt = 1): ?TransportResult
    {
        $id       = (string) ($payload['endpoint_id'] ?? '');
        $test     = !empty($payload['test']);
        $endpoint = $this->endpoints->find($id);
        if ($endpoint === null || (!$endpoint->enabled() && !$test)) {
            return null;
        }
        $msgId = (string) ($payload['msg_id'] ?? '');
        $type  = (string) ($payload['type'] ?? '');
        $body  = (string) ($payload['body'] ?? '');
        $now   = $this->clock->now();

        $check = $this->guard->check($endpoint->url());
        if (!$check['ok']) {
            $result = TransportResult::failed('Address refused: ' . rtrim((string) ($check['error'] ?? 'not allowed'), '.'));
        } else {
            $result = $this->transport->post($endpoint->url(), (string) $check['ip'], self::headers($msgId, $now, $body, $endpoint->signingSecrets($now)), $body);
        }

        $status  = $result->describe();
        $changed = $this->endpoints->change($id, static function (Endpoint $e) use ($test, $result, $status, $now): Endpoint {
            if ($test) {
                return $e->withTestResult($status, $now);
            }

            if ($result->ok()) {
                return $e->withSuccess($status, $now);
            }

            return $result->gone() ? $e->withGone($status, $now) : $e->withFailure($status, $now);
        });
        if ($changed !== null && $changed[0]->enabled() && $changed[1]->paused()) {
            try {
                ($this->notify)($changed[1]);
            } catch (\Throwable $e) {
                error_log('webhook: the pause e-mail for ' . $id . ' was not sent: ' . $e->getMessage());
            }
        }

        $endpointData = ($changed[1] ?? $endpoint)->toArray($now);
        $event        = ['id' => $msgId, 'type' => $type, 'test' => $test];
        $httpStatus   = $result->status();
        osc_run_hook('api_webhook_delivered', $endpointData, $event, $httpStatus, $attempt);

        if (!$result->ok() && !$test && !$result->gone()) {
            // The id, never the URL: a URL often carries a token, and this text is kept on the job.
            throw new JobRetry(
                'Webhook to endpoint ' . $id . ': ' . $status,
                RetrySchedule::delay($attempt, $result->retryAfter()),
                RetrySchedule::MAX_ATTEMPTS
            );
        }

        return $result;
    }

    /**
     * The request headers of a delivery signed at $timestamp.
     *
     * @param string[] $secrets
     *
     * @return array<string,string>
     */
    public static function headers(string $msgId, int $timestamp, string $body, array $secrets): array
    {
        return [
            'Content-Type'      => 'application/json',
            'User-Agent'        => 'Shopclass-Webhooks/' . (defined('OSCLASS_VERSION') ? OSCLASS_VERSION : '7'),
            'webhook-id'        => $msgId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => Signer::sign($msgId, $timestamp, $body, $secrets),
        ];
    }

    /**
     * Tell the site's contact address that an endpoint was paused.
     */
    public static function mailPaused(Endpoint $endpoint): void
    {
        $site = osc_page_title();
        $gone = $endpoint->lastStatus() === 'HTTP 410';
        osc_sendMail([
            'from'    => _osc_from_email_aux(),
            'to'      => osc_contact_email(),
            'subject' => sprintf(__('[%s] A webhook endpoint was paused'), $site),
            'body'    => '<p>' . osc_esc_html($gone ? sprintf(
                __('%1$s answered 410 Gone, so the site stopped sending to it. Switch the endpoint on again in Settings, then API, if this was a mistake.'),
                self::label($endpoint)
            ) : sprintf(
                __('Deliveries to %1$s failed %2$d times in a row, so the site stopped sending to it. The last answer was: %3$s. Fix the receiver, then switch the endpoint on again in Settings, then API.'),
                self::label($endpoint),
                $endpoint->failures(),
                (string) $endpoint->lastStatus()
            )) . '</p>',
        ]);
    }

    /**
     * How the endpoint is named outside the admin screen: its id and host, never the full URL.
     */
    public static function label(Endpoint $endpoint): string
    {
        $host = (string) parse_url($endpoint->url(), PHP_URL_HOST);

        return $endpoint->id() . ($host !== '' ? ' (' . $host . ')' : '');
    }
}
