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
 * The plugin side of the API serializers: `api_listing`, `api_user` and `api_category` may
 * add data only under `ext.<slug>` (a top-level key is dropped and the callback named),
 * osc_api_register_field() declares fields for the schema and `?fields=`, a declared field
 * reaches only its views, and `api_listings_prefetch` runs once per list with every id.
 *
 * DB-free.  Usage: php tests/api-extensions.php
 */

require_once __DIR__ . '/lib/api-boot.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

use mindstellar\api\auth\Credential;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\ProblemException;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\read\ListingRelations;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\CategorySerializer;
use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\api\serializer\ExtensionMembers;
use mindstellar\api\serializer\Extensions;
use mindstellar\api\serializer\Links;
use mindstellar\api\serializer\ListingSerializer;
use mindstellar\api\serializer\SparseFieldset;
use mindstellar\api\serializer\UserSerializer;
use mindstellar\api\serializer\ViewContext;

final class PlainLinks implements Links
{
    public function listing(array $item): string
    {
        return 'https://site.test/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'https://site.test/p/' . $resource['pk_i_id'];
    }

    public function user(int $id, string $username): string
    {
        return 'https://site.test/user/' . $id;
    }

    public function avatar(int $userId): string
    {
        return 'https://site.test/avatar.png';
    }

    public function api(string $path): string
    {
        return 'https://site.test/api/v1/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return (string) $micros;
    }
}

/** A named callback, so the warning can be checked for its name. */
function acme_bad_listing_filter(array $data): array
{
    $data['rating'] = 5;

    return $data;
}

$warnings = [];
$warn     = static function (string $m) use (&$warnings): void {
    $warnings[] = $m;
};
$refused  = [];
$log      = static function (string $m) use (&$refused): void {
    $refused[] = $m;
};
$plain    = new Validator();

harness_section('declaring fields');
osc_api_register_field('listing', 'acme', 'rating', ['type' => 'integer', 'minimum' => 0, 'maximum' => 5]);
osc_api_register_field('listing', 'acme', 'cost_price', ['type' => 'string'], ['admin']);
osc_api_register_field('user', 'acme', 'badge', ['type' => 'string'], ['owner']);
osc_api_register_field('listing', 'Acme', 'x', ['type' => 'string']);
osc_api_register_field('order', 'acme', 'x', ['type' => 'string']);
osc_api_register_field('listing', 'acme', 'Bad-Name', ['type' => 'string']);
osc_api_register_field('listing', 'acme', 'y', ['oneOf' => []]);
osc_api_register_field('listing', 'acme', 'z', ['type' => 'string'], ['everyone']);
$declared = ExtensionMembers::fromHooks($plain, $log);
pin('valid declarations are kept', ['acme.rating', 'acme.cost_price'], array_map(
    static fn ($f): string => $f->slug() . '.' . $f->name(),
    $declared->forObject('listing')
));
pin('each bad one is refused and logged', 5, count($refused));
check('the log names the field and the rule', str_contains($refused[0], 'listing ext.Acme.x') && str_contains($refused[0], 'slug'));
check('a schema keyword the validator lacks is refused', str_contains(implode("\n", $refused), 'unsupported keyword oneOf'));
pin('a public field reaches everyone', [true, true, true], array_map(
    static fn (string $v): bool => $declared->find('listing', 'acme', 'rating')->visibleIn($v),
    ViewContext::VIEWS
));
pin('an admin field reaches admins only', [false, false, true], array_map(
    static fn (string $v): bool => $declared->find('listing', 'acme', 'cost_price')->visibleIn($v),
    ViewContext::VIEWS
));
$schemas = Schema::components($declared);
pin('a declared field is in the Listing schema under ext.<slug>', ['type' => 'integer', 'minimum' => 0, 'maximum' => 5], $schemas['Listing']['properties']['ext']['properties']['acme']['properties']['rating']);
pin('and a user field under User', ['type' => 'string'], $schemas['User']['properties']['ext']['properties']['acme']['properties']['badge']);
pin('the schema with plugin fields is still one the validator can check', [], (new Validator($schemas))->schemaProblems($schemas['Listing']));

