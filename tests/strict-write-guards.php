<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A refused write must never be reported to the visitor as a success.
 *
 * DAO::insertGetId() catches the database error and answers 0; DAO::update()
 * answers false. Neither return was read on the registration path, so with the
 * strict SQL modes on a field one character too long for its column made the
 * insert fail, UserActions::add() carried on with $userId = 0 and returned 2 —
 * "your account has been created" — for an account that did not exist. The
 * publish path had the same unread return on the listing's location row.
 *
 * Two properties are pinned, and they are separate:
 *
 *   1. A value wider than the column that has to hold it is refused BY NAME,
 *      before any statement runs, on a relaxed connection as well as a strict
 *      one. Truncating it was data loss; a caught exception is not something to
 *      show a visitor either.
 *   2. If a write is refused anyway — a plugin filter putting the value back,
 *      a column narrower than core thinks — the caller is told, and no success
 *      message is produced.
 *
 * The declared widths are read back out of information_schema, so a column that
 * is widened by a migration cannot leave a stale limit here rejecting values the
 * database would now accept.
 *
 * The session is set strict by hand: a connection is opened once, so the
 * OSC_DB_STRICT_MODE constant cannot be flipped mid-process, and MariaDB's
 * defaults omit these modes entirely.
 *
 * Usage:  php tests/strict-write-guards.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root — the throwaway container)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_strict_write_guards');

/*
 * Stand-ins the action classes reach through, all guarded. They are the same ones
 * tests/models/billing.php uses to drive ItemActions::add(); hDefines.php cannot be
 * loaded instead, because it declares osc_uploads_path() unguarded and the scratch
 * bootstrap has already defined a stand-in for it.
 */
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
// The flash strings are what the pins below read, so they pass through unchanged
// rather than through the translation stack.
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';
// ItemActions::add() asks osc_items_wait_time_for_user() about the flood wait, and
// hBilling.php registers the render targets it names the moment it is included.
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';

$prefix = DB_TABLE_PREFIX;

seed_locale($admin);
seed_country($admin, 'US', 'United States');
seed_currency($admin);
$catParent = seed_category($admin, 'Motors');
$catChild  = seed_category($admin, 'Cars', $catParent);

/** How many user rows exist right now. */
$userCount = static function () use ($admin, $prefix): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM {$prefix}t_user")->fetch_assoc()['c'];
};

/**
 * Post a registration and hand back whatever add() answered. Params is static and
 * request-scoped, so every field one call sets is cleared before the next.
 */
$register = static function (array $params, bool $isAdmin = false) {
    foreach (array(
        's_name', 's_email', 's_username', 's_password', 's_password2', 's_website',
        's_phone_land', 's_phone_mobile', 'countryId', 'country', 'regionId', 'region',
        'cityId', 'city', 'cityArea', 'address', 'zip', 'd_coord_lat', 'd_coord_long',
        'b_company', 's_info',
    ) as $stale) {
        Params::unsetParam($stale);
    }

    $defaults = array(
        's_name'      => 'Ada Lovelace',
        's_email'     => 'ada' . mt_rand(1, 999999) . '@example.test',
        's_username'  => '',
        's_password'  => 'correct horse battery',
        's_password2' => 'correct horse battery',
    );
    foreach (array_merge($defaults, $params) as $key => $value) {
        Params::setParam($key, $value);
    }

    return (new UserActions($isAdmin))->add();
};

/* ----------------------------------------------------------------------------
 * The widths the action class declares have to be the widths the database has.
 * Everything below is only meaningful if these two agree.
 * ------------------------------------------------------------------------- */
harness_section('the declared widths are the schema\'s widths');

// Read off the classes, never retyped here: a hand-copied table pins the copy against the
// schema and lets the code that does the rejecting drift away from both.
$declared     = UserActions::COLUMN_WIDTHS;
$itemDeclared = ItemActions::COLUMN_WIDTHS;

