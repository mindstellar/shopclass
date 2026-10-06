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

namespace mindstellar\listing;

use mindstellar\auth\Actor;

/**
 * Who may see, change and post listings, for the web pages and the API alike.
 */
final class ListingPolicy
{
    /** mayPost(): allowed. */
    public const ALLOWED = '';

    /** mayPost(): the site takes listings from signed-in users only. */
    public const REGISTERED_ONLY = 'registered_only';

    /** mayPost(): a ban rule matches the contact e-mail. */
    public const BANNED_EMAIL = 'banned_email';

    /** mayPost(): a ban rule matches the address. */
    public const BANNED_IP = 'banned_ip';

    private function __construct()
    {
    }

    /**
     * Not validated, disabled or spam. An expired listing is not hidden: its own page still
     * shows it, with a notice, while search and lists leave it out through ListingStatus.
     *
     * @param array<string,mixed> $item a t_item row
     */
    public static function isHidden(array $item): bool
    {
        return (int) ($item['b_active'] ?? 0) !== 1
            || (int) ($item['b_enabled'] ?? 0) === 0
            || (int) ($item['b_spam'] ?? 0) === 1;
    }

    /**
     * @param array<string,mixed> $item a t_item row
     */
    public static function isOwner(array $item, Actor $actor): bool
    {
        return $actor->userId() !== null && (int) ($item['fk_i_user_id'] ?? 0) === $actor->userId();
    }

    /**
     * One listing on its own page or at its own API address: everyone unless it is hidden,
     * and then its owner and admins.
     *
     * @param array<string,mixed> $item a t_item row
     */
    public static function canView(array $item, Actor $actor): bool
    {
        return $actor->isAdmin() || !self::isHidden($item) || self::isOwner($item, $actor);
    }

    /**
     * Edit, delete or remove photos: an admin, the owner, or for a guest listing whoever
     * sends its edit secret.
     *
     * @param array<string,mixed> $item a t_item row
     */
    public static function canManage(array $item, Actor $actor): bool
    {
        if ($actor->isAdmin() || self::isOwner($item, $actor)) {
            return true;
        }

        return empty($item['fk_i_user_id']) && self::holdsSecret($item, $actor);
    }

    /**
     * The listing when this actor may manage it, else null.
     *
     * @return array<string,mixed>|null a t_item row
     */
    public static function manageable(int $id, Actor $actor): ?array
    {
        $item = $id > 0 ? \Item::getInstance()->findByPrimaryKey($id) : null;

        return is_array($item) && isset($item['pk_i_id']) && self::canManage($item, $actor) ? $item : null;
    }

    /**
     * Whether the actor sent the listing's edit secret, as the links in its e-mails carry it.
     *
     * @param array<string,mixed> $item a t_item row
     */
    public static function holdsSecret(array $item, Actor $actor): bool
    {
        return $actor->secret() !== '' && hash_equals((string) ($item['s_secret'] ?? ''), $actor->secret());
    }

    /**
     * Whether a photo row belongs to the listing and matches the code sent with it.
     *
     * @param array<string,mixed>|mixed $resource a t_item_resource row, or what a failed lookup returned
     * @param array<string,mixed>       $item
     * @param string                    $code     the photo's s_name as sent
     */
    public static function isPhotoOf(mixed $resource, array $item, string $code): bool
    {
        return is_array($resource)
            && isset($resource['fk_i_item_id'], $resource['s_name'], $item['pk_i_id'])
            && (int) $resource['fk_i_item_id'] === (int) $item['pk_i_id']
            && $code !== ''
            && hash_equals((string) $resource['s_name'], $code);
    }

    /**
     * Whether this actor may post a listing with this contact e-mail. Admins always may.
     *
     * @return string ALLOWED, REGISTERED_ONLY, BANNED_EMAIL or BANNED_IP
     */
    public static function mayPost(Actor $actor, string $email): string
    {
        if ($actor->isAdmin()) {
            return self::ALLOWED;
        }
        if (self::requiresSignIn($actor)) {
            return self::REGISTERED_ONLY;
        }

        return match ((int) osc_is_banned($email, $actor->ip())) {
            1       => self::BANNED_EMAIL,
            2       => self::BANNED_IP,
            default => self::ALLOWED,
        };
    }

    /**
     * Whether a guest gave the e-mail of an account, which has to sign in to post with it. The
     * web form asks before posting; the service itself does not, so plugins that post as a
     * guest keep working.
     */
    public static function usesAccountEmail(Actor $actor, string $email): bool
    {
        return $actor->isGuest() && $email !== '' && isset(\User::getInstance()->findByEmail($email)['pk_i_id']);
    }

    /**
     * Whether the site takes listings from signed-in users only and this actor is not one.
     */
    public static function requiresSignIn(Actor $actor): bool
    {
        return !$actor->isAdmin() && $actor->userId() === null && osc_reg_user_post();
    }

    /**
     * Whether the posting wait since this address's last listing is still running. The
     * wait is the site's, or none for a user whose plan waives it; admins never wait.
     */
    public static function postingTooSoon(Actor $actor): bool
    {
        if ($actor->isAdmin()) {
            return false;
        }
        $wait = (int) osc_items_wait_time_for_user($actor->userId());

        return $wait > 0
            && \LoginAttempt::getInstance()->countByIpContext('item_post', $actor->ip(), date('Y-m-d H:i:s', time() - $wait)) > 0;
    }

    /**
     * The account a listing posted or edited by this actor belongs to, as a t_user row; null
     * for none. A user owns their own. An admin names the owner with $ownerId (0 for none) or,
     * without it, by a contact e-mail that is an account's.
     *
     * @return array<string,mixed>|null
     */
    public static function owner(Actor $actor, ?int $ownerId, string $contactEmail): ?array
    {
        $users = \User::getInstance();
        if ($actor->isAdmin()) {
            $row = $ownerId === null ? $users->findByEmail($contactEmail) : ($ownerId > 0 ? $users->findByPrimaryKey($ownerId) : array());
        } else {
            $row = $actor->userId() === null ? array() : $users->findByPrimaryKey($actor->userId());
        }

        return is_array($row) && isset($row['pk_i_id']) && is_numeric($row['pk_i_id']) ? $row : null;
    }

    /**
     * The status a new listing starts in, 'ACTIVE' or 'INACTIVE', from the site's moderation
     * settings and who posts. Admins post active.
     */
    public static function newListingStatus(Actor $actor): string
    {
        if ($actor->isAdmin()) {
            return 'ACTIVE';
        }
        $moderate = osc_moderate_items();
        $loggedIn = $actor->userId() !== null;
        if ($moderate > 0) {
            if (!$loggedIn) {
                return 'INACTIVE';
            }
            if (osc_logged_user_item_validation()) {
                return 'ACTIVE';
            }
            $user = \User::getInstance()->findByPrimaryKey($actor->userId());

            return $user['i_items'] < $moderate ? 'INACTIVE' : 'ACTIVE';
        }
        if ($moderate == 0) {
            return $loggedIn && osc_logged_user_item_validation() ? 'ACTIVE' : 'INACTIVE';
        }

        return 'ACTIVE';
    }
}