harness_section('fields= with plugin fields');
$fs = SparseFieldset::parse('title,ext.acme.rating', ListingSerializer::MEMBERS, $declared, 'listing');
pin('a declared field can be selected', ['id' => 1, 'title' => 't', 'ext' => ['acme' => ['rating' => 4]]], $fs->apply([
    'id' => 1, 'title' => 't', 'url' => 'u', 'ext' => ['acme' => ['rating' => 4, 'cost_price' => '1'], 'other' => ['a' => 1]],
]));
pin('a whole plugin namespace can be selected', ['id' => 1, 'ext' => ['other' => ['a' => 1]]], SparseFieldset::parse('ext.other', ListingSerializer::MEMBERS, $declared, 'listing')->apply([
    'id' => 1, 'ext' => ['acme' => ['rating' => 4], 'other' => ['a' => 1]],
]));
$problem = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (ProblemException $e) {
        return (string) $e->response()->body()['code'];
    }

    return null;
};
pin('an undeclared field cannot be selected by name', 'invalid_query', $problem(static fn () => SparseFieldset::parse('ext.acme.nope', ListingSerializer::MEMBERS, $declared, 'listing')));

harness_section('api_listing');
$ext       = new Extensions($declared, $warn);
$listings  = new ListingSerializer(new PlainLinks(), $ext, new CustomFieldSerializer());
$relations = new ListingRelations(new CategoryCatalog([]));
$row       = static fn (int $id, int $userId = 7): array => [
    'pk_i_id' => (string) $id, 'fk_i_user_id' => (string) $userId, 'fk_i_category_id' => '1', 'b_enabled' => '1', 'b_active' => '1',
    'b_spam' => '0', 'b_premium' => '0', 'dt_expiration' => '2099-01-01 00:00:00', 's_title' => 'T' . $id, 's_description' => '',
    'i_price' => null, 'dt_pub_date' => '2026-10-01 10:00:00',
];
$anonymous = Credential::anonymous(['listings:read']);
$admin     = new Credential(CredentialKind::KEY, ['admin:listings', 'admin:taxonomy'], null, 1);

$seen   = [];
$filter = static function (array $data, array $item, ViewContext $context) use (&$seen): array {
    $seen[]                       = [$context->view(), $item['pk_i_id']];
    $data['ext']['acme']          = ['rating' => 4, 'cost_price' => '9.00', 'note' => 'undeclared'];
    $data['ext']['Not A Slug']    = ['x' => 1];

    return $data;
};
$out = api_with_filter('api_listing', $filter, static fn () => $listings->one($row(1), $relations, new ViewContext($anonymous, 'en_US')));
pin('in the public view only the declared public field is sent: admin-only and undeclared data stay out', ['rating' => 4], $out['ext']['acme']);
check('a key in ext that is not a slug is dropped with a warning', !isset($out['ext']['Not A Slug']) && str_contains(implode("\n", $warnings), 'ext.Not A Slug'));
pin('the filter gets the raw row and the view context', [['public', '1']], $seen);
$adminOut = api_with_filter('api_listing', $filter, static fn () => $listings->one($row(1), $relations, new ViewContext($admin, 'en_US')));
pin('admins get the admin-only field and the undeclared one', ['9.00', 'undeclared'], [$adminOut['ext']['acme']['cost_price'], $adminOut['ext']['acme']['note']]);
$readOnlyAdmin = new Credential(CredentialKind::KEY, ['listings:read'], null, 1);
pin('an admin key without admin:listings gets the public ext', ['rating' => 4], api_with_filter('api_listing', $filter, static fn () => $listings->one($row(1), $relations, new ViewContext($readOnlyAdmin, 'en_US')))['ext']['acme']);
pin('the listing with ext matches the schema', [], (new Validator(Schema::components($declared)))->check(Schema::ref('Listing'), $out));