/** information_schema hands columns back in ordinal order; the pins compare by name. */
$sorted = static function (array $widths): array {
    ksort($widths);

    return $widths;
};

$widthsOf = static function (string $table) use ($admin, $prefix): array {
    $res = $admin->query(
        'SELECT column_name AS col, character_maximum_length AS len FROM information_schema.columns'
        . " WHERE table_schema = DATABASE() AND table_name = '{$prefix}{$table}'"
    );
    $out = array();
    while ($row = $res->fetch_assoc()) {
        $out[strtolower($row['col'])] = (int) $row['len'];
    }
    $res->free();

    return $out;
};

$userWidths = $widthsOf('t_user');
pin(
    'every column UserActions caps is as wide as it says it is',
    $sorted($declared),
    $sorted(array_intersect_key($userWidths, $declared))
);

// s_contact_phone is the one capped column that lives on t_item; the rest are the location row.
$locationWidths = $widthsOf('t_item_location');
$itemLocation   = array_diff_key($itemDeclared, array('s_contact_phone' => true));
pin(
    'and every listing location column ItemActions caps matches too',
    $sorted($itemLocation),
    $sorted(array_intersect_key($locationWidths, $itemLocation))
);
pin(
    'as does the listing phone column',
    $itemDeclared['s_contact_phone'],
    $widthsOf('t_item')['s_contact_phone'] ?? null
);

/*
 * The denormalised place names are copies of catalog rows the user picks from, so a
 * copy narrower than the column it is copied out of cannot hold every value that can
 * legitimately be chosen. s_country was VARCHAR(40) against a source of 80, which is
 * what made the country cap a refusal rather than a limit nobody could reach.
 */
harness_section('every place-name copy is at least as wide as its source');

$catalogWidths = array(
    's_country' => $widthsOf('t_country')['s_name'] ?? 0,
    's_region'  => $widthsOf('t_region')['s_name'] ?? 0,
    's_city'    => $widthsOf('t_city')['s_name'] ?? 0,
);

foreach (array('t_user' => $userWidths, 't_item_location' => $locationWidths) as $table => $widths) {
    foreach ($catalogWidths as $column => $source) {
        check(
            $table . '.' . $column . ' holds any name the catalog can offer',
            ($widths[$column] ?? 0) >= $source,
            ($widths[$column] ?? 0) . ' vs source ' . $source
        );
    }
}

/* ----------------------------------------------------------------------------
 * Relaxed connection first: this is what an upgraded install runs. The over-long
 * value used to be cut short and stored; it is refused now, in both modes.
 * ------------------------------------------------------------------------- */
harness_section('registration on a relaxed connection');

check('the connection really is relaxed', !harness_strict_writes(), implode(',', harness_sql_mode()));

$before = $userCount();
pin('an ordinary registration still succeeds', 2, $register(array()));
pin('and wrote its user row', $before + 1, $userCount());

$before = $userCount();
pin(
    'a name one character over the column is refused by name',
    "Name is too long, the maximum is 100 characters\n",
    $register(array('s_name' => str_repeat('a', 101)))
);
pin('and no user row was written', $before, $userCount());

pin(
    'a name of exactly the column width is accepted',
    2,
    $register(array('s_name' => str_repeat('a', 100)))
);

pin(
    'a 16-character postcode is refused — the column holds 15',
    "Zip code is too long, the maximum is 15 characters\n",
    $register(array('zip' => str_repeat('9', 16)))
);

pin(
    'every over-long field is named, not just the first',
    "Name is too long, the maximum is 100 characters\n"
    . "Zip code is too long, the maximum is 15 characters\n",
    $register(array('s_name' => str_repeat('a', 101), 'zip' => str_repeat('9', 16)))
);

