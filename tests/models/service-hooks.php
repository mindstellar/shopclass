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
 * The account, comment, moderation and taxonomy writes fire the same hooks in the same order
 * from the web path and from the API, and leave the same rows behind.
 *
 * Usage:  php tests/models/service-hooks.php        (standalone, own scratch database)
 *         php tests/run-models.php service-hooks    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$admin = api_admin_boot('osc_models_service_hooks');
if (!function_exists('osc_change_user_email_confirm_url')) {
    function osc_change_user_email_confirm_url($userId, $code)
    {
        return WEB_PATH . 'email/' . $userId . '/' . $code;
    }
}

/* ----------------------------------------------------------------------------
 * Fixture: two users with passwords and listings, an admin with a key.
 * ------------------------------------------------------------------------- */
$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
seed_currency($admin);
seed_currency($admin, 'EUR', 'Euro');
seed_country($admin, 'US', 'United States');
$cars   = seed_category($admin, 'Cars', null, $locale);
$sue    = seed_user($admin, 'sue', 'sue@example.test');
$tom    = seed_user($admin, 'tom', 'tom@example.test');
$hash   = password_hash('open sesame', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string($hash) . "'");
$sueCar = seed_item($admin, $cars, $sue, 'Sue car', 100.0);
$tomCar = seed_item($admin, $cars, $tom, 'Tom car', 200.0);
foreach ([
    'enabled_users'            => '1',
    'enabled_comments'         => '1',
    'moderate_comments'        => '-1',
    'reg_user_post_comments'   => '0',
    'notify_new_comment'       => '1',
    'notify_new_comment_user'  => '1',
    'language'                 => 'en_US',
    'currency'                 => 'USD',
    'logs_admin'               => '1',
] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.70';
Params::init();

$bossId = api_admin_seed_admin($admin, 'boss');
$boss   = api_admin_key($bossId);
$call   = api_admin_caller();
$login  = static fn (string $email): string => (string) ($call('POST', 'auth/token', ['grant_type' => 'password', 'username' => $email, 'password' => 'open sesame'])->body()['access_token'] ?? '');

/** Every core hook and filter, recorded in the order it runs. */
$trace = null;
foreach (file(dirname(__DIR__) . '/fixtures/hook-names.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $name = (string) substr($line, (int) strpos($line, ' ') + 1);
    if (str_starts_with($name, 'api_') || str_starts_with($name, 'image_') || in_array($name, ['init', 'before_html', 'after_html'], true)) {
        continue;
    }
    osc_add_hook($name, static function (...$args) use (&$trace, $name) {
        if (is_array($trace)) {
            $trace[] = $name;
        }

        return $args[0] ?? null;
    }, 1);
}
$record = static function (callable $fn) use (&$trace): array {
    $trace = [];
    try {
        $fn();
    } finally {
        $seen  = $trace;
        $trace = null;
    }

    return array_values(array_filter($seen, static fn (string $h): bool => !in_array($h, ['invalidate_item_cache', 'invalidate_user_cache', 'moderator_access'], true)));
};
$asUser = static function (?int $userId): void {
    \mindstellar\api\identity\WebIdentity::forget();
    Params::init();
    Session::getInstance()->_dropEphemeral('userId');
    if ($userId !== null) {
        Session::getInstance()->_setEphemeral('userId', (string) $userId);
    }
};
$user     = static fn (int $id): array => (array) $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $id")->fetch_assoc();
$comments = static fn (int $item): array => $admin->query("SELECT pk_i_id, s_body, b_active, fk_i_user_id FROM {$p}t_item_comment WHERE fk_i_item_id = $item ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC);
$sueToken = $login('sue@example.test');
$tomToken = $login('tom@example.test');

/** What each web controller does for the write, with the same input. */
$web = [
    // CWebItem 'add_comment'
    'comment' => static function (int $userId, int $itemId, string $body) use ($asUser): bool {
        $asUser($userId);
        try {
            (new \mindstellar\comment\CommentService())->post($itemId, ['title' => '', 'body' => $body], new \mindstellar\auth\Actor($userId, null, '192.0.2.70'));
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }

        return true;
    },
    // CWebItem 'delete_comment'
    'deleteComment' => static function (int $userId, int $itemId, int $commentId) use ($asUser): bool {
        $asUser($userId);
        try {
            (new \mindstellar\comment\CommentService())->delete($commentId, new \mindstellar\auth\Actor($userId, null, '192.0.2.70'), $itemId);
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }

        return true;
    },
    // CWebUser 'profile_post'
    'profile' => static function (int $userId, array $form) use ($asUser): bool {
        $asUser($userId);
        try {
            (new \mindstellar\user\AccountService())->update($userId, Params::withRequest($form, static fn (): array => \mindstellar\user\AccountInput::read(false)), \mindstellar\auth\Actor::user($userId, '192.0.2.70'));
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }

        return true;
    },
    // CWebUser 'change_email_post'
    'email' => static function (int $userId, string $new) use ($asUser): bool {
        $asUser($userId);
        try {
            return (new \mindstellar\user\AccountService())->requestEmailChange($userId, $new, \mindstellar\auth\Actor::user($userId, '192.0.2.70'));
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }
    },
    // CWebUser 'change_password_post'
    'password' => static function (int $userId, string $current, string $new) use ($asUser, $user): bool {
        $asUser($userId);
        try {
            (new \mindstellar\user\AccountService())->changePassword($user($userId), $current, $new, $new);
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }

        return true;
    },
    // CAdminUsers 'edit_post'
    'adminEdit' => static function (int $adminId, int $userId, array $patch) use ($user): bool {
        $form = \mindstellar\api\write\AccountBody::admin($user($userId), $patch);
        try {
            (new \mindstellar\user\AccountService())->update($userId, $form, \mindstellar\auth\Actor::admin($adminId));
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }

        return true;
    },
    // CAdminUsers 'activate', 'deactivate', 'enable', 'disable'
    'adminAct' => static fn (int $adminId, string $action, int $userId): bool => (new \mindstellar\user\AccountService())->$action($userId, \mindstellar\auth\Actor::admin($adminId)),
    // CAdminItemComments 'status' and 'delete'
    'moderate' => static fn (string $action, int $id): bool => (bool) \mindstellar\moderation\CommentModeration::make()->$action($id),
    // CAdminItemComments 'comment_edit_post'
    'editComment' => static function (int $id, string $body, string $email): bool {
        try {
            \mindstellar\moderation\CommentModeration::make()->edit($id, ['title' => '', 'body' => $body, 'author_name' => 'Tom', 'author_email' => $email]);
        } catch (\mindstellar\validation\InvalidException $e) {
            return false;
        }

        return true;
    },
    // CAdminItems 'status', 'status_premium', 'status_spam'
    'listing' => static fn (int $adminId, string $action, int $id): bool => \mindstellar\moderation\ListingModeration::make()->apply($action, $id, $adminId, ''),
    // CAdminAjax 'edit_category_post'
    'category' => static fn (int $id, string $name): bool => \mindstellar\category\CategoryService::make()->update($id, ['i_expiration_days' => 0, 'b_price_enabled' => 1], ['en_US' => ['s_name' => $name, 's_description' => '', 's_slug' => '']], false),
    // CAdminSettingsCurrencies 'edit_post'
    'currency' => static fn (string $code, string $name): bool => \mindstellar\currency\CurrencyService::make()->update($code, $name, '€'),
    // CAdminSettingsCurrencies 'delete'
    'deleteCurrency' => static function (string $code): bool {
        try {
            \mindstellar\currency\CurrencyService::make()->delete($code);
        } catch (\mindstellar\validation\RefusedException $e) {
            return false;
        }

        return true;
    },
];

harness_section('posting a comment');
$webComment = $record(static fn () => $web['comment']($tom, $sueCar, 'Still for sale?'));
$asUser(null);
$apiComment = $record(static fn () => $call('POST', 'listings/' . $sueCar . '/comments', ['body' => 'Any rust?'], $tomToken));
pin('the web post fires these, in order', [
    'pre_item_add_comment_post', 'action_throttle_limit', 'before_add_comment', 'hook_email_new_comment_user', 'hook_email_new_comment_admin', 'add_comment',
], $webComment);
pin('the API post fires the same, in the same order', $webComment, $apiComment);
$rows = $comments($sueCar);
pin('both are stored live under the author, the author counted twice', [['Still for sale?', '1', (string) $tom], ['Any rust?', '1', (string) $tom], '2'], [
    [$rows[0]['s_body'] ?? null, $rows[0]['b_active'] ?? null, $rows[0]['fk_i_user_id'] ?? null],
    [$rows[1]['s_body'] ?? null, $rows[1]['b_active'] ?? null, $rows[1]['fk_i_user_id'] ?? null],
    (string) $user($tom)['i_comments'],
]);
pin('an empty body is refused on both sides', [false, 422], [$web['comment']($tom, $sueCar, '  '), $call('POST', 'listings/' . $sueCar . '/comments', ['body' => '<b></b>'], $tomToken)->status()]);
$asUser(null);

harness_section('deleting your own comment');
$webDelete = $record(static fn () => $web['deleteComment']($tom, $sueCar, (int) $rows[0]['pk_i_id']));
$asUser(null);
$apiDelete = $record(static fn () => $call('DELETE', 'comments/' . $rows[1]['pk_i_id'], null, $tomToken));
pin('the web delete fires these, in order', ['pre_item_delete_comment_post', 'delete_comment'], $webDelete);
pin('the API delete fires the same, in the same order', $webDelete, $apiDelete);
pin('both rows are gone', [], $comments($sueCar));
$other = seed_exec($admin, "INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id) VALUES (?, NOW(), '', 'Tom', 'tom@example.test', 'Mine', 1, 1, 0, ?)", 'ii', [$sueCar, $tom]);
pin('someone else\'s comment is not deleted on either side', [false, '403 forbidden', 1], [
    $web['deleteComment']($sue, $sueCar, $other), api_admin_code($call('DELETE', 'comments/' . $other, null, $sueToken)), count($comments($sueCar)),
]);
$pendingOwn = static fn (): int => seed_exec($admin, "INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id) VALUES (?, NOW(), '', 'Tom', 'tom@example.test', 'Waiting', 1, 0, 0, ?)", 'ii', [$sueCar, $tom]);
pin('the author\'s comment still waiting for approval is kept on both sides', [false, '409 conflict'], [
    $web['deleteComment']($tom, $sueCar, $pendingOwn()), api_admin_code($call('DELETE', 'comments/' . $pendingOwn(), null, $tomToken)),
]);
$admin->query("DELETE FROM {$p}t_item_comment WHERE fk_i_item_id = $sueCar AND pk_i_id <> $other");
$asUser(null);

harness_section('the old add_comment() for plugins');
$legacy = static function (array $form) use ($asUser, $tom): array {
    $asUser($tom);
    $actions = new ItemActions(false);
    $code    = Params::withRequest($form, static fn () => $actions->add_comment());
    $asUser(null);

    return [$code, $actions->lastCommentId() > 0];
};
pin('a saved comment: 2 and its id', [2, true], $legacy(['id' => (string) $sueCar, 'body' => 'Legacy']));
pin('an empty body: 4', [4, false], $legacy(['id' => (string) $sueCar, 'body' => '']));
Preference::getInstance()->set('enabled_comments', '0');
osc_reset_preferences();
pin('comments off: 7', [7, false], $legacy(['id' => (string) $sueCar, 'body' => 'Off']));
Preference::getInstance()->set('enabled_comments', '1');
osc_reset_preferences();
$admin->query("INSERT INTO {$p}t_ban_rule (s_name, s_ip) VALUES ('test', '192.0.2.70')");
\mindstellar\security\BanRuleStore::forget();
osc_reset_preferences();
pin('a banned address: 5, on the web as in the API', [[5, false], '403 forbidden'], [$legacy(['id' => (string) $sueCar, 'body' => 'Banned']), api_admin_code($call('POST', 'listings/' . $sueCar . '/comments', ['body' => 'Banned'], $tomToken))]);
$admin->query("DELETE FROM {$p}t_ban_rule");
\mindstellar\security\BanRuleStore::forget();
$guest = static fn (): string => (string) (static function () {
    try {
        (new \mindstellar\comment\CommentService())->post($GLOBALS['sueCar'], ['author_name' => 'Ann', 'author_email' => 'ann@example.test', 'body' => 'Guest'], \mindstellar\auth\Actor::guest('192.0.2.80'));
    } catch (\mindstellar\validation\RefusedException $e) {
        return get_class($e);
    }

    return 'saved';
})();
$GLOBALS['sueCar'] = $sueCar;
pin('a guest comments under the name sent', ['saved', 'Ann'], [$guest(), $admin->query("SELECT s_author_name FROM {$p}t_item_comment WHERE s_body = 'Guest'")->fetch_row()[0] ?? null]);
for ($i = 1; $i < \mindstellar\comment\CommentPolicy::PER_HOUR; $i++) {
    \mindstellar\security\RateLimit::hit('comment_post', 'ip:192.0.2.80', 1000, 3600);
}
pin('past the hourly cap a guest is refused too', 'mindstellar\\validation\\BlockedException', $guest());
for ($i = 0; $i < \mindstellar\comment\CommentPolicy::PER_HOUR; $i++) {
    \mindstellar\comment\CommentPolicy::tooMany(\mindstellar\auth\Actor::guest('2001:db8:1:2::' . dechex($i + 1)));
}
pin('a guest on IPv6 is counted per /64, so a new address in it is still over the cap', true, \mindstellar\comment\CommentPolicy::tooMany(\mindstellar\auth\Actor::guest('2001:db8:1:2:ffff::1')));
$admin->query("DELETE FROM {$p}t_rate_counter");
$admin->query("DELETE FROM {$p}t_item_comment WHERE pk_i_id <> $other");

harness_section('editing the profile');
$webProfile = $record(static fn () => $web['profile']($sue, ['s_name' => 'Susan']));
$asUser(null);
$apiProfile = $record(static fn () => $call('PATCH', 'account', ['name' => 'Tommy'], $tomToken));
pin('the web edit fires these, in order', ['pre_user_post', 'user_edit_flash_error', 'user_edit_completed'], $webProfile);
pin('the API edit fires the same, in the same order', $webProfile, $apiProfile);
pin('both names are saved and copied to the listings', ['Susan', 'Tommy', 'Susan', 'Tommy'], [
    $user($sue)['s_name'], $user($tom)['s_name'],
    $admin->query("SELECT s_contact_name FROM {$p}t_item WHERE pk_i_id = $sueCar")->fetch_row()[0],
    $admin->query("SELECT s_contact_name FROM {$p}t_item WHERE pk_i_id = $tomCar")->fetch_row()[0],
]);
pin('a blank name is refused on both sides', [false, 422], [$web['profile']($sue, ['s_name' => '']), $call('PATCH', 'account', ['name' => ''], $tomToken)->status()]);
$asUser(null);

harness_section('asking to change the e-mail');
$webEmail = $record(static fn () => $web['email']($sue, 'susan@example.test'));
$asUser(null);
$apiEmail = $record(static fn () => $call('PATCH', 'account', ['email' => 'tommy@example.test'], $tomToken));
pin('the web request fires these', ['hook_email_new_email'], $webEmail);
pin('the API request fires the same', $webEmail, $apiEmail);
pin('both wait for the link, the address unchanged', [['susan@example.test', 'sue@example.test'], ['tommy@example.test', 'tom@example.test']], [
    [$admin->query("SELECT s_new_email FROM {$p}t_user_email_tmp WHERE fk_i_user_id = $sue")->fetch_row()[0] ?? null, $user($sue)['s_email']],
    [$admin->query("SELECT s_new_email FROM {$p}t_user_email_tmp WHERE fk_i_user_id = $tom")->fetch_row()[0] ?? null, $user($tom)['s_email']],
]);
pin('an address another account holds: neither side sends a link or says it is taken', [false, 200, []], [
    $web['email']($sue, 'tom@example.test'),
    $call('PATCH', 'account', ['email' => 'sue@example.test'], $tomToken)->status(),
    $record(static fn () => $call('PATCH', 'account', ['email' => 'sue@example.test'], $tomToken)),
]);
$asUser(null);

harness_section('changing the password');
$webPassword = $record(static fn () => $web['password']($sue, 'open sesame', 'new secret'));
$asUser(null);
$apiPassword = $record(static fn () => $call('POST', 'account/password', ['current_password' => 'open sesame', 'new_password' => 'new secret'], $tomToken));
pin('the web and API change fire the same', $webPassword, $apiPassword);
check('both are stored', password_verify('new secret', (string) $user($sue)['s_password']) && password_verify('new secret', (string) $user($tom)['s_password']));
pin('a wrong current password is refused on both sides', [false, 422], [
    $web['password']($sue, 'wrong', 'other'), $call('POST', 'account/password', ['current_password' => 'wrong', 'new_password' => 'other'], $login2 = $call('POST', 'auth/token', ['grant_type' => 'password', 'username' => 'tom@example.test', 'password' => 'new secret'])->body()['access_token'] ?? '')->status(),
]);
$asUser(null);

harness_section('the admin edits a user');
$webAdminEdit = $record(static fn () => $web['adminEdit']($bossId, $sue, ['name' => 'Sue A']));
$apiAdminEdit = $record(static fn () => $call('PATCH', 'admin/users/' . $tom, ['name' => 'Tom A'], $boss));
pin('the users screen fires these, in order', ['pre_user_post', 'user_edit_flash_error', 'user_edit_completed'], $webAdminEdit);
pin('the API fires the same, in the same order', $webAdminEdit, $apiAdminEdit);
$webBlock = $record(static fn () => $web['adminAct']($bossId, 'disable', $sue));
$apiBlock = $record(static fn () => $call('PATCH', 'admin/users/' . $tom, ['blocked' => true], $boss));
pin('blocking a user fires these, in order', ['disable_item', 'item_decrease_stat', 'disable_user'], $webBlock);
pin('the API fires the same, in the same order', $webBlock, $apiBlock);
$web['adminAct']($bossId, 'enable', $sue);
$call('PATCH', 'admin/users/' . $tom, ['blocked' => false], $boss);

$web['adminEdit']($bossId, $sue, ['name' => 'Sue']);
pin('both log the admin edit under the admin', [(string) $bossId, (string) $bossId], [
    $admin->query("SELECT fk_i_who_id FROM {$p}t_log WHERE s_section = 'user' AND s_action = 'edit' AND fk_i_id = $sue ORDER BY dt_date DESC LIMIT 1")->fetch_row()[0] ?? null,
    $admin->query("SELECT fk_i_who_id FROM {$p}t_log WHERE s_section = 'user' AND s_action = 'edit' AND fk_i_id = $tom ORDER BY dt_date DESC LIMIT 1")->fetch_row()[0] ?? null,
]);

harness_section('deleting an account');
$ann = seed_user($admin, 'ann', 'ann@example.test');
$bob = seed_user($admin, 'bob', 'bob@example.test');
$cat = seed_user($admin, 'cat', 'cat@example.test');
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string($hash) . "' WHERE pk_i_id = $cat");
$webDrop = $record(static fn () => (new \mindstellar\user\AccountService())->delete($ann, \mindstellar\auth\Actor::admin($bossId)));
$apiDrop = $record(static fn () => $call('DELETE', 'admin/users/' . $bob, null, $boss));
pin('the users screen deletes with these hooks, in order', ['before_user_delete', 'delete_user', 'after_delete_user'], $webDrop);
pin('the API fires the same, in the same order', $webDrop, $apiDrop);
$self = static function (string $password) use ($cat): string {
    try {
        (new \mindstellar\user\AccountService())->delete($cat, \mindstellar\auth\Actor::user($cat, '192.0.2.70'), $password);
    } catch (\mindstellar\validation\InvalidException $e) {
        return $e->reason();
    }

    return 'deleted';
};
pin('a user deletes their own account with their password only', ['required', 'mismatch', 'deleted', 0], [$self(''), $self('wrong'), $self('open sesame'), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE pk_i_id IN ($ann, $bob, $cat)")->fetch_row()[0]]);

harness_section('the admin moderates comments');
$seedComment = static fn (int $active = 0): int => seed_exec($admin, "INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id) VALUES (?, NOW(), '', 'Tom', 'tom@example.test', 'Pending', 1, ?, 0, ?)", 'iii', [$sueCar, $active, $tom]);
$a = $seedComment();
$b = $seedComment();
foreach (['activate', 'deactivate', 'disable', 'enable'] as $action) {
    $webAct = $record(static fn () => $web['moderate']($action, $a));
    $apiAct = $record(static fn () => $call('PATCH', 'admin/comments/' . $b, ['activate' => ['approved' => true], 'deactivate' => ['approved' => false], 'enable' => ['blocked' => false], 'disable' => ['blocked' => true]][$action], $boss));
    pin("$action: the screen and the API fire the same", $webAct, $apiAct);
}
pin('an approval tells the author', ['hook_email_comment_validated', 'activate_comment'], $record(static fn () => $web['moderate']('activate', $a)));
$webEditComment = $record(static fn () => $web['editComment']($a, 'Edited', 'tom@example.test'));
$apiEditComment = $record(static fn () => $call('PATCH', 'admin/comments/' . $b, ['body' => 'Edited'], $boss));
pin('an edit fires edit_comment on both sides', [['edit_comment'], ['edit_comment']], [$webEditComment, $apiEditComment]);
pin('a bad author e-mail is refused on both sides', [false, 422], [$web['editComment']($a, 'Edited', 'not an address'), $call('PATCH', 'admin/comments/' . $b, ['author_email' => 'not an address'], $boss)->status()]);
$webDrop = $record(static fn () => $web['moderate']('delete', $a));
$apiDrop = $record(static fn () => $call('DELETE', 'admin/comments/' . $b, null, $boss));
pin('a delete fires delete_comment on both sides', [['delete_comment'], ['delete_comment']], [$webDrop, $apiDrop]);

harness_section('the admin moderates listings');
foreach (['disable', 'enable', 'spam', 'unspam', 'premium', 'unpremium', 'deactivate', 'activate'] as $action) {
    $webAct = $record(static fn () => $web['listing']($bossId, $action, $sueCar));
    $apiAct = $record(static fn () => $call('POST', 'admin/listings/' . $tomCar . '/' . $action, null, $boss));
    pin("$action: the screen and the API fire the same", $webAct, $apiAct);
}
pin('both log each change under the admin', [8, 8], [
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_log WHERE s_section = 'item' AND fk_i_id = $sueCar AND fk_i_who_id = $bossId")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_log WHERE s_section = 'item' AND fk_i_id = $tomCar AND fk_i_who_id = $bossId")->fetch_row()[0],
]);

harness_section('the admin edits taxonomy');
$webCategory = $record(static fn () => $web['category']($cars, 'Autos'));
$apiCategory = $record(static fn () => $call('PATCH', 'admin/categories/' . $cars, ['translations' => ['en_US' => ['name' => 'Motors']]], $boss));
pin('a category edit fires edited_category on both sides', [['slug', 'edited_category'], ['slug', 'edited_category']], [$webCategory, $apiCategory]);
pin('the name is saved', 'Motors', $admin->query("SELECT s_name FROM {$p}t_category_description WHERE fk_i_category_id = $cars")->fetch_row()[0]);
$GLOBALS['osc_page_cache_purge'] = [];
$web['currency']('EUR', 'Euros');
$webPurge = $GLOBALS['osc_page_cache_purge'] ?? [];
$GLOBALS['osc_page_cache_purge'] = [];
$call('PATCH', 'admin/currencies/EUR', ['name' => 'Euro'], $boss);
$apiPurge = $GLOBALS['osc_page_cache_purge'] ?? [];
pin('a currency edit purges the page cache on both sides', [['currency'], ['currency']], [array_values(array_unique((array) $webPurge)), array_values(array_unique((array) $apiPurge))]);
pin('the default currency is not deleted on either side', [false, 409], [$web['deleteCurrency']('USD'), $call('DELETE', 'admin/currencies/USD', null, $boss)->status()]);

harness_section('registering');
foreach (['enabled_user_registration' => '1', 'enabled_user_validation' => '0', 'notify_new_user' => '1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
osc_reset_preferences();
$signUp = api_admin_caller(static fn (): \mindstellar\apiaccess\ApiSettings => new \mindstellar\apiaccess\ApiSettings(enabled: true, registration: true));
// CWebRegister 'register_post'
$webRegister = static function (array $form) use ($asUser) {
    $asUser(null);
    osc_run_hook('before_user_register');

    return Params::withRequest($form, static fn () => (new UserActions(false))->add());
};
$apiRegister = static fn (array $body) => $signUp('POST', 'users', $body);
$form = static fn (string $name, string $email, string $username = ''): array => ['s_name' => $name, 's_email' => $email, 's_password' => 'pass word', 's_password2' => 'pass word', 's_username' => $username];
$body = static fn (string $name, string $email, string $username = ''): array => ['name' => $name, 'email' => $email, 'password' => 'pass word', 'username' => $username];
$webJoin = $record(static fn () => $webRegister($form('Dee', 'dee@example.test')));
$apiJoin = $record(static fn () => $apiRegister($body('Eve', 'eve@example.test')));
pin('the sign-up form fires these, in order', ['before_user_register', 'user_add_flash_error', 'pre_user_post', 'hook_email_admin_new_user', 'user_register_completed'], $webJoin);
pin('the API fires the same, in the same order', $webJoin, $apiJoin);
$joined = static fn (string $email): array => (array) $admin->query("SELECT s_name, b_active, s_username <> '' AS named FROM {$p}t_user WHERE s_email = '" . $admin->real_escape_string($email) . "'")->fetch_assoc();
pin('both accounts are active, with a username', [['Dee', '1', '1'], ['Eve', '1', '1']], [array_values($joined('dee@example.test')), array_values($joined('eve@example.test'))]);
pin('both log the sign-up under the new user', ['register', 'register'], [
    $admin->query("SELECT s_action FROM {$p}t_log WHERE s_section = 'user' AND s_data = 'dee@example.test'")->fetch_row()[0] ?? null,
    $admin->query("SELECT s_action FROM {$p}t_log WHERE s_section = 'user' AND s_data = 'eve@example.test'")->fetch_row()[0] ?? null,
]);
pin('the old add() answers 2 for an active account, the API 201', [2, 201], [$webRegister($form('Fay', 'fay@example.test')), $apiRegister($body('Gus', 'gus@example.test'))->status()]);

$webTaken = $record(static fn () => $webRegister($form('Dee', 'dee@example.test')));
$apiTaken = $record(static fn () => $apiRegister($body('Eve', 'eve@example.test')));
pin('a taken e-mail fires these, in order', ['before_user_register', 'register_email_taken', 'user_add_flash_error', 'user_register_failed'], $webTaken);
pin('the API fires the same, in the same order', $webTaken, $apiTaken);
pin('a taken e-mail: the message, and 422 from the API', ['The specified e-mail is already in use' . PHP_EOL, 422], [$webRegister($form('Dee', 'dee@example.test')), $apiRegister($body('Eve', 'eve@example.test'))->status()]);
$webRegister($form('Hal', 'hal@example.test', 'hal'));
pin('a taken username is refused on both sides', ['Username is already taken' . PHP_EOL, 422, 0], [
    $webRegister($form('Ida', 'ida@example.test', 'hal')), $apiRegister($body('Jon', 'jon@example.test', 'hal'))->status(),
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE s_email IN ('ida@example.test', 'jon@example.test')")->fetch_row()[0],
]);
pin('a blank name and a bad e-mail: both messages', 'The name cannot be empty' . PHP_EOL . 'The email is not valid' . PHP_EOL, $webRegister($form('', 'nope')));

Preference::getInstance()->set('enabled_user_validation', '1');
osc_reset_preferences();
$webPending = $record(static fn () => $webRegister($form('Kim', 'kim@example.test')));
$apiPending = $record(static fn () => $apiRegister($body('Lee', 'lee@example.test')));
pin('with activation on, the sign-up form fires these, in order', ['before_user_register', 'user_add_flash_error', 'pre_user_post', 'hook_email_admin_new_user', 'hook_email_user_validation', 'user_register_completed'], $webPending);
pin('the API fires the same, in the same order', $webPending, $apiPending);
pin('both wait for the link', [['Kim', '0', '1'], ['Lee', '0', '1']], [array_values($joined('kim@example.test')), array_values($joined('lee@example.test'))]);
pin('the old add() answers 1, the API says not active', [1, false], [$webRegister($form('Max', 'max@example.test')), $apiRegister($body('Ned', 'ned@example.test'))->body()['data']['active'] ?? null]);
Preference::getInstance()->set('enabled_user_validation', '0');
osc_reset_preferences();

// CAdminUsers 'create_post'
$adminCreate = $record(static fn () => Params::withRequest($form('Oz', 'oz@example.test'), static fn () => (new UserActions(true))->add()));
pin('the users screen creates without the new-user e-mail', ['user_add_flash_error', 'pre_user_post', 'user_register_completed'], $adminCreate);
$admin->query("DELETE FROM {$p}t_rate_counter");

harness_section('a failed write inside the profile save rolls back');
$nameOf = static fn (string $sql): string => (string) $admin->query($sql)->fetch_row()[0];
$tomBefore  = $nameOf("SELECT s_name FROM {$p}t_user WHERE pk_i_id = $tom");
$carBefore  = $nameOf("SELECT s_contact_name FROM {$p}t_item WHERE pk_i_id = $tomCar");
// The last of the user-row cascades now fails, after the user row and the listing were written.
$admin->query("ALTER TABLE {$p}t_alerts RENAME COLUMN s_email TO s_email_off");
$threw = false;
try {
    $web['adminEdit']($bossId, $tom, ['name' => 'Tom Rolled', 'email' => 'tom.rolled@example.test']);
} catch (\Throwable $e) {
    $threw = true;
}
$admin->query("ALTER TABLE {$p}t_alerts RENAME COLUMN s_email_off TO s_email");
check('the failed alert update stops the save', $threw);
pin('the user row is rolled back', $tomBefore, $nameOf("SELECT s_name FROM {$p}t_user WHERE pk_i_id = $tom"));
pin('the listing contact name is rolled back', $carBefore, $nameOf("SELECT s_contact_name FROM {$p}t_item WHERE pk_i_id = $tomCar"));

exit(harness_result());
