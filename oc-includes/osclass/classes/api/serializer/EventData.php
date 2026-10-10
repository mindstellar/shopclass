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

namespace mindstellar\api\serializer;

use mindstellar\api\ApiServices;
use mindstellar\api\read\ListingReader;
use mindstellar\api\read\SiteFacts;
use mindstellar\apikey\ApiSettings;
use mindstellar\apikey\Credential;
use mindstellar\comment\CommentQuery;
use mindstellar\comment\CommentStatus;
use mindstellar\database\Db;
use mindstellar\listing\ListingStatus;
use mindstellar\user\UserQuery;
use mindstellar\utility\Clock;
use mindstellar\webhook\WebhookServices;

/**
 * The `data` of a core event: the resource as an anonymous GET shows it, in the site's default
 * language and ApiSettings::PINNED_VERSION's shape. A listing or comment that is not live is sent
 * as `{id, url, live: false}` only.
 */
final class EventData
{
    public function __construct(
        private ListingReader $listings,
        private Links $links,
        private UserSerializer $userSerializer,
        private SiteFacts $facts,
        private UserQuery $users,
        private CommentQuery $comments,
        private Clock $clock
    ) {
    }

    /**
     * @return array<string,mixed>|null null when there is no such listing
     */
    public function listing(int $id): ?array
    {
        $item = $this->listings->row($id);
        if ($item === null) {
            return null;
        }
        if (ListingStatus::of($item, $this->clock->now()) !== ListingStatus::ACTIVE) {
            return self::notLive($id, $this->links->listing($item));
        }

        return $this->listings->view($item, $this->context());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function user(int $id): ?array
    {
        $user = $this->users->bareRow($id);

        return $user === null ? null : $this->userSerializer->one($user, $this->context());
    }

    /**
     * @return array<string,mixed>|null
     */
    public function comment(int $id): ?array
    {
        $row = $this->comments->find($id);
        if ($row === null) {
            return null;
        }
        if (CommentStatus::of($row) !== CommentStatus::ACTIVE) {
            return self::notLive($id, null) + ['listing_id' => (int) ($row['fk_i_item_id'] ?? 0)];
        }

        return (new CommentSerializer())->one($row);
    }

    /**
     * What the event of a resource the public cannot see holds; the dispatcher sends it thin.
     *
     * @return array{id:int, url:?string, live:false}
     */
    public static function notLive(int $id, ?string $url): array
    {
        return ['id' => $id, 'url' => $url, 'live' => false];
    }

    /**
     * What a deleted resource's event holds.
     *
     * @return array{id:int}
     */
    public static function deleted(int $id): array
    {
        return ['id' => $id];
    }

    /**
     * Register the hook listeners that turn core events into webhooks.
     *
     * @param (\Closure(): self)|null $data the EventData to build each event with; the site's when null
     */
    public static function listen(?\Closure $data = null): void
    {
        $data ??= static fn (): self => ApiServices::site()->eventData();
        $listing = static fn (string $type): \Closure => static function ($item) use ($type, $data): void {
            $id = (int) (is_array($item) ? ($item['pk_i_id'] ?? 0) : $item);
            self::bridge($type, static fn (): ?array => $id > 0 ? $data()->listing($id) : null);
        };
        osc_add_hook('posted_item', $listing('listing.created'));
        osc_add_hook('edited_item', $listing('listing.updated'));
        osc_add_hook('activate_item', $listing('listing.activated'));
        osc_add_hook('deactivate_item', $listing('listing.deactivated'));
        osc_add_hook('item_spam_on', $listing('listing.spam'));
        osc_add_hook('item_marked', static function ($id, $reason): void {
            self::bridge('listing.reported', static fn (): array => ['id' => (int) $id, 'reason' => (string) $reason]);
        });
        osc_add_hook('after_delete_item', static function ($id): void {
            self::bridge('listing.deleted', static fn (): array => self::deleted((int) $id));
        });
        osc_add_hook('add_comment', static function ($id) use ($data): void {
            self::bridge('comment.created', static fn (): ?array => $data()->comment((int) $id));
        });
        osc_add_hook('user_register_completed', static function ($id) use ($data): void {
            self::bridge('user.registered', static fn (): ?array => $data()->user((int) $id));
        });
        osc_add_hook('user_edit_completed', static function ($id) use ($data): void {
            self::bridge('user.updated', static fn (): ?array => $data()->user((int) $id));
        });
        osc_add_hook('after_delete_user', static function ($id): void {
            self::bridge('user.deleted', static fn (): array => self::deleted((int) $id));
        });
    }

    /**
     * A hook's event, never failing the request that fired it. Inside a write's transaction
     * the data is built and queued once it has committed, so no rows stay locked meanwhile,
     * and a write that rolls back sends no event.
     *
     * @param callable(): ?array<string,mixed> $data
     */
    private static function bridge(string $type, callable $data): void
    {
        Db::afterCommit(static function () use ($type, $data): void {
            try {
                WebhookServices::site()->dispatcher()->dispatch($type, $data);
            } catch (\Throwable $e) {
                error_log('webhook: ' . $type . ' was not queued: ' . $e->getMessage());
            }
        });
    }

    private function context(): ViewContext
    {
        return new ViewContext(Credential::anonymous(), $this->facts->defaultLocale(), version: ApiSettings::PINNED_VERSION);
    }
}