// The half-width copy is what made this a truncation rather than a stored value:
// on a relaxed connection the second 40 characters of the country name were dropped
// and nothing said so.
pin('an 80-character country name registers here too', 2, $register(array('country' => str_repeat('C', 80))));
pin(
    'and all 80 characters were stored',
    str_repeat('C', 80),
    (string) $admin->query(
        "SELECT s_country FROM {$prefix}t_user ORDER BY pk_i_id DESC LIMIT 1"
    )->fetch_assoc()['s_country']
);

/* ----------------------------------------------------------------------------
 * The same, strict. This is what a new install runs, and it is the mode in which
 * the unchecked return told the visitor to go and read a welcome email.
 * ------------------------------------------------------------------------- */
harness_section('registration on a strict connection');

DBConnectionClass::newInstance()->getOsclassDb()->query(
    "SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,STRICT_ALL_TABLES,"
    . "NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
);
check('the connection is strict now', harness_strict_writes(), implode(',', harness_sql_mode()));

$before = $userCount();
pin('an ordinary registration is unaffected by the mode', 2, $register(array()));
pin('and wrote the second user row', $before + 1, $userCount());

$before = $userCount();
pin(
    'the over-long name is refused the same way, not by an exception',
    "Name is too long, the maximum is 100 characters\n",
    $register(array('s_name' => str_repeat('a', 150)))
);
pin('and still no user row', $before, $userCount());

$before = $userCount();
pin(
    'an 80-character country name registers — the width t_country.s_name allows',
    2,
    $register(array('country' => str_repeat('C', 80)))
);
pin('and the row is there', $before + 1, $userCount());
pin(
    'the whole name was stored, not the first 40 characters of it',
    str_repeat('C', 80),
    (string) $admin->query(
        "SELECT s_country FROM {$prefix}t_user ORDER BY pk_i_id DESC LIMIT 1"
    )->fetch_assoc()['s_country']
);
pin(
    'at 81 it is refused by name rather than by the database',
    "Country is too long, the maximum is 80 characters\n",
    $register(array('country' => str_repeat('C', 81)))
);

/* ----------------------------------------------------------------------------
 * The guard behind the validation. A filter is all it takes to hand add() a row
 * the database will refuse — which is exactly what a plugin does.
 * ------------------------------------------------------------------------- */
harness_section('a write that fails anyway is reported, not swallowed');

/**
 * Stands in for a plugin that overwrites the accumulated errors. It runs after the
 * length check and clears its verdict, so the insert is attempted with the
 * over-long value still in place -- the shape the unread return value was hiding.
 */
function strict_guard_clear_flash($flash_error)
{
    return '';
}

osc_add_filter('user_add_flash_error', 'strict_guard_clear_flash');

$warnings = array();
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;

    return true;
});
$before   = $userCount();
$refused  = $register(array('s_name' => str_repeat('a', 150)));
restore_error_handler();

check('add() returns an error string, not a success code', is_string($refused), describe($refused));
pin(
    'and it is the "could not be created" message, not a validation one',
    "Your account could not be created. Please try again.\n",
    $refused
);
pin('no user row was created', $before, $userCount());
pin('the silent failure left a warning behind', 1, count($warnings));
check(
    'naming the insert',
    strpos($warnings[0] ?? '', 'User insert produced no row') === 0,
    describe($warnings[0] ?? null)
);

osc_remove_filter('user_add_flash_error', 'strict_guard_clear_flash');

/* ----------------------------------------------------------------------------
 * The profile form shares prepareData() with registration, and shared the same
 * unread return: update() answers false on a refused write.
 * ------------------------------------------------------------------------- */
harness_section('the profile form');

$editUser = seed_user($admin, 'editme', 'editme@example.test');
$nameOf   = static function (int $id) use ($admin, $prefix): string {
    return (string) $admin->query("SELECT s_name FROM {$prefix}t_user WHERE pk_i_id = $id")
        ->fetch_assoc()['s_name'];
};
$nameBefore = $nameOf($editUser);

