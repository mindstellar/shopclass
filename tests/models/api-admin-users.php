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
 * `/admin/users` and `/admin/comments` end to end through Kernel::handle(): who may call
 * them, the lists and their filters, the users screen's edit and actions (hooks, activity
 * log, a blocked user's keys stop working), a user's sign-ins, the delete with everything
 * the user owns in one transaction, and the comments screen's moderation.
 *
 * Usage:  php tests/models/api-admin-users.php        (standalone, own scratch database)
 *         php tests/run-models.php api-admin-users    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\KeyOwner;
use mindstellar\api\auth\Scopes;
use mindstellar\api\Response;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

$admin = api_admin_boot('osc_models_api_admin_users');

/* ----------------------------------------------------------------------------
 * Fixture: three users with passwords, listings, comments in each status, an alert and a
 * profile text; an admin, a moderator and their keys.
 * ------------------------------------------------------------------------- */
$p        = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cars     = seed_category($admin, 'Cars', null, $locale);
$sue      = seed_user($admin, 'sue', 'sue@example.test');
$tom      = seed_user($admin, 'tom', 'tom@example.test');
$ann      = seed_user($admin, 'ann', 'ann@example.test', 0);
$hash     = password_hash('open sesame', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string($hash) . "'");
$sueCar   = seed_item($admin, $cars, $sue, 'Sue car', 100.0);
$tomCar   = seed_item($admin, $cars, $tom, 'Tom car', 200.0);
$comment  = static fn (int $item, int $user, string $body, int $active = 1, int $enabled = 1, int $spam = 0): int => seed_exec(
    $admin,
    "INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id)"
    . " VALUES (?, NOW(), 'Hi', 'Author', 'author@example.test', ?, ?, ?, ?, ?)",
    'isiiii',
    [$item, $body, $enabled, $active, $spam, $user]
);
$liveComment    = $comment($sueCar, $tom, 'Still for sale?');
$pendingComment = $comment($sueCar, $tom, 'Price?', 0);
$blockedComment = $comment($tomCar, $sue, 'Rude', 1, 0);
$spamComment    = $comment($tomCar, $sue, 'Buy pills', 1, 1, 1);
$tomComment     = $comment($sueCar, $tom, 'Any rust?');
seed_exec($admin, "INSERT INTO {$p}t_alerts (s_email, fk_i_user_id, s_search, s_secret, b_active, e_type, dt_date) VALUES ('tom@example.test', ?, '{}', 'x', 1, 'DAILY', NOW())", 'i', [$tom]);
seed_exec($admin, "INSERT INTO {$p}t_user_description (fk_i_user_id, fk_c_locale_code, s_info) VALUES (?, 'en_US', 'About Tom')", 'i', [$tom]);
foreach (['enabled_users' => '1', 'enabled_comments' => '1', 'language' => 'en_US', 'logs_admin' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';

$bossId  = api_admin_seed_admin($admin, 'boss');
$modId   = api_admin_seed_admin($admin, 'mod', true);
$boss    = api_admin_key($bossId);
$mod     = api_admin_key($modId, true);
$lister  = api_admin_key($bossId, false, ['admin:listings']);
$keys    = new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
$sueKey  = $keys->create('key', 'sue script', ['listings:read', 'account:read'], KeyOwner::user($sue))->token();
$tomKey  = $keys->create('key', 'tom script', ['listings:read', 'account:read'], KeyOwner::user($tom))->token();

$call  = api_admin_caller();
$fired = [];
foreach (['activate_user', 'deactivate_user', 'enable_user', 'disable_user', 'user_edit_completed', 'delete_user', 'after_delete_user',
    'activate_comment', 'deactivate_comment', 'enable_comment', 'disable_comment', 'edit_comment', 'delete_comment',
    'hook_email_comment_validated', 'disable_item'] as $hook) {
    osc_add_hook($hook, static function (...$args) use (&$fired, $hook): void {
        $fired[$hook] = ($fired[$hook] ?? 0) + 1;
    });
}
$user = static fn (int $id): ?array => $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $id")->fetch_assoc();
$log  = static fn (string $action, int $id): ?string => $admin->query("SELECT fk_i_who_id FROM {$p}t_log WHERE s_section = 'user' AND s_action = '$action' AND fk_i_id = $id")->fetch_row()[0] ?? null;
$ids  = static fn (Response $r): array => array_column($r->body()['data'] ?? [], 'id');

harness_section('users: who may call');
pin('an admin key: 200', 200, $call('GET', 'admin/users', null, $boss)->status());
pin('a moderator: 403, the users screen is closed to moderators', '403 insufficient_scope', api_admin_code($call('GET', 'admin/users', null, $mod)));
pin('a key without admin:users: 403', '403 insufficient_scope', api_admin_code($call('GET', 'admin/users/' . $sue, null, $lister)));
pin('a user key: 403 forbidden', '403 forbidden', api_admin_code($call('GET', 'admin/users', null, $sueKey)));

harness_section('users: the list');
$r = $call('GET', 'admin/users', null, $boss, [], ['count' => 'true']);
pin('newest first, with the total', [[$ann, $tom, $sue], 3], [$ids($r), $r->body()['meta']['total'] ?? null]);
pin('the full shape: e-mail and account state', ['ann@example.test', false], [$r->body()['data'][0]['email'] ?? null, $r->body()['data'][0]['active'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('UserPage', $r));
pin('active=false', [$ann], $ids($call('GET', 'admin/users', null, $boss, [], ['active' => 'false'])));
pin('q= matches the start of an e-mail, username or name', [$tom], $ids($call('GET', 'admin/users', null, $boss, [], ['q' => 'tom@'])));
pin('limit=1 pages by id', [[$ann], true], (static fn (Response $r): array => [$ids($r), is_string($r->body()['links']['next'] ?? null)])($call('GET', 'admin/users', null, $boss, [], ['limit' => '1'])));
pin('one user', [200, 'tom@example.test'], (static fn (Response $r): array => [$r->status(), $r->body()['data']['email'] ?? null])($call('GET', 'admin/users/' . $tom, null, $boss)));
pin('matches the schema', [], api_admin_schema_errors('UserDocument', $call('GET', 'admin/users/' . $tom, null, $boss)));
pin('an unknown user is 404', 404, $call('GET', 'admin/users/99999', null, $boss)->status());

harness_section('comments: who may call');
pin('a moderator, as the comments screen is open to moderators: 200', 200, $call('GET', 'admin/comments', null, $mod)->status());
pin('a key without admin:comments: 403', '403 insufficient_scope', api_admin_code($call('GET', 'admin/comments', null, $lister)));
pin('a user key: 403 forbidden', '403 forbidden', api_admin_code($call('GET', 'admin/comments', null, $sueKey)));

harness_section('comments: the list');
$r = $call('GET', 'admin/comments', null, $mod, [], ['count' => 'true']);
pin('every status, newest first', [[$tomComment, $spamComment, $blockedComment, $pendingComment, $liveComment], 5], [$ids($r), $r->body()['meta']['total'] ?? null]);
pin('with the author\'s e-mail and the status', ['author@example.test', 'spam'], [$r->body()['data'][1]['author']['email'] ?? null, $r->body()['data'][1]['status'] ?? null]);
pin('matches the schema', [], api_admin_schema_errors('AdminCommentPage', $r));
pin('status=pending', [$pendingComment], $ids($call('GET', 'admin/comments', null, $mod, [], ['status' => 'pending'])));
pin('status=disabled,spam', [$spamComment, $blockedComment], $ids($call('GET', 'admin/comments', null, $mod, [], ['status' => ['disabled', 'spam']])));
pin('listing=', [$tomComment, $pendingComment, $liveComment], $ids($call('GET', 'admin/comments', null, $mod, [], ['listing' => (string) $sueCar])));
pin('an unknown status is 422', 422, $call('GET', 'admin/comments', null, $mod, [], ['status' => 'nope'])->status());

harness_section('comments: moderation');
$fired = [];
$r     = $call('POST', 'admin/comments/' . $pendingComment . '/activate', null, $mod);
pin('activate: approved, the author is told, activate_comment once', [200, 'active', 1, 1], [$r->status(), $r->body()['data']['status'] ?? null, $fired['hook_email_comment_validated'] ?? 0, $fired['activate_comment'] ?? 0]);
pin('matches the schema', [], api_admin_schema_errors('AdminCommentDocument', $r));
pin('deactivate', ['pending', 1], [$call('POST', 'admin/comments/' . $pendingComment . '/deactivate', null, $mod)->body()['data']['status'] ?? null, $fired['deactivate_comment'] ?? 0]);
pin('disable', ['disabled', 1], [$call('POST', 'admin/comments/' . $liveComment . '/disable', null, $mod)->body()['data']['status'] ?? null, $fired['disable_comment'] ?? 0]);
$fired = [];
pin('enable: live again, so the author is told', ['active', 1, 1], [
    $call('POST', 'admin/comments/' . $liveComment . '/enable', null, $mod)->body()['data']['status'] ?? null, $fired['enable_comment'] ?? 0, $fired['hook_email_comment_validated'] ?? 0,
]);
$call('POST', 'admin/comments/' . $pendingComment . '/disable', null, $mod);
$call('POST', 'admin/comments/' . $spamComment . '/disable', null, $mod);
$fired = [];
pin('unblocking a comment still waiting for approval tells no one', ['pending', 1, 0], [
    $call('POST', 'admin/comments/' . $pendingComment . '/enable', null, $mod)->body()['data']['status'] ?? null, $fired['enable_comment'] ?? 0, $fired['hook_email_comment_validated'] ?? 0,
]);
pin('nor one held as spam', ['spam', 0], [$call('POST', 'admin/comments/' . $spamComment . '/enable', null, $mod)->body()['data']['status'] ?? null, $fired['hook_email_comment_validated'] ?? 0]);
$r = $call('PATCH', 'admin/comments/' . $liveComment, ['body' => '<b>Is it</b> still for sale?'], $mod);
pin('PATCH stores plain text and keeps the rest', [200, 'Is it still for sale?', 'Hi', 1], [$r->status(), $r->body()['data']['body'] ?? null, $r->body()['data']['title'] ?? null, $fired['edit_comment'] ?? 0]);
pin('an empty body is 422', 422, $call('PATCH', 'admin/comments/' . $liveComment, ['body' => '<i></i>'], $mod)->status());
pin('DELETE: 204, delete_comment once', [204, 1], [$call('DELETE', 'admin/comments/' . $liveComment, null, $mod)->status(), $fired['delete_comment'] ?? 0]);
pin('again it is 404', 404, $call('DELETE', 'admin/comments/' . $liveComment, null, $mod)->status());
pin('an unknown comment is 404', 404, $call('POST', 'admin/comments/99999/activate', null, $mod)->status());

harness_section('comments: queries');
for ($i = 0; $i < 6; $i++) {
    $comment($sueCar, $sue, 'Filler ' . $i);
}
$count = static fn (int $limit): int => harness_query_count(static fn () => $call('GET', 'admin/comments', null, $mod, [], ['limit' => (string) $limit]));
$count(2);
pin('a page of 6 comments costs the same queries as a page of 2', $count(2), $count(6));

harness_section('users: the edit');
$fired = [];
$r     = $call('PATCH', 'admin/users/' . $sue, ['name' => 'Susan', 'email' => 'susan@example.test', 'phone_mobile' => '5550101'], $boss);
pin('PATCH answers the user', [200, 'Susan', 'susan@example.test', '5550101'], [$r->status(), $r->body()['data']['name'] ?? null, $r->body()['data']['email'] ?? null, $r->body()['data']['phone_mobile'] ?? null]);
pin('the admin edit applies the e-mail at once and to the user\'s listings', ['susan@example.test', 'susan@example.test'], [
    $user($sue)['s_email'], $admin->query("SELECT s_contact_email FROM {$p}t_item WHERE pk_i_id = $sueCar")->fetch_row()[0],
]);
pin('user_edit_completed fired once, logged under the admin', [1, (string) $bossId], [$fired['user_edit_completed'] ?? 0, $log('edit', $sue)]);
pin('members not sent keep their values, account state too', ['sue', '1', '1'], [$user($sue)['s_username'], $user($sue)['b_enabled'], $user($sue)['b_active']]);
pin('an e-mail another user has is refused', 422, $call('PATCH', 'admin/users/' . $sue, ['email' => 'tom@example.test'], $boss)->status());
pin('a new password', 200, $call('PATCH', 'admin/users/' . $sue, ['password' => 'new secret'], $boss)->status());
check('is stored', password_verify('new secret', (string) $user($sue)['s_password']));
pin('and ends the user\'s keys', 401, $call('GET', 'account', null, $sueKey)->status());

harness_section('users: the actions');
pin('the key works while the user is enabled', 200, $call('GET', 'account', null, $tomKey)->status());
$fired = [];
$r     = $call('POST', 'admin/users/' . $tom . '/disable', null, $boss);
pin('disable: 200, blocked, disable_user once, logged', [200, false, 1, (string) $bossId], [$r->status(), $r->body()['data']['enabled'] ?? null, $fired['disable_user'] ?? 0, $log('disable', $tom)]);
pin('and the user\'s listings are blocked with it', [1, '0'], [$fired['disable_item'] ?? 0, $admin->query("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id = $tomCar")->fetch_row()[0]]);
pin('a blocked user\'s key stops working', 401, $call('GET', 'account', null, $tomKey)->status());
pin('enable: enable_user once', [200, 1], [$call('POST', 'admin/users/' . $tom . '/enable', null, $boss)->status(), $fired['enable_user'] ?? 0]);
pin('activate: activate_user once, active', [1, '1'], [($call('POST', 'admin/users/' . $ann . '/activate', null, $boss) && true) ? ($fired['activate_user'] ?? 0) : 0, $user($ann)['b_active']]);
pin('deactivate: deactivate_user once', [1, '0'], [($call('POST', 'admin/users/' . $ann . '/deactivate', null, $boss) && true) ? ($fired['deactivate_user'] ?? 0) : 0, $user($ann)['b_active']]);
pin('an unknown user is 404', 404, $call('POST', 'admin/users/99999/enable', null, $boss)->status());

harness_section('users: sign-ins');
$signIn = $call('POST', 'auth/token', ['grant_type' => 'password', 'username' => 'tom@example.test', 'password' => 'open sesame', 'label' => 'Phone']);
$access = (string) ($signIn->body()['access_token'] ?? '');
pin('the user signs in', 200, $signIn->status());
$r       = $call('GET', 'admin/users/' . $tom . '/sessions', null, $boss);
$session = array_values(array_filter($r->body()['data'] ?? [], static fn (array $s): bool => $s['type'] === 'token'))[0] ?? [];
pin('the admin sees the sign-in and the key', [200, 'Phone', 2], [$r->status(), $session['label'] ?? null, count($r->body()['data'] ?? [])]);
pin('matches the schema', [], api_admin_schema_errors('SessionList', $r));
pin('ending it: 204', 204, $call('DELETE', 'admin/users/' . $tom . '/sessions/' . ($session['id'] ?? 'x'), null, $boss)->status());
pin('its refresh token no longer works', 400, $call('POST', 'auth/token', ['grant_type' => 'refresh_token', 'refresh_token' => (string) ($signIn->body()['refresh_token'] ?? '')])->status());
pin('another user\'s session id is 404', 404, $call('DELETE', 'admin/users/' . $sue . '/sessions/' . ($session['id'] ?? 'x'), null, $boss)->status());
check('its access token still lives out its few minutes', $access !== '');

$again   = $call('POST', 'auth/token', ['grant_type' => 'password', 'username' => 'tom@example.test', 'password' => 'open sesame']);
$tomKey2 = $keys->create('key', 'tom script 2', ['listings:read', 'account:read'], KeyOwner::user($tom))->token();
pin('a moderator cannot sign a user out of all devices', 403, $call('POST', 'admin/users/' . $tom . '/sign-out-everywhere', null, $mod)->status());
pin('sign-out-everywhere: 204, stamp up', [204, '1'], [$call('POST', 'admin/users/' . $tom . '/sign-out-everywhere', null, $boss)->status(), $user($tom)['i_auth_stamp']]);
pin('the access token, refresh token and key stop at once', [401, 400, 401], [
    $call('GET', 'account', null, (string) ($again->body()['access_token'] ?? ''))->status(),
    $call('POST', 'auth/token', ['grant_type' => 'refresh_token', 'refresh_token' => (string) ($again->body()['refresh_token'] ?? '')])->status(),
    $call('GET', 'account', null, $tomKey2)->status(),
]);
pin('and the user has no live sign-in or key left', [], $call('GET', 'admin/users/' . $tom . '/sessions', null, $boss)->body()['data'] ?? null);
pin('an unknown user is 404 here too', 404, $call('POST', 'admin/users/99999/sign-out-everywhere', null, $boss)->status());

harness_section('users: delete');
$fired = [];
osc_add_hook('after_delete_user', static function (): void {
    if (!empty($GLOBALS['au_fail_delete'])) {
        throw new RuntimeException('A plugin failed.');
    }
});
$GLOBALS['au_fail_delete'] = true;
$tomCredentials            = $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE fk_i_user_id = $tom")->fetch_row()[0];
$logged = ini_set('error_log', '/dev/null');
$failed = $call('DELETE', 'admin/users/' . $tom, null, $boss);
ini_set('error_log', (string) $logged);
pin('a failure part way is the kernel\'s 500', [500, 'server_error'], [$failed->status(), $failed->body()['code'] ?? null]);
pin('and leaves everything as it was', [true, 1, 1, $tomCredentials], [
    $user($tom) !== null,
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item WHERE fk_i_user_id = $tom")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_alerts WHERE fk_i_user_id = $tom")->fetch_row()[0],
    $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE fk_i_user_id = $tom")->fetch_row()[0],
]);
$GLOBALS['au_fail_delete'] = false;
$fired = [];
pin('DELETE: 204', 204, $call('DELETE', 'admin/users/' . $tom, null, $boss)->status());
pin('the user, their listings, comments, alerts, profile text and keys are gone', [null, 0, 0, 0, 0, 0], [
    $user($tom),
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item WHERE fk_i_user_id = $tom")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_comment WHERE fk_i_user_id = $tom")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_alerts WHERE fk_i_user_id = $tom")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user_description WHERE fk_i_user_id = $tom")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE fk_i_user_id = $tom")->fetch_row()[0],
]);
pin('the hooks fired once and the log names the admin', [1, 1, (string) $bossId], [$fired['delete_user'] ?? 0, $fired['after_delete_user'] ?? 0, $log('delete', $tom)]);
pin('again it is 404', 404, $call('DELETE', 'admin/users/' . $tom, null, $boss)->status());

harness_section('users: queries');
for ($i = 0; $i < 6; $i++) {
    seed_user($admin, 'filler' . $i, 'filler' . $i . '@example.test');
}
scratchdb_forget_cache();
$count = static fn (int $limit): int => harness_query_count(static fn () => $call('GET', 'admin/users', null, $boss, [], ['limit' => (string) $limit]));
$count(2);
$two = $count(2);
$six = $count(6);
echo "  a page of 2: $two queries, of 6: $six\n";
pin('a page of 6 users costs the same queries as a page of 2', $two, $six);
pin('a page of users: 5 queries, the count is skipped', 5, $six);

harness_section('an admin signs out of all devices');
$liveCount  = static fn (int $id): int => count((new \mindstellar\model\ApiCredential())->listBy(null, null, $id, true));
$bossKey    = api_admin_key($bossId);
$bossPublic = $keys->create('public', 'boss app', [Scopes::PUBLIC_READ], KeyOwner::admin($bossId))->token();
$otherId    = api_admin_seed_admin($admin, 'other');
$otherKey   = api_admin_key($otherId);
$sueKey2    = $keys->create('key', 'sue script 2', ['listings:read', 'account:read'], KeyOwner::user($sue))->token();
$adminRow   = static fn (int $id): array => $admin->query("SELECT * FROM {$p}t_admin WHERE pk_i_id = $id")->fetch_assoc();
pin('fixture: the keys work', [200, 200, 200, 200], [
    $call('GET', 'admin/users', null, $bossKey)->status(), $call('GET', 'listings', null, $bossPublic)->status(), $call('GET', 'admin/users', null, $otherKey)->status(),
    $call('GET', 'account', null, $sueKey2)->status(),
]);
pin('the page counts the admin\'s live admin and public keys', 4, $liveCount($bossId));
pin('signing out raises the stamp', [true, '1'], [\mindstellar\auth\SignOut::everywhereAdmin($bossId), $adminRow($bossId)['i_auth_stamp']]);
pin('every key the admin made answers 401', [401, 401, 401], [
    $call('GET', 'admin/users', null, $bossKey)->status(), $call('GET', 'admin/users', null, $boss)->status(), $call('GET', 'listings', null, $bossPublic)->status(),
]);
pin('and is revoked, so none is left to count', [0, 0], [
    $liveCount($bossId),
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE fk_i_admin_id = $bossId AND dt_revoked IS NULL")->fetch_row()[0],
]);
pin('another admin\'s key still works', [200, '0'], [$call('GET', 'admin/users', null, $otherKey)->status(), $adminRow($otherId)['i_auth_stamp']]);
pin('a user\'s key is not touched', 200, $call('GET', 'account', null, $sueKey2)->status());
pin('an unknown admin is not signed out', false, \mindstellar\auth\SignOut::everywhereAdmin(999999));
$admins = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAdmins.php');
check('the profile button signs out through it', str_contains(substr($admins, (int) strpos($admins, 'private function signOutEverywhere()'), 900), 'SignOut::everywhereAdmin('));
check('the profile counts keys without the API layer', !str_contains($admins, 'mindstellar\\api\\'));

exit(harness_result());
