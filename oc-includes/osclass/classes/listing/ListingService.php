<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\listing;

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\exception\ForbiddenException;
use mindstellar\exception\InvalidException;
use mindstellar\exception\RefusedException;
use mindstellar\fields\FieldQuery;
use mindstellar\security\ActionThrottle;
use mindstellar\user\UserQuery;
use mindstellar\utility\DeferredMail;
use mindstellar\utility\Sanitize;
use mindstellar\utility\ViewScope;

/**
 * Posting, editing and deleting a listing, for the web form, the admin, the API and plugins
 * alike. Each write runs in one transaction and its e-mails go out once it has committed;
 * photos a failed write had stored are removed again. The hooks fire from here, so every
 * caller fires the same ones in the same order.
 *
 * \$data is the listing data ListingInput::read() builds from a form, with the
 * custom field values under 'meta'. An actor with admin rights posts and edits as the admin
 * screen does: no posting wait, no e-mails, no listing limit, the owner may change.
 */
final class ListingService
{
    private \Item $items;
    private Sanitize $sanitize;
    private ListingValidator $validator;
    private PhotoService $photos;

    /** @var string|null the actor and e-mail mayPost() last allowed */
    private ?string $allowed = null;

    /** @var array<int,array<int,array<string,mixed>>> fields() by category */
    private array $fields = array();

    /**
     * Each collaborator defaults to the one the site uses; tests pass their own.
     */
    public function __construct(
        ?\Item $items = null,
        ?Sanitize $sanitize = null,
        ?ListingValidator $validator = null,
        ?PhotoService $photos = null
    ) {
        $this->items     = $items ?? \Item::getInstance();
        $this->sanitize  = $sanitize ?? new Sanitize();
        $this->validator = $validator ?? new ListingValidator();
        $this->photos    = $photos ?? new PhotoService();
    }

    /**
     * Post a listing. Fires `item_add_prepare_data`, `pre_item_add`, `pre_item_add_error`,
     * `uploaded_file` per photo, the new-listing e-mail hooks, `item_increase_stat` and
     * `posted_item`. Over the listing limit with pay at post on, a non-admin post is saved hidden
     * and unpaid and fires none of the e-mail or `posted_item` hooks until publish().
     *
     * @param array<string,mixed> $data
     * @param bool                $import admin rights that still apply the listing limit and moderation
     *
     * @throws ForbiddenException when ListingPolicy::mayPost() refuses the actor
     * @throws InvalidException   with the form's errors, each with its member, code and message
     */
    public function create(array $data, Actor $actor, bool $import = false): SavedListing
    {
        $this->mayPost($actor, (string) ($data['contactEmail'] ?? ''));
        // A post refused for coming too soon resizes no photo.
        $tooSoon = ListingPolicy::postingTooSoon($actor);
        $files   = $tooSoon || empty($data['photos']['error']) ? null : $data['photos'];

        return $this->write(
            fn (array &$notices): array => $this->checkAdd($data, $actor, $tooSoon, $notices),
            fn (array $checked): SavedListing => $this->insert($checked, $actor, $import),
            $files,
            $files === null ? null : PhotoService::cap(isset($data['userId']) ? (int) $data['userId'] : null)
        );
    }

    /**
     * Refuse a post ListingPolicy::mayPost() refuses. A caller may ask before it reads the
     * listing; create() then does not ask again for the same actor and e-mail.
     *
     * @throws ForbiddenException
     */
    public function mayPost(Actor $actor, string $email): void
    {
        $key = implode('|', array((string) $actor->userId(), (string) $actor->adminId(), $actor->ip(), $email));
        if ($this->allowed === $key) {
            return;
        }
        $refusal = ListingPolicy::mayPost($actor, $email);
        if ($refusal !== ListingPolicy::ALLOWED) {
            throw new ForbiddenException(self::refusalMessage($refusal), match ($refusal) {
                ListingPolicy::REGISTERED_ONLY => ForbiddenException::SIGN_IN,
                ListingPolicy::SHOP_MODE       => ForbiddenException::DISABLED,
                default                        => ForbiddenException::BANNED,
            });
        }
        $this->allowed = $key;
    }

    /**
     * The custom fields a category's listings take, read once for this service.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fields(int $categoryId): array
    {
        return $this->fields[$categoryId] ??= \Field::getInstance()->findByCategory($categoryId);
    }

    /**
     * Save an edit. Fires `item_edit_prepare_data`, `pre_item_edit`, `pre_item_edit_error`,
     * `uploaded_file` per photo and `edited_item`.
     *
     * @param array<string,mixed> $data        with 'idItem' and, from a form, 'secret'
     * @param bool                $import      admin rights that still apply moderation
     * @param bool                $matchSecret the save matches the secret in $data, as a form
     *                                         carries it; an admin saving plain data has none
     * @param callable|null       $then        more writes for the edit's transaction, given the saved edit
     *
     * @throws InvalidException with the form's errors, each with its member, code and message
     */
    public function update(array $data, Actor $actor, bool $import = false, bool $matchSecret = true, ?callable $then = null): SavedListing
    {
        // An edit whose secret does not match resizes no photo.
        $files = empty($data['photos']['error']) ? null : $data['photos'];
        $id    = (int) ($data['idItem'] ?? 0);
        $row   = $files === null ? null : ListingStore::find($id, array('s_secret', 'fk_i_user_id'));
        if ($row === null || ($matchSecret && !hash_equals((string) $row['s_secret'], (string) ($data['secret'] ?? '')))) {
            $files = null;
        }

        return $this->write(
            fn (array &$notices): array => $this->checkEdit($data, $actor, $notices),
            function (array $checked) use ($actor, $import, $matchSecret, $then): SavedListing {
                $saved = $this->change($checked, $actor, $import, $matchSecret);
                if ($then !== null) {
                    $then($saved);
                }

                return $saved;
            },
            $files,
            $row === null ? null : PhotoService::room($id, (int) $row['fk_i_user_id'])
        );
    }

    /**
     * Save the listing form for a web, admin or plugin caller and flash the photo notices.
     * The caller puts the posted custom field values under 'meta' (see ListingInput::withMeta()).
     *
     * @param array<string,mixed> $data
     *
     * @return SavedListing|string what was saved, or the refusal's message
     */
    public function saveForm(array $data, Actor $actor, bool $isAdd, bool $import = false, bool $matchSecret = true): SavedListing|string
    {
        try {
            $saved = $isAdd ? $this->create($data, $actor, $import) : $this->update($data, $actor, $import, $matchSecret);
        } catch (RefusedException $e) {
            ListingNotices::flash($e->notices(), $actor->isAdmin());

            return $e->getMessage();
        }
        ListingNotices::flash($saved->notices(), $actor->isAdmin());

        return $saved;
    }

    /**
     * What ItemActions has always answered for a save: 1 when a new listing waits for
     * validation, 2 when not, else the rows an edit changed, or the refusal's message.
     */
    public static function legacyResult(SavedListing|string $result, bool $isAdd): int|string|false|null
    {
        if (is_string($result)) {
            return $result;
        }
        if (!$isAdd) {
            return $result->rows();
        }

        return $result->needsValidation() ? 1 : 2;
    }