$edit = static function (array $params, int $id) {
    foreach (array('s_name', 's_email', 's_username', 's_website', 'zip', 'address') as $stale) {
        Params::unsetParam($stale);
    }
    foreach ($params as $key => $value) {
        Params::setParam($key, $value);
    }

    return (new UserActions(true))->edit($id);
};

pin(
    'an over-long name is refused by name here too',
    "Name is too long, the maximum is 100 characters\n",
    $edit(array('s_name' => str_repeat('a', 101), 's_email' => 'editme@example.test'), $editUser)
);
pin('and the stored name is untouched', $nameBefore, $nameOf($editUser));

/**
 * The profile-form counterpart of strict_guard_clear_flash(): a plugin clearing the
 * verdict, so the update is attempted with the over-long value still in place.
 */
function strict_guard_clear_edit_flash($flash_error, $userId = null)
{
    return '';
}

osc_add_filter('user_edit_flash_error', 'strict_guard_clear_edit_flash');

$warnings = array();
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;

    return true;
});
$refusedEdit = $edit(array('s_name' => str_repeat('a', 150), 's_email' => 'editme@example.test'), $editUser);
restore_error_handler();

osc_remove_filter('user_edit_flash_error', 'strict_guard_clear_edit_flash');

pin(
    'a refused update reports an error rather than "profile updated"',
    "Your profile could not be saved. Please try again.\n",
    $refusedEdit
);
pin('the stored name is still untouched', $nameBefore, $nameOf($editUser));
pin('and the refusal left a warning', 1, count($warnings));
check(
    'naming the update',
    strpos($warnings[0] ?? '', 'User update wrote no row') === 0,
    describe($warnings[0] ?? null)
);

/* ----------------------------------------------------------------------------
 * Publishing. The listing row's own insert has been checked since the id-capture
 * fix; its location row had not been, and two of the location columns had no
 * length check at all.
 * ------------------------------------------------------------------------- */
harness_section('publishing: the location columns that had no cap');

$publisher = seed_user($admin, 'publisher', 'publisher@example.test');

$itemData = static function (array $overrides) use ($catChild, $publisher): array {
    return array_merge(array(
        'title'         => array('en_US' => 'A listing with a title'),
        'description'   => array('en_US' => 'A description long enough to pass validation.'),
        'catId'         => $catChild,
        'price'         => 10,
        'currency'      => 'USD',
        'contactName'   => 'Publisher',
        'contactEmail'  => 'publisher@example.test',
        'contactPhone'  => '',
        'cityArea'      => '',
        'address'       => '',
        'countryId'     => 'US',
        'countryName'   => 'United States',
        'regionId'      => null,
        'regionName'    => '',
        'cityId'        => null,
        'cityName'      => '',
        'd_coord_lat'   => null,
        'd_coord_long'  => null,
        's_zip'         => '',
        'photos'        => array(),
        'showEmail'     => 1,
        'active'        => 'ACTIVE',
        'userId'        => $publisher,
        's_ip'          => '127.0.0.1',
        'dt_expiration' => '0',
    ), $overrides);
};

$publish = static function (array $overrides) use ($itemData) {
    $action       = new ItemActions(false);
    $action->data = $itemData($overrides);

    return $action->add();
};

$itemCount = static function () use ($admin, $prefix): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM {$prefix}t_item")->fetch_assoc()['c'];
};

$before = $itemCount();
pin('an ordinary post succeeds', 2, $publish(array()));
pin('and wrote its listing', $before + 1, $itemCount());

$before = $itemCount();
pin(
    'a 16-character postcode is refused',
    "Zip code too long.\n",
    $publish(array('s_zip' => str_repeat('9', 16)))
);
pin('and no listing was written', $before, $itemCount());

pin(
    'a 41-character phone is refused — the column holds 40',
    "Phone too long.\n",
    $publish(array('contactPhone' => str_repeat('5', 41)))
);

/*
 * The format check runs on the sanitised value, so it governs what would be stored.
 * Sanitize::phone() has already thrown away everything but the digits and a leading
 * plus by then: punctuation cannot fail it, and an entry with no digits at all is
 * emptied rather than refused.
 */
