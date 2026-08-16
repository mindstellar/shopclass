<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\privacy;

use Item;
use User;

/**
 * Where a person's data lives, so a copy of it can be handed back.
 *
 * A data-subject request has two halves. Erasure is already answered by
 * User::deleteUser(), which removes the account and everything hanging off it in one
 * transaction. The other half — handing the person a copy of what is held about them —
 * is what this class does.
 *
 * The point of putting the table list in one place is that the two halves cannot be
 * allowed to disagree. A table that erasure clears but export never mentions is a table
 * nobody can see the contents of; a table export reads but erasure leaves behind is data
 * that outlives the account. Both are the same bug — an incomplete answer to a legal
 * request — and both are silent. So the map below records, per table, what each half does
 * with it and why, and tests/personal-data-map.php fails if a table holding a user
 * reference appears in the schema without appearing here.
 *
 * What this deliberately does not do: no consent records, no retention policy, no audit
 * log, no settings. Those are site policy rather than something core can decide, and none
 * of them is needed to answer an access or erasure request.
 */
class PersonalData
{
    /** Erasure removes the row directly. */
    public const ERASE_DELETED = 'deleted';

    /** Erasure removes it through a foreign key or a parent record. */
    public const ERASE_CASCADE = 'cascade';

    /** Erasure deliberately leaves it, for a reason the entry states. */
    public const ERASE_RETAINED = 'retained';

