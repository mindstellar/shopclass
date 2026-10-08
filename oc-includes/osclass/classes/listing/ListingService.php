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
use mindstellar\user\UserQuery;
use mindstellar\utility\DeferredMail;
use mindstellar\utility\Sanitize;
use mindstellar\utility\ViewScope;
use mindstellar\validation\ForbiddenException;
use mindstellar\validation\InvalidException;
use mindstellar\validation\RefusedException;

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
     * `posted_item`.
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

        return $this->write(fn (array &$notices): SavedListing => $this->insert($data, $actor, $import, $notices));
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
            throw new ForbiddenException(self::refusalMessage($refusal), $refusal === ListingPolicy::REGISTERED_ONLY ? ForbiddenException::SIGN_IN : ForbiddenException::BANNED);
        }
        $this->allowed = $key;
    }

    /**
     * Save an edit. Fires `item_edit_prepare_data`, `pre_item_edit`, `pre_item_edit_error`,
     * `uploaded_file` per photo and `edited_item`.
     *
     * @param array<string,mixed> $data        with 'idItem' and, from a form, 'secret'
     * @param bool                $import      admin rights that still apply moderation
     * @param bool                $matchSecret the save matches the secret in $data, as a form
     *                                         carries it; an admin saving plain data has none
     *
     * @throws InvalidException with the form's errors, each with its member, code and message
     */
    public function update(array $data, Actor $actor, bool $import = false, bool $matchSecret = true): SavedListing
    {
        return $this->write(fn (array &$notices): SavedListing => $this->change($data, $actor, $import, $matchSecret, $notices));
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
        return DeferredMail::transaction(function () use ($itemId, $secret, $actor) {
            $item = $this->items->findByPrimaryKey($itemId);

            osc_run_hook('before_delete_item', $itemId);

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
                $result = $this->items->deleteByPrimaryKey($itemId);
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
     * @param int|string $id
     */
    public function disable(int|string $id): bool
    {
        $result = $this->items->update(
            array('b_enabled' => 0),
            array('pk_i_id' => $id)
        );

        // updated correctly
        if ($result == 1) {
            osc_run_hook('disable_item', $id);
            $item = $this->items->findByPrimaryKey($id);
            if (osc_item_is_counted(array('b_enabled' => 1) + $item)) {
                ListingStats::decrease($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Mark a listing validated: the owner confirmed it. One the admin has not enabled yet is
     * still marked validated and stays hidden until enabled. Fires `activate_item`.
     *
     * @param string|null $secret the listing's edit secret, when the caller must match it
     *
     * @return bool|null null when there is no such listing, the secret is wrong or it was
     *                   already validated
     */
    public function activate(int $id, ?string $secret = null): ?bool
    {
        $where = array('pk_i_id' => $id);
        if ($secret !== null) {
            $where['s_secret'] = $secret;
        }
        $item = $this->items->findByPrimaryKey($id);
        if (!is_array($item) || !isset($item['b_active']) || (int) $item['b_active'] !== 0
            || ($secret !== null && !hash_equals((string) $item['s_secret'], $secret))
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
     * Put a listing back on the site after moderation. Fires `enable_item`.
     */
    public function enable(int $id): bool
    {
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
     * dt_premium_expiration passes and the hourly sweep flips it back. Omitting it keeps
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
        $current = ListingStore::premiumState((int) $id);

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
        foreach ($title as $k => $_data) {
            $_title       = $_data;
            $_description = $description[$k];
            $written      = true;
            if ($type === 'EDIT' && self::sameText($stored[$k] ?? null, (string) $_title, (string) $_description)) {
                // The write is skipped, but plugins still hear of every locale an edit saved, as before.
                osc_run_hook('item_content_updated', (int) $itemId, $k);
                continue;
            }
            if ($type === 'ADD') {
                $written = $this->items->insertLocale($itemId, $k, $_title, $_description);
            } elseif ($type === 'EDIT') {
                $written = $this->items->updateLocaleForce($itemId, $k, $_title, $_description);
            }
            if (!$written) {
                trigger_error('Item locale ' . $k . ' was not written for item ' . $itemId . '.', E_USER_WARNING);

                return false;
            }
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

        return $stored[0] === mb_substr($title, 0, \Item::TITLE_WIDTH, 'UTF-8') && $stored[1] === $description;
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
        ViewScope::withItem($item, static function () use ($item, $active, $actor): void {
            $registered = $actor->userId() !== null;
            if ($active === 'INACTIVE' && !$registered) {
                osc_run_hook('hook_email_item_validation_non_register_user', $item);
            } elseif ($active === 'INACTIVE') {
                osc_run_hook('hook_email_item_validation', $item);
            } elseif (!$registered) {
                osc_run_hook('hook_email_new_item_non_register_user', $item);
            }

            if (osc_notify_new_item()) {
                osc_run_hook('hook_email_admin_new_item', $item);
            }
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
            ListingPolicy::REGISTERED_ONLY => _m('Only registered users are allowed to post listings'),
            ListingPolicy::BANNED_EMAIL    => _m('Your current email is not allowed'),
            default                        => _m('Your current IP is not allowed'),
        };
    }

    /**
     * Run a write in one transaction with e-mails held until it commits, and remove the
     * files of photos it stored when it fails. What went wrong with a photo goes with the
     * result, or with the refusal, for the form to show.
     *
     * @param callable(string[]&): SavedListing $fn
     */
    private function write(callable $fn): SavedListing
    {
        $notices = array();
        try {
            $saved = PhotoService::cleanUpOnFailure(
                $this->photos,
                static function () use ($fn, &$notices): SavedListing {
                    return DeferredMail::transaction(static function () use ($fn, &$notices): SavedListing {
                        return $fn($notices);
                    });
                }
            );
        } catch (RefusedException $e) {
            throw $e->withNotices(array_merge($notices, $this->photos->notices()));
        }

        return $saved->withNotices(array_merge($notices, $this->photos->notices()));
    }

    /**
     * @param array<string,mixed> $data
     * @param string[]            $notices
     */
    private function insert(array $data, Actor $actor, bool $import, array &$notices): SavedListing
    {
        $aItem   = osc_apply_filter('item_add_prepare_data', $data);
        $is_spam = 0;
        $enabled = 1;
        $code    = osc_genRandomPassword();

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
        if (ListingPolicy::postingTooSoon($actor)) {
            $errors[] = ListingValidator::entry('', 'too_fast', _m('Too fast. You should wait a little to publish your ad.'));
        }

        // akismet check spam ...
        if ($this->validator->isSpam($aItem['title'], $aItem['description'], $aItem['contactName'], $aItem['contactEmail'], (string) ($aItem['s_ip'] ?? ''))) {
            $is_spam = 1;
        }
        [$meta, $metaErrors] = $this->customFields($aItem);
        $errors              = array_merge($errors, $metaErrors);

        $text = self::errorText($errors);
        osc_run_hook('pre_item_add', $aItem, $text);
        $errors = self::pluginErrors($errors, $text, (string) osc_apply_filter('pre_item_add_error', $text, $aItem));

        // The one choke point for the listing quota. Guest posts (no user id) have no
        // wallet to charge and admin posts are never metered, so both skip enforcement
        // entirely. withinFreeQuota() is the same COUNT canPublish() would otherwise run
        // again internally, so it is computed once here and handed to canPublish() rather
        // than paying for it twice on every post. Nothing is ever consumed here: a
        // listing.slot entitlement only ever raises the ceiling withinFreeQuota() already
        // checked, so there is nothing left to spend once a post is allowed through.
        if ((!$actor->isAdmin() || $import) && osc_billing_enabled() && !empty($aItem['userId'])) {
            $withinFreeQuota = \mindstellar\billing\EntitlementStore::withinFreeQuota($aItem['userId']);
            if (!\mindstellar\billing\EntitlementStore::canPublish($aItem['userId'], array('item' => $aItem), $withinFreeQuota)) {
                $errors[] = ListingValidator::entry('', 'listing_limit', osc_listing_limit_message((int) $aItem['userId'], $aItem));
            }
        }

        if ($errors !== array()) {
            throw self::refuse($errors);
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
            'dt_first_pub_date'  => $publishedAt,
            'fk_i_category_id'   => $aItem['catId'],
            'i_price'            => $aItem['price'],
            'fk_c_currency_code' => $aItem['currency'],
            's_contact_name'     => $aItem['contactName'],
            's_contact_email'    => $aItem['contactEmail'],
            's_contact_phone'    => $aItem['contactPhone'],
            's_secret'           => $code,
            'b_active'           => $active === 'ACTIVE' ? 1 : 0,
            'b_enabled'          => $enabled,
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

            throw new InvalidException('', 'rejected', _m('Your listing could not be saved. Please try again.'));
        }

        // Written first so a refused title or description removes the new row again,
        // rather than leaving a live listing with no text.
        if (!$this->writeLocales('ADD', $aItem['title'], $aItem['description'], $itemId)) {
            throw new InvalidException('', 'rejected', _m('Your listing could not be saved. Please try again.'));
        }

        if (!$actor->isAdmin()) {
            // Record the publish so the flood wait is enforced server-side (see the
            // countByIpContext check above): durable, correct across app servers, and
            // not resettable by clearing cookies the way the old session/cookie was.
            \LoginAttempt::getInstance()->record(
                'item_post',
                (string)$aItem['contactEmail'],
                $actor->ip(),
                date('Y-m-d H:i:s')
            );
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
        } elseif (ListingGeocode::wanted($location)) {
            ListingGeocode::queueAfterCommit((int) $itemId);
        }

        $this->photos->store($aItem['photos'], $itemId);

        // update dt_expiration at t_item
        \Item::getInstance()->updateExpirationDate($itemId, $aItem['dt_expiration']);

        $this->writeMeta($meta, $itemId);

        // We need at least one record in t_item_stats
        $mStats = new \ItemStats();
        $mStats->emptyRow($itemId);

        $item          = $this->items->findByPrimaryKey($itemId);
        $aItem['item'] = $item;

        if (!$actor->isAdmin()) {
            $this->notifyNew($item, $active, $actor);
        }

        if ($active !== 'INACTIVE') {
            $aAux = array(
                'fk_i_user_id'      => $aItem['userId'],
                'fk_i_category_id'  => $aItem['catId'],
                'fk_c_country_code' => $location['fk_c_country_code'],
                'fk_i_region_id'    => $location['fk_i_region_id'],
                'fk_i_city_id'      => $location['fk_i_city_id']
            );
            // if is_spam not increase stats
            if ($is_spam == 0) {
                ListingStats::increase($aAux);
            }
        }

        if ((!$actor->isAdmin() || $import) && osc_moderate_admin_post()) {
            $this->disable($item['pk_i_id']);
        }

        // Listeners may read the new listing through osc_item_*().
        ViewScope::withItem($item, static fn () => osc_run_hook('posted_item', $item));

        return new SavedListing((int) $itemId, $active === 'INACTIVE');
    }

    /**
     * @param array<string,mixed> $data
     * @param string[]            $notices
     */
    private function change(array $data, Actor $actor, bool $import, bool $matchSecret, array &$notices): SavedListing
    {
        $aItem           = osc_apply_filter('item_edit_prepare_data', $data);
        $aItem['idItem'] = (int) $aItem['idItem'];

        $aItem             = $this->sanitize($aItem);
        $aItem['cityArea'] = self::place($aItem['cityArea']);
        $aItem['address']  = self::place($aItem['address']);

        // Only the columns the stats and expiry below compare; read before anything is written.
        $old_item = ListingStore::find($aItem['idItem'], array('fk_i_user_id', 'fk_i_category_id', 'b_enabled', 'b_active', 'b_spam', 'b_premium', 'dt_expiration'));
        $old_item = $old_item === null ? array() : Db::stringifyRow($old_item);

        // Validate
        $errors = array_merge(
            self::ownerErrors($aItem, $actor, (int) ($old_item['fk_i_user_id'] ?? 0)),
            $this->validator->common($aItem, $notices)
        );
        // Only an admin editing a listing with no owner writes the contact name and e-mail.
        if ($actor->isAdmin() && !$aItem['userId']) {
            $errors = array_merge($errors, $this->validator->contactWidths($aItem));
        }

        [$meta, $metaErrors] = $this->customFields($aItem);
        $errors              = array_merge($errors, $metaErrors);

        $text = self::errorText($errors);
        osc_run_hook('pre_item_edit', $aItem, $text);
        $errors = self::pluginErrors($errors, $text, (string) osc_apply_filter('pre_item_edit_error', $text, $aItem));

        if ($errors !== array()) {
            throw self::refuse($errors);
        }

        // Text first: when it is refused, nothing else about the listing has changed yet.
        if (!$this->writeLocales('EDIT', $aItem['title'], $aItem['description'], $aItem['idItem'])) {
            throw new InvalidException('', 'rejected', _m('Your listing could not be saved. Please try again.'));
        }

        $location = $this->locationRow($aItem);

        $locationManager   = \ItemLocation::getInstance();
        $old_item_location = $locationManager->findByPrimaryKey($aItem['idItem']);

        // A rejected update leaves the previous location in place and every hook
        // below still fires, so the only trace it left was the unread return value.
        if ($locationManager->update($location, array('fk_i_item_id' => $aItem['idItem'])) === false) {
            trigger_error('Item location update wrote no row for item ' . $aItem['idItem'] . '.', E_USER_WARNING);
        } elseif (ListingGeocode::wanted($location)) {
            ListingGeocode::queueAfterCommit($aItem['idItem']);
        }

        if ($aItem['userId']) {
            $user                  = \User::getInstance()->findByPrimaryKey($aItem['userId']);
            $aItem['contactName']  = $user['s_name'];
            $aItem['contactEmail'] = $user['s_email'];
        } else {
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
        $result = $this->items->update($aUpdate, $where);
        // UPLOAD item resources
        $this->photos->store($aItem['photos'], $aItem['idItem']);

        \Log::getInstance()->insertLog(
            'item',
            'edit',
            $aItem['idItem'],
            current(array_values($aItem['title'])),
            $actor->logRole(),
            $actor->logId()
        );

        $this->writeMeta($meta, $aItem['idItem']);

        // Premium keeps an expired listing counted, as the recount does.
        $oldIsExpired  = empty($old_item['b_premium']) && osc_isExpired($old_item['dt_expiration']);
        $dt_expiration = \Item::getInstance()
            ->updateExpirationDate($aItem['idItem'], $aItem['dt_expiration'], false);
        if ($dt_expiration === false) {
            $dt_expiration          = $old_item['dt_expiration'];
            $aItem['dt_expiration'] = $old_item['dt_expiration'];
        }
        $newIsExpired = empty($old_item['b_premium']) && osc_isExpired($dt_expiration);

        // Recalculate stats related with items
        ListingStats::afterEdit(
            $result,
            $old_item,
            $oldIsExpired,
            $old_item_location,
            $aItem,
            $newIsExpired,
            $location
        );

        unset($old_item);

        if ((!$actor->isAdmin() || $import) && osc_moderate_admin_edit()) {
            $this->disable($aItem['idItem']);
        }

        // THIS HOOK IS FINE, YAY!
        osc_run_hook('edited_item', \Item::getInstance()->findByPrimaryKey($aItem['idItem']));

        return new SavedListing((int) $aItem['idItem'], false, $result === false ? false : (int) $result);
    }

    /**
     * Refuse an owner an admin named that is not an account. The current owner is not
     * checked again.
     *
     * @param array<string,mixed> $aItem
     *
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    private static function ownerErrors(array $aItem, Actor $actor, int $current = 0): array
    {
        $asked = (int) ($aItem['ownerId'] ?? $aItem['userId'] ?? 0);
        if (!$actor->isAdmin() || $asked <= 0 || $asked === $current || (new UserQuery())->exists($asked)) {
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
        $_meta  = \Field::getInstance()->findByCategory($aItem['catId']);
        $meta   = $aItem['meta'] ?? array();
        $errors = $this->validator->meta($_meta, $meta);

        return array($meta, $errors);
    }

    /**
     * @param mixed $meta custom field values by field id
     */
    private function writeMeta(mixed $meta, int|string $itemId): void
    {
        if (!$meta || count($meta) === 0) {
            return;
        }
        $mField = \Field::getInstance();
        foreach ($meta as $k => $v) {
            // if dateinterval
            if (is_array($v) && !isset($v['from']) && !isset($v['to'])) {
                $v = implode(',', $v);
            }
            $mField->replace($itemId, $k, $v);
        }
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