pin(
    'a three-digit phone is refused as a phone',
    "Phone invalid.\n",
    $publish(array('contactPhone' => '123'))
);
pin(
    'and four digits is enough',
    2,
    $publish(array('contactPhone' => '5551'))
);
pin(
    'a phone written the way people write one goes through',
    2,
    $publish(array('contactPhone' => '+1 (555) 123-4567'))
);
pin(
    'and an entry with no digits is emptied by the sanitiser, not refused',
    2,
    $publish(array('contactPhone' => 'call me'))
);

// The country copy is as wide as t_country.s_name now, so the cap is 80 rather than
// the 50 it was against a column of 40 -- a name in between used to be cut short on a
// relaxed connection and refuse the whole insert on a strict one.
pin(
    'an 81-character country name is refused',
    "Country too long.\n",
    $publish(array('countryName' => str_repeat('C', 81)))
);
pin(
    'and 80 characters — the width of the column, and of the catalog it copies — goes through',
    2,
    $publish(array('countryName' => str_repeat('C', 80)))
);

// t_city.s_name is VARCHAR(60) and the copy holds 100, but the cap was 50: every
// catalog city with a longer name than that was unpublishable.
pin(
    'a 60-character city name — the widest the catalog can offer — goes through',
    2,
    $publish(array('cityName' => str_repeat('c', 60), 'regionName' => str_repeat('r', 60)))
);
pin(
    'and 101 characters, past the column, is still refused',
    "City too long.\n",
    $publish(array('cityName' => str_repeat('c', 101)))
);

// s_city_area holds 200 and the cap was 50, so a municipality between the two was refused
// with "too long" against a column with room for it four times over.
pin(
    'a 60-character municipality goes through',
    2,
    $publish(array('cityArea' => str_repeat('m', 60)))
);
pin(
    'and 201 characters, past the column, is refused',
    "Municipality too long.\n",
    $publish(array('cityArea' => str_repeat('m', 201)))
);

/* ----------------------------------------------------------------------------
 * And the guard behind those caps. Clearing the accumulated errors from a filter
 * is all a plugin has to do to put the over-long value back, and the location
 * insert's return was the only thing that knew.
 * ------------------------------------------------------------------------- */
harness_section('a refused location write leaves a trace');

/** The listing equivalent of strict_guard_clear_flash(): a plugin overwriting the verdict. */
function strict_guard_clear_item_flash($flash_error)
{
    return '';
}

osc_add_filter('pre_item_add_error', 'strict_guard_clear_item_flash');

$warnings = array();
set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
    $warnings[] = $errstr;

    return true;
});
$before   = $itemCount();
$orphaned = $publish(array('s_zip' => str_repeat('9', 16)));
restore_error_handler();

osc_remove_filter('pre_item_add_error', 'strict_guard_clear_item_flash');

pin('the listing itself was published', 2, $orphaned);
pin('and its row is there', $before + 1, $itemCount());
pin(
    'but the location row it needs is not -- the strict connection refused it',
    0,
    (int) $admin->query(
        "SELECT COUNT(*) c FROM {$prefix}t_item_location WHERE s_zip = '" . str_repeat('9', 16) . "'"
    )->fetch_assoc()['c']
);
pin('and that left a warning, where it used to leave nothing at all', 1, count($warnings));
check(
    'naming the listing whose location is missing',
    strpos($warnings[0] ?? '', 'Item location insert wrote no row for item ') === 0,
    describe($warnings[0] ?? null)
);

check(
    'the location row is there for the listing that was accepted',
    (int) $admin->query(
        "SELECT COUNT(*) c FROM {$prefix}t_item_location WHERE s_country = '" . str_repeat('C', 80) . "'"
    )->fetch_assoc()['c'] === 1
);

exit(harness_result());

/* file end: ./tests/strict-write-guards.php */