$warnings = [];
$out      = api_with_filter('api_listing', 'acme_bad_listing_filter', static fn () => $listings->one($row(2), $relations, new ViewContext($anonymous, 'en_US')));
check('a top-level key a filter adds is dropped', !array_key_exists('rating', $out));
check('the warning names the callback and the key', count($warnings) === 1 && str_contains($warnings[0], 'acme_bad_listing_filter (rating)') && str_contains($warnings[0], 'ext.<plugin-slug>'));
$warnings = [];
$closure  = static function (array $data): array {
    $data['badge'] = 'x';

    return $data;
};
api_with_filter('api_listing', $closure, static fn () => $listings->one($row(3), $relations, new ViewContext($anonymous, 'en_US')));
check('a closure is named by its file and line', str_contains($warnings[0] ?? '', 'closure in ' . __FILE__ . ':'));
$removed = api_with_filter('api_listing', static function (array $data): array {
    unset($data['contact']);

    return $data;
}, static fn () => $listings->one($row(4), $relations, new ViewContext($anonymous, 'en_US')));
check('a filter may remove a core member', !array_key_exists('contact', $removed));
$warnings = [];
$kept     = api_with_filter('api_listing', static fn (array $data) => 'oops', static fn () => $listings->one($row(5), $relations, new ViewContext($anonymous, 'en_US')));
check('a filter that returns no array is ignored, with a warning', $kept['id'] === 5 && str_contains($warnings[0] ?? '', 'returned string'));
$sparse = api_with_filter('api_listing', $filter, static fn () => $listings->one($row(6), $relations, new ViewContext($anonymous, 'en_US', SparseFieldset::parse('title', ListingSerializer::MEMBERS, $declared, 'listing'))));
pin('fields= applies after the filter, so ext is left out when not asked for', ['id', 'title'], array_keys($sparse));

harness_section('api_listings_prefetch');
$calls    = [];
$prefetch = static function (array $ids, ViewContext $context) use (&$calls): void {
    $calls[] = $ids;
};
api_with_filter('api_listings_prefetch', $prefetch, static fn () => $listings->many([$row(11), $row(12), $row(13)], $relations, new ViewContext($anonymous, 'en_US')));
pin('runs once per list, with every id', [[11, 12, 13]], $calls);
$calls = [];
api_with_filter('api_listings_prefetch', $prefetch, static fn () => $listings->many([], $relations, new ViewContext($anonymous, 'en_US')));
pin('not at all for an empty list', [], $calls);

harness_section('api_user and api_category');
$users = new UserSerializer(new PlainLinks(), $ext);
$user  = ['pk_i_id' => '7', 's_name' => 'Ana', 's_username' => 'ana', 'b_enabled' => '1', 'b_active' => '1'];
$badge = static function (array $data): array {
    $data['ext']['acme'] = ['badge' => 'gold'];

    return $data;
};
check('an owner-only user field stays out of the public profile', !isset(api_with_filter('api_user', $badge, static fn () => $users->one($user, new ViewContext($anonymous, 'en_US')))['ext']));
pin('and reaches the user', 'gold', api_with_filter('api_user', $badge, static fn () => $users->one($user, new ViewContext(new Credential(CredentialKind::USER, [], 7), 'en_US')))['ext']['acme']['badge']);
$warnings   = [];
$categories = new CategorySerializer($ext, new CustomFieldSerializer());
$catalog    = new CategoryCatalog([['pk_i_id' => '1', 'fk_i_parent_id' => null, 's_name' => 'A', 's_slug' => 'a']]);
$flat       = api_with_filter('api_category', static function (array $data): array {
    $data['icon']               = 'x';
    $data['ext']['acme-icons'] = ['icon' => 'car'];

    return $data;
}, static fn () => $categories->flat($catalog, new ViewContext($anonymous, 'en_US')));
pin('api_category drops a top-level key, and undeclared ext data outside the admin view', [false, false], [array_key_exists('icon', $flat[0]), isset($flat[0]['ext'])]);
check('with a message naming the filter', str_contains($warnings[0] ?? '', 'api_category'));
$adminFlat = api_with_filter('api_category', static function (array $data): array {
    $data['ext']['acme-icons'] = ['icon' => 'car'];

    return $data;
}, static fn () => $categories->flat($catalog, new ViewContext($admin, 'en_US')));
pin('an admin key with admin:taxonomy gets it', 'car', $adminFlat[0]['ext']['acme-icons']['icon']);

harness_section('messages go to the error log, not the page');
$logFile = tempnam(sys_get_temp_dir(), 'apiext');
$oldLog  = ini_set('error_log', $logFile);
$raised  = [];
set_error_handler(static function (int $no, string $msg) use (&$raised): bool {
    $raised[] = $msg;

    return true;
});
$quiet = new ListingSerializer(new PlainLinks(), new Extensions($declared), new CustomFieldSerializer());
api_with_filter('api_listing', 'acme_bad_listing_filter', static fn () => $quiet->one($row(7), $relations, new ViewContext($anonymous, 'en_US')));
restore_error_handler();
ini_set('error_log', (string) $oldLog);
pin('no PHP warning is raised', [], $raised);
check('the callback is named in the error log', str_contains((string) file_get_contents($logFile), 'acme_bad_listing_filter (rating)'));
unlink($logFile);

exit(harness_result());
