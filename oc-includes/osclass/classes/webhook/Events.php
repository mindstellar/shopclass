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

/**
 * The webhook events an endpoint can subscribe to: core's, and plugin events added through
 * the `api_webhook_events` filter as type => ['description' => ..., 'schema' => ...]. A plugin
 * type is `ext.<slug>.<name>`; any other new name is dropped with a warning, and core's
 * events cannot be changed or removed.
 */
final class Events
{
    /** Sent only by "Send test"; no endpoint subscribes to it. */
    public const PING = 'ping';

    /** type => [description, schema of `data`] */
    public const CORE = [
        'listing.created'     => ['A listing was posted.', 'Listing'],
        'listing.updated'     => ['A listing was edited.', 'Listing'],
        'listing.deleted'     => ['A listing was deleted. The data holds its id only.', 'WebhookDeleted'],
        'listing.activated'   => ['A listing was approved or activated.', 'Listing'],
        'listing.deactivated' => ['A listing was sent back to moderation.', 'Listing'],
        'listing.spam'        => ['A listing was marked as spam.', 'Listing'],
        'comment.created'     => ['A comment was added to a listing, approved or waiting for moderation.', 'Comment'],
        'user.registered'     => ['A user signed up.', 'User'],
        'user.updated'        => ['A user changed their profile.', 'User'],
        'user.deleted'        => ['A user was deleted. The data holds their id only.', 'WebhookDeleted'],
        self::PING            => ['A test event, sent by "Send test".', 'WebhookPing'],
    ];

    private const PLUGIN_TYPE = '/^ext\.[a-z0-9][a-z0-9_-]{0,39}\.[a-z0-9][a-z0-9_.]{0,59}$/D';

    /**
     * @param array<string,array{description:string,schema:string}> $events
     */
    public function __construct(private array $events)
    {
    }

    /**
     * Core's events and the plugins'.
     */
    public static function fromHooks(): self
    {
        $events = self::core();
        $added  = osc_apply_filter('api_webhook_events', $events);
        foreach (is_array($added) ? $added : [] as $type => $spec) {
            $type = (string) $type;
            if (isset($events[$type])) {
                continue;
            }
            if (preg_match(self::PLUGIN_TYPE, $type) !== 1) {
                trigger_error('api_webhook_events: "' . $type . '" is dropped; a plugin event is named ext.<plugin-slug>.<name>.', E_USER_WARNING);
                continue;
            }
            $spec           = is_array($spec) ? $spec : ['description' => (string) $spec];
            $events[$type]  = [
                'description' => (string) ($spec['description'] ?? ''),
                'schema'      => (string) ($spec['schema'] ?? ''),
            ];
        }

        return new self($events);
    }

    /**
     * @return array<string,array{description:string,schema:string}>
     */
    public static function core(): array
    {
        $out = [];
        foreach (self::CORE as $type => [$description, $schema]) {
            $out[$type] = ['description' => $description, 'schema' => $schema];
        }

        return $out;
    }

    /**
     * Every event, ping included.
     *
     * @return array<string,array{description:string,schema:string}>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * The events an endpoint may subscribe to: all but ping.
     *
     * @return string[]
     */
    public function subscribable(): array
    {
        return array_values(array_filter(array_keys($this->events), static fn (string $t): bool => $t !== self::PING));
    }

    public function has(string $type): bool
    {
        return isset($this->events[$type]);
    }

    public function description(string $type): string
    {
        return $this->events[$type]['description'] ?? '';
    }
}
