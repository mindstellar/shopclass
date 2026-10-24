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
 * Ratings on comments: posting a rating, a comment or both, one rated comment per user and
 * listing, the ratings switch, the rating summary and distribution, `sort=newest`, and the migration.
 * Usage: php tests/api-comments-rating.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\comment\CommentQuery;

$admin = api_admin_boot('osc_api_comments_rating');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Mugs', seed_category($admin, 'Goods', null, $locale), $locale);
$sue = seed_user($admin, 'sue', 'sue@example.test');
$tom = seed_user($admin, 'tom', 'tom@example.test');
foreach (['enabled_users' => '1', 'enabled_comments' => '1', 'comment_ratings' => '1', 'moderate_comments' => '-1', 'reg_user_post_comments' => '0', 'language' => 'en_US',
    'currency' => 'USD', 'rewriteEnabled' => '0'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_COOKIE['oc_userLocale'] = 'en_US';

$tee   = seed_item($admin, $cat, $sue, 'Tee', 10.0);
$mug   = seed_item($admin, $cat, $sue, 'Mug', 5.0);
$other = seed_item($admin, $cat, $sue, 'Unrated', 5.0);

$keys = new \mindstellar\apikey\ApiKeys(new \mindstellar\model\ApiCredential(), new \mindstellar\apikey\Scopes(), new \mindstellar\utility\SystemClock());
$key  = static fn (int $user, string $name): string => $keys->create('key', $name, ['comments:write', 'listings:read'], \mindstellar\apikey\KeyOwner::user($user))->token();
$sueKey = $key($sue, 'sue');
$tomKey = $key($tom, 'tom');
$call   = api_admin_caller();
$post   = static fn (string $token, int $listing, array $body): \mindstellar\api\Response => $call('POST', 'listings/' . $listing . '/comments', $body, $token);
$row    = static function (\mindstellar\api\Response $r) use ($admin, $p): array {
    $id = (int) ($r->body()['data']['id'] ?? 0);

    return $admin->query("SELECT * FROM {$p}t_item_comment WHERE pk_i_id = $id")->fetch_assoc() ?: [];
};

$rated = static fn (\mindstellar\api\Response $r): ?int => ($v = $row($r)['i_rating'] ?? null) === null ? null : (int) $v;

harness_section('the column');
$col = $admin->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$p}t_item_comment' AND column_name = 'i_rating'")->fetch_assoc();
pin('i_rating is a nullable unsigned tiny integer', ['tinyint unsigned', 'YES'], [preg_replace('/\(\d+\)/', '', strtolower((string) ($col['COLUMN_TYPE'] ?? ''))), $col['IS_NULLABLE'] ?? '']);
$def = static fn (): array => array_values($admin->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, ORDINAL_POSITION FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$p}t_item_comment' AND column_name = 'i_rating'")->fetch_assoc() ?: []);
$fresh = $def();
$admin->query("ALTER TABLE {$p}t_item_comment DROP COLUMN i_rating");
$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0079_comment_rating.php';
$migration->up(\mindstellar\database\Connection::getInstance());
pin('the migration adds the column struct.sql has', $fresh, $def());
$migration->up(\mindstellar\database\Connection::getInstance());
pin('and runs again without error', $fresh, $def());

harness_section('posting');
$r = $post($sueKey, $tee, ['body' => 'Fits well', 'rating' => 5]);
pin('a comment with a rating is saved', [201, 5], [$r->status(), $rated($r)]);
pin('and answers its rating', 5, $r->body()['data']['rating'] ?? null);
pin('the response matches the schema', [], api_admin_schema_errors('SavedComment', $r));
$r = $post($tomKey, $tee, ['body' => 'Plain words']);
pin('a comment with no rating is saved with null', [201, null], [$r->status(), $rated($r)]);
$r = $post($tomKey, $tee, ['rating' => 4]);
pin('a rating alone is saved with an empty body', [201, 4, ''], [$r->status(), $rated($r), $row($r)['s_body'] ?? 'missing']);
pin('neither text nor rating is refused', '422 validation_failed', api_admin_code($post($tomKey, $mug, ['title' => 'Hi'])));
foreach ([0, 6, -1] as $bad) {
    $r = $post($sueKey, $mug, ['body' => 'x', 'rating' => $bad]);
    pin("rating $bad is refused", ['422 validation_failed', '/rating'], [api_admin_code($r), $r->body()['errors'][0]['pointer'] ?? '']);
}
foreach (['five', 4.5, true, [3]] as $bad) {
    pin('rating ' . json_encode($bad) . ' is refused', '422 validation_failed', api_admin_code($post($sueKey, $mug, ['body' => 'x', 'rating' => $bad])));
}
$service = new \mindstellar\comment\CommentService();
$before  = (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item_comment")->fetch_assoc()['c'];
foreach ([0, 6, -1, 4.5, 'five', '9'] as $bad) {
    try {
        $service->post($mug, ['body' => 'x', 'rating' => $bad], \mindstellar\auth\Actor::user($sue));
        $got = 'saved';
    } catch (\mindstellar\exception\InvalidException $e) {
        $got = $e->pointer();
    }
    pin('the service refuses rating ' . json_encode($bad) . ' at /rating', '/rating', $got);
}
pin('and wrote no row', $before, (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item_comment")->fetch_assoc()['c']);
pin('a second rated comment by the same user is a 409', '409 already_reviewed', api_admin_code($post($sueKey, $tee, ['body' => 'Again', 'rating' => 1])));
pin('a rating-less comment by that user is still allowed', 201, $post($sueKey, $tee, ['body' => 'More words'])->status());
pin('another listing takes a rating from the same user', 201, $post($sueKey, $mug, ['body' => 'Good', 'rating' => 3])->status());
$count = static fn (int $listing): int => (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item_comment WHERE fk_i_item_id = $listing")->fetch_assoc()['c'];
pin('refused ratings saved nothing: four comments on the tee, one on the mug', [4, 1], [$count($tee), $count($mug)]);

try {
    (new \mindstellar\comment\CommentService())->post($tee, ['body' => 'Hi', 'rating' => 5, 'author_name' => 'Ann', 'author_email' => 'ann@example.test'], \mindstellar\auth\Actor::guest('192.0.2.9'));
    $guest = 'saved';
} catch (\mindstellar\exception\ForbiddenException $e) {
    $guest = $e->reason();
}
pin('a guest cannot rate', 'sign_in', $guest);

harness_section('the ratings switch');
// '' is what a site that never saved the setting reads.
$ratings = static function (string $value, string $mode): void {
    Preference::getInstance()->set('comment_ratings', $value);
    Preference::getInstance()->set('site_mode', $mode);
};
$site = static fn (): ?bool => $call('GET', '', null, $tomKey)->body()['data']['features']['ratings'] ?? null;
$ratings('0', 'shop');
pin('switched off, a rating is refused even in a shop', '403 feature_disabled', api_admin_code($post($tomKey, $other, ['body' => 'x', 'rating' => 3])));
pin('and the site says so', false, $site());
pin('a comment with no rating still goes through', 201, $post($tomKey, $other, ['body' => 'Words only'])->status());
$ratings('', 'classifieds');
pin('never saved, a classifieds site refuses a rating', ['403 feature_disabled', false], [api_admin_code($post($tomKey, $other, ['rating' => 3])), $site()]);
$ratings('', 'shop');
pin('never saved, a shop takes one', [true, 201], [$site(), $post($tomKey, $other, ['rating' => 3])->status()]);
$ratings('1', 'classifieds');
pin('switched on, a classifieds site takes one', [true, 201], [$site(), $post($sueKey, $other, ['rating' => 4])->status()]);
$admin->query("DELETE FROM {$p}t_item_comment WHERE fk_i_item_id = $other");

harness_section('moderation and blocked rows');
$admin->query("UPDATE {$p}t_item_comment SET b_enabled = 0 WHERE fk_i_item_id = $mug");
pin('a blocked rated comment no longer stops a new one', 201, $post($sueKey, $mug, ['body' => 'Retry', 'rating' => 2])->status());
$admin->query("UPDATE {$p}t_item_comment SET b_active = 0 WHERE pk_i_id = (SELECT m FROM (SELECT MAX(pk_i_id) m FROM {$p}t_item_comment WHERE fk_i_item_id = $mug) x)");
$admin->query("INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id, i_rating)
    VALUES ($mug, NOW(), '', 'x', 'x@example.test', 'spam', 1, 1, 1, NULL, 1), ($mug, NOW(), '', 'x', 'x@example.test', 'blocked', 0, 1, 0, NULL, 1)");

harness_section('summary and distribution');
$query = new CommentQuery();
pin('the tee has two ratings, 5 and 4', ['count' => 2, 'average' => 4.5], $query->summary([$tee])[$tee]);
pin('pending, blocked and spam rows are left out, an unrated listing is a zero', [['count' => 0, 'average' => null], ['count' => 0, 'average' => null]], [$query->summary([$mug])[$mug], $query->summary([$other])[$other]]);
$ratedBy = static function (int $listing, array $stars) use ($admin, $p): void {
    foreach ($stars as $n) {
        $admin->query("INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id, i_rating)
            VALUES ($listing, NOW(), '', 'x', 'x@example.test', 'r', 1, 1, 0, NULL, $n)");
    }
};
$round = [];
foreach ([array_merge(array_fill(0, 7, 4), array_fill(0, 13, 5)), array_merge(array_fill(0, 9, 4), array_fill(0, 16, 5)), [5, 5, 5]] as $k => $stars) {
    $round[$k] = seed_item($admin, $cat, $sue, 'Round' . $k, 5.0);
    $ratedBy($round[$k], $stars);
}
$sum = $query->summary($round);
pin('the average is rounded to one decimal, no float noise (4.65, 4.64, 5)', [4.7, 4.6, 5.0], [$sum[$round[0]]['average'], $sum[$round[1]]['average'], $sum[$round[2]]['average']]);
pin('and it prints as a short number', ['4.7', '4.6', '5'], [json_encode($sum[$round[0]]['average']), json_encode($sum[$round[1]]['average']), json_encode($sum[$round[2]]['average'])]);
pin('no ids, no query', [], $query->summary([]));
pin('the distribution sums to the count', [5 => 1, 4 => 1, 3 => 0, 2 => 0, 1 => 0], $query->distribution($tee));
pin('one listing is not mixed into another', [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], $query->distribution($other));

harness_section('the listing');
$show = $call('GET', 'listings/' . $tee, null, $sueKey, [], ['include' => 'rating']);
pin('a listing with include=rating carries average, count and distribution', ['average' => 4.5, 'count' => 2, 'distribution' => ['5' => 1, '4' => 1, '3' => 0, '2' => 0, '1' => 0]], $show->body()['data']['rating'] ?? null);
pin('the response matches the schema', [], api_admin_schema_errors('ListingDocument', $show));
pin('without include there is no rating', false, array_key_exists('rating', $call('GET', 'listings/' . $tee, null, $sueKey)->body()['data']));
$list = $call('GET', 'listings', null, $sueKey, [], ['include' => 'rating']);
$byTitle = [];
foreach ($list->body()['data'] as $l) {
    $byTitle[$l['title']] = $l['rating'] ?? 'missing';
}
pin('a list gives each listing its average and count, no distribution', [['average' => 4.5, 'count' => 2], ['average' => null, 'count' => 0]], [$byTitle['Tee'], $byTitle['Unrated']]);
pin('fields can leave it out', false, array_key_exists('rating', $call('GET', 'listings/' . $tee, null, $sueKey, [], ['include' => 'rating', 'fields' => 'title'])->body()['data']));

harness_section('comments sort');
$titles = static fn (\mindstellar\api\Response $r): array => array_column($r->body()['data'] ?? [], 'body');
$old = $call('GET', 'listings/' . $tee . '/comments', null, $sueKey);
pin('oldest first by default, with ratings', [['Fits well', 'Plain words', '', 'More words'], [5, null, 4, null]], [$titles($old), array_column($old->body()['data'], 'rating')]);
$new = $call('GET', 'listings/' . $tee . '/comments', null, $sueKey, [], ['sort' => 'newest']);
pin('sort=newest reverses it', ['More words', '', 'Plain words', 'Fits well'], $titles($new));
pin('the page matches the schema', [], api_admin_schema_errors('CommentPage', $new));
$first = $call('GET', 'listings/' . $tee . '/comments', null, $sueKey, [], ['sort' => 'newest', 'limit' => 3]);
$next  = [];
parse_str((string) parse_url((string) ($first->body()['links']['next'] ?? ''), PHP_URL_QUERY), $next);
pin('a newest cursor goes on to the older ones', ['Fits well'], $titles($call('GET', 'listings/' . $tee . '/comments', null, $sueKey, [], $next)));
pin('an unknown sort is refused', 422, $call('GET', 'listings/' . $tee . '/comments', null, $sueKey, [], ['sort' => 'best'])->status());

harness_section('one rating per user');
$cap = seed_item($admin, $cat, $sue, 'Cap', 8.0);
$ann = seed_user($admin, 'ann', 'ann@example.test');
$bob = seed_user($admin, 'bob', 'bob@example.test');
$admin->query("INSERT INTO {$p}t_item_comment (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body, b_enabled, b_active, b_spam, fk_i_user_id, i_rating)
    VALUES ($cap, NOW(), '', 'Tom', 'tom@example.test', 'spam', 1, 1, 1, $tom, 2), ($cap, NOW(), '', 'Ann', 'ann@example.test', 'pending', 1, 0, 0, $ann, 3)");
pin('a rating marked spam does not stop the next one', 201, $post($tomKey, $cap, ['rating' => 5])->status());
pin('a pending rating does', '409 already_reviewed', api_admin_code($post($key($ann, 'ann'), $cap, ['rating' => 5])));

// Two rated posts by one user in two processes. Holding the user's row here makes both wait
// where the write checks again, so they run one after the other once it is released.
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$admin->begin_transaction();
$admin->query("SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = $bob FOR UPDATE");
$posts = [];
$outs  = [];
for ($i = 0; $i < 2; $i++) {
    $posts[] = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/lib/comment-rating-child.php', $dbName, (string) $cap, (string) $bob], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $outs[$i] = $pipes;
}
$watch   = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, $dbName, (int) ini_get('mysqli.default_port'));
$waiting = 0;
for ($try = 0; $try < 400 && $waiting < 2; $try++) {
    usleep(50000);
    $waiting = (int) $watch->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND DB = '{$dbName}' AND INFO LIKE 'SELECT pk_i_id FROM {$p}t_user WHERE pk_i_id = % FOR UPDATE'")->fetch_row()[0];
}
$watch->close();
$admin->commit();
$printed = '';
foreach ($posts as $i => $proc) {
    $printed .= stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]);
    proc_close($proc);
}
pin('both posts waited on the user', 2, $waiting);
pin('two rated posts at once: one is saved, one is refused', [1, 1], [substr_count($printed, 'won'), substr_count($printed, 'lost')]);
pin('and one rated row is written', 1, (int) $admin->query("SELECT COUNT(*) c FROM {$p}t_item_comment WHERE fk_i_item_id = $cap AND fk_i_user_id = $bob")->fetch_assoc()['c']);

exit(harness_result());
