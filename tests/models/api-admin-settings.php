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
 * `/admin/settings`, `/admin/keys` and `/admin/jobs` end to end: the settings list holds no
 * secret and is saved through the settings screens' own forms, all or none; keys are made,
 * rotated and revoked with the token shown once and never a scope the caller lacks; the job
 * queue is read without payloads.
 *
 * Usage:  php tests/models/api-admin-settings.php        (standalone, own scratch database)
 *         php tests/run-models.php api-admin-settings    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\admin\ExposedSettings;
use mindstellar\api\Response;
use mindstellar\settings\SettingsPageRegistry;

$admin = api_admin_boot('osc_models_api_admin_settings');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
seed_currency($admin);
$secrets = [
    'mailserver_password'  => 'mail-password-SECRET',
    'googlemaps_api_key'   => 'maps-key-SECRET',
    'openstreet_api_key'   => 'osm-key-SECRET',
    'storage_s3_secret'    => 's3-secret-SECRET',
    'recaptchaPrivKey'     => 'captcha-SECRET',
];
foreach (['pageTitle' => 'My shop', 'contactEmail' => 'owner@example.test', 'language' => 'en_US', 'currency' => 'USD',
    'timezone' => 'UTC', 'comments_per_page' => '10', 'enabled_comments' => '1', 'dateFormat' => 'Y-m-d', 'timeFormat' => 'H:i'] + $secrets as $k => $v) {
    Preference::getInstance()->replace($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

$bossId   = api_admin_seed_admin($admin, 'boss');
$otherId  = api_admin_seed_admin($admin, 'other');
$modId    = api_admin_seed_admin($admin, 'mod', true);
$boss     = api_admin_key($bossId);
$mod      = api_admin_key($modId, true);
$keyMaker = api_admin_key($bossId, false, ['admin:keys']);
$call     = api_admin_caller();
$pref     = static fn (string $name, string $section = 'osclass'): ?string => $admin->query(
    "SELECT s_value FROM {$p}t_preference WHERE s_section = '$section' AND s_name = '" . $admin->real_escape_string($name) . "'"
)->fetch_row()[0] ?? null;

harness_section('settings: who may call');
pin('an admin key: 200', 200, $call('GET', 'admin/settings', null, $boss)->status());
pin('a moderator: 403', '403 insufficient_scope', api_admin_code($call('GET', 'admin/settings', null, $mod)));
pin('a key without admin:settings: 403', '403 insufficient_scope', api_admin_code($call('GET', 'admin/settings', null, $keyMaker)));

harness_section('settings: no secret');
$r    = $call('GET', 'admin/settings', null, $boss);
$json = (string) json_encode($r->body());
pin('the stored values, typed', ['My shop', 'owner@example.test', 10, true], [
    $r->body()['data']['site_title'] ?? null, $r->body()['data']['contact_email'] ?? null, $r->body()['data']['comments_per_page'] ?? null, $r->body()['data']['comments_enabled'] ?? null,
]);
pin('matches the schema', [], api_admin_schema_errors('SettingsDocument', $r));
pin('no stored secret is in the answer', [], array_values(array_filter($secrets, static fn (string $secret): bool => str_contains($json, $secret))));
$fields = [];
foreach (ExposedSettings::FIELDS as $member => [$form, $field]) {
    $fields[$member] = SettingsPageRegistry::getInstance()->fields($form::register())[$field] ?? null;
}
pin('every exposed setting is a declared field of its form', [], array_keys(array_filter($fields, static fn ($f): bool => $f === null)));
pin('none is a secret or password field', [], array_keys(array_filter($fields, static fn ($f): bool => in_array($f['type'] ?? '', ['secret', 'password'], true))));
pin('none is named like a secret, and the API switch is not one', [], array_values(array_filter(
    array_merge(array_keys(ExposedSettings::FIELDS), array_column(ExposedSettings::FIELDS, 1)),
    static fn (string $name): bool => preg_match('/pass|secret|crypt|token|_key$|api_enabled/i', $name) === 1
)));

harness_section('settings: changes');
$r = $call('PATCH', 'admin/settings', ['site_title' => 'Our shop', 'comments_per_page' => 25, 'api_rate_limit_default' => 300], $boss);
pin('PATCH answers the settings as saved', [200, 'Our shop', 25, 300], [$r->status(), $r->body()['data']['site_title'] ?? null, $r->body()['data']['comments_per_page'] ?? null, $r->body()['data']['api_rate_limit_default'] ?? null]);
pin('stored where the screens store them', ['Our shop', '25', '300'], [$pref('pageTitle'), $pref('comments_per_page'), $pref('api_rate_limit_default', 'api')]);
pin('settings of a form that were not sent keep their values', ['owner@example.test', '1'], [$pref('contactEmail'), $pref('enabled_comments')]);
$r = $call('PATCH', 'admin/settings', ['comments_enabled' => false], $boss);
pin('a switch turned off', [false, '0'], [$r->body()['data']['comments_enabled'] ?? null, $pref('enabled_comments')]);
pin('a value the schema refuses is 422', 422, $call('PATCH', 'admin/settings', ['api_rate_limit_default' => 0], $boss)->status());
pin('the API switch cannot be reached', 422, $call('PATCH', 'admin/settings', ['api_enabled' => false], $boss)->status());
pin('nor a secret', 422, $call('PATCH', 'admin/settings', ['mailserver_password' => 'x'], $boss)->status());
$r = $call('PATCH', 'admin/settings', ['site_title' => 'Never saved', 'language' => 'xx_XX'], $boss);
pin('what the form refuses is 422', [422, 'rejected'], [$r->status(), $r->body()['errors'][0]['code'] ?? null]);
pin('and nothing of the request is saved', ['Our shop', 'en_US'], [$pref('pageTitle'), $pref('language')]);
$r = $call('PATCH', 'admin/settings', ['site_title' => 'Saved first', 'api_cors_origins' => 'not an origin'], $boss);
pin('a refusal in a later form is 422', 422, $r->status());
pin('and rolls back the form saved before it', 'Our shop', $pref('pageTitle'));

harness_section('keys');
$r = $call('GET', 'admin/keys', null, $boss);
pin('the list: every key, never a secret', [200, 3], [$r->status(), count($r->body()['data'] ?? [])]);
pin('matches the schema', [], api_admin_schema_errors('ApiKeyList', $r));
check('no secret hash is in the list', !str_contains((string) json_encode($r->body()), (string) $admin->query("SELECT s_secret_hash FROM {$p}t_api_credential LIMIT 1")->fetch_row()[0]));
$r     = $call('POST', 'admin/keys', ['name' => 'Moderation bot', 'scopes' => ['admin:listings', 'admin:comments'], 'expires_at' => '90d'], $boss);
$token = (string) ($r->body()['data']['token'] ?? '');
$made  = (int) ($r->body()['data']['id'] ?? 0);
pin('POST: 201 with the token once', [201, 'admin', ['admin:listings', 'admin:comments'], true], [$r->status(), $r->body()['data']['kind'] ?? null, $r->body()['data']['scopes'] ?? null, str_starts_with($token, 'sck_')]);
pin('Location names it, and it belongs to the caller\'s admin', ['http://localhost/api/v1/admin/keys/' . $made, (string) $bossId], [
    $r->header('Location'), $admin->query("SELECT fk_i_admin_id FROM {$p}t_api_credential WHERE pk_i_id = $made")->fetch_row()[0],
]);
pin('matches the schema', [], api_admin_schema_errors('ApiKeyDocument', $r));
pin('the token works', 200, $call('GET', 'admin/listings', null, $token)->status());
pin('it is not shown again', false, isset($call('GET', 'admin/keys/' . $made, null, $boss)->body()['data']['token']));
pin('a key cannot grant a scope it lacks', '403 forbidden', api_admin_code($call('POST', 'admin/keys', ['name' => 'Escalate', 'scopes' => ['admin:users']], $keyMaker)));
pin('but can make a public key', [201, 'public', ['listings:read']], (static fn (Response $r): array => [$r->status(), $r->body()['data']['kind'] ?? null, $r->body()['data']['scopes'] ?? null])(
    $call('POST', 'admin/keys', ['name' => 'Mobile app', 'kind' => 'public'], $keyMaker)
));
pin('an unknown scope is refused', 403, $call('POST', 'admin/keys', ['name' => 'Typo', 'scopes' => ['admin:everything']], $boss)->status());
pin('a past expiry is 422', 422, $call('POST', 'admin/keys', ['name' => 'Old', 'scopes' => ['admin:listings'], 'expires_at' => '2020-01-01'], $boss)->status());
$short    = (string) ($call('POST', 'admin/keys', ['name' => 'Short', 'scopes' => ['admin:keys', 'admin:listings'], 'expires_at' => '10d'], $boss)->body()['data']['token'] ?? '');
$shortEnd = $call('GET', 'admin/keys', null, $boss)->body()['data'][0]['expires_at'] ?? null;
$child    = $call('POST', 'admin/keys', ['name' => 'Child', 'scopes' => ['admin:listings']], $short);
pin('a key made by a short-lived key gets its expiry', [201, true], [$child->status(), $shortEnd !== null && ($child->body()['data']['expires_at'] ?? null) === $shortEnd]);
pin('and cannot outlive it', 422, $call('POST', 'admin/keys', ['name' => 'Longer', 'scopes' => ['admin:listings'], 'expires_at' => '30d'], $short)->status());
$long = (int) ($call('POST', 'admin/keys', ['name' => 'Long', 'scopes' => ['admin:listings'], 'expires_at' => '90d'], $boss)->body()['data']['id'] ?? 0);
$r    = $call('POST', 'admin/keys/' . $long . '/rotate', null, $short);
pin('nor rotate a longer key into one that outlives it', [201, true], [$r->status(), $shortEnd !== null && ($r->body()['data']['expires_at'] ?? null) === $shortEnd]);
$r     = $call('POST', 'admin/keys/' . $made . '/rotate', null, $boss);
$fresh = (string) ($r->body()['data']['token'] ?? '');
pin('rotate: 201, a new token with the same scopes', [201, true, ['admin:listings', 'admin:comments']], [$r->status(), $fresh !== '' && $fresh !== $token, $r->body()['data']['scopes'] ?? null]);
pin('both keys work until the old one is revoked', [200, 200], [$call('GET', 'admin/listings', null, $token)->status(), $call('GET', 'admin/listings', null, $fresh)->status()]);
$others = api_admin_key($otherId);
$theirs = (int) $admin->query("SELECT MAX(pk_i_id) FROM {$p}t_api_credential WHERE fk_i_admin_id = $otherId")->fetch_row()[0];
pin('another admin\'s key cannot be rotated', '403 not_owner', api_admin_code($call('POST', 'admin/keys/' . $theirs . '/rotate', null, $boss)));
pin('nor revoked', ['403 not_owner', 200], [api_admin_code($call('DELETE', 'admin/keys/' . $theirs, null, $boss)), $call('GET', 'admin/listings', null, $others)->status()]);
$theirApp = (int) ($call('POST', 'admin/keys', ['name' => 'Their app', 'kind' => 'public'], $others)->body()['data']['id'] ?? 0);
$r        = $call('POST', 'admin/keys/' . $theirApp . '/rotate', null, $boss);
pin('another admin\'s public key rotates into one the caller owns', [201, (string) $bossId], [
    $r->status(), $admin->query("SELECT fk_i_admin_id FROM {$p}t_api_credential WHERE pk_i_id = " . (int) ($r->body()['data']['id'] ?? 0))->fetch_row()[0] ?? null,
]);
pin('and their public key can be revoked', 204, $call('DELETE', 'admin/keys/' . $theirApp, null, $boss)->status());
pin('DELETE revokes: 204, then the key is refused', [204, 401], [$call('DELETE', 'admin/keys/' . $made, null, $boss)->status(), $call('GET', 'admin/listings', null, $token)->status()]);
pin('revoking it again is 409', '409 conflict', api_admin_code($call('DELETE', 'admin/keys/' . $made, null, $boss)));
pin('a revoked key cannot be rotated', '409 conflict', api_admin_code($call('POST', 'admin/keys/' . $made . '/rotate', null, $boss)));
pin('an unknown key is 404', 404, $call('DELETE', 'admin/keys/99999', null, $boss)->status());
pin('a moderator cannot manage keys', '403 insufficient_scope', api_admin_code($call('GET', 'admin/keys', null, $mod)));

harness_section('jobs');
osc_job_enqueue('test.noop', ['n' => 1]);
seed_exec($admin, "INSERT INTO {$p}t_job_queue (s_type, s_payload, s_status, i_attempts, s_last_error, dt_next_run, dt_created) VALUES ('test.dead', '{\"token\":\"payload-SECRET\"}', 'error', 8, 'gave up', NOW(), NOW())", '', []);
$r = $call('GET', 'admin/jobs', null, $boss);
pin('counts per status and the dead letters', [200, 1, 1, 'test.dead', 'gave up'], [
    $r->status(), $r->body()['data']['counts']['pending'] ?? null, $r->body()['data']['counts']['error'] ?? null,
    $r->body()['data']['dead_letters'][0]['type'] ?? null, $r->body()['data']['dead_letters'][0]['last_error'] ?? null,
]);
pin('matches the schema', [], api_admin_schema_errors('JobsDocument', $r));
check('never a job\'s payload', !str_contains((string) json_encode($r->body()), 'payload-SECRET'));
pin('a moderator cannot read it', 403, $call('GET', 'admin/jobs', null, $mod)->status());

exit(harness_result());
