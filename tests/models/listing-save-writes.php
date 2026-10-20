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
use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingGeocode;
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
$cat = seed_category($admin, 'Cars', seed_category($admin, 'Vehicles', null, $en), $en);
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
osc_add_hook('item_increase_stat', $conflict);
$before  = $items();
$refused = null;
try {
    @$service->create(ListingInput::fromArray($form(), $actor, true), $actor);
} catch (InvalidException $e) {
    $refused = $e;
}
osc_remove_hook('item_increase_stat', $conflict);
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

exit(harness_result());
