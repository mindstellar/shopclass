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

use mindstellar\security\SecretBox;

/**
 * One webhook endpoint as t_key_value stores it, plus the version its row was read at. Changes
 * return a copy; WebhookEndpointStore writes a copy back only if nobody wrote in between.
 */
final class Endpoint
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_DISABLED];

    /** Consecutive failed deliveries that pause an endpoint. */
    public const PAUSE_AFTER = 8;

    private const SECRET_PURPOSE = 'webhook-secret';

    /**
     * @param string[] $events
     */
    public function __construct(
        private string $id,
        private string $url,
        private array $events,
        private string $description,
        private bool $enabled,
        private string $secret,
        private ?string $previousSecret = null,
        private ?int $previousUntil = null,
        private int $failures = 0,
        private ?string $lastStatus = null,
        private ?int $lastAttempt = null,
        private ?int $lastSuccess = null,
        private ?int $lastFailure = null,
        private ?int $pausedAt = null,
        private ?string $pausedReason = null,
        private ?int $createdBy = null,
        private int $created = 0,
        private int $updated = 0,
        private int $version = 0
    ) {
        $this->events = array_values(array_unique(array_map('strval', $events)));
    }

    /**
     * @param array<string,mixed> $data the decoded t_key_value value
     */
    public static function fromStored(string $id, array $data, int $version): self
    {
        $int = static fn ($v): ?int => $v === null || $v === '' ? null : (int) $v;
        $str = static fn ($v): ?string => $v === null || $v === '' ? null : (string) $v;

        return new self(
            $id,
            (string) ($data['url'] ?? ''),
            array_map('strval', (array) ($data['events'] ?? [])),
            (string) ($data['description'] ?? ''),
            (bool) ($data['enabled'] ?? false),
            (string) SecretBox::open(self::SECRET_PURPOSE, (string) ($data['secret'] ?? '')),
            $str(SecretBox::open(self::SECRET_PURPOSE, (string) ($data['previous_secret'] ?? ''))),
            $int($data['previous_until'] ?? null),
            (int) ($data['failures'] ?? 0),
            $str($data['last_status'] ?? null),
            $int($data['last_attempt_at'] ?? null),
            $int($data['last_success_at'] ?? null),
            $int($data['last_failure_at'] ?? null),
            $int($data['paused_at'] ?? null),
            $str($data['paused_reason'] ?? null),
            $int($data['created_by'] ?? null),
            (int) ($data['created_at'] ?? 0),
            (int) ($data['updated_at'] ?? 0),
            $version
        );
    }

    /**
     * @return array<string,mixed> what t_key_value keeps
     */
    public function toStored(): array
    {
        return [
            'url'             => $this->url,
            'events'          => $this->events,
            'description'     => $this->description,
            'enabled'         => $this->enabled,
            'secret'          => $this->secret === '' ? '' : SecretBox::seal(self::SECRET_PURPOSE, $this->secret),
            'previous_secret' => $this->previousSecret === null ? null : SecretBox::seal(self::SECRET_PURPOSE, $this->previousSecret),
            'previous_until'  => $this->previousUntil,
            'failures'        => $this->failures,
            'last_status'     => $this->lastStatus,
            'last_attempt_at' => $this->lastAttempt,
            'last_success_at' => $this->lastSuccess,
            'last_failure_at' => $this->lastFailure,
            'paused_at'       => $this->pausedAt,
            'paused_reason'   => $this->pausedReason,
            'created_by'      => $this->createdBy,
            'created_at'      => $this->created,
            'updated_at'      => $this->updated,
        ];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return string[]
     */
    public function events(): array
    {
        return $this->events;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether deliveries stopped because too many failed in a row.
     */
    public function paused(): bool
    {
        return !$this->enabled && $this->pausedAt !== null;
    }

    public function subscribes(string $type): bool
    {
        return in_array($type, $this->events, true);
    }

    public function secret(): string
    {
        return $this->secret;
    }

    /**
     * The secrets a delivery is signed with now: the current one, and the one it replaced
     * while that is still valid. Empty when the stored secret cannot be read, such as after
     * the install's signing key changed; the secret must then be rotated.
     *
     * @return string[]
     */
    public function signingSecrets(int $now): array
    {
        if ($this->secret === '') {
            return [];
        }
        $secrets = [$this->secret];
        if ($this->previousSecret !== null && $this->previousUntil !== null && $now < $this->previousUntil) {
            $secrets[] = $this->previousSecret;
        }

        return $secrets;
    }

    /**
     * Until when the replaced secret still signs, or null when none does.
     */
    public function previousUntil(int $now): ?int
    {
        return $this->previousSecret !== null && $this->previousUntil !== null && $now < $this->previousUntil ? $this->previousUntil : null;
    }

    public function failures(): int
    {
        return $this->failures;
    }

    public function lastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function lastAttempt(): ?int
    {
        return $this->lastAttempt;
    }

    public function lastSuccess(): ?int
    {
        return $this->lastSuccess;
    }

    public function lastFailure(): ?int
    {
        return $this->lastFailure;
    }

    public function pausedAt(): ?int
    {
        return $this->pausedAt;
    }

    public function pausedReason(): ?string
    {
        return $this->pausedReason;
    }

    public function createdBy(): ?int
    {
        return $this->createdBy;
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @param string[] $events
     */
    public function withSettings(string $url, array $events, string $description, int $now): self
    {
        $copy              = clone $this;
        $copy->url         = $url;
        $copy->events      = array_values(array_unique(array_map('strval', $events)));
        $copy->description = $description;
        $copy->updated     = $now;

        return $copy;
    }

    /**
     * Switched on (which also clears a pause and the failure count) or off.
     */
    public function withEnabled(bool $enabled, int $now): self
    {
        $copy          = clone $this;
        $copy->enabled = $enabled;
        $copy->updated = $now;
        if ($enabled) {
            $copy->failures     = 0;
            $copy->pausedAt     = null;
            $copy->pausedReason = null;
        }

        return $copy;
    }

    /**
     * A new secret; the old one keeps signing until $previousUntil.
     */
    public function withSecret(string $secret, int $previousUntil, int $now): self
    {
        $copy                 = clone $this;
        $copy->previousSecret = $this->secret;
        $copy->previousUntil  = $previousUntil;
        $copy->secret         = $secret;
        $copy->updated        = $now;

        return $copy;
    }

    /**
     * A delivery got a 2xx answer: the failure count starts again.
     */
    public function withSuccess(string $status, int $now): self
    {
        $copy              = clone $this;
        $copy->failures    = 0;
        $copy->lastStatus  = $status;
        $copy->lastAttempt = $now;
        $copy->lastSuccess = $now;

        return $copy;
    }

    /**
     * A delivery failed. The failure that reaches PAUSE_AFTER in a row switches the endpoint off.
     */
    public function withFailure(string $status, int $now): self
    {
        $copy              = clone $this;
        $copy->failures    = $this->failures + 1;
        $copy->lastStatus  = $status;
        $copy->lastAttempt = $now;
        $copy->lastFailure = $now;
        if ($copy->enabled && $copy->failures >= self::PAUSE_AFTER) {
            $copy->enabled      = false;
            $copy->pausedAt     = $now;
            $copy->pausedReason = sprintf('%d deliveries failed in a row; the last: %s', $copy->failures, $status);
        }

        return $copy;
    }

    /**
     * A test delivery's outcome: shown as the last status, but it neither counts as a
     * failure nor clears the count.
     */
    /**
     * The receiver said 410 Gone: the endpoint is switched off and counted as paused.
     */
    public function withGone(string $status, int $now): self
    {
        $copy              = clone $this;
        $copy->failures    = $this->failures + 1;
        $copy->lastStatus  = $status;
        $copy->lastAttempt = $now;
        $copy->lastFailure = $now;
        if ($copy->enabled) {
            $copy->enabled      = false;
            $copy->pausedAt     = $now;
            $copy->pausedReason = 'The receiver answered 410 Gone';
        }

        return $copy;
    }

    public function withTestResult(string $status, int $now): self
    {
        $copy              = clone $this;
        $copy->lastStatus  = $status;
        $copy->lastAttempt = $now;

        return $copy;
    }

    /**
     * The endpoint as hooks and the admin see it. Never its secret.
     *
     * @return array<string,mixed>
     */
    public function toArray(?int $now = null, ?string $secret = null): array
    {
        $at = static fn (?int $time): ?string => $time === null ? null : gmdate('Y-m-d\TH:i:s\Z', $time);

        $out = [
            'id'                    => $this->id(),
            'url'                   => $this->url(),
            'description'           => $this->description(),
            'events'                => $this->events(),
            'enabled'               => $this->enabled(),
            'status'                => $this->status(),
            'failures'              => $this->failures(),
            'last_status'           => $this->lastStatus(),
            'last_attempt_at'       => $at($this->lastAttempt()),
            'last_success_at'       => $at($this->lastSuccess()),
            'last_failure_at'       => $at($this->lastFailure()),
            'paused_at'             => $at($this->pausedAt()),
            'paused_reason'         => $this->pausedReason(),
            'previous_secret_until' => $at($this->previousUntil($now ?? time())),
            'created_by'            => $this->createdBy(),
            'created_at'            => $at($this->created()),
            'updated_at'            => $at($this->updated()),
        ];
        if ($secret !== null) {
            $out['secret'] = $secret;
        }

        return $out;
    }

    public function status(): string
    {
        if ($this->enabled()) {
            return self::STATUS_ACTIVE;
        }

        return $this->paused() ? self::STATUS_PAUSED : self::STATUS_DISABLED;
    }
}
