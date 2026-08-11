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
 * Integration tests for the delete cascades.
 *
 * Every entity that can be removed is deleted here with a full set of children
 * attached, and the tables that referenced it are then read back and required to
 * be empty. The point is coverage rather than characterization: a new child table
 * added to an existing parent without a matching cascade fails the sweep at the
 * bottom of this file even if nobody remembers to write a pin for it.
 *
 * Two classes of bug are pinned explicitly because both have shipped before:
 *
 *  - A dependent table missed by the model. The parent's foreign key is RESTRICT,
 *    so the parent delete fails, while the children the model DID remember are
 *    already gone -- leaving a half-erased row that can never be deleted. This is
 *    what t_meta_categories did to category deletion, and what
 *    t_form_submission_value did to custom-field deletion.
 *  - A child table with no foreign key at all, which nothing blocks and nothing
 *    cleans up: t_alerts, t_item_report_log, t_item_moderation_log,
 *    t_location_slug_history, t_form_submission.
 *
 * The final sweep walks every foreign key in information_schema and asserts that
 * no child row points at a missing parent, which covers the tables no pin names.
 *
 * Usage:  php tests/delete-cascade.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_delete_cascade');

if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('DEMO')) {
    // osc_deleteResource() short-circuits under DEMO, so the item cascade never
    // reaches the filesystem while still firing every hook and database delete.
    define('DEMO', true);
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}

// Field and Category resolve the current locale in their constructors, through
// osc_current_user_locale() -> osc_language(). These are the real helpers: with
// no session and no preferences seeded they resolve to the empty locale code,
// which is all these deletes need.
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

$prefix = DB_TABLE_PREFIX;

/** Row count from a raw, unprepared read. */
$count = static function (string $sql) use ($admin): int {
    return (int) $admin->query($sql)->fetch_assoc()['c'];
};

/** Rows in $table matching $where. */
$rows = static function (string $table, string $where) use ($admin, $prefix): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM {$prefix}{$table} WHERE {$where}")->fetch_assoc()['c'];
};

seed_locale($admin);
seed_currency($admin);

/* ---------------------------------------------------------------------------
 * Custom field with a submitted value -- the delete that used to fail outright.
 * ------------------------------------------------------------------------ */

harness_section('Field::deleteByPrimaryKey — a field that has been submitted');

$group = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_group (s_name, s_slug, i_position) VALUES (?, ?, 0)",
    'ss',
    array('Contact form', 'contact-form')
);
$fieldCat = seed_category($admin, 'Widgets');
$field    = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_fields (fk_i_group_id, s_name, s_slug, e_type, i_position)
     VALUES (?, ?, ?, 'TEXT', 0)",
    'iss',
    array($group, 'Your name', 'your-name')
);

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)",
    'ii',
    array($fieldCat, $field)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_group_fields (fk_i_group_id, fk_i_field_id, i_position) VALUES (?, ?, 0)",
    'ii',
    array($group, $field)
);
$submission = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_form_submission (fk_i_group_id, s_context_type, i_context_id, s_status, dt_created)
     VALUES (?, 'test', 0, 'new', NOW())",
    'i',
    array($group)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_form_submission_value (fk_i_submission_id, fk_i_field_id, s_value, s_multi)
     VALUES (?, ?, 'Ada', '')",
    'ii',
    array($submission, $field)
);

pin('the submitted value is there to begin with', 1, $rows('t_form_submission_value', "fk_i_field_id = $field"));

$deleted = Field::newInstance()->deleteByPrimaryKey($field);

pin('the field reports one row removed', 1, $deleted);
pin('the field is gone', 0, $rows('t_meta_fields', "pk_i_id = $field"));
pin('its submitted values went with it', 0, $rows('t_form_submission_value', "fk_i_field_id = $field"));
pin('its category assignment went with it', 0, $rows('t_meta_categories', "fk_i_field_id = $field"));
pin('its form membership went with it', 0, $rows('t_meta_group_fields', "fk_i_field_id = $field"));
pin('the submission row itself survives the field', 1, $rows('t_form_submission', "pk_i_id = $submission"));

/* ---------------------------------------------------------------------------
 * Form group -- submissions carry no foreign key, so only the model clears them.
 * ------------------------------------------------------------------------ */

harness_section('FieldGroup::deleteByPrimaryKey — a form with submissions');

$loose = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_fields (fk_i_group_id, s_name, s_slug, e_type, i_position)
     VALUES (?, ?, ?, 'TEXT', 0)",
    'iss',
    array($group, 'Message', 'message')
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_group_categories (fk_i_group_id, fk_i_category_id) VALUES (?, ?)",
    'ii',
    array($group, $fieldCat)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_form_submission_value (fk_i_submission_id, fk_i_field_id, s_value, s_multi)
     VALUES (?, ?, 'Hello', '')",
    'ii',
    array($submission, $loose)
);

