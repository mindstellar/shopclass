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
use mindstellar\database\Db;
use mindstellar\listing\ListingGeocode;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\validation\InvalidException;

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
$cat = seed_category($admin, 'Cars', seed_category($admin, 'Vehicles', null, $en), $en);
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'map_type' => 'google',
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

$urls  = [];
$fetch = static function (string $url) use (&$urls): string {
    $urls[] = $url;

    return '{"results":[{"geometry":{"location":{"lat":30.2672,"lng":-97.7431}}}]}';
};
check('the job writes what the map service answered', (new ListingGeocode($fetch))->run($id));
pin('the coordinates are stored', ['30.267200', '-97.743100'], $coords($id));
check('the address went to the map service', str_contains($urls[0] ?? '', urlencode('1 Main Street, Austin, Texas, United States')));
check('a listing that has coordinates is not looked up again', !(new ListingGeocode($fetch))->run($id) && count($urls) === 1);
check('a listing that is gone is skipped', !(new ListingGeocode($fetch))->run(999999));
pin('a MapQuest answer is read without count() on an object', [40.5, -3.25], ListingGeocode::parse('openstreet', '{"results":[{"locations":[{"latLng":{"lat":40.5,"lng":-3.25}}]}]}'));
pin('an answer with no place is null', null, ListingGeocode::parse('google', '{"results":[],"status":"ZERO_RESULTS"}'));
pin('nothing to geocode is null', null, ListingGeocode::parse('google', 'not json'));

$admin->query("DELETE FROM {$p}t_job_queue");
$given = $service->create(ListingInput::fromArray($form(['ownerId' => '0', 'd_coord_lat' => '10.5', 'd_coord_long' => '20.5']), $actor, true), $actor)->id();
pin('a listing posted with coordinates queues no job', [], $jobs());
Preference::getInstance()->set('map_type', 'none');
scratchdb_forget_cache();
osc_reset_preferences();
$service->create(ListingInput::fromArray($form(['ownerId' => '0']), $actor, true), $actor);
pin('a site with no map queues no job', [], $jobs());
Preference::getInstance()->set('map_type', 'google');
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
Preference::getInstance()->set('map_type', 'none');
scratchdb_forget_cache();
osc_reset_preferences();
$service->update(ListingInput::fromArray($form(['id' => (string) $noCoords, 'ownerId' => '0', 'address' => '4 Main Street']), $actor, false), $actor, false, false);
pin('a new address on a site with no map queues none', [], $jobs());
Preference::getInstance()->set('map_type', 'google');
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

exit(harness_result());
