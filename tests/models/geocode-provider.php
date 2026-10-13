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
 * A geocode provider from a plugin: the owner's pick is used when it is set up, each answer is
 * cached, a listing with no street costs no call, and a provider error leaves the job to retry.
 *
 * Usage:  php tests/models/geocode-provider.php       (standalone, own scratch database)
 *         php tests/run-models.php geocode-provider   (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\listing\ListingGeocode;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\location\GeocodeProvider;
use mindstellar\location\GeocodeProviderRegistry;
use mindstellar\location\Geocoder;

$admin = api_admin_boot('osc_models_geocode_provider');
if (!function_exists('osc_item_map_type')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
}
if (!function_exists('osc_geocode')) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocation.php';
}

class FakeGeocoder implements GeocodeProvider
{
    /** @var array<int,array{0:string,1:?string}> */
    public array $calls = [];
    public ?array $answer = [18.52, 73.85];
    public bool $fail = false;
    public ?int $ttl = 3600;
    public bool $configured = true;

    public function id(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function geocode(string $address, ?string $countryCode = null): ?array
    {
        $this->calls[] = [$address, $countryCode];
        if ($this->fail) {
            throw new RuntimeException('service down');
        }

        return $this->answer;
    }

    public function cacheTtl(): ?int
    {
        return $this->ttl;
    }

    public function attribution(): string
    {
        return '<a href="https://example.test">Fake maps</a>';
    }
}

$fake = new FakeGeocoder();
osc_add_hook(GeocodeProviderRegistry::HOOK, static function (GeocodeProviderRegistry $registry) use ($fake): void {
    $registry->register($fake);
});

$p   = DB_TABLE_PREFIX;
$en  = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'IN', 'India');
$cat = seed_category($admin, 'Flats', seed_category($admin, 'Property', null, $en), $en);
$set = static function (array $prefs): void {
    foreach ($prefs as $k => $v) {
        Preference::getInstance()->set($k, $v);
    }
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$set(['moderate_items' => '-1', 'items_wait_time' => '0', 'language' => 'en_US', 'map_type' => '0',
    'title_character_length' => '100', 'description_character_length' => '5000']);
$_SERVER['REMOTE_ADDR'] = '192.0.2.72';

$actor   = Actor::admin(1, '192.0.2.72');
$service = new ListingService();
$form    = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $cat,
    'ownerId'      => '0',
    'countryId'    => 'IN',
    'country'      => 'India',
    'region'       => 'Maharashtra',
    'city'         => 'Pune',
    'address'      => '12 FC Road',
    'title'        => ['en_US' => 'Two room flat'],
    'description'  => ['en_US' => 'A bright flat near the college, two rooms and a balcony.'],
    'price'        => '15000',
    'contactName'  => 'Office',
    'contactEmail' => 'office@example.test',
];
$jobs   = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . ListingGeocode::JOB . "'")->fetch_row()[0];
$coords = static fn (int $id): array => array_values((array) $admin->query("SELECT d_coord_lat, d_coord_long FROM {$p}t_item_location WHERE fk_i_item_id = $id")->fetch_assoc());