$deleted = FieldGroup::newInstance()->deleteByPrimaryKey($group);

pin('the form reports one row removed', 1, $deleted);
pin('the form is gone', 0, $rows('t_meta_group', "pk_i_id = $group"));
pin('its category links went with it', 0, $rows('t_meta_group_categories', "fk_i_group_id = $group"));
pin('its submissions went with it', 0, $rows('t_form_submission', "fk_i_group_id = $group"));
pin('and their values cascaded', 0, $rows('t_form_submission_value', "fk_i_submission_id = $submission"));
pin('its fields are loosened, not deleted', 1, $rows('t_meta_fields', "pk_i_id = $loose"));
pin('and no longer point at the form', 0, $rows('t_meta_fields', "fk_i_group_id = $group"));

/* ---------------------------------------------------------------------------
 * Item, with every dependent table populated including the two log tables that
 * carry no foreign key.
 * ------------------------------------------------------------------------ */

harness_section('Item::deleteByPrimaryKey — an item with every child row');

$country = seed_country($admin);
$region  = seed_region($admin);
$city    = seed_city($admin, $region);
$cat     = seed_category($admin, 'Motors');
$user    = seed_user($admin);
$item    = seed_item($admin, $cat, $user);

$itemField = seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_fields (s_name, s_slug, e_type, i_position) VALUES (?, ?, 'TEXT', 0)",
    'ss',
    array('Mileage', 'mileage')
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, '120000', '')",
    'ii',
    array($item, $itemField)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_comment
     (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id)
     VALUES (?, NOW(), 'Nice', 'Bob', 'bob@example.test', 'Interested', 1, 1, 0, ?)",
    'ii',
    array($item, $user)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_resource (fk_i_item_id, s_name, s_extension, s_content_type, s_path, s_storage)
     VALUES (?, 'photo', 'jpg', 'image/jpeg', 'oc-content/uploads/', 'local')",
    'i',
    array($item)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_report_log (fk_i_item_id, s_reporter, fk_i_user_id, s_ip, s_reason, dt_date)
     VALUES (?, 'reporter@example.test', ?, '127.0.0.1', 'spam', NOW())",
    'ii',
    array($item, $user)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_moderation_log (fk_i_item_id, s_source, s_reason, s_field, s_action, dt_date)
     VALUES (?, 'auto', 'keyword', 's_title', 'flag', NOW())",
    'i',
    array($item)
);

$deleted = Item::newInstance()->deleteByPrimaryKey($item);

pin('the item reports one row removed', 1, $deleted);
pin('the item is gone', 0, $rows('t_item', "pk_i_id = $item"));
foreach (
    array(
        't_item_description',
        't_item_comment',
        't_item_resource',
        't_item_location',
        't_item_stats',
        't_item_meta',
        't_item_report_log',
        't_item_moderation_log',
    ) as $child
) {
    pin("$child has nothing left for the item", 0, $rows($child, "fk_i_item_id = $item"));
}

/* ---------------------------------------------------------------------------
 * The rollback. This is the guarantee the transaction exists for, and the one that
 * used to be missing: before it, a delete that could not finish had already removed
 * the listing's description, comments, images and stats by the time the parent delete
 * failed, leaving a listing that was still there but stripped of everything.
 *
 * The failure is provoked with a table outside the cascade holding a RESTRICT
 * reference, which is what a third-party plugin's own foreign key looks like from
 * here — the model clears the children it knows about and is then refused the parent.
 * ------------------------------------------------------------------------ */

harness_section('Item::deleteByPrimaryKey — a delete that cannot finish changes nothing');

$admin->query(
    "CREATE TABLE {$prefix}t_delete_blocker (
        fk_i_item_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (fk_i_item_id),
        FOREIGN KEY (fk_i_item_id) REFERENCES {$prefix}t_item (pk_i_id)
    ) ENGINE=InnoDB DEFAULT CHARACTER SET 'utf8mb4' COLLATE 'utf8mb4_general_ci'"
);

$blocked = seed_item($admin, $cat, $user, 'A listing that cannot be deleted');
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_resource (fk_i_item_id, s_name, s_extension, s_content_type, s_path, s_storage)
     VALUES (?, 'photo', 'jpg', 'image/jpeg', 'oc-content/uploads/', 'local')",
    'i',
    array($blocked)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_delete_blocker (fk_i_item_id) VALUES (?)",
    'i',
    array($blocked)
);