    /**
     * Delete a listing whose secret matches. Fires `before_delete_item`, then on success
     * `after_delete_item`.
     *
     * @return int|false rows deleted, or false
     */
    public function delete(int $itemId, string $secret, Actor $actor): int|false
    {
        $item = $this->items->findByPrimaryKey($itemId);
        // Before the transaction opens, so a plugin that redirects from it leaves none open.
        osc_run_hook('before_delete_item', $itemId);

        return DeferredMail::transaction(function () use ($itemId, $secret, $actor, $item) {
            // Locked before the delete reads anything, so a concurrent edit or views flush waits here.
            if (ListingStore::lockForWrite($itemId) === null) {
                return false;
            }
            if (is_array($item) && hash_equals((string) ($item['s_secret'] ?? ''), $secret)) {
                \Log::getInstance()
                    ->insertLog(
                        'item',
                        'delete',
                        $itemId,
                        $item['s_title'],
                        $actor->logRole(),
                        $actor->logId()
                    );
                $result = $this->items->deleteByPrimaryKey($itemId, $actor);
                if ($result !== false) {
                    osc_run_hook('after_delete_item', $itemId, $item);
                }

                return $result;
            }

            return false;
        });
    }

    /**
     * Take a listing off the site for moderation. Fires `disable_item`.
     *
     * @param int|string                $id
     * @param array<string,mixed>|null $item the listing as Item::findByPrimaryKey() gives it, when the caller has it
     */
    public function disable(int|string $id, ?array $item = null): bool
    {
        $result = $this->items->update(
            array('b_enabled' => 0),
            array('pk_i_id' => $id)
        );

        // updated correctly
        if ($result == 1) {
            osc_run_hook('disable_item', $id);
            if ($item === null) {
                $item = $this->items->findByPrimaryKey($id);
            } else {
                $item['b_enabled'] = '0';
            }
            if (osc_item_is_counted(array('b_enabled' => 1) + $item)) {
                ListingStats::decrease($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Mark a listing validated: the owner confirmed it. One the admin has not enabled yet is
     * still marked validated and stays hidden until enabled. A listing waiting for payment
     * is left alone. Fires `activate_item`.
     *
     * @param string|null $secret the listing's edit secret, when the caller must match it
     *
     * @return bool|null null when there is no such listing, the secret is wrong, it was
     *                   already validated or it is waiting for payment
     */
    public function activate(int $id, ?string $secret = null): ?bool
    {
        $where = array('pk_i_id' => $id);
        if ($secret !== null) {
            $where['s_secret'] = $secret;
        }
        // Locked first: in a transaction the read below must not open the snapshot before the update.
        if (Db::inTransaction()) {
            ListingStore::find($id, array('pk_i_id'), true);
        }
        $item = $this->items->findByPrimaryKey($id);
        if (!is_array($item) || !isset($item['b_active']) || (int) $item['b_active'] !== 0
            || ($secret !== null && !hash_equals((string) $item['s_secret'], $secret))
            || ListingHoldJobs::holds($id, Db::stringifyRow($item))
        ) {
            return null;
        }
        if ($this->items->update(array('b_active' => 1), $where) != 1) {
            return false;
        }

        osc_run_hook('activate_item', $id);
        if (osc_item_is_counted(array('b_active' => 1) + $item)) {
            ListingStats::increase($item);
        }

        return true;
    }

    /**
     * Send a listing back to waiting for validation. Fires `deactivate_item`.
     */
    public function deactivate(int $id): bool
    {
        if ($this->items->update(array('b_active' => 0), array('pk_i_id' => $id)) != 1) {
            return false;
        }

        osc_run_hook('deactivate_item', $id);
        $item = $this->items->findByPrimaryKey($id);
        if (osc_item_is_counted(array('b_active' => 1) + $item)) {
            ListingStats::decrease($item);
        }

        return true;
    }

    /**
     * Put a listing back on the site after moderation. A listing waiting for payment is left
     * alone: only publish() puts it live. Fires `enable_item`.
     */
    public function enable(int $id): bool
    {
        $row = ListingStore::find($id, array('b_enabled', 'b_active', 'dt_first_pub_date'), Db::inTransaction());
        if ($row !== null && ListingHoldJobs::holds($id, Db::stringifyRow($row))) {
            return false;
        }
        $result = $this->items->update(
            array('b_enabled' => 1),
            array('pk_i_id' => $id)
        );

        // updated correctly
        if ($result == 1) {
            osc_run_hook('enable_item', $id);
            $item = $this->items->findByPrimaryKey($id);
            if (osc_item_is_counted(array('b_enabled' => 1) + $item)) {
                ListingStats::increase($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Move a listing to the top of "newest first" by setting its publish date. Fires
     * `item_bumped` once it moved, unless $announce takes that over.
     *
     * @param string|null   $at       the new publish date, now when null
     * @param callable|null $announce runs instead of the hook once the date moved
     *
     * @return bool false when no listing changed
     */
    public function bump(int $id, ?string $at = null, ?callable $announce = null): bool
    {
        $moved = ListingStore::setPubDate($id, $at ?? date('Y-m-d H:i:s'));
        if ($moved < 1) {
            return false;
        }
        if ($announce !== null) {
            $announce();
        } else {
            osc_run_hook('item_bumped', $id);
        }
        osc_invalidate_search_cache();

        return true;
    }

    /**
     * Make a listing premium or not. Fires `item_premium_on` or `item_premium_off`.
     *
     * $days makes the upgrade time-limited: the listing stops being premium once
     * dt_premium_expiration passes and a job queued for that time flips it back. Omitting it keeps
     * the historical behaviour of a permanent, admin-granted upgrade with no end date.
     *
     * @param int      $id
     * @param bool     $on
     * @param int|null $days     Days the upgrade lasts, or null for no expiry
     * @param bool     $fireHook Whether to fire item_premium_on/item_premium_off on
     *                           success. False lets a caller that will announce the
     *                           change itself once its own work has fully landed --
     *                           the billing feature that drives this, once its spend
     *                           has committed -- skip the immediate one here.
     *
     * @return bool
     */
    public function premium(int $id, bool $on = true, ?int $days = null, bool $fireHook = true): bool
    {
        $value = 0;
        if ($on) {
            $value = 1;
        }

        $set = array('b_premium' => $value);

        // Turning premium off always clears the date, so a later permanent grant does
        // not inherit a stale expiry and get swept away an hour after it is made.
        $set['dt_premium_expiration'] = null;

        // Just the columns needed here, not findByPrimaryKey(): that hydrates locales and
        // resources this decision has no use for.
        $current = ListingStore::premiumState((int) $id, Db::inTransaction());

        if ($on && $days !== null) {
            if (!empty($current['b_premium']) && empty($current['dt_premium_expiration'])) {
                // Already premium with no end date. A dated purchase must not turn an
                // open-ended upgrade into one that expires.
                unset($set['dt_premium_expiration']);
            } else {
                // Extend from whatever time is left, never from now: a repurchase must
                // add to the spot the seller already paid for rather than replace it,
                // which a shortened billing_premium_days would otherwise make a downgrade.
                // Calendar arithmetic, not $days * 86400, so a DST change cannot move it.
                $remaining = isset($current['dt_premium_expiration'])
                    ? strtotime((string) $current['dt_premium_expiration'])
                    : false;
                $base      = ($remaining !== false && $remaining > time()) ? $remaining : time();

                $set['dt_premium_expiration'] = date('Y-m-d H:i:s', strtotime('+' . (int) $days . ' days', $base));
            }
        }

        $result = $this->items->update(
            $set,
            array('pk_i_id' => $id)
        );
        // updated correctly
        if ($result == 1) {
            if (!empty($set['dt_premium_expiration'])) {
                \mindstellar\billing\Premium::scheduleEnd($set['dt_premium_expiration']);
            }
            // An expired listing is counted only while premium, so the switch can move it.
            if ($current && osc_item_is_counted($current) !== osc_item_is_counted(array('b_premium' => $value) + $current)) {
                $item = $this->items->findByPrimaryKey($id);
                if ($on) {
                    ListingStats::increase($item);
                } else {
                    ListingStats::decrease($item);
                }
            }
            if ($fireHook) {
                if ($on) {
                    osc_run_hook('item_premium_on', $id);
                } else {
                    osc_run_hook('item_premium_off', $id);
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Mark a listing as spam or not. Fires `item_spam_on` or `item_spam_off`.
     */
    public function spam(int $id, bool $on = true): bool
    {
        if (Db::inTransaction()) {
            ListingStore::find($id, array('pk_i_id'), true);
        }
        $item = $this->items->findByPrimaryKey($id);
        if ($on) {
            $result = $this->items->update(
                array('b_spam' => '1'),
                array('pk_i_id' => $id)
            );
        } else {
            $result = $this->items->update(
                array('b_spam' => '0'),
                array('pk_i_id' => $id)
            );
        }

        // updated corretcly
        if ($result == 1) {
            if ($on) {
                osc_run_hook('item_spam_on', $id);
            } else {
                osc_run_hook('item_spam_off', $id);
            }

            $before = osc_item_is_counted($item);
            $after  = osc_item_is_counted(array('b_spam' => $on ? 1 : 0) + $item);
            if ($before && !$after) {
                ListingStats::decrease($item);
            } elseif (!$before && $after) {
                ListingStats::increase($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Count a visitor's report: 'spam', 'badcat', 'offensive', 'repeated' or 'expired'.
     * Fires the `item_mark` filter first, then `item_marked`.
     */
    public function mark(int $id, string $as): void
    {
        switch ($as) {
            case 'spam':
                $column = 'i_num_spam';
                break;
            case 'badcat':
                $column = 'i_num_bad_classified';
                break;
            case 'offensive':
                $column = 'i_num_offensive';
                break;
            case 'repeated':
                $column = 'i_num_repeated';
                break;
            case 'expired':
                $column = 'i_num_expired';
                break;
        }

        if (isset($column)) {
            // Gate the mark before it counts: a listener returns false to block it (per-reporter
            // dedup, rate-limit, captcha) — core applies no such control of its own. Only when it
            // passes does the counter move and the after-hook fire for the reaction (threshold
            // auto-block, logging, notifications).
            if (osc_apply_filter('item_mark', true, $id, $as) === false) {
                return;
            }
            if (\ItemStats::getInstance()->increase($column, $id) !== false) {
                osc_run_hook('item_marked', $id, $as);
            }
        }
    }

    /**
     * Write one title and description per locale. An edit skips a locale whose text has not
     * changed, and an empty one that has no row yet.
     *
     * @param string               $type        'ADD' or 'EDIT'
     * @param array<string,string> $title       Title per locale
     * @param array<string,string> $description Description per locale
     * @param int|string           $itemId
     *
     * @return bool False when a locale could not be written
     */
    public function writeLocales(string $type, array $title, array $description, int|string $itemId): bool
    {
        $stored = $type === 'EDIT' ? $this->storedLocales((int) $itemId) : array();

        return $this->writeTexts($type, $title, $description, $itemId, $stored);
    }

    /**
     * writeLocales() with the stored texts already read; $stored then holds what is stored.
     *
     * @param array<string,string>                    $title
     * @param array<string,string>                    $description
     * @param array<string,array{0:string,1:string}> $stored
     */
    private function writeTexts(string $type, array $title, array $description, int|string $itemId, array &$stored): bool
    {
        foreach ($title as $locale => $_data) {
            $_title       = $_data;
            $_description = $description[$locale];
            $written      = true;
            if ($type === 'EDIT' && self::sameText($stored[$locale] ?? null, (string) $_title, (string) $_description)) {
                // The write is skipped, but plugins still hear of every locale an edit saved, as before.
                osc_run_hook('item_content_updated', (int) $itemId, $locale);
                continue;
            }
            if ($type === 'ADD') {
                $written = $this->items->insertLocale($itemId, $locale, $_title, $_description);
            } elseif ($type === 'EDIT') {
                $written = $this->items->updateLocaleForce($itemId, $locale, $_title, $_description);
            }
            if (!$written) {
                trigger_error('Item locale ' . $locale . ' was not written for item ' . $itemId . '.', E_USER_WARNING);

                return false;
            }
            $stored[$locale] = array((string) \Item::fitTitle((string) $_title), (string) $_description);
        }

        return true;
    }

    /**
     * The listing's stored title and description per locale.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private function storedLocales(int $itemId): array
    {
        $stored = array();
        foreach ((new ListingQuery())->descriptions(array($itemId)) as $row) {
            $stored[(string) $row['fk_c_locale_code']] = array((string) $row['s_title'], (string) $row['s_description']);
        }

        return $stored;
    }

    /**
     * Whether a locale's text matches what is stored, or is empty with nothing stored.
     *
     * @param array{0:string,1:string}|null $stored
     */
    private static function sameText(?array $stored, string $title, string $description): bool
    {
        if ($stored === null) {
            return $title === '' && $description === '';
        }

        return $stored[0] === \Item::fitTitle($title) && $stored[1] === $description;
    }

    /**
     * The t_item_description rows of these stored texts, as a read gives them.
     *
     * @param array<string,array{0:string,1:string}> $stored from writeTexts()
     *
     * @return array<int,array<string,string>>
     */
    private static function textRows(int $itemId, array $stored): array
    {
        ksort($stored, SORT_STRING);
        $rows = array();
        foreach ($stored as $locale => [$text, $body]) {
            $rows[] = array('fk_i_item_id' => (string) $itemId, 'fk_c_locale_code' => (string) $locale, 's_title' => $text, 's_description' => $body);
        }

        return $rows;
    }

    /**
     * Fire the e-mail hooks for a new listing: its activation link, or the posted notice
     * for a guest, and the admin's notice when the site asks for one.
     *
     * @param array<string,mixed> $item   the new t_item row
     * @param string              $active 'ACTIVE' or 'INACTIVE'
     */
    public function notifyNew(array $item, string $active, Actor $actor): void
    {
        // A mail that cannot be written is logged: it must not roll back the save, or a paid publish.
        $guard = static function (callable $send) use ($item): void {
            try {
                $send();
            } catch (\Throwable $e) {
                error_log('Listing #' . $item['pk_i_id'] . ': a new-listing e-mail failed: ' . $e->getMessage());
            }
        };
        ViewScope::withItem($item, static function () use ($item, $active, $actor, $guard): void {
            $registered = $actor->userId() !== null;
            if ($active === 'INACTIVE' && !$registered) {
                $guard(static fn () => osc_run_hook('hook_email_item_validation_non_register_user', $item));
            } elseif ($active === 'INACTIVE') {
                $guard(static fn () => osc_run_hook('hook_email_item_validation', $item));
            } elseif (!$registered) {
                $guard(static fn () => osc_run_hook('hook_email_new_item_non_register_user', $item));
            }

            if (osc_notify_new_item()) {
                $guard(static fn () => osc_run_hook('hook_email_admin_new_item', $item));
            }
        });
    }

    /**
     * What follows a listing going live: the e-mails, the counters, the moderation hold and
     * `posted_item`. A new listing runs it when it is saved, a held one when it is published.
     *
     * @param array<string,mixed>      $item     the listing row with its texts
     * @param array<string,mixed>|null $location its location row, read when not given
     */
    private function announce(array $item, string $active, Actor $actor, bool $import, ?array $location = null): void
    {
        if (!$actor->isAdmin()) {
            $this->notifyNew($item, $active, $actor);
        }

        // A spam-flagged listing is not counted.
        if ($active !== 'INACTIVE' && (int) ($item['b_spam'] ?? 0) === 0) {
            $location ??= ListingStore::location((int) $item['pk_i_id']) ?? array();
            ListingStats::increase(array(
                'fk_i_user_id'      => $item['fk_i_user_id'] === null ? null : (int) $item['fk_i_user_id'],
                'fk_i_category_id'  => (int) $item['fk_i_category_id'],
                'fk_c_country_code' => $location['fk_c_country_code'] ?? null,
                'fk_i_region_id'    => $location['fk_i_region_id'] ?? null,
                'fk_i_city_id'      => $location['fk_i_city_id'] ?? null,
            ));
        }

        if ((!$actor->isAdmin() || $import) && osc_moderate_admin_post()) {
            $this->disable($item['pk_i_id'], $item);
        }

        // Listeners may read the new listing through osc_item_*().
        ViewScope::withItem($item, static fn () => osc_run_hook('posted_item', $item));
    }

    /**
     * Put a held listing live: it starts its run now, takes its moderation status from its
     * owner, and gets the e-mails and `posted_item` a new listing gets. A listing that is not
     * held, or that another call published first, is left alone. It charges nothing: a seller
     * pays through ListingCheckout::publish(), which calls it.
     *
     * @return bool whether this call published it
     */
    public function publish(int $id, Actor $actor): bool
    {
        return (bool) DeferredMail::transaction(function () use ($id, $actor): bool {
            $row = ListingStore::lockForWrite($id);
            if ($row === null || !ListingStatus::isHeld(Db::stringifyRow($row))) {
                return false;
            }
            // Before any read: the worker may be claiming these jobs right now.
            ListingHoldJobs::cancel($id);
            $ownerId = (int) $row['fk_i_user_id'];
            $active  = ListingPolicy::newListingStatus($ownerId > 0 ? Actor::user($ownerId) : Actor::guest());
            $now     = date('Y-m-d H:i:s');

            $updated = Db::execute(
                'UPDATE ' . $this->items->getTableName() . ' SET b_enabled = 1, b_active = ?, dt_pub_date = ?, dt_first_pub_date = ?, dt_mod_date = ?'
                . ' WHERE pk_i_id = ? AND dt_first_pub_date IS NULL',
                array($active === 'ACTIVE' ? 1 : 0, $now, $now, $now, $id)
            );
            if (!$updated) {
                return false;
            }

            $days = ListingInput::expirationDays((int) $row['fk_i_category_id'], $ownerId > 0 ? $ownerId : null);
            // No run time is the column's "never" value.
            if ($days === '' || $days === 0) {
                Db::execute('UPDATE ' . $this->items->getTableName() . ' SET dt_expiration = ? WHERE pk_i_id = ?', array('9999-12-31 23:59:59', $id));
            } else {
                // announce() counts it; the expiry update must not count it as well.
                $this->items->updateExpirationDate($id, (string) $days, false);
            }

            $texts = $this->storedLocales($id);
            $fresh = ListingStore::find($id, array('*'));
            if ($fresh === null) {
                return false;
            }
            $item = $this->items->extendRows(array(Db::stringifyRow($fresh)), null, self::textRows($id, $texts))[0];

            \Log::getInstance()->insertLog('item', 'publish', $id, (string) ($item['s_title'] ?? ''), $actor->logRole(), $actor->logId());
            $this->announce($item, $active, $actor, !$actor->isAdmin());

            return true;
        });
    }

    /**
     * The message for a guest who used an account's e-mail.
     */
    public static function accountEmailMessage(): string
    {
        return _m('A user with that email address already exists, if it is you, please log in');
    }

    /**
     * The message for a mayPost() refusal.
     */
    public static function refusalMessage(string $refusal): string
    {
        return match ($refusal) {
            ListingPolicy::SHOP_MODE       => _m('Only the shop owner can add products'),
            ListingPolicy::REGISTERED_ONLY => _m('Only registered users are allowed to post listings'),
            ListingPolicy::BANNED_EMAIL    => _m('Your current email is not allowed'),
            default                        => _m('Your current IP is not allowed'),
        };
    }

    /**
     * Run a write in one transaction with e-mails held until it commits, and remove the
     * files of photos it stored when it fails. What went wrong with a photo goes with the
     * result, or with the refusal, for the form to show. The photos are resized and the
     * form is checked before the transaction opens: a plugin hook that redirects from the
     * check leaves no transaction open, and the transaction holds its locks only while rows
     * are written.
     *
     * @param callable(string[]&): array<string,mixed> $check the prepare filter, validation and pre hooks
     * @param callable(array<string,mixed>): SavedListing $fn the writes, given what $check returned
     * @param mixed                             $files the photos to resize first; null for none
     * @param int|null                          $cap   how many of them the listing may take; null for no cap
     */
    private function write(callable $check, callable $fn, mixed $files, ?int $cap): SavedListing
    {
        $notices = array();
        $photos  = $this->photos;
        try {
            $saved = PhotoService::cleanUpOnFailure(
                $photos,
                static function () use ($check, $fn, &$notices, $photos, $files, $cap): SavedListing {
                    $photos->prepare($files, $cap);
                    $checked = $check($notices);

                    return DeferredMail::transaction(static fn (): SavedListing => $fn($checked));
                }
            );
        } catch (DbException $e) {
            // A deadlock or snapshot conflict: the server undid the save, so nothing was written.
            if (!in_array($e->getCode(), Db::LOST_TRANSACTION, true)) {
                throw $e;
            }
            throw self::notSaved()->withNotices(array_merge($notices, $this->photos->notices()));
        } catch (RefusedException $e) {
            throw $e->withNotices(array_merge($notices, $this->photos->notices()));
        }

        return $saved->withNotices(array_merge($notices, $this->photos->notices()));
    }

    /** The refusal for a save the database did not keep. */
    private static function notSaved(): InvalidException
    {
        return new InvalidException('', 'rejected', _m('Your listing could not be saved. Please try again.'));
    }

    /**
     * Filter and check a new listing: `item_add_prepare_data`, the validation, `pre_item_add`
     * and `pre_item_add_error`. Reads only, so it runs before the transaction opens.
     *
     * @param array<string,mixed> $data
     * @param string[]            $notices
     *
     * @return array{item:array<string,mixed>,meta:mixed,spam:int,active:string}
     * @throws InvalidException with the form's errors
     */
    private function checkAdd(array $data, Actor $actor, bool $tooSoon, array &$notices): array
    {
        $aItem   = osc_apply_filter('item_add_prepare_data', $data);
        $is_spam = 0;

        // Check status
        $active = $aItem['active'];

        $aItem                 = $this->sanitize($aItem);
        $aItem['contactName']  = trim($this->sanitize->string($aItem['contactName']));
        $aItem['contactEmail'] = $this->sanitize->email($aItem['contactEmail']);
        $aItem['cityArea']     = $aItem['cityArea'] ? self::place($aItem['cityArea']) : '';
        $aItem['address']      = $aItem['address'] ? self::place($aItem['address']) : '';

        // Anonymous
        $aItem['contactName'] = osc_validate_text($aItem['contactName'], 3) ? $aItem['contactName'] : __('Anonymous');

        // Validate
        $errors = self::ownerErrors($aItem, $actor);
        if (!osc_validate_max($aItem['contactName'], 35)) {
            $errors[] = ListingValidator::entry('/contact_name', 'too_long', _m('Name too long.'));
        }
        if (!osc_validate_email($aItem['contactEmail'])) {
            $errors[] = ListingValidator::entry('/contact_email', 'invalid', _m('Email invalid.'));
        }
        // The name already has its tighter cap above.
        $errors = array_merge(
            $errors,
            $this->validator->contactWidths(array('contactEmail' => $aItem['contactEmail'])),
            $this->validator->common($aItem, $notices)
        );

        // The wait is the global preference unless the posting user holds a
        // listing.no_wait entitlement; a guest always waits the global one.
        if ($tooSoon) {
            $errors[] = ListingValidator::entry('', 'too_fast', _m('Too fast. You should wait a little to publish your ad.'));
        }

        // akismet check spam ...
        if ($this->validator->isSpam($aItem['title'], $aItem['description'], $aItem['contactName'], $aItem['contactEmail'], (string) ($aItem['s_ip'] ?? ''))) {
            $is_spam = 1;
        }
        [$meta, $metaErrors] = $this->customFields($aItem);
        $errors              = array_merge($errors, $metaErrors);
        $errors              = array_merge($errors, $this->productErrors($aItem, $actor));

        $text = self::errorText($errors);
        osc_run_hook('pre_item_add', $aItem, $text);
        $errors = self::pluginErrors($errors, $text, (string) osc_apply_filter('pre_item_add_error', $text, $aItem));

        if ($errors !== array()) {
            throw self::refuse($errors);
        }

        return array('item' => $aItem, 'meta' => $meta, 'spam' => $is_spam, 'active' => $active);
    }

    /**
     * Write a new listing checkAdd() passed, and fire its e-mail hooks and `posted_item`.
     *
     * @param array{item:array<string,mixed>,meta:mixed,spam:int,active:string} $checked
     */
    private function insert(array $checked, Actor $actor, bool $import): SavedListing
    {
        ['item' => $aItem, 'meta' => $meta, 'spam' => $is_spam, 'active' => $active] = $checked;
        $enabled = 1;
        $code    = osc_genRandomPassword();
        $hold    = false;

        // The one choke point for the listing quota: guests and admin posts are not metered.
        // Over the limit the listing is held until paid for, or refused (see postingRule()).
        // It runs here, inside the transaction, so the seller lock holds until the insert commits.
        if ((!$actor->isAdmin() || $import) && osc_billing_credits_enabled() && !empty($aItem['userId'])) {
            // Locked, so two posts or a post and a publish cannot both take the last free slot.
            \mindstellar\billing\EntitlementStore::lockSeller((int) $aItem['userId']);
            $rule = \mindstellar\billing\ListingCheckout::postingRule((int) $aItem['userId'], array('item' => $aItem), true, !$actor->isAdmin());
            if ($rule === \mindstellar\billing\ListingCheckout::POST_HOLD) {
                $hold = true;
            } elseif ($rule === \mindstellar\billing\ListingCheckout::POST_REFUSE) {
                throw self::refuse(array(ListingValidator::entry('', 'listing_limit', osc_listing_limit_message((int) $aItem['userId'], $aItem))));
            }
        }

        if (empty($aItem['price'])) {
            $aItem['currency'] = null;
        }

        // Capture the new id from the insert itself (see DAO::insertGetId), not a later
        // decoupled read of the shared connection's insert_id, which intermittently came
        // back 0 and cascaded into FK-failing child inserts and an empty posted_item hook.
        // dt_first_pub_date records the listing's original publish date, distinct from
        // dt_pub_date (the sort key a bump is free to move) -- this insert is the ONLY
        // place that ever writes it, so it stays the one durable record of when the
        // listing first went live even after a later bump moves dt_pub_date forward.
        $publishedAt = date('Y-m-d H:i:s');
        $itemId = $this->items->insertGetId(array(
            'fk_i_user_id'       => $aItem['userId'],
            'dt_pub_date'        => $publishedAt,
            'dt_first_pub_date'  => $hold ? null : $publishedAt,
            'fk_i_category_id'   => $aItem['catId'],
            'i_price'            => $aItem['price'],
            'fk_c_currency_code' => $aItem['currency'],
            's_contact_name'     => $aItem['contactName'],
            's_contact_email'    => $aItem['contactEmail'],
            's_contact_phone'    => $aItem['contactPhone'],
            's_secret'           => $code,
            'b_active'           => !$hold && $active === 'ACTIVE' ? 1 : 0,
            'b_enabled'          => $hold ? 0 : 1,
            'b_show_email'       => $aItem['showEmail'],
            'b_spam'             => $is_spam,
            's_ip'               => $aItem['s_ip']
        ));

        // The parent insert must have produced a row before any of the child inserts
        // below run. On production the parent has been seen to leave no durable row while
        // insertedId() returns 0 and nothing logs a failure; carrying on then FK-fails all
        // five child inserts (locales, location, resources, meta, stats) against a
        // non-existent item and fires posted_item with an empty payload. Abort cleanly with
        // an error the caller shows and redirects on, and make the silent failure visible.
        if (!$itemId) {
            trigger_error(
                'Item insert produced no row (insertedId=0); aborting before child inserts.',
                E_USER_WARNING
            );

            throw self::notSaved();
        }

        // Written first so a refused title or description removes the new row again,
        // rather than leaving a live listing with no text.
        $texts = array();
        if (!$this->writeTexts('ADD', $aItem['title'], $aItem['description'], $itemId, $texts)) {
            throw self::notSaved();
        }

        if (!$actor->isAdmin()) {
            // Counts toward the posting wait in ListingPolicy::postingTooSoon().
            $wait = (int) osc_items_wait_time_for_user($actor->userId());
            Db::afterCommit(static function () use ($wait): void {
                ActionThrottle::record('item_post', $wait);
            });
        }

        \Log::getInstance()->insertLog(
            'item',
            'add',
            $itemId,
            current(array_values($aItem['title'])),
            $actor->logRole(),
            $actor->logId()
        );

        $location = $this->locationRow($aItem, $itemId);

        $locationManager = \ItemLocation::getInstance();
        // The listing row already exists, so there is nothing useful to tell the
        // poster here -- but a refused location write leaves a listing that no
        // location search will ever return, and DAO::insert() reports it only in
        // its return value. The length checks above make user input a clean
        // rejection instead; what is left is a filtered or plugin-supplied value.
        if (!$locationManager->insert($location)) {
            trigger_error('Item location insert wrote no row for item ' . $itemId . '.', E_USER_WARNING);
        } else {
            ListingStore::stampPoint((int) $itemId);
            if (ListingGeocode::wanted($location)) {
                ListingGeocode::queueAfterCommit((int) $itemId);
            }
        }

        $this->photos->store($aItem['photos'], $itemId);

        // A held listing's deadline is the hold; its run starts when it goes live.
        if ($hold) {
            \Item::getInstance()->updateExpirationDate($itemId, (string) osc_billing_hold_days(), false);
        } else {
            // A day count must reach it as text: it tells days from a date by ctype_digit().
            $expiry = $aItem['dt_expiration'];
            \Item::getInstance()->updateExpirationDate($itemId, is_int($expiry) ? (string) $expiry : $expiry);
        }

        $this->writeMeta($meta, $itemId);
        $this->writeProduct((int) $itemId, $aItem, $actor);

        // We need at least one record in t_item_stats
        $mStats = new \ItemStats();
        $mStats->emptyRow($itemId);

        if ($hold) {
            // In this transaction: a held row with no delete job must not be saved.
            try {
                ListingHoldJobs::queue((int) $itemId, osc_billing_hold_days());
            } catch (\RuntimeException $e) {
                error_log($e->getMessage());

                throw self::notSaved();
            }

            return new SavedListing((int) $itemId, false, null, true);
        }

        $row = ListingStore::find((int) $itemId, array('*'));
        if ($row === null) {
            trigger_error('Item ' . $itemId . ' is gone before posted_item; aborting.', E_USER_WARNING);

            throw self::notSaved();
        }
        $item          = $this->items->extendRows(array(Db::stringifyRow($row)), null, self::textRows((int) $itemId, $texts))[0];
        $aItem['item'] = $item;

        $this->announce($item, $active, $actor, $import, $location);

        return new SavedListing((int) $itemId, $active === 'INACTIVE');
    }

    /**
     * Filter and check an edit: `item_edit_prepare_data`, the validation, `pre_item_edit` and
     * `pre_item_edit_error`. Reads only, so it runs before the transaction opens.
     *
     * @param array<string,mixed> $data
     * @param string[]            $notices
     *
     * @return array{item:array<string,mixed>,meta:mixed}
     * @throws InvalidException with the form's errors
     */
    private function checkEdit(array $data, Actor $actor, array &$notices): array
    {
        $aItem           = osc_apply_filter('item_edit_prepare_data', $data);
        $aItem['idItem'] = (int) $aItem['idItem'];

        $aItem             = $this->sanitize($aItem);
        $aItem['cityArea'] = self::place($aItem['cityArea']);
        $aItem['address']  = self::place($aItem['address']);

        // Validate
        $errors = array_merge(
            self::ownerErrors($aItem, $actor, $aItem['idItem']),
            $this->validator->common($aItem, $notices)
        );
        // Only an admin editing a listing with no owner writes the contact name and e-mail.
        if ($actor->isAdmin() && !$aItem['userId']) {
            $errors = array_merge($errors, $this->validator->contactWidths($aItem));
        }

        [$meta, $metaErrors] = $this->customFields($aItem);
        $errors              = array_merge($errors, $metaErrors);
        $errors              = array_merge($errors, $this->productErrors($aItem, $actor));

        $text = self::errorText($errors);
        osc_run_hook('pre_item_edit', $aItem, $text);
        $errors = self::pluginErrors($errors, $text, (string) osc_apply_filter('pre_item_edit_error', $text, $aItem));

        if ($errors !== array()) {
            throw self::refuse($errors);
        }

        return array('item' => $aItem, 'meta' => $meta);
    }

    /**
     * Write an edit checkEdit() passed, and fire `edited_item`.
     *
     * @param array{item:array<string,mixed>,meta:mixed} $checked
     */
    private function change(array $checked, Actor $actor, bool $import, bool $matchSecret): SavedListing
    {
        ['item' => $aItem, 'meta' => $meta] = $checked;

        // The whole row, read before anything is written; edited_item gets it back with the edit on it.
        // Locking the listing row first keeps the lock order of an If-Match edit, so the two cannot deadlock.
        $old_item = ListingStore::lockForWrite((int) $aItem['idItem']);
        $old_item = $old_item === null ? array() : Db::stringifyRow($old_item);
        // A listing waiting for payment keeps its hold and its deadline through an edit.
        $unpaid = $old_item !== array() && ListingStatus::isHeld($old_item);

        // Text first: when it is refused, nothing else about the listing has changed yet.
        $texts = $this->storedLocales($aItem['idItem']);
        if (!$this->writeTexts('EDIT', $aItem['title'], $aItem['description'], $aItem['idItem'], $texts)) {
            throw self::notSaved();
        }

        $location = $this->locationRow($aItem);

        $locationManager   = \ItemLocation::getInstance();
        $old_item_location = ListingStore::location($aItem['idItem'], true);
        $old_item_location = $old_item_location === null ? false : Db::stringifyRow($old_item_location);
        $location          = self::keepCoordinates($location, $old_item_location);

        // A rejected update leaves the previous location in place and every hook
        // below still fires, so the only trace it left was the unread return value.
        if (!self::sameLocation($location, $old_item_location)) {
            if ($locationManager->update($location, array('fk_i_item_id' => $aItem['idItem'])) === false) {
                trigger_error('Item location update wrote no row for item ' . $aItem['idItem'] . '.', E_USER_WARNING);
            } else {
                ListingStore::stampPoint((int) $aItem['idItem']);
                if (ListingGeocode::wanted($location)) {
                    // Only a changed address is looked up again, so a failed lookup is not retried on every edit.
                    ListingGeocode::queueAfterCommit($aItem['idItem']);
                }
            }
        }

        if (!$aItem['userId']) {
            $aItem['userId'] = null;
        }

        if (empty($aItem['price'])) {
            $aItem['currency'] = null;
        }

        $aUpdate = array(
            'dt_mod_date'        => date('Y-m-d H:i:s'),
            'fk_i_category_id'   => $aItem['catId'],
            'i_price'            => $aItem['price'],
            'fk_c_currency_code' => $aItem['currency'],
            'b_show_email'       => $aItem['showEmail'],
            's_contact_phone'    => $aItem['contactPhone'],
        );

        // only can change the user if you're an admin
        if ($actor->isAdmin()) {
            if ($aItem['userId']) {
                $user                  = \User::getInstance()->findByPrimaryKey($aItem['userId']);
                $aItem['contactName']  = $user['s_name'];
                $aItem['contactEmail'] = $user['s_email'];
            }
            $aUpdate['fk_i_user_id']    = $aItem['userId'];
            $aUpdate['s_contact_name']  = $aItem['contactName'];
            $aUpdate['s_contact_email'] = $aItem['contactEmail'];
        } else {
            $aUpdate['s_ip'] = $aItem['s_ip'];
        }

        // A form carries the listing's secret, and the save must match it. An admin
        // caller that passed plain data has no form, so it needs no secret.
        $where = array('pk_i_id' => $aItem['idItem']);
        if ($matchSecret) {
            $where['s_secret'] = $aItem['secret'];
        }
        // Variants with no price of their own sell at the listing's, so its change is theirs.
        $oldPrice   = is_numeric($old_item['i_price'] ?? null) ? (int) $old_item['i_price'] : null;
        $newPrice   = is_numeric($aUpdate['i_price']) ? (int) round((float) $aUpdate['i_price']) : null;
        $inheriting = $oldPrice !== $newPrice ? VariantStore::inheriting((int) $aItem['idItem']) : array();
        $result     = $this->items->update($aUpdate, $where);
        // A wrong secret changes no row, so it must not attach photos either.
        if (!$matchSecret || hash_equals((string) ($old_item['s_secret'] ?? ''), (string) $aItem['secret'])) {
            $this->photos->store($aItem['photos'], $aItem['idItem']);
        }

        \Log::getInstance()->insertLog(
            'item',
            'edit',
            $aItem['idItem'],
            current(array_values($aItem['title'])),
            $actor->logRole(),
            $actor->logId()
        );

        $this->writeMeta($meta, $aItem['idItem'], $meta ? FieldQuery::lockedListingValues($aItem['idItem']) : array());
        $this->writeProduct($aItem['idItem'], $aItem, $actor);
        if ($result) {
            VariantStore::listingPriceChanged((int) $aItem['idItem'], $inheriting, $oldPrice, $newPrice);
        }

        // Premium keeps an expired listing counted, as the recount does.
        $oldIsExpired  = empty($old_item['b_premium']) && osc_isExpired($old_item['dt_expiration']);
        $dt_expiration = $unpaid || self::sameExpiry($aItem['dt_expiration'], $old_item)
            ? false
            : \Item::getInstance()->updateExpirationDate($aItem['idItem'], $aItem['dt_expiration'], false);
        if ($dt_expiration === false) {
            $dt_expiration          = $old_item['dt_expiration'];
            $aItem['dt_expiration'] = $old_item['dt_expiration'];
        }
        $newIsExpired = empty($old_item['b_premium']) && osc_isExpired($dt_expiration);

        ListingStats::afterEdit(
            $result,
            $old_item,
            $oldIsExpired,
            $old_item_location,
            $aItem,
            $newIsExpired,
            $location
        );

        // The locked row with the edit on it, when the update wrote exactly that row; else it is read again.
        $edited = $result === 1 && $old_item !== array()
            ? \Item::getInstance()->extendRows(array(self::editedRow($old_item, $aUpdate, $dt_expiration)), null, self::textRows($aItem['idItem'], $texts))[0]
            : \Item::getInstance()->findByPrimaryKey($aItem['idItem']);

        $held = !$unpaid && (!$actor->isAdmin() || $import) && osc_moderate_admin_edit();
        if ($held && $this->disable($aItem['idItem'], $edited ?: null) && $edited) {
            $edited['b_enabled'] = '0';
        }

        osc_run_hook('edited_item', $edited);

        // Pending as on a new listing: it still waits for activation, or the edit waits for the admin.
        $pending = $held || ($old_item !== array() && ListingStatus::of($old_item) === ListingStatus::PENDING);

        return new SavedListing((int) $aItem['idItem'], $pending, $result === false ? false : (int) $result, $unpaid);
    }

    /**
     * The stored row with an update's values on it, as the columns hold them.
     *
     * @param array<string,string|null> $row
     * @param array<string,mixed>       $update
     *
     * @return array<string,string|null>
     */
    private static function editedRow(array $row, array $update, ?string $expiration): array
    {
        foreach (array('fk_i_category_id', 'i_price') as $column) {
            if (is_numeric($update[$column] ?? null)) {
                $update[$column] = (int) round((float) $update[$column]);
            }
        }

        return Db::stringifyRow(array_merge($row, $update, array('dt_expiration' => $expiration)));
    }

    /**
     * Whether the expiry an edit asks for is the one stored, so it need not be written again.
     *
     * @param mixed                $asked a date, or days from the publish date, as ListingInput gives it
     * @param array<string,string> $old   the stored row, with dt_pub_date and dt_expiration
     */
    private static function sameExpiry(mixed $asked, array $old): bool
    {
        if (!is_string($asked) || $asked === '' || !isset($old['dt_expiration'], $old['dt_pub_date'])) {
            return false;
        }
        if (!ctype_digit($asked)) {
            return $asked === $old['dt_expiration'];
        }
        $from = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $old['dt_pub_date'], new \DateTimeZone('UTC'));

        return $from !== false && $from->modify('+' . (int) $asked . ' days')->format('Y-m-d H:i:s') === $old['dt_expiration'];
    }

    /**
     * Refuse an owner an admin named that is not an account. The current owner of the
     * listing being edited is not checked again.
     *
     * @param array<string,mixed> $aItem
     * @param int|null            $listingId the listing being edited; null for a new one
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private static function ownerErrors(array $aItem, Actor $actor, ?int $listingId = null): array
    {
        $asked = (int) ($aItem['ownerId'] ?? $aItem['userId'] ?? 0);
        if (!$actor->isAdmin() || $asked <= 0) {
            return array();
        }
        $current = $listingId === null ? 0 : (int) (ListingStore::find($listingId, array('fk_i_user_id'))['fk_i_user_id'] ?? 0);
        if ($asked === $current || (new UserQuery())->exists($asked)) {
            return array();
        }

        return array(ListingValidator::entry('/owner_id', 'unknown', _m('There is no user with that ID.')));
    }

    /**
     * The sanitising a new listing and an edit share: titles, price and phone.
     *
     * @param array<string,mixed> $aItem
     *
     * @return array<string,mixed>
     */
    private function sanitize(array $aItem): array
    {
        foreach ($aItem['title'] as $key => $value) {
            $aItem['title'][$key] = $this->sanitize->title($value);
        }
        if ($aItem['price'] !== null) {
            $aItem['price'] = $this->sanitize->price($aItem['price']);
        }
        $aItem['contactPhone'] = $this->sanitize->phone($aItem['contactPhone']);

        return $aItem;
    }

    /**
     * Errors for the `product` member, which only an admin's save carries. A variant photo
     * must be one the listing already has, so a new listing's rows take none.
     *
     * @param array<string,mixed> $aItem
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private function productErrors(array $aItem, Actor $actor): array
    {
        if (!$actor->isAdmin() || !is_array($aItem['product'] ?? null)) {
            return array();
        }
        $itemId = (int) ($aItem['idItem'] ?? 0);
        $photos = $itemId > 0 && is_array($aItem['product']['variants'] ?? null)
            ? array_map(static fn (array $photo): int => (int) $photo['pk_i_id'], PhotoStore::ofItems(array($itemId)))
            : array();

        return array_merge(
            $this->validator->productCurrency($aItem['product'], $aItem['currency'] ?? null),
            $this->validator->product($aItem['product'], $photos)
        );
    }

    /**
     * Save the listing's default row or its option rows from the `product` member, or remove
     * every row when it is not for sale. Ignored unless an admin saves, whatever the data holds.
     *
     * @param array<string,mixed> $aItem
     */
    private function writeProduct(int $itemId, array $aItem, Actor $actor): void
    {
        if (!$actor->isAdmin() || !is_array($aItem['product'] ?? null)) {
            return;
        }
        if (empty($aItem['product']['sell'])) {
            VariantStore::deleteForItem($itemId);

            return;
        }
        if (is_array($aItem['product']['variants'] ?? null)) {
            VariantStore::saveSet($itemId, $aItem['product']['variants']);

            return;
        }
        // A product with no options sells at the listing price, so its row keeps none of its own.
        VariantStore::saveDefault($itemId, array('i_price' => null) + (array) ($aItem['product']['fields'] ?? array()));
    }

    private static function place(mixed $value): string
    {
        return osc_sanitize_name(strip_tags(trim((string) $value)));
    }

    /**
     * The t_item_location row, with the item id first when it is a new listing. Missing
     * coordinates are looked up by a job once the save commits (see ListingGeocode).
     *
     * @param array<string,mixed> $aItem
     *
     * @return array<string,mixed>
     */
    private function locationRow(array $aItem, int|string|null $itemId = null): array
    {
        $location = array(
            'fk_c_country_code' => $aItem['countryId'],
            's_country'         => $aItem['countryName'],
            'fk_i_region_id'    => $aItem['regionId'],
            's_region'          => $aItem['regionName'],
            'fk_i_city_id'      => $aItem['cityId'],
            's_city'            => $aItem['cityName'],
            's_city_area'       => $aItem['cityArea'],
            's_address'         => $aItem['address'],
            'd_coord_lat'       => $aItem['d_coord_lat'],
            'd_coord_long'      => $aItem['d_coord_long'],
            's_zip'             => $aItem['s_zip']
        );
        if ($itemId !== null) {
            $location = array('fk_i_item_id' => $itemId) + $location;
        }

        return $location;
    }

    /**
     * The category's custom field values from the data, checked and cleaned. They come with
     * the data when there is no form post, as on an import.
     *
     * @param array<string,mixed> $aItem
     *
     * @return array{0:mixed,1:array<int,array{pointer:string,code:string,message:string}>} the values and the errors
     */
    private function customFields(array $aItem): array
    {
        $_meta  = $this->fields((int) $aItem['catId']);
        $meta   = $aItem['meta'] ?? array();
        \mindstellar\search\FacetIndex::rememberTypes($_meta);
        $errors = $this->validator->meta($_meta, $meta);

        return array($meta, $errors);
    }

    /**
     * @param mixed                          $meta   custom field values by field id
     * @param array<int,array<string,mixed>> $stored the listing's t_item_meta rows; a value they already hold is not written
     */
    private function writeMeta(mixed $meta, int|string $itemId, array $stored = array()): void
    {
        if (!$meta || count($meta) === 0) {
            return;
        }
        $held = array();
        foreach ($stored as $row) {
            $held[(int) $row['fk_i_field_id']][(string) $row['s_multi']] = $row['s_value'] === null ? null : (string) $row['s_value'];
        }
        $mField = \Field::getInstance();
        foreach ($meta as $k => $v) {
            // A date interval keeps its from and to rows; any other list is one value.
            if (is_array($v) && (isset($v['from']) || isset($v['to']))) {
                $rows = $v;
            } else {
                $v    = is_array($v) ? implode(',', $v) : $v;
                $rows = array('' => $v);
            }
            $same = true;
            foreach ($rows as $multi => $value) {
                $have = $held[(int) $k] ?? array();
                if (!array_key_exists((string) $multi, $have) || $have[(string) $multi] !== ($value === null ? null : (string) $value)) {
                    $same = false;
                }
            }
            if (!$same) {
                $mField->replace($itemId, $k, $v);
            }
        }
    }

    /**
     * A form without map fields sends no coordinates; the stored ones stay while the place is the same.
     *
     * @param array<string,mixed>       $location
     * @param array<string,mixed>|false $stored
     *
     * @return array<string,mixed>
     */
    private static function keepCoordinates(array $location, array|false $stored): array
    {
        if (!is_array($stored) || !empty($location['d_coord_lat']) || !empty($location['d_coord_long'])) {
            return $location;
        }
        $place = array_diff_key($location, array('d_coord_lat' => 1, 'd_coord_long' => 1));
        if (self::sameLocation($place, $stored)) {
            $location['d_coord_lat']  = $stored['d_coord_lat'] ?? null;
            $location['d_coord_long'] = $stored['d_coord_long'] ?? null;
        }

        return $location;
    }

    /**
     * Whether the location row holds these values already. Coordinates compare as numbers.
     *
     * @param array<string,mixed>       $location
     * @param array<string,mixed>|false $stored
     */
    private static function sameLocation(array $location, array|false $stored): bool
    {
        if (!is_array($stored)) {
            return false;
        }
        foreach ($location as $column => $value) {
            if (!array_key_exists($column, $stored)) {
                return false;
            }
            $have = $stored[$column];
            if ($value === null || $have === null) {
                if ($value !== $have) {
                    return false;
                }
            } elseif (in_array($column, array('d_coord_lat', 'd_coord_long'), true) && is_numeric($value) && is_numeric($have)) {
                if ((float) $value !== (float) $have) {
                    return false;
                }
            } elseif ((string) $value !== (string) $have) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fold what a plugin's `pre_item_*_error` filter added to the error text back into the list.
     *
     * @param array<int,array{pointer:string,code:string,message:string}> $errors
     * @param string                                                      $text     the errors as sent to the filter
     * @param string                                                      $filtered the filter's answer
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private static function pluginErrors(array $errors, string $text, string $filtered): array
    {
        if ($filtered === $text) {
            return $errors;
        }
        if ($text !== '' && str_starts_with($filtered, $text)) {
            $extra = substr($filtered, strlen($text));
        } else {
            $extra  = $filtered;
            $errors = array();
        }
        foreach (preg_split('/\R/', $extra) ?: array() as $line) {
            if (trim($line) !== '') {
                $errors[] = ListingValidator::entry('', 'rejected', trim($line));
            }
        }

        return $errors;
    }

    /**
     * The errors as the form shows them: one message per line.
     *
     * @param array<int,array{pointer:string,code:string,message:string}> $errors
     */
    private static function errorText(array $errors): string
    {
        return implode('', array_map(static fn (array $e): string => $e['message'] . PHP_EOL, $errors));
    }

    /**
     * @param array<int,array{pointer:string,code:string,message:string}> $errors at least one
     */
    private static function refuse(array $errors): InvalidException
    {
        return InvalidException::all($errors, self::errorText($errors));
    }
}
