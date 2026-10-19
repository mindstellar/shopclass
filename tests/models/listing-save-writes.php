<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * A listing save looks coordinates up from a job after it commits, never inside its
 * transaction; an edit rewrites only the languages whose text changed; an admin cannot
 * name an owner that is not an account.
 *
 * Usage:  php tests/models/listing-save-writes.php       (standalone, own scratch database)
 *         php tests/run-models.php listing-save-writes   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\billing\Billing;
use mindstellar\database\Db;
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingGeocode;
use mindstellar\listing\ListingHoldJobs;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\model\Resource;

$admin = api_admin_boot('osc_models_listing_save_writes');
if (!function_exists('osc_item_map_type')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
}
if (!function_exists('osc_google_maps_geocode_url')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
}

$p   = DB_TABLE_PREFIX;
$en  = seed_locale($admin);
$es  = seed_locale($admin, 'es_ES', 'Spanish');
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$vehicles = seed_category($admin, 'Vehicles', null, $en);
$cat = seed_category($admin, 'Cars', $vehicles, $en);
$bikes     = seed_category($admin, 'Bikes', $vehicles, $en, 1, 30);
$evergreen = seed_category($admin, 'Evergreen', $vehicles, $en, 1, 0);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'map_type' => 'google', 'geocode_provider' => 'savetest',
    'title_character_length' => '100', 'description_character_length' => '5000'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.71';

$actor   = Actor::admin(1, '192.0.2.71');
$service = new ListingService();
$form    = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $cat,
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'address'      => '1 Main Street',
    'title'        => ['en_US' => 'Red hatchback', 'es_ES' => 'Coche rojo'],
    'description'  => ['en_US' => 'A small red car, one owner, full service history.', 'es_ES' => 'Un coche rojo pequeño, un dueño.'],
    'price'        => '1500',
    'contactName'  => 'Office',
    'contactEmail' => 'office@example.test',
    'contactPhone' => '5550199',
];
// A lookup plugin that answers every address with the same point and notes what it was asked.
class SaveTestGeocoder implements \mindstellar\location\GeocodeProvider
{
    /** @var string[] */
    public array $asked = [];

    public function id(): string
    {
        return 'savetest';
    }