    /**
     * Every table holding something about a person, and what happens to it.
     *
     * `user_key` is the column tying a row to the account, or null when the tie is
     * indirect (through a listing, say). `export` says whether the person gets a copy —
     * false is for records that are about them but are not theirs to receive, which is
     * why each one carries a reason.
     *
     * @return array<string,array{user_key:?string,export:bool,erase:string,why:string}>
     */
    public static function map()
    {
        return array(
            't_user' => array(
                'user_key' => 'pk_i_id',
                'export'   => true,
                'erase'    => self::ERASE_DELETED,
                'why'      => 'The account itself.',
            ),
            't_user_description' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_DELETED,
                'why'      => 'The profile text, one row per locale.',
            ),
            't_user_email_tmp' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_DELETED,
                'why'      => 'A pending email change they started.',
            ),
            't_item' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_CASCADE,
                'why'      => 'Their listings. deleteUser() removes each one through Item::deleteByPrimaryKey().',
            ),
            't_item_comment' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_DELETED,
                'why'      => 'Comments they wrote, on any listing.',
            ),
            't_alerts' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_DELETED,
                'why'      => 'Saved searches they subscribed to.',
            ),
            't_billing_order' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_RETAINED,
                'why'      => 'What they paid. Kept after the account goes: a sale is an accounting '
                    . 'record the business has its own obligation to hold, so it outlives the '
                    . 'account by design — which is also why the table carries no foreign key.',
            ),
            't_billing_ledger' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_RETAINED,
                'why'      => 'Credit movements, kept for the same reason as the orders.',
            ),
            't_billing_wallet' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_CASCADE,
                'why'      => 'Their credit balance.',
            ),
            't_user_entitlement' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_CASCADE,
                'why'      => 'What their credits bought them.',
            ),
            't_form_submission' => array(
                'user_key' => 'fk_i_user_id',
                'export'   => true,
                'erase'    => self::ERASE_CASCADE,
                'why'      => 'Anything they sent through a custom form.',
            ),
            't_item_report_log' => array(
                'user_key' => null,
                'export'   => false,
                'erase'    => self::ERASE_CASCADE,
                'why'      => 'Reports about a listing, keyed by reporter IP rather than by account. '
                    . 'Not exported: handing someone the reports made against them would identify '
                    . 'whoever reported it.',
            ),
            't_login_attempt' => array(
                'user_key' => null,
                'export'   => false,
                'erase'    => self::ERASE_RETAINED,
                'why'      => 'Failed sign-ins, keyed by address and by the name typed rather than by '
                    . 'account, and cleared on a short rolling window by the throttle itself. '
                    . 'Not exported: it is a security record about attempts, which may not have '
                    . 'been theirs.',
            ),
            't_log' => array(
                'user_key' => null,
                'export'   => false,
                'erase'    => self::ERASE_RETAINED,
                'why'      => 'Administrator actions, about staff rather than the public account.',
            ),
            't_ban_rule' => array(
                'user_key' => null,
                'export'   => false,
                'erase'    => self::ERASE_RETAINED,
                'why'      => 'Moderation rules matching an address or email pattern, not an account. '
                    . 'Exporting or clearing them on request would let a banned person '
                    . 'discover or undo the ban.',
            ),
            't_admin' => array(
                'user_key' => null,
                'export'   => false,
                'erase'    => self::ERASE_RETAINED,
                'why'      => 'Panel logins, a separate population from public accounts.',
            ),
        );
    }

    /**
     * Everything held about one person, ready to be encoded.
     *
     * Assembled in memory and handed straight to the caller — nothing is written to disk.
     * A file would need somewhere to live, a name nobody can guess, and something to
     * remove it later; skipping the file skips all three.
     *
     * @param int $userId
     *
     * @return array<string,mixed>|null null when there is no such account
     */
    public static function export($userId)
    {
        $userId = (int)$userId;
        $user   = User::newInstance()->findByPrimaryKey($userId);
        if (!is_array($user) || $user === array()) {
            return null;
        }

        $out = array(
            'generated_at' => date('c'),
            'site'         => osc_base_url(),
            'about'        => array(
                'note' => 'Everything this site holds that is tied to your account. '
                    . 'Sections listed as retained are kept after an account is deleted; '
                    . 'each says why.',
            ),
            'data'         => array(),
            'not_included' => array(),
        );

        foreach (self::map() as $table => $spec) {
            if (!$spec['export'] || $spec['user_key'] === null) {
                $out['not_included'][$table] = $spec['why'];
                continue;
            }

            $rows = self::rows($table, $spec['user_key'], $userId);
            $out['data'][$table] = array(
                'retention' => $spec['erase'],
                'why'       => $spec['why'],
                'rows'      => self::redact($table, $rows),
            );
        }

        // The listings are already public, so their addresses are more use to the person
        // than the raw rows. The uploaded images are referenced, not copied: including the
        // bytes would turn this back into a file to build, store and clean up.
        $out['data']['t_item']['urls'] = self::itemUrls($userId);

        return $out;
    }

    /**
     * Read one table's rows for this account.
     *
     * @param string $table
     * @param string $column
     * @param int    $userId
     *
     * @return array<int,array<string,mixed>>
     */
    private static function rows($table, $column, $userId)
    {
        try {
            return osc_db_select(
                'SELECT * FROM ' . DB_TABLE_PREFIX . $table . ' WHERE ' . $column . ' = ?',
                array($userId)
            );
        } catch (\Throwable $e) {
            // A table an install has not got — a plugin's, or one added after this
            // release — is reported as unreadable rather than failing the whole export.
            return array();
        }
    }

    /**
     * Strip what must not be handed out even to the person themselves.
     *
     * The password hash and the secrets are credentials: they authenticate, so a copy of
     * them in a downloaded file is a copy of the key to the account. Everything else the
     * account holds is theirs to have.
     *
     * @param string                          $table
     * @param array<int,array<string,mixed>>  $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private static function redact($table, array $rows)
    {
        $secret = array('s_password', 's_secret', 's_pass_code');

        foreach ($rows as $i => $row) {
            foreach ($secret as $column) {
                if (array_key_exists($column, $row)) {
                    $rows[$i][$column] = '[not included]';
                }
            }
        }

        return $rows;
    }

    /**
     * Public addresses of the person's listings.
     *
     * @param int $userId
     *
     * @return array<int,string>
     */
    private static function itemUrls($userId)
    {
        $urls = array();
        try {
            $items = Item::newInstance()->findByUserID((int)$userId);
        } catch (\Throwable $e) {
            return $urls;
        }

        foreach ((array)$items as $item) {
            if (is_array($item) && isset($item['pk_i_id'])) {
                $urls[(int)$item['pk_i_id']] = osc_item_url_from_item($item);
            }
        }

        return $urls;
    }
}
