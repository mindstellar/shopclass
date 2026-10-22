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
 * On a demo site the API refuses what the admin screens refuse, because every visitor knows
 * the admin login. Usage: php tests/models/api-demo-lock.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\apikey\ApiAccess;
use mindstellar\apikey\ApiKeys;
use mindstellar\apikey\CredentialKind;
use mindstellar\apikey\KeyOwner;
use mindstellar\apikey\Scopes;
use mindstellar\exception\ForbiddenException;
use mindstellar\location\LocationService;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;
use mindstellar\webhook\Endpoint;
use mindstellar\webhook\WebhookEndpointStore;
use mindstellar\webhook\WebhookServices;

$admin = api_admin_boot('osc_models_api_demo_lock');
$p     = DB_TABLE_PREFIX;
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

$bossId = api_admin_seed_admin($admin, 'boss');
$boss   = api_admin_key($bossId);
$owner  = KeyOwner::admin($bossId);
$target = (int) (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create(CredentialKind::KEY, 'target', ['admin:listings'], $owner)->id();
$hook   = (new WebhookEndpointStore())->insert(new Endpoint(WebhookEndpointStore::newId(), 'https://93.184.216.34/hook', ['listing.created'], 'Kept', true, 'whsec_test', createdBy: $bossId, created: time(), updated: time()))->id();
$country = seed_country($admin, 'US', 'United States');
$region  = seed_region($admin, $country, 'Alpha');
$city    = seed_city($admin, $region, 'Aville', $country);
$call   = api_admin_caller();
$count  = static fn (string $table): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}{$table}")->fetch_row()[0];
$live   = static fn (int $id): bool => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE pk_i_id = $id AND dt_revoked IS NULL")->fetch_row()[0] === 1;

/**
 * The reason a core call refused with, or '' when it went through.
 */
function demo_refusal(callable $write): string
{
    try {
        $write();
    } catch (ForbiddenException $e) {
        return $e->reason();
    }

    return '';
}

// Defined after the seeding, because it cannot be undefined.
define('DEMO', true);

harness_section('keys');
$keys = $count('t_api_credential');
pin('making a key is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/keys', ['name' => 'Kept', 'scopes' => ['admin:listings']], $boss)));
pin('rotating a key is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/keys/' . $target . '/rotate', null, $boss)));
pin('revoking a key is 403 demo_site', '403 demo_site', api_admin_code($call('DELETE', 'admin/keys/' . $target, null, $boss)));
pin('no key is made and the old one still works', [$keys, true], [$count('t_api_credential'), $live($target)]);
pin('the service the admin screen and the CLI use refuses too', [ForbiddenException::DEMO, ForbiddenException::DEMO, ForbiddenException::DEMO], [
    demo_refusal(static fn () => ApiAccess::site()->keyService()->create($owner, 'Web', CredentialKind::KEY, ['admin:listings'])),
    demo_refusal(static fn () => ApiAccess::site()->keyService()->rotate($target, $bossId)),
    demo_refusal(static fn () => ApiAccess::site()->keyService()->revoke($target)),
]);
pin('still no key is made', $keys, $count('t_api_credential'));

harness_section('webhooks');
$hooks  = static fn (): array => $admin->query("SELECT s_key, s_value FROM {$p}t_key_value WHERE s_group = 'api_webhook' ORDER BY s_key")->fetch_all();
$before = [$hooks(), $count('t_job_queue')];
pin('adding an endpoint is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/webhooks', ['url' => 'https://93.184.216.34/other', 'events' => ['listing.created']], $boss)));
pin('changing one is 403 demo_site', '403 demo_site', api_admin_code($call('PATCH', 'admin/webhooks/' . $hook, ['url' => 'https://93.184.216.35/hook'], $boss)));
pin('a test ping is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/webhooks/' . $hook . '/test', null, $boss)));
pin('a new secret is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/webhooks/' . $hook . '/rotate-secret', null, $boss)));
pin('deleting one is 403 demo_site', '403 demo_site', api_admin_code($call('DELETE', 'admin/webhooks/' . $hook, null, $boss)));
pin('no endpoint changes and no ping is queued', $before, [$hooks(), $count('t_job_queue')]);
$service = WebhookServices::site()->service();
pin('the service the admin screen uses refuses too', array_fill(0, 5, ForbiddenException::DEMO), [
    demo_refusal(static fn () => $service->create('https://93.184.216.34/web', ['listing.created'])),
    demo_refusal(static fn () => $service->update($hook, null, null, null, false)),
    demo_refusal(static fn () => $service->rotate($hook)),
    demo_refusal(static fn () => $service->test($hook)),
    demo_refusal(static fn () => $service->delete($hook)),
]);
pin('still nothing changes', $before, [$hooks(), $count('t_job_queue')]);

harness_section('locations');
$places = static fn (): array => [$admin->query("SELECT pk_i_id, s_name FROM {$p}t_region ORDER BY pk_i_id")->fetch_all(), $admin->query("SELECT pk_i_id, s_name FROM {$p}t_city ORDER BY pk_i_id")->fetch_all(), $count('t_city_area')];
$before = $places();
pin('adding a region is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/regions', ['country' => 'US', 'name' => 'Beta'], $boss)));
pin('renaming a region is 403 demo_site', '403 demo_site', api_admin_code($call('PATCH', 'admin/regions/' . $region, ['name' => 'Renamed'], $boss)));
pin('adding a city is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/cities', ['region_id' => $region, 'name' => 'Bville'], $boss)));
pin('adding an area is 403 demo_site', '403 demo_site', api_admin_code($call('POST', 'admin/areas', ['city_id' => $city, 'name' => 'Downtown'], $boss)));
pin('deleting a city is 403 demo_site', '403 demo_site', api_admin_code($call('DELETE', 'admin/cities/' . $city, null, $boss)));
pin('deleting a region is 403 demo_site', '403 demo_site', api_admin_code($call('DELETE', 'admin/regions/' . $region, null, $boss)));
pin('no place changes', $before, $places());
$editor = new LocationService();
pin('the service the admin screen uses refuses too', array_fill(0, 3, ForbiddenException::DEMO), [
    demo_refusal(static fn () => $editor->addCountry('DE', 'Germany')),
    demo_refusal(static fn () => $editor->editCountry('US', 'Renamed')),
    demo_refusal(static fn () => $editor->delete('country', 'US')),
]);
pin('still no place changes', [$before, 1], [$places(), $count('t_country')]);

exit(harness_result());