    public function label(): string
    {
        return 'Save test';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function geocode(string $address, ?string $countryCode = null): ?array
    {
        $this->asked[] = $address;

        return [30.2672, -97.7431];
    }

    public function cacheTtl(): ?int
    {
        return 0;
    }

    public function attribution(): string
    {
        return '';
    }
}
$lookup = new SaveTestGeocoder();
osc_add_hook(\mindstellar\location\GeocodeProviderRegistry::HOOK, static function ($registry) use ($lookup): void {
    $registry->register($lookup);
});
$jobs   = static fn (): array => DBConnectionClass::newInstance()->getOsclassDb()->query("SELECT s_payload FROM {$p}t_job_queue WHERE s_type = '" . ListingGeocode::JOB . "'")->fetch_all(MYSQLI_ASSOC);
$coords = static fn (int $id): array => array_values((array) $admin->query("SELECT d_coord_lat, d_coord_long FROM {$p}t_item_location WHERE fk_i_item_id = $id")->fetch_assoc());

harness_section('coordinates are looked up after the save commits');
check('ListingService makes no HTTP call', !str_contains((string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/listing/ListingService.php'), 'osc_file_get_contents'));
$inside = null;
$id     = Db::transaction(static function () use ($service, $form, $actor, $jobs, &$inside): int {
    $id     = $service->create(ListingInput::fromArray($form(['ownerId' => '0']), $actor, true), $actor)->id();
    $inside = count($jobs());

    return $id;
});
pin('no job is queued while the transaction is open', 0, $inside);
pin('one geocode job for the listing once it commits', [['s_payload' => json_encode(['item' => $id])]], $jobs());
pin('the save left the coordinates empty', [null, null], $coords($id));

$rolled = 0;
try {
    Db::transaction(static function () use ($service, $form, $actor, &$rolled): void {
        $rolled = $service->create(ListingInput::fromArray($form(['ownerId' => '0']), $actor, true), $actor)->id();

        throw new RuntimeException('roll back');
    });
} catch (RuntimeException $e) {
}
pin('a save that rolls back queues nothing', 1, count($jobs()));

check('the job writes what the lookup plugin answered', (new ListingGeocode())->run($id));
pin('the coordinates are stored', ['30.267200', '-97.743100'], $coords($id));
pin('the address went to the plugin', ['1 Main Street, Austin, Texas, United States'], $lookup->asked);
check('a listing that has coordinates is not looked up again', !(new ListingGeocode())->run($id) && count($lookup->asked) === 1);
check('a listing that is gone is skipped', !(new ListingGeocode())->run(999999));

$admin->query("DELETE FROM {$p}t_job_queue");
$given = $service->create(ListingInput::fromArray($form(['ownerId' => '0', 'd_coord_lat' => '10.5', 'd_coord_long' => '20.5']), $actor, true), $actor)->id();
pin('a listing posted with coordinates queues no job', [], $jobs());
foreach (['google', 'openstreet'] as $type) {
    Preference::getInstance()->set('map_type', $type);
    Preference::getInstance()->set('geocode_provider', '');
    scratchdb_forget_cache();
    osc_reset_preferences();
    $service->create(ListingInput::fromArray($form(['ownerId' => '0']), $actor, true), $actor);
    pin('a ' . $type . ' map with no lookup plugin queues no job', [], $jobs());
}
Preference::getInstance()->set('map_type', 'google');
Preference::getInstance()->set('geocode_provider', 'savetest');
scratchdb_forget_cache();
osc_reset_preferences();

harness_section('an edit looks the address up again only when it changed');
$noCoords = $service->create(ListingInput::fromArray($form(['ownerId' => '0']), $actor, true), $actor)->id();
$admin->query("DELETE FROM {$p}t_job_queue");
$service->update(ListingInput::fromArray($form(['id' => (string) $noCoords, 'ownerId' => '0', 'price' => '1600']), $actor, false), $actor, false, false);
pin('a price edit on a listing with no coordinates queues no lookup', [], $jobs());
$service->update(ListingInput::fromArray($form(['id' => (string) $noCoords, 'ownerId' => '0', 'address' => '2 Main Street']), $actor, false), $actor, false, false);
pin('a new address queues one', [['s_payload' => json_encode(['item' => $noCoords])]], $jobs());
$admin->query("DELETE FROM {$p}t_job_queue");
$service->update(ListingInput::fromArray($form(['id' => (string) $given, 'ownerId' => '0', 'address' => '3 Main Street', 'd_coord_lat' => '10.5', 'd_coord_long' => '20.5']), $actor, false), $actor, false, false);
pin('a new address on a listing that keeps its coordinates queues none', [[], ['10.5', '20.5']], [$jobs(), array_map(static fn ($v): string => (string) (float) $v, $coords($given))]);
$point = static fn (int $id): array => array_values((array) $admin->query("SELECT e_geo_precision, d_geo_lat FROM {$p}t_item_location WHERE fk_i_item_id = $id")->fetch_assoc());
pin('a listing with its own position searches from it', ['exact', '10.500000'], $point($given));
$service->update(ListingInput::fromArray($form(['id' => (string) $given, 'ownerId' => '0', 'address' => '3 Main Street', 'price' => '1700']), $actor, false), $actor, false, false);
pin('an edit from a form with no map fields keeps the coordinates', ['10.5', '20.5'], array_map(static fn ($v): string => (string) (float) $v, $coords($given)));
$service->update(ListingInput::fromArray($form(['id' => (string) $given, 'ownerId' => '0', 'address' => '5 Main Street']), $actor, false), $actor, false, false);
pin('a new address with no coordinates clears them for a new lookup', [null, null], $coords($given));
$admin->query("DELETE FROM {$p}t_job_queue");
Preference::getInstance()->set('geocode_provider', '');
scratchdb_forget_cache();
osc_reset_preferences();
$service->update(ListingInput::fromArray($form(['id' => (string) $noCoords, 'ownerId' => '0', 'address' => '4 Main Street']), $actor, false), $actor, false, false);
pin('a new address on a site with no lookup plugin queues none', [], $jobs());
Preference::getInstance()->set('geocode_provider', 'savetest');
scratchdb_forget_cache();
osc_reset_preferences();

harness_section('an edit rewrites only the languages that changed');
$written = [];
osc_add_hook('item_content_updated', static function (int $item, string $locale) use (&$written): void {
    $written[] = $locale;
});
$replaces = static function (): int {
    $row = DBConnectionClass::newInstance()->getOsclassDb()->query("SHOW SESSION STATUS LIKE 'Com_replace'")->fetch_assoc();

    return (int) ($row['Value'] ?? -1);
};
$edit = static fn (array $extra = []): array => ListingInput::fromArray($form($extra + ['id' => (string) $id, 'ownerId' => '0']), $actor, false);
$before = $replaces();
$service->update($edit(), $actor, false, false);
pin('the same text writes no language', 0, $replaces() - $before);
pin('...but the hook still hears of every language, as before', ['en_US', 'es_ES'], $written);
$before = $replaces();
$service->update($edit(['title' => ['en_US' => 'Blue hatchback', 'es_ES' => 'Coche rojo']]), $actor, false, false);
pin('a new English title writes English only', 1, $replaces() - $before);
pin('and it is stored', 'Blue hatchback', $admin->query("SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = $id AND fk_c_locale_code = 'en_US'")->fetch_assoc()['s_title'] ?? null);
$admin->query("DELETE FROM {$p}t_item_description WHERE fk_i_item_id = $id AND fk_c_locale_code = 'es_ES'");
$before = $replaces();
$service->update($edit(['title' => ['en_US' => 'Blue hatchback', 'es_ES' => ''], 'description' => ['en_US' => 'A small red car, one owner, full service history.', 'es_ES' => '']]), $actor, false, false);
pin('an empty language with no row writes nothing', 0, $replaces() - $before);
$before = $replaces();
$service->update($edit(['title' => ['en_US' => 'Blue hatchback', 'es_ES' => 'Coche azul']]), $actor, false, false);
pin('a language with no row yet is written', [1, 'Coche azul'], [$replaces() - $before, $admin->query("SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = $id AND fk_c_locale_code = 'es_ES'")->fetch_assoc()['s_title'] ?? null]);

harness_section('the hooks get the texts as a fresh read gives them');
$hooked = [];
osc_add_hook('posted_item', static function ($item) use (&$hooked): void {
    $hooked['posted_item'] = $item;
});
osc_add_hook('edited_item', static function ($item) use (&$hooked): void {
    $hooked['edited_item'] = $item;
});
$long   = str_repeat('ñ', 100);
$posted = $service->create(ListingInput::fromArray($form(['ownerId' => '0', 'title' => ['es_ES' => $long, 'en_US' => 'Red hatchback']]), $actor, true), $actor)->id();
pin('posted_item: a full-width title, languages in stored order', Item::newInstance()->findByPrimaryKey($posted), $hooked['posted_item'] ?? null);
// A new price each time, so the update writes the row and the hook is built from it, not read again.
$service->update($edit(['price' => '1601', 'title' => ['es_ES' => 'Coche verde', 'en_US' => $long]]), $actor, false, false);
pin('edited_item: changed and unchanged languages', Item::newInstance()->findByPrimaryKey($id), $hooked['edited_item'] ?? null);
$service->update($edit(['price' => '1602', 'title' => ['es_ES' => '', 'en_US' => 'Blue hatchback'], 'description' => ['es_ES' => '', 'en_US' => 'Blue car.']]), $actor, false, false);
pin('edited_item: a language emptied', Item::newInstance()->findByPrimaryKey($id), $hooked['edited_item'] ?? null);

harness_section('an owner that is not an account');
$refused = null;
try {
    $service->update($edit(['ownerId' => '999999']), $actor, false, false);
} catch (InvalidException $e) {
    $refused = $e;
}
pin('the edit is refused at /owner_id', ['/owner_id', 'unknown'], [$refused?->errors()[0]['pointer'] ?? null, $refused?->errors()[0]['code'] ?? null]);
$refused = null;
try {
    $service->create(ListingInput::fromArray($form(['ownerId' => '999999']), $actor, true), $actor);
} catch (InvalidException $e) {
    $refused = $e;
}
pin('so is a new listing', '/owner_id', $refused?->errors()[0]['pointer'] ?? null);
$service->update($edit(['ownerId' => (string) $sue]), $actor, false, false);
pin('a real account is taken', (string) $sue, $admin->query("SELECT fk_i_user_id FROM {$p}t_item WHERE pk_i_id = $id")->fetch_assoc()['fk_i_user_id'] ?? null);

harness_section('a deadlock mid-save undoes the post and fires no posted_item');
$legacy = new DBCommandClass(\mindstellar\database\ConnectionManager::getInstance()->getHandle());
pin('outside a transaction a failed legacy query only returns false', false, @$legacy->query("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'test deadlock'"));
$deadlock = static function () use ($legacy): void {
    $legacy->query("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'test deadlock'");
};
osc_add_hook('posted_item', $deadlock, 1);
$later = 0;
osc_add_hook('posted_item', static function () use (&$later): void {
    $later++;
}, 10);
$items   = static fn (): int => (int) $admin->query("SELECT COUNT(*) AS n FROM {$p}t_item")->fetch_assoc()['n'];
$before  = $items();
$refused = null;
try {
    $service->create(ListingInput::fromArray($form(), $actor, true), $actor);
} catch (InvalidException $e) {
    $refused = $e;
}
osc_remove_hook('posted_item', $deadlock);
pin('the post is refused with "rejected"', 'rejected', $refused?->errors()[0]['code'] ?? null);
pin('no listing row is left', $before, $items());
pin('no later posted_item listener ran', 0, $later);
pin('the next post works', $before + 1, ($service->create(ListingInput::fromArray($form(), $actor, true), $actor)->id() > 0) ? $items() : -1);

harness_section('a snapshot conflict a caller swallows still undoes the post');
$conflict = static function (): void {
    try {
        \mindstellar\database\Db::execute("SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO = 1020, MESSAGE_TEXT = 'test snapshot conflict'");
    } catch (\mindstellar\database\DbException $e) {
        // swallowed, as the DAO write helpers do
    }
};
osc_add_hook('pre_item_add', $conflict);
$before  = $items();
$refused = null;
try {
    @$service->create(ListingInput::fromArray($form(), $actor, true), $actor);
} catch (InvalidException $e) {
    $refused = $e;
}
osc_remove_hook('pre_item_add', $conflict);
pin('the post is refused with "rejected"', 'rejected', $refused?->errors()[0]['code'] ?? null);
pin('no listing row is written outside the transaction', $before, $items());
pin('the next post works', $before + 1, ($service->create(ListingInput::fromArray($form(), $actor, true), $actor)->id() > 0) ? $items() : -1);

harness_section('a swallowed snapshot conflict also blocks a photo row and a queued job');
// Called directly, not through a post: on prod a failed location write went on to the photo step.
$noOwner = 999999;
$photo   = static fn (): int|false => (new Resource())->insertResource('item', $noOwner, ['s_name' => 'x', 's_extension' => 'webp', 's_content_type' => 'image/webp', 's_path' => 'oc-content/uploads/0/']);
$job     = static fn (): int => osc_job_enqueue('storage.offload', ['id' => $noOwner]);
Db::beginTransaction();
$works = [$photo() > 0, $job() > 0];
Db::rollBack();
pin('in an intact transaction both are written', [true, true], $works);
Db::beginTransaction();
$conflict();
$refused = [$photo(), $job()];
Db::rollBack();
pin('after the conflict both are refused', [false, 0], $refused);

harness_section('over the limit a post is held, not refused');
$ann       = seed_user($admin, 'ann', 'ann@example.test');
scratchdb_forget_cache();
foreach ([Billing::PREF_ENABLED => 'BOOLEAN', 'billing_slot_enabled' => 'BOOLEAN'] as $k => $t) {
    osc_set_preference($k, '1', $k === Billing::PREF_ENABLED ? Billing::PREF_GROUP : 'osclass', $t);
}
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_delete_preference('billing_pay_at_post', 'osclass');
osc_set_preference('notify_new_item', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$fired = [];
foreach (['posted_item', 'hook_email_admin_new_item', 'hook_email_new_item_non_register_user', 'hook_email_item_validation', 'item_increase_stat'] as $name) {
    $fired[$name] = 0;
    osc_add_hook($name, static function () use (&$fired, $name): void {
        $fired[$name]++;
    });
}
$row      = static fn (int $id): array => (array) $admin->query("SELECT b_enabled, b_active, dt_first_pub_date, dt_pub_date, dt_expiration FROM {$p}t_item WHERE pk_i_id = $id")->fetch_assoc();
$state    = static fn (int $id): array => [(int) $row($id)['b_enabled'], (int) $row($id)['b_active'], $row($id)['dt_first_pub_date']];
$days     = static fn (int $id, string $col): float => round((strtotime((string) $row($id)[$col]) - time()) / 86400, 1);
$catCount = static fn (int $c): int => (int) ($admin->query("SELECT i_num_items FROM {$p}t_category WHERE pk_i_id = $c")->fetch_assoc()['i_num_items'] ?? 0);
$jobKeys  = static fn (string $type): array => array_column($admin->query("SELECT s_unique FROM {$p}t_job_queue WHERE s_type = '$type'")->fetch_all(MYSQLI_ASSOC), 's_unique');
$sellerOf = static fn (int $id): Actor => Actor::user($id, '192.0.2.71');
$post     = static fn (Actor $a, array $extra = [], bool $import = false): \mindstellar\listing\SavedListing
    => $service->create(ListingInput::fromArray($form($extra), $a, true), $a, $import);

$ann1 = $post($sellerOf($ann), ['catId' => (string) $bikes]);
pin('the first post is under the limit and goes live', [1, 1], array_slice($state($ann1->id()), 0, 2));
check('an in-limit post is not unpaid', !$ann1->unpaid());
$counts = $fired;
$catBefore = $catCount($bikes);
$held = $post($sellerOf($ann), ['catId' => (string) $bikes]);
$hid  = $held->id();
check('the second post is unpaid', $held->unpaid());
pin('the held row is hidden and never went live', [0, 0, null], $state($hid));
check('the held row has a publish date', $row($hid)['dt_pub_date'] !== null);
pin('its deadline is the hold: 7 days', 7.0, $days($hid, 'dt_expiration'));
pin('no posted_item, no e-mail hook, no stat increase', $counts, $fired);
pin('the category count did not change', $catBefore, $catCount($bikes));
pin('the stats row exists', 1, (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item_stats WHERE fk_i_item_id = $hid")->fetch_assoc()['c']);
pin('the texts are stored', 2, (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item_description WHERE fk_i_item_id = $hid")->fetch_assoc()['c']);
pin('the expire and remind jobs are queued under the id', [[(string) $hid], [(string) $hid]], [array_values(array_intersect($jobKeys(ListingHoldJobs::EXPIRE), [(string) $hid])), array_values(array_intersect($jobKeys(ListingHoldJobs::REMIND), [(string) $hid]))]);

osc_set_preference('billing_pay_at_post', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$refused = null;
try {
    $post($sellerOf($ann), ['catId' => (string) $bikes]);
} catch (InvalidException $e) {
    $refused = $e->errors()[0]['code'] ?? null;
}
pin('with the switch off the post is refused with listing_limit', 'listing_limit', $refused);
osc_set_preference('billing_pay_at_post', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();

$refused = null;
try {
    $post($actor, ['ownerId' => (string) $ann, 'catId' => (string) $bikes], true);
} catch (InvalidException $e) {
    $refused = $e->errors()[0]['code'] ?? null;
}
pin('an admin import over the limit is still refused', 'listing_limit', $refused);
$bea  = seed_user($admin, 'bea', 'bea@example.test');
$veto = static fn (): bool => false;
osc_add_filter('billing_can_publish', $veto);
$refused = null;
try {
    $post($sellerOf($bea), ['catId' => (string) $bikes]);
} catch (InvalidException $e) {
    $refused = $e->errors()[0]['code'] ?? null;
}
osc_remove_filter('billing_can_publish', $veto);
pin('a plugin refusal within the free limit is refused, not held', 'listing_limit', $refused);
osc_add_filter('billing_can_publish', $veto);
$refused = null;
try {
    $post($sellerOf($ann), ['catId' => (string) $bikes]);
} catch (InvalidException $e) {
    $refused = $e->errors()[0]['code'] ?? null;
}
osc_remove_filter('billing_can_publish', $veto);
pin('a plugin refusal over the limit is refused too, as the post form does', 'listing_limit', $refused);
$adminPost = $post($actor, ['ownerId' => (string) $ann, 'catId' => (string) $bikes]);
pin('an admin post over the limit goes live', [1, 1], array_slice($state($adminPost->id()), 0, 2));
$guestPost = $post(Actor::guest('192.0.2.71'), ['catId' => (string) $bikes]);
pin('a guest post is not metered', [1, 1], array_slice($state($guestPost->id()), 0, 2));

harness_section('a held listing goes live once');
$fired = array_map(static fn (): int => 0, $fired);
$catBefore = $catCount($bikes);
check('publish() takes the held listing live', $service->publish($hid, $sellerOf($ann)));
pin('it is live', [1, 1], array_slice($state($hid), 0, 2));
check('dt_first_pub_date is now', abs(strtotime((string) $row($hid)['dt_first_pub_date']) - time()) < 5);
pin('dt_pub_date equals dt_first_pub_date', $row($hid)['dt_first_pub_date'], $row($hid)['dt_pub_date']);
pin('its run is the category days: 30', 30.0, $days($hid, 'dt_expiration'));
pin('posted_item fired once', 1, $fired['posted_item']);
pin('the stats went up once', 1, $fired['item_increase_stat']);
pin('the admin mail hook fired once', 1, $fired['hook_email_admin_new_item']);
pin('the category count went up by one', $catBefore + 1, $catCount($bikes));
check('a second publish() returns false', $service->publish($hid, $sellerOf($ann)) === false);
pin('nothing fired again', 1, $fired['posted_item']);
check('publish() of a live listing is false', $service->publish($ann1->id(), $sellerOf($ann)) === false);

harness_section('publish() follows the owner moderation rule');
$held2 = $post($sellerOf($ann), ['catId' => (string) $bikes])->id();
osc_set_preference('moderate_items', '0', 'osclass', 'INTEGER');
osc_reset_preferences();
$fired = array_map(static fn (): int => 0, $fired);
$service->publish($held2, $sellerOf($ann));
osc_set_preference('moderate_items', '-1', 'osclass', 'INTEGER');
osc_reset_preferences();
pin('a post that would wait for validation is enabled but not active', [1, 0], array_slice($state($held2), 0, 2));
pin('the validation mail hook fired, not the live one', [1, 0], [$fired['hook_email_item_validation'], $fired['posted_item'] - 1]);

harness_section('publish() applies admin moderation');
$held3 = $post($sellerOf($ann), ['catId' => (string) $bikes])->id();
osc_set_preference('moderate_admin_post', '1', 'osclass', 'BOOLEAN');
osc_reset_preferences();
$service->publish($held3, $sellerOf($ann));
osc_set_preference('moderate_admin_post', '0', 'osclass', 'BOOLEAN');
osc_reset_preferences();
pin('the listing is disabled after announce, as a fresh post', 0, (int) $row($held3)['b_enabled']);

harness_section('a category with no run time leaves no deadline');
$held4 = $post($sellerOf($ann), ['catId' => (string) $evergreen])->id();
check('the held row has the hold deadline', $row($held4)['dt_expiration'] !== null);
$service->publish($held4, $sellerOf($ann));
pin('after publish the deadline is gone', '9999-12-31 23:59:59', $row($held4)['dt_expiration']);

harness_section('a held listing past its deadline counts once when published');
$late = $post($sellerOf($ann), ['catId' => (string) $bikes])->id();
$admin->query("UPDATE {$p}t_item SET dt_expiration = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE pk_i_id = $late");
$catBefore = $catCount($bikes);
$service->publish($late, $sellerOf($ann));
pin('the category count went up by one', $catBefore + 1, $catCount($bikes));

harness_section('an edit keeps a held listing held');
$held5   = $post($sellerOf($ann), ['catId' => (string) $bikes]);
$h5      = $held5->id();
$before  = $row($h5);
// An admin edit that asks for 5 days, which would move the deadline on a live listing.
$edit    = ListingInput::fromArray($form(['catId' => (string) $bikes, 'id' => (string) $h5, 'ownerId' => (string) $ann, 'dt_expiration' => '5', 'title' => ['en_US' => 'Edited bike', 'es_ES' => 'Bici editada']]), $actor, false);
$saved   = $service->update($edit, $actor, false, false);
check('the edit says unpaid', $saved->unpaid());
pin('the new title is stored', 'Edited bike', $admin->query("SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = $h5 AND fk_c_locale_code = 'en_US'")->fetch_assoc()['s_title']);
pin('the row is still hidden and unpublished', [0, 0, null], $state($h5));
pin('the deadline is unchanged', $before['dt_expiration'], $row($h5)['dt_expiration']);

exit(harness_result());