harness_section('picking a provider');
pin('no pick, no geocoder', null, Geocoder::active());
pin('no pick, no credit', '', osc_geocode_attribution());
$set([GeocodeProviderRegistry::PREF => 'fake']);
check('the pick is used', Geocoder::active()?->provider() === $fake);
pin('its credit is shown', '<a href="https://example.test">Fake maps</a>', osc_geocode_attribution());
$fake->configured = false;
pin('a provider that is not set up is not used', null, Geocoder::active());
$fake->configured = true;
$set([GeocodeProviderRegistry::PREF => 'gone']);
pin('a pick whose plugin is gone is not used', null, Geocoder::active());
$set([GeocodeProviderRegistry::PREF => 'fake']);
$threw = false;
try {
    GeocodeProviderRegistry::getInstance()->register(new class () extends FakeGeocoder {
    });
    GeocodeProviderRegistry::getInstance()->register(new class () extends FakeGeocoder {
        public function id(): string
        {
            return 'Bad Id';
        }
    });
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check('a bad id is refused', $threw);
GeocodeProviderRegistry::getInstance()->register($fake);

harness_section('answers are cached');
$admin->query("DELETE FROM {$p}t_key_value WHERE s_group = 'geocode'");
$geo = new Geocoder($fake);
pin('an answer', [18.52, 73.85], $geo->geocode('12 FC Road,, Pune ', 'IN'));
pin('the address is tidied and the country passed lower case', [['12 FC Road, Pune', 'in']], $fake->calls);
pin('the same address again costs no call', [[18.52, 73.85], 1], [$geo->geocode('12 fc road, pune', 'IN'), count($fake->calls)]);
$fake->answer = null;
pin('no match', null, $geo->geocode('Nowhere Lane', 'IN'));
pin('is kept too', [null, 2], [$geo->geocode('Nowhere Lane', 'IN'), count($fake->calls)]);
$fake->answer = [91.0, 10.0];
pin('a position off the globe is no match', null, $geo->geocode('Bad Place', null));
$fake->answer = [0.0, 0.0];
pin('so is 0,0', null, $geo->geocode('Null Island', null));
$fake->answer = [18.6, 73.9];
$fake->ttl    = 0;
$geo->geocode('Short Lived', null);
$geo->geocode('Short Lived', null);
pin('a provider that allows no caching is asked each time', 2, count(array_filter($fake->calls, static fn (array $c): bool => $c[0] === 'Short Lived')));
$fake->ttl = null;
$geo->geocode('Forever Road', null);
$expires = $admin->query("SELECT dt_expires FROM {$p}t_key_value WHERE s_group = 'geocode' AND s_key = '" . hash('sha256', 'fake||forever road') . "'")->fetch_row()[0] ?? null;
check('an answer with no limit is still kept at most a year', $expires !== null && strtotime($expires) <= time() + 31536000 + 60 && strtotime($expires) > time() + 31536000 - 86400);
$fake->ttl = 3600;
osc_add_filter('geocode_coordinates', $nudge = static fn ($coords) => is_array($coords) ? [$coords[0] + 1, $coords[1]] : $coords);
pin('geocode_coordinates may change the answer', [19.6, 73.9], $geo->geocode('Filtered Road', null));
osc_remove_filter('geocode_coordinates', $nudge);
$fake->fail = true;
$threw      = false;
try {
    $geo->geocode('Down Road', null);
} catch (RuntimeException $e) {
    $threw = true;
}
check('a provider error reaches the caller', $threw);
pin('and is not cached', false, osc_kv_get(Geocoder::KV_GROUP, hash('sha256', 'fake||down road'), false));
pin('osc_geocode() turns an error into null', null, osc_geocode('Down Road'));
$fake->fail = false;

harness_section('listings');
$admin->query("DELETE FROM {$p}t_job_queue");
$id = $service->create(ListingInput::fromArray($form(), $actor, true), $actor)->id();
pin('a listing with a street queues a lookup, with no map set', 1, $jobs());
$fake->calls  = [];
$fake->answer = [18.5308, 73.8475];
check('the job uses the provider', (new ListingGeocode(static fn (): string => 'unused'))->run($id));
pin('with the address and country', [['12 FC Road, Pune, Maharashtra, India', 'in']], $fake->calls);
pin('the position is stored', ['18.530800', '73.847500'], $coords($id));

$admin->query("DELETE FROM {$p}t_job_queue");
$noStreet = $service->create(ListingInput::fromArray($form(['address' => '']), $actor, true), $actor)->id();
pin('a listing with no street queues none', 0, $jobs());
check('and its job would not call the provider', !(new ListingGeocode())->run($noStreet) && count($fake->calls) === 1);

$fake->fail = true;
$other      = $service->create(ListingInput::fromArray($form(['address' => '9 MG Road']), $actor, true), $actor)->id();
$threw      = false;
try {
    (new ListingGeocode())->run($other);
} catch (RuntimeException $e) {
    $threw = true;
}
check('a provider error fails the job, so the queue retries it', $threw);
$fake->fail = false;

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