$refused = Item::newInstance()->deleteByPrimaryKey($blocked);

pin('the delete reports failure rather than a row count', false, $refused);
pin('the listing is still there', 1, $rows('t_item', "pk_i_id = $blocked"));
pin('its description survived the rollback', 1, $rows('t_item_description', "fk_i_item_id = $blocked"));
// The row surviving is also what keeps the files: they are unlinked only after the
// commit, from rows read before it, so a rollback never reaches the filesystem.
pin('its images survived the rollback', 1, $rows('t_item_resource', "fk_i_item_id = $blocked"));
pin('its location survived the rollback', 1, $rows('t_item_location', "fk_i_item_id = $blocked"));
pin('its stats survived the rollback', 1, $rows('t_item_stats', "fk_i_item_id = $blocked"));

$admin->query("DROP TABLE {$prefix}t_delete_blocker");

/* ---------------------------------------------------------------------------
 * Category, including a subcategory and the custom-field assignments that broke
 * this delete before.
 * ------------------------------------------------------------------------ */

harness_section('Category::deleteByPrimaryKey — a tree with items and field assignments');

$parent = seed_category($admin, 'Property');
$child  = seed_category($admin, 'Rentals', $parent);
$childItem = seed_item($admin, $child, $user, 'A flat');

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)",
    'ii',
    array($child, $itemField)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_category_slug_history (fk_i_category_id, fk_c_locale_code, s_slug, dt_date)
     VALUES (?, 'en_US', 'old-rentals-slug', NOW())",
    'i',
    array($child)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_plugin_category (s_plugin_name, fk_i_category_id) VALUES ('demo', ?)",
    'i',
    array($child)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_category_stats (fk_i_category_id, i_num_items) VALUES (?, 1)",
    'i',
    array($child)
);

$deleted = Category::newInstance()->deleteByPrimaryKey($parent);

pin('the parent category reports one row removed', 1, $deleted);
pin('the parent is gone', 0, $rows('t_category', "pk_i_id = $parent"));
pin('the subcategory went with it', 0, $rows('t_category', "pk_i_id = $child"));
pin('the subcategory items went too', 0, $rows('t_item', "pk_i_id = $childItem"));
pin('descriptions are gone', 0, $rows('t_category_description', "fk_i_category_id IN ($parent, $child)"));
pin('field assignments are gone', 0, $rows('t_meta_categories', "fk_i_category_id = $child"));
pin('slug history is gone', 0, $rows('t_category_slug_history', "fk_i_category_id = $child"));
pin('plugin links are gone', 0, $rows('t_plugin_category', "fk_i_category_id = $child"));
pin('stats are gone', 0, $rows('t_category_stats', "fk_i_category_id = $child"));

/* ---------------------------------------------------------------------------
 * User, including t_alerts which has no foreign key.
 * ------------------------------------------------------------------------ */

harness_section('User::deleteUser — a user with listings and alerts');

$owner = seed_user($admin, 'owner', 'owner@example.test');
$owned = seed_item($admin, $cat, $owner, 'Owned listing');

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_alerts (s_email, fk_i_user_id, s_search, s_secret, b_active, e_type, dt_date)
     VALUES ('owner@example.test', ?, 'a:0:{}', 'sec', 1, 'DAILY', NOW())",
    'i',
    array($owner)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_user_description (fk_i_user_id, fk_c_locale_code, s_info) VALUES (?, 'en_US', 'Bio')",
    'i',
    array($owner)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_user_email_tmp (fk_i_user_id, s_new_email, dt_date) VALUES (?, 'new@example.test', NOW())",
    'i',
    array($owner)
);

$ok = User::newInstance()->deleteUser($owner);

pin('the user delete reports success', true, $ok);
pin('the user is gone', 0, $rows('t_user', "pk_i_id = $owner"));
pin('their listings went with them', 0, $rows('t_item', "pk_i_id = $owned"));
pin('their alerts went with them', 0, $rows('t_alerts', "fk_i_user_id = $owner"));
pin('their profile went with them', 0, $rows('t_user_description', "fk_i_user_id = $owner"));
pin('their pending email change went with them', 0, $rows('t_user_email_tmp', "fk_i_user_id = $owner"));

/* ---------------------------------------------------------------------------
 * Locations -- the slug history has no foreign key and nothing else clears it.
 * ------------------------------------------------------------------------ */

harness_section('City / Region delete — recorded slug history');

$histRegion = seed_region($admin, 'US', 'Beta');
$histCity   = seed_city($admin, $histRegion, 'Shelbyville');

seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_location_slug_history (e_type, s_slug, fk_i_id, dt_date)
     VALUES ('CITY', 'old-shelbyville', ?, NOW())",
    'i',
    array($histCity)
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_location_slug_history (e_type, s_slug, fk_i_id, dt_date)
     VALUES ('REGION', 'old-beta', ?, NOW())",
    'i',
    array($histRegion)
);

City::newInstance()->deleteByPrimaryKey($histCity);
pin('the city is gone', 0, $rows('t_city', "pk_i_id = $histCity"));
pin("the city's slug history is gone", 0, $rows('t_location_slug_history', "e_type = 'CITY' AND fk_i_id = $histCity"));

Region::newInstance()->deleteByPrimaryKey($histRegion);
pin('the region is gone', 0, $rows('t_region', "pk_i_id = $histRegion"));
pin("the region's slug history is gone", 0, $rows('t_location_slug_history', "e_type = 'REGION' AND fk_i_id = $histRegion"));

/* ---------------------------------------------------------------------------
 * Page.
 * ------------------------------------------------------------------------ */

harness_section('Page::deleteByPrimaryKey');

$page = seed_page($admin, 'terms', 'Terms');

$deleted = Page::newInstance()->deleteByPrimaryKey($page);

pin('the page reports one row removed', 1, $deleted);
pin('the page is gone', 0, $rows('t_pages', "pk_i_id = $page"));
pin('its description went with it', 0, $rows('t_pages_description', "fk_i_pages_id = $page"));

/* ---------------------------------------------------------------------------
 * The sweep: no child row anywhere may point at a parent that is not there.
 * ------------------------------------------------------------------------ */

harness_section('No orphans anywhere');

$fks = $admin->query(
    "SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
       FROM information_schema.KEY_COLUMN_USAGE kcu
      WHERE kcu.TABLE_SCHEMA = DATABASE()
        AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
      ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME"
);

$checked = 0;
while ($fk = $fks->fetch_assoc()) {
    $orphans = $count(
        "SELECT COUNT(*) c FROM {$fk['TABLE_NAME']} c
           LEFT JOIN {$fk['REFERENCED_TABLE_NAME']} p
             ON c.{$fk['COLUMN_NAME']} = p.{$fk['REFERENCED_COLUMN_NAME']}
          WHERE c.{$fk['COLUMN_NAME']} IS NOT NULL AND p.{$fk['REFERENCED_COLUMN_NAME']} IS NULL"
    );
    pin("{$fk['TABLE_NAME']}.{$fk['COLUMN_NAME']} has no orphans", 0, $orphans);
    $checked++;
}
$fks->free();

check('the sweep actually inspected the schema', $checked > 30, "only $checked keys seen");

/* The foreign-key-free tables the sweep cannot reach, checked by hand. */
pin('no alerts for a missing user', 0, $count(
    "SELECT COUNT(*) c FROM {$prefix}t_alerts a
       LEFT JOIN {$prefix}t_user u ON a.fk_i_user_id = u.pk_i_id
      WHERE a.fk_i_user_id IS NOT NULL AND u.pk_i_id IS NULL"
));
pin('no report log for a missing item', 0, $count(
    "SELECT COUNT(*) c FROM {$prefix}t_item_report_log r
       LEFT JOIN {$prefix}t_item i ON r.fk_i_item_id = i.pk_i_id
      WHERE i.pk_i_id IS NULL"
));
pin('no moderation log for a missing item', 0, $count(
    "SELECT COUNT(*) c FROM {$prefix}t_item_moderation_log m
       LEFT JOIN {$prefix}t_item i ON m.fk_i_item_id = i.pk_i_id
      WHERE i.pk_i_id IS NULL"
));
pin('no submissions for a missing form', 0, $count(
    "SELECT COUNT(*) c FROM {$prefix}t_form_submission s
       LEFT JOIN {$prefix}t_meta_group g ON s.fk_i_group_id = g.pk_i_id
      WHERE g.pk_i_id IS NULL"
));
pin('no city slug history for a missing city', 0, $count(
    "SELECT COUNT(*) c FROM {$prefix}t_location_slug_history h
       LEFT JOIN {$prefix}t_city ci ON h.fk_i_id = ci.pk_i_id
      WHERE h.e_type = 'CITY' AND ci.pk_i_id IS NULL"
));
pin('no region slug history for a missing region', 0, $count(
    "SELECT COUNT(*) c FROM {$prefix}t_location_slug_history h
       LEFT JOIN {$prefix}t_region r ON h.fk_i_id = r.pk_i_id
      WHERE h.e_type = 'REGION' AND r.pk_i_id IS NULL"
));

exit(harness_result());

/* file end: ./tests/delete-cascade.php */
