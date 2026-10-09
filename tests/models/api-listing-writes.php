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
 * The seller writes end to end through Kernel::handle(): listings, photos, comments, saved searches and the API access page.
 * Usage: php tests/models/api-listing-writes.php
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// This file loads page helpers that earlier files in the suite stub, so under the runner it
// runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $lwOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $lwOut, $lwCode);
    $lwOut = implode("\n", $lwOut);
    echo $lwOut, "\n";
    $lwFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $lwOut, $lwM) === 1;
    $lwFail  = $lwFound ? (int)$lwM[2] : 0;
    if (!$lwFound || ($lwCode !== 0 && $lwFail === 0)) {
        $lwFail = max(1, $lwFail);
    }
    $GLOBALS['okCount']   += $lwFound ? (int)$lwM[1] : 0;
    $GLOBALS['failCount'] += $lwFail;
    if ($lwFail > 0) {
        $GLOBALS['failLabels'][] = 'api-listing-writes: ' . $lwFail . ' failed (exit ' . $lwCode . ')';
    }

    return;
}

$lwRoot = sys_get_temp_dir() . '/osc-api-writes-' . getmypid() . '/';
@mkdir($lwRoot . 'uploads/', 0777, true);
@mkdir($lwRoot . 'stage/', 0777, true);
define('UPLOADS_PATH', $lwRoot . 'uploads/');
register_shutdown_function(static function () use ($lwRoot): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lwRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($lwRoot);
});

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_api_listing_writes');

foreach (array(
    'OSC_CACHE_TTL'   => 60,
    'WEB_PATH'        => 'http://localhost/',
    'REL_WEB_URL'     => '/',
    'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'        => false,
    'OSC_DEBUG'       => false,
    'OSC_CSRF_SECRET' => 'api-writes-test-secret',
    'BCRYPT_COST'     => 4,
) as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return $GLOBALS['lwRoot'];
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}
if (!function_exists('osc_item_url')) {
    function osc_item_url($locale = '')
    {
        return WEB_PATH . 'item';
    }
}
if (!function_exists('osc_core_url')) {
    function osc_core_url($name, $args = array())
    {
        return WEB_PATH . 'route/' . $name;
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';
require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hFields.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSearch.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

use mindstellar\api\ApiServices;
use mindstellar\api\auth\UserRows;
use mindstellar\api\identity\WebIdentity;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\ratelimit\RatePolicy;
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\api\write\ImageFetcher;
use mindstellar\api\write\PhotoFile;
use mindstellar\api\write\PhotoStage;
use mindstellar\apiaccess\AccountAccess;
use mindstellar\apiaccess\ApiKeys;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\KeyOwner;
use mindstellar\apiaccess\Scopes;
use mindstellar\billing\Billing;
use mindstellar\model\ApiCredential;
use mindstellar\security\AddressGuard;
use mindstellar\utility\SystemClock;

/** A mailer that records what it would send. */
final class HeldMailer extends PHPMailer\PHPMailer\PHPMailer
{
    /** @var string[] subjects sent */
    public static array $sent = array();

    public function send()
    {
        self::$sent[] = $this->Subject;

        return true;
    }
}

/** Links without the theme helpers. */
final class WriteLinks implements Links
{
    public function listing(array $item): string
    {
        return 'http://localhost/item/' . $item['pk_i_id'];
    }

    public function photo(array $resource, string $variant): string
    {
        return 'http://localhost/' . $resource['s_path'] . $resource['pk_i_id'] . ($variant === '' ? '' : '_' . $variant) . '.' . $resource['s_extension'];
    }

    public function user(int $id, string $username): string
    {
        return 'http://localhost/user/' . $id;
    }

    public function avatar(int $userId): string
    {
        return 'http://localhost/avatar/' . $userId;
    }

    public function api(string $path, ?string $version = null): string
    {
        return 'http://localhost/api/' . ($version ?? 'v1') . '/' . $path;
    }

    public function price(?int $micros, string $symbol): string
    {
        return '';
    }
}

/* ----------------------------------------------------------------------------
 * Fixture: Vehicles > Cars with a required custom field, a region and city, two sellers
 * with passwords, a JPEG and a file that only claims to be one.
 * ------------------------------------------------------------------------- */
$p        = DB_TABLE_PREFIX;
$locale   = seed_locale($admin);
seed_currency($admin);
$country  = seed_country($admin, 'US', 'United States');
$region   = seed_region($admin, $country, 'Alpha');
$city     = seed_city($admin, $region, 'Aville', $country);
$vehicles = seed_category($admin, 'Vehicles', null, $locale);
$cars     = seed_category($admin, 'Cars', $vehicles, $locale);
$sue      = seed_user($admin, 'sue', 'sue@example.test');
$tom      = seed_user($admin, 'tom', 'tom@example.test');
$hash     = password_hash('open sesame', PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));
$admin->query("UPDATE {$p}t_user SET s_password = '" . $admin->real_escape_string($hash) . "', s_phone_mobile = '5550100'");
$colour = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position, s_meta) VALUES ('Colour', 'colour', 'TEXT', 1, 1, 1, '')", '', array());
seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($cars, $colour));
$boats = seed_category($admin, 'Boats', null, $locale);
$misc  = seed_category($admin, 'Misc', seed_category($admin, 'Other', null, $locale), $locale);
$hull  = seed_exec($admin, "INSERT INTO {$p}t_meta_fields (s_name, s_slug, e_type, b_required, b_searchable, i_position, s_meta) VALUES ('Hull', 'hull', 'TEXT', 0, 0, 2, '')", '', array());
seed_exec($admin, "INSERT INTO {$p}t_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($boats, $hull));
foreach (array(
    'enabled_users'     => '1',
    'enabled_comments'  => '1',
    'moderate_items'    => '-1',
    'moderate_comments' => '-1',
    'items_wait_time'   => '0',
    'numImages@items'   => '3',
    'maxSizeKb'         => '2048',
    'allowedExt'        => 'png,gif,jpg,jpeg,webp',
    'language'          => 'en_US',
    'title_character_length'       => '100',
    'description_character_length' => '5000',
    'dimNormal'         => '640x480',
    'dimPreview'        => '480x340',
    'dimThumbnail'      => '240x200',
) as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.60';
Params::init();

$jpeg = $lwRoot . 'car.jpg';
$img  = imagecreatetruecolor(64, 48);
imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 200, 30, 30));
imagejpeg($img, $jpeg, 80);
$fake = $lwRoot . 'fake.jpg';
file_put_contents($fake, "<?php echo 'not an image';");

/** Every hook a test cares about, counted. */
$fired = array();
foreach (array('posted_item', 'edited_item', 'before_delete_item', 'after_delete_item', 'hook_email_item_validation', 'add_comment', 'pre_item_add_comment_post', 'pre_item_delete_comment_post', 'uploaded_file') as $hook) {
    osc_add_hook($hook, static function (...$args) use (&$fired, $hook): void {
        $fired[$hook] = ($fired[$hook] ?? 0) + 1;
    });
}
// A plugin that fails part way through a save, when told to.
osc_add_hook('posted_item', static function (): void {
    if (!empty($GLOBALS['lw_fail_posted'])) {
        throw new RuntimeException('A plugin failed.');
    }
});
$postedBy = null;
osc_add_hook('posted_item', static function ($item) use (&$postedBy): void {
    $postedBy = array((int) $item['fk_i_user_id'], (int) osc_logged_user_id());
});

/* ----------------------------------------------------------------------------
 * The kernel, wired as ApiServices wires the site's, one per request.
 * ------------------------------------------------------------------------- */
$validator = new Validator(Schema::components());
$facts     = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')));
$settings  = new ApiSettings(true, userKeys: true);
$fetches   = 0;
$transport = static function (array $jobs, int $max) use ($jpeg, &$fetches): array {
    $out = array();
    foreach ($jobs as $key => $job) {
        $fetches++;
        $GLOBALS['lw_fetch_in_transaction'][] = \mindstellar\database\Db::inTransaction();
        $out[$key] = copy($jpeg, $job['file']) ? null : 'copy failed';
    }

    return $out;
};
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $headers = array(), array $files = array(), ?string $raw = null, ?Closure $reader = null) use ($validator, $facts, &$settings, $lwRoot, $transport): Response {
    $users    = new UserRows();
    $guard    = new AddressGuard(static fn (string $host): array => $host === 'photos.example.com' ? array('93.184.216.34') : array('10.0.0.5'));
    $services = new ApiServices(
        $settings,
        new Scopes(),
        new ApiCredential(),
        $users,
        new SystemClock(),
        $GLOBALS['lw_limiter'] ?? api_test_limiter(static fn () => 1),
        $facts,
        new WriteLinks(),
        new PhotoStage($lwRoot . 'stage/', new SystemClock()),
        new ImageFetcher($guard, $transport),
        2048 * 1024
    );
    $kernel   = api_services_kernel($services, $validator);
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer ' . $token;
    }
    $content = $raw ?? '';
    if ($body !== null) {
        $headers['Content-Type'] = 'application/json';
        $content                 = (string) json_encode($body);
    }
    Params::init();
    WebIdentity::forget();
    $GLOBALS['lw_services'] = $services;

    return $kernel->handle(new Request($method, 'v1/' . $path, array(), $headers, '192.0.2.60', $content, $files, $reader));
};
$login = static fn (string $user): string => (string) ($call('POST', 'auth/token', array('grant_type' => 'password', 'username' => $user, 'password' => 'open sesame'))->body()['access_token'] ?? '');
$code  = static fn (Response $r): string => $r->status() . ' ' . (string) ($r->body()['code'] ?? '');
$first = static fn (Response $r): string => (string) ($r->body()['errors'][0]['message'] ?? '');
$schemaErrors = static fn (string $schema, Response $r): array => $validator->check(Schema::ref($schema), $r->body());
$itemRow = static fn (int $id): ?array => $admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = $id")->fetch_assoc();
$photoFile = static function (string $path) use ($lwRoot): array {
    $copy = $lwRoot . 'up-' . bin2hex(random_bytes(4));
    copy($path, $copy);

    return array('photo' => array('name' => 'car.jpg', 'type' => 'image/jpeg', 'tmp_name' => $copy, 'error' => UPLOAD_ERR_OK, 'size' => filesize($copy)));
};
$listing = static fn (array $extra = array()): array => $extra + array(
    'category_id'   => $cars,
    'title'         => 'Red hatchback',
    'description'   => 'A small red car, one owner, full service history.',
    'price'         => '1500.50',
    'currency'      => 'USD',
    'country'       => 'US',
    'region_id'     => $region,
    'city_id'       => $city,
    'contact_phone' => '5550199',
    'custom_fields'        => array((string) $colour => 'red'),
);

$sueToken = $login('sue');
$tomToken = $login('tom');
check('both sellers signed in', $sueToken !== '' && $tomToken !== '');

harness_section('posting a listing');
$fired = array();
$r     = $call('POST', 'listings', $listing(), $sueToken);
$made  = (int) ($r->body()['data']['id'] ?? 0);
pin('201 with the listing in the owner view', array(201, 'active', 'Red hatchback', '1500.50', 'US'), array(
    $r->status(), $r->body()['data']['status'] ?? null, $r->body()['data']['title'] ?? null, $r->body()['data']['price']['amount'] ?? null, $r->body()['data']['location']['country']['code'] ?? null,
));
pin('Location names it', 'http://localhost/api/v1/listings/' . $made, $r->header('Location'));
pin('matches the schema', array(), $schemaErrors('SavedListing', $r));
pin('owned by the token\'s user, who core saw as signed in', array($sue, $sue), $postedBy);
pin('posted_item fires once', 1, $fired['posted_item'] ?? 0);
pin('the custom field and the phone are stored', array('red', '5550199'), array(
    $admin->query("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $made")->fetch_row()[0] ?? null, $itemRow($made)['s_contact_phone'],
));
check('the owner view carries the e-mail, which only owners see', ($r->body()['data']['contact']['email'] ?? null) === 'sue@example.test');

pin('a missing title is 422 from the schema', array(422, '/title'), array(
    $call('POST', 'listings', array_diff_key($listing(), array('title' => 1)), $sueToken)->status(),
    $call('POST', 'listings', array_diff_key($listing(), array('title' => 1)), $sueToken)->body()['errors'][0]['pointer'] ?? null,
));
pin('an unknown member is 422', '422 validation_failed', $code($call('POST', 'listings', $listing(array('owner' => 1)), $sueToken)));
pin('an unknown city is 422', array(422, '/city_id'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null))($call('POST', 'listings', $listing(array('city_id' => 99999)), $sueToken)));
$placeError   = static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null, $r->body()['errors'][0]['code'] ?? null);
$otherCountry = seed_country($admin, 'CA', 'Canada');
$otherRegion  = seed_region($admin, $otherCountry, 'Gamma');
$otherCity    = seed_city($admin, $otherRegion, 'Gtown', $otherCountry);
pin('an unknown place answers unknown', array(array(422, '/country', 'unknown'), array(422, '/region_id', 'unknown'), array(422, '/city_id', 'unknown')), array(
    $placeError($call('POST', 'listings', $listing(array('country' => 'ZZ')), $sueToken)),
    $placeError($call('POST', 'listings', $listing(array('region_id' => 99999)), $sueToken)),
    $placeError($call('POST', 'listings', $listing(array('city_id' => 99999)), $sueToken)),
));
pin('a region of another country or a city of another region answers mismatch', array(array(422, '/region_id', 'mismatch'), array(422, '/city_id', 'mismatch')), array(
    $placeError($call('POST', 'listings', $listing(array('region_id' => $otherRegion)), $sueToken)),
    $placeError($call('POST', 'listings', $listing(array('city_id' => $otherCity)), $sueToken)),
));
pin('ItemActions\' own refusal is 422 with its message', array(422, 'Description too short (en_US).'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['message'] ?? null))($call('POST', 'listings', $listing(array('description' => 'ab')), $sueToken)));
pin('a refusal carries the member and code of each error', array('validation_failed', '/description', 'minLength'), (static fn (Response $r): array => array($r->body()['code'] ?? null, $r->body()['errors'][0]['pointer'] ?? null, $r->body()['errors'][0]['code'] ?? null))($call('POST', 'listings', $listing(array('description' => 'ab')), $sueToken)));
pin('a language the site does not have is 422', array(422, '/translations/fr_FR'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null))($call('POST', 'listings', $listing(array('translations' => array('fr_FR' => array('title' => 'Voiture')))), $sueToken)));
pin('a required custom field left out is refused as on the form', 422, $call('POST', 'listings', $listing(array('custom_fields' => array())), $sueToken)->status());
pin('without a credential it is 401', 401, $call('POST', 'listings', $listing())->status());
$readOnly = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create('key', 'reader', array('listings:read'), KeyOwner::user($sue))->token();
pin('without listings:write it is 403', '403 insufficient_scope', $code($call('POST', 'listings', $listing(), $readOnly)));

harness_section('an Idempotency-Key');
$fired = array();
$one   = $call('POST', 'listings', $listing(array('title' => 'Blue van')), $sueToken, array('Idempotency-Key' => 'van-1'));
$two   = $call('POST', 'listings', $listing(array('title' => 'Blue van')), $sueToken, array('Idempotency-Key' => 'van-1'));
pin('a repeat answers the first listing again', array(201, 'true', $one->body()['data']['id'] ?? null), array($two->status(), $two->header('Idempotency-Replayed'), $two->body()['data']['id'] ?? null));
pin('only one listing was made, and posted_item fired once', array(1, 1), array(
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_description WHERE s_title = 'Blue van'")->fetch_row()[0], $fired['posted_item'] ?? 0,
));

harness_section('moderation, the listing limit, the posting wait and the hourly cap');
Preference::getInstance()->set('moderate_items', '0');
osc_reset_preferences();
$fired = array();
$r     = $call('POST', 'listings', $listing(array('title' => 'Moderated wagon')), $sueToken);
pin('with moderation on the listing waits: pending, with a warning', array(201, 'pending', 'listing_pending'), array($r->status(), $r->body()['data']['status'] ?? null, $r->body()['warnings'][0]['code'] ?? null));
pin('a pending listing sends the activation e-mail as from the form', 1, $fired['hook_email_item_validation'] ?? 0);
$pendingId = (int) $r->body()['data']['id'];
Preference::getInstance()->set('moderate_items', '-1');
$heldId = (int) ($call('POST', 'listings', $listing(array('title' => 'Held coupe')), $sueToken)->body()['data']['id'] ?? 0);
$r      = $call('PATCH', 'listings/' . $heldId, array('price' => '1300'), $sueToken);
pin('a live listing\'s edit has no warning', array(200, array()), array($r->status(), $r->body()['warnings'] ?? array()));
Preference::getInstance()->set('moderate_admin_edit', '1');
osc_reset_preferences();
$heldArgs = array();
foreach (array('item_decrease_stat', 'edited_item') as $hook) {
    osc_add_hook($hook, static function ($item) use (&$heldArgs, $hook): void {
        $heldArgs[$hook] = $item;
    });
}
$r = $call('PATCH', 'listings/' . $heldId, array('price' => '1400'), $sueToken);
pin('an edit held for the admin\'s approval disables the listing and warns that it is pending', array(200, 'disabled', 'listing_pending'), array($r->status(), $r->body()['data']['status'] ?? null, $r->body()['warnings'][0]['code'] ?? null));
$heldRead = Item::newInstance()->findByPrimaryKey($heldId);
pin('...and item_decrease_stat and edited_item get the disabled listing as a fresh read gives it', array($heldRead, $heldRead), array($heldArgs['item_decrease_stat'] ?? null, $heldArgs['edited_item'] ?? null));
Preference::getInstance()->set('moderate_admin_edit', '0');
Preference::getInstance()->set('moderate_admin_post', '1');
osc_reset_preferences();
$heldArgs = array();
$heldPost = (int) ($call('POST', 'listings', $listing(array('title' => 'Held estate')), $sueToken)->body()['data']['id'] ?? 0);
pin('a post held for the admin gives item_decrease_stat the disabled listing as a fresh read gives it', Item::newInstance()->findByPrimaryKey($heldPost), $heldArgs['item_decrease_stat'] ?? null);
Preference::getInstance()->set('moderate_admin_post', '0');
Preference::getInstance()->set('moderate_admin_edit', '0');
osc_reset_preferences();
$r = $call('PATCH', 'listings/' . $pendingId, array('price' => '950'), $sueToken);
pin('an edit of a listing still awaiting activation stays pending and warns so', array(200, 'pending', 'listing_pending'), array($r->status(), $r->body()['data']['status'] ?? null, $r->body()['warnings'][0]['code'] ?? null));

osc_set_preference(Billing::PREF_ENABLED, '1', Billing::PREF_GROUP, 'BOOLEAN');
osc_set_preference('billing_free_live_listings', '1', 'osclass', 'INTEGER');
osc_reset_preferences();
$r = $call('POST', 'listings', $listing(array('title' => 'Over the limit')), $tomToken);
$r = $call('POST', 'listings', $listing(array('title' => 'Over the limit two')), $tomToken);
pin('past the listing limit it is 422 listing_limit', '422 listing_limit', $code($r));
osc_set_preference(Billing::PREF_ENABLED, '0', Billing::PREF_GROUP, 'BOOLEAN');
osc_reset_preferences();

Preference::getInstance()->set('items_wait_time', '600');
osc_reset_preferences();
$r = $call('POST', 'listings', $listing(array('title' => 'Too soon')), $sueToken);
pin('the posting wait applies', array(422, 'Too fast. You should wait a little to publish your ad.'), array($r->status(), $first($r)));
Preference::getInstance()->set('items_wait_time', '0');
osc_reset_preferences();

$GLOBALS['lw_limiter'] = api_test_limiter(static fn (string $bucket) => $bucket === 'api_listing_post' ? ApiSettings::LISTINGS_PER_HOUR + 1 : 1);
pin('past the hourly cap it is 429, as there is no captcha', '429 rate_limited', $code($call('POST', 'listings', $listing(array('title' => 'One too many')), $sueToken)));
unset($GLOBALS['lw_limiter']);

harness_section('editing a listing');
$fired     = array();
$editedArg = null;
osc_add_hook('edited_item', static function ($item) use (&$editedArg): void {
    $editedArg = $item;
});
$r     = $call('PATCH', 'listings/' . $made, array('price' => '1200'), $sueToken);
pin('PATCH answers the saved listing', array(200, '1200.00'), array($r->status(), $r->body()['data']['price']['amount'] ?? null));
pin('members not sent keep their values', array('Red hatchback', 'A small red car, one owner, full service history.', '5550199', 'red', (string) $city, 'USD'), array(
    $admin->query("SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = $made")->fetch_row()[0],
    $admin->query("SELECT s_description FROM {$p}t_item_description WHERE fk_i_item_id = $made")->fetch_row()[0],
    $itemRow($made)['s_contact_phone'],
    $admin->query("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $made")->fetch_row()[0],
    $admin->query("SELECT fk_i_city_id FROM {$p}t_item_location WHERE fk_i_item_id = $made")->fetch_row()[0],
    $itemRow($made)['fk_c_currency_code'],
));
pin('edited_item fires once', 1, $fired['edited_item'] ?? 0);
pin('...with the listing as a fresh read gives it, built from the locked row', Item::newInstance()->findByPrimaryKey($made), $editedArg);
pin('matches the schema', array(), $schemaErrors('SavedListing', $r));
$r = $call('PATCH', 'listings/' . $made, array('title' => 'Red hatchback with new tyres', 'custom_fields' => array((string) $colour => 'crimson'), 'price' => null), $sueToken);
pin('what is sent changes; a null price removes it', array('Red hatchback with new tyres', 'crimson', null, null), array(
    $r->body()['data']['title'] ?? null, $admin->query("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $made")->fetch_row()[0],
    array_key_exists('price', $r->body()['data'] ?? array()) ? $r->body()['data']['price'] : 'x', $itemRow($made)['i_price'],
));
pin('...and edited_item has the new title and no price', Item::newInstance()->findByPrimaryKey($made), $editedArg);
pin('another seller\'s live listing is 403 not_owner', '403 not_owner', $code($call('PATCH', 'listings/' . $made, array('price' => '1'), $tomToken)));
pin('another seller\'s pending listing is 404', 404, $call('PATCH', 'listings/' . $pendingId, array('price' => '1'), $tomToken)->status());
pin('an unknown listing is 404', 404, $call('PATCH', 'listings/999999', array('price' => '1'), $sueToken)->status());
pin('an edit is refused as the form refuses it', 422, $call('PATCH', 'listings/' . $made, array('title' => ''), $sueToken)->status());
pin('an edit checks a sent region against the stored country', array(422, '/region_id', 'mismatch'), $placeError($call('PATCH', 'listings/' . $made, array('region_id' => $otherRegion), $sueToken)));
$r = $call('PATCH', 'listings/' . $made, array('contact_phone' => null, 'address' => null), $sueToken);
$before = $call('GET', 'listings/' . $made, null, $sueToken)->body()['data']['title'] ?? null;
$r      = $call('PATCH', 'listings/' . $made, array('translations' => array('en_US' => null)), $sueToken);
pin('null on the listing\'s own language does not remove its title', array(200, $before), array($r->status(), $r->body()['data']['title'] ?? null));
pin('null clears an optional member, as JSON Merge Patch says', array(200, null, null), array($r->status(), $r->body()['data']['contact']['phone'] ?? null, $r->body()['data']['location']['address'] ?? null));
$colourOf = static fn (): ?string => $admin->query("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $made AND fk_i_field_id = $colour")->fetch_row()[0] ?? null;
$call('PATCH', 'listings/' . $made, array('custom_fields' => array((string) $colour => 'Black & white < "grey"')), $sueToken);
$once = $colourOf();
$call('PATCH', 'listings/' . $made, array('price' => '1250'), $sueToken);
$call('PATCH', 'listings/' . $made, array('price' => '1200'), $sueToken);
check('the value was stored', (string) $once !== '');
pin('a text field not sent is stored again as it was, not encoded once more', $once, $colourOf());
$call('PATCH', 'listings/' . $made, array('custom_fields' => array((string) $colour => 'crimson')), $sueToken);

harness_section('photos');
$stage = $call('POST', 'photos', null, $sueToken, array(), $photoFile($jpeg));
$token = (string) ($stage->body()['data']['token'] ?? '');
pin('POST /photos keeps a photo and answers its token: 200, as a token is not a resource to GET', array(200, 32, null), array($stage->status(), strlen($token), $stage->header('Location')));
pin('matches the schema', array(), $schemaErrors('PhotoTokenDocument', $stage));
pin('a file that is not an image is refused', array(422, '/photo'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null))($call('POST', 'photos', null, $sueToken, array(), $photoFile($fake))));
pin('a JSON body is not a photo', '415 unsupported_media_type', $code($call('POST', 'photos', array('photo' => 'x'), $sueToken)));
$fired = array();
$r     = $call('POST', 'listings', $listing(array('title' => 'With a photo', 'photo_tokens' => array($token))), $sueToken);
$withPhoto = (int) ($r->body()['data']['id'] ?? 0);
check('every error of a refused edit is listed, not one joined line', count($call('PATCH', 'listings/' . $withPhoto, array('title' => str_repeat('t', 150), 'description' => 'ab'), $sueToken)->body()['errors'] ?? array()) >= 2);
pin('photo_tokens attach the staged photo', array(201, 1, 1), array($r->status(), count($r->body()['data']['photos'] ?? array()), $fired['uploaded_file'] ?? 0));
pin('a token works once', array(422, '/photo_tokens/0'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null))($call('POST', 'listings', $listing(array('title' => 'Again', 'photo_tokens' => array($token))), $sueToken)));
$tomsToken = (string) $call('POST', 'photos', null, $tomToken, array(), $photoFile($jpeg))->body()['data']['token'];
pin('another user\'s token is refused', 422, $call('POST', 'listings', $listing(array('title' => 'Stolen', 'photo_tokens' => array($tomsToken))), $sueToken)->status());

$r = $call('POST', 'listings/' . $withPhoto . '/photos', null, $sueToken, array('Content-Type' => 'image/jpeg'), array(), (string) file_get_contents($jpeg));
pin('a raw image body adds a photo', array(201, true), array($r->status(), is_int($r->body()['data']['id'] ?? null)));
pin('Location names it', 'http://localhost/api/v1/listings/' . $withPhoto . '/photos/' . ($r->body()['data']['id'] ?? ''), $r->header('Location'));
$one = $call('GET', 'listings/' . $withPhoto . '/photos/' . ($r->body()['data']['id'] ?? ''), null, $sueToken);
pin('a created listing can be read at its Location', array(200, $r->body()['data']['id'] ?? null), array($one->status(), $one->body()['data']['id'] ?? null));
pin('matches the schema', array(), $schemaErrors('PhotoDocument', $one));
pin('a photo of another listing is 404 there', 404, $call('GET', 'listings/' . $made . '/photos/' . ($r->body()['data']['id'] ?? ''), null, $sueToken)->status());
pin('matches the schema', array(), $schemaErrors('PhotoDocument', $r));
$third = $call('POST', 'listings/' . $withPhoto . '/photos', null, $sueToken, array(), $photoFile($jpeg));
pin('a multipart photo too, up to the cap of 3', 201, $third->status());
$over = $call('POST', 'listings/' . $withPhoto . '/photos', null, $sueToken, array(), $photoFile($jpeg));
pin('a photo over the cap is refused', array(422, 'limit'), array($over->status(), $over->body()['errors'][0]['code'] ?? null));
pin('the listing holds 3', 3, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_resource WHERE fk_i_item_id = $withPhoto")->fetch_row()[0]);
$racyRoom = new class () extends \mindstellar\listing\PhotoRoom {
    private int $calls = 0;

    public function room(int $itemId, ?int $ownerId): ?int
    {
        return $this->calls++ === 0 ? 1 : parent::room($itemId, $ownerId);
    }
};
$raced = null;
try {
    (new \mindstellar\api\controller\PhotosController($GLOBALS['lw_services'], $racyRoom))->add(new \mindstellar\api\ApiCall(
        new Request('POST', 'v1/listings/' . $withPhoto . '/photos', array(), array(), '192.0.2.60', '', $photoFile($jpeg)),
        new \mindstellar\apiaccess\Credential(\mindstellar\apiaccess\CredentialKind::USER, array('listings:write'), (int) $itemRow($withPhoto)['fk_i_user_id']),
        array('id' => (string) $withPhoto)
    ));
} catch (\mindstellar\api\ProblemException $e) {
    $raced = array($e->response()->status(), $e->response()->body()['errors'][0]['code'] ?? null);
}
pin('a photo that loses the race for the last place is 422 limit, not 500', array(422, 'limit'), $raced);
pin('a bad image on a listing is refused', 422, $call('POST', 'listings/' . $made . '/photos', null, $sueToken, array(), $photoFile($fake))->status());
pin('another seller cannot add one', '403 not_owner', $code($call('POST', 'listings/' . $withPhoto . '/photos', null, $tomToken, array(), $photoFile($jpeg))));
$photoId = (int) $third->body()['data']['id'];
pin('another seller cannot remove one', '403 not_owner', $code($call('DELETE', 'listings/' . $withPhoto . '/photos/' . $photoId, null, $tomToken)));
pin('the owner removes one', 204, $call('DELETE', 'listings/' . $withPhoto . '/photos/' . $photoId, null, $sueToken)->status());
pin('a removed photo is gone from the table', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_resource WHERE pk_i_id = $photoId")->fetch_row()[0]);
pin('a photo of another listing is 404 here', 404, $call('DELETE', 'listings/' . $made . '/photos/' . $photoId, null, $sueToken)->status());
$tokens = array();
for ($i = 0; $i < 3; $i++) {
    $tokens[] = (string) $call('POST', 'photos', null, $sueToken, array(), $photoFile($jpeg))->body()['data']['token'];
}
$tokens[] = (string) $call('POST', 'photos', null, $sueToken, array(), $photoFile($jpeg))->body()['data']['token'];
$r = $call('POST', 'listings', $listing(array('title' => 'Four photos', 'photo_tokens' => $tokens)), $sueToken);
pin('more tokens than the cap: three are added and a warning says so', array(201, 3, 'photo_skipped'), array($r->status(), count($r->body()['data']['photos'] ?? array()), $r->body()['warnings'][0]['code'] ?? null));

pin('photo_urls is off by default', array(422, '/photo_urls'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null))($call('POST', 'listings', $listing(array('title' => 'By URL', 'photo_urls' => array('https://photos.example.com/car.jpg'))), $sueToken)));
$settings = new ApiSettings(true, userKeys: true, photoUrls: true);
pin('when on, a private address is refused', array(422, '/photo_urls/0'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['pointer'] ?? null))($call('POST', 'listings', $listing(array('title' => 'By URL', 'photo_urls' => array('https://intranet.example.com/car.jpg'))), $sueToken)));
$r = $call('POST', 'listings', $listing(array('title' => 'By URL', 'photo_urls' => array('https://photos.example.com/car.jpg'))), $sueToken);
pin('a public one is downloaded and attached', array(201, 1), array($r->status(), count($r->body()['data']['photos'] ?? array())));
$settings = new ApiSettings(true, userKeys: true);

harness_section('custom fields are the category\'s own, cleaned as the form cleans them');
$r = $call('POST', 'listings', $listing(array('category_id' => $misc, 'title' => 'No fields here', 'custom_fields' => array((string) $colour => '<script>alert(1)</script>'))), $sueToken);
$noFields = (int) ($r->body()['data']['id'] ?? 0);
pin('a category with no fields takes none: nothing is stored', array(201, 0), array($r->status(), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_meta WHERE fk_i_item_id = $noFields")->fetch_row()[0]));
$r = $call('POST', 'listings', $listing(array('title' => 'Foreign field', 'custom_fields' => array((string) $colour => '<b onmouseover="x()">red</b>', (string) $hull => '<script>alert(1)</script>'))), $sueToken);
$foreign = (int) ($r->body()['data']['id'] ?? 0);
pin('another category\'s field is dropped; a value is purified as the form\'s', array(201, array((string) $colour => 'red')), array(
    $r->status(), array_column($admin->query("SELECT fk_i_field_id, s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $foreign")->fetch_all(MYSQLI_ASSOC), 's_value', 'fk_i_field_id'),
));
$r = $call('PATCH', 'listings/' . $foreign, array('category_id' => $misc), $sueToken);
pin('moving a listing to a category with no fields stores nothing new', array(200, 0), array($r->status(), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_meta WHERE fk_i_item_id = $foreign AND fk_i_field_id <> $colour")->fetch_row()[0]));

$direct = static function (int $category, array $meta) use ($region, $city): int {
    $actions = new ItemActions(true);
    $actions->prepareDataFrom(array(
        'catId' => (string) $category, 'title' => array('en_US' => 'Plain data car'), 'description' => array('en_US' => 'Saved straight through ItemActions.'),
        'contactName' => 'Sue', 'contactEmail' => 'sue@example.test', 'countryId' => 'US', 'regionId' => (string) $region, 'cityId' => (string) $city,
        'contactPhone' => '5550100', 'meta' => $meta,
    ), true);
    $actions->add();

    return $actions->lastItemId();
};
$plain = $direct($misc, array($colour => '<script>alert(1)</script>'));
pin('prepareDataFrom(): a category with no fields stores no value', array(true, 0), array($plain > 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_meta WHERE fk_i_item_id = $plain")->fetch_row()[0]));
$plain = $direct($cars, array($colour => '<i>blue</i>', $hull => 'oak'));
pin('prepareDataFrom(): only the category\'s fields, purified as the form\'s', array((string) $colour => 'blue'), array_column($admin->query("SELECT fk_i_field_id, s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $plain")->fetch_all(MYSQLI_ASSOC), 's_value', 'fk_i_field_id'));

harness_section('bans and hourly caps');
$admin->query("INSERT INTO {$p}t_ban_rule (s_name, s_email) VALUES ('test', 'sue@example.test')");
\mindstellar\security\BanRuleStore::forget();
pin('a banned e-mail cannot post a listing', '403 banned', $code($call('POST', 'listings', $listing(array('title' => 'Banned car')), $sueToken)));
$admin->query("DELETE FROM {$p}t_ban_rule");
\mindstellar\security\BanRuleStore::forget();
$admin->query("INSERT INTO {$p}t_ban_rule (s_name, s_ip) VALUES ('test', '192.0.2.60')");
\mindstellar\security\BanRuleStore::forget();
pin('nor a banned address', '403 banned', $code($call('POST', 'listings', $listing(array('title' => 'Banned car')), $sueToken)));
$admin->query("DELETE FROM {$p}t_ban_rule");
\mindstellar\security\BanRuleStore::forget();
pin('none of it was saved', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_description WHERE s_title = 'Banned car'")->fetch_row()[0]);

$GLOBALS['lw_limiter'] = api_test_limiter(static fn (string $bucket) => $bucket === 'api_listing_post' ? 3 : 1);
$settings = new ApiSettings(true, userKeys: true, listingRate: 2);
pin('the hourly listing cap is a setting', '429 rate_limited', $code($call('POST', 'listings', $listing(array('title' => 'Third this hour')), $sueToken)));
$settings = new ApiSettings(true, userKeys: true, listingRate: 5);
pin('a higher one lets it through', 201, $call('POST', 'listings', $listing(array('title' => 'Third this hour')), $sueToken)->status());
$settings = new ApiSettings(true, userKeys: true);
pin('the default is 30 an hour, 10 while the listing form asks for a captcha', array(30, 10, 7), array(
    (new ApiSettings(true, userKeys: true))->listingsPerHour(), (new ApiSettings(true, userKeys: true, listingCaptcha: true))->listingsPerHour(), (new ApiSettings(true, userKeys: true, listingRate: 7, listingCaptcha: true))->listingsPerHour(),
));
$GLOBALS['lw_limiter'] = api_test_limiter(static fn (string $bucket) => $bucket === 'api_listing_ip' ? 1000 : 1);
pin('one address is capped across accounts too', '429 rate_limited', $code($call('POST', 'listings', $listing(array('title' => 'From a busy address')), $sueToken)));
unset($GLOBALS['lw_limiter']);
for ($i = 0; $i < \mindstellar\comment\CommentPolicy::PER_HOUR; $i++) {
    \mindstellar\security\RateLimit::hit('comment_post', 'user:' . $tom, 1000, 3600);
}
pin('comments have an hourly cap per user, shared with the comment form', '429 rate_limited', $code($call('POST', 'listings/' . $withPhoto . '/comments', array('body' => 'Another question'), $tomToken)));
$admin->query("DELETE FROM {$p}t_rate_counter");

harness_section('photo URLs on an edit');
$settings = new ApiSettings(true, userKeys: true, photoUrls: true);
$GLOBALS['lw_limiter'] = api_test_limiter(static fn (string $bucket) => $bucket === 'api_photo_fetch' ? RatePolicy::PHOTO_FETCHES_PER_HOUR + 1 : 1);
pin('fetches on an edit have an hourly cap per user', '429 rate_limited', $code($call('PATCH', 'listings/' . $withPhoto, array('photo_urls' => array('https://photos.example.com/car.jpg')), $sueToken)));
pin('fetches for a new listing count in the same hourly cap', '429 rate_limited', $code($call('POST', 'listings', $listing(array('title' => 'Fetched', 'photo_urls' => array('https://photos.example.com/car.jpg'))), $sueToken)));
unset($GLOBALS['lw_limiter']);
$fetches = 0;
$r = $call('PATCH', 'listings/' . $withPhoto, array('photo_urls' => array('https://photos.example.com/a.jpg', 'https://photos.example.com/b.jpg')), $sueToken);
pin('only what the listing has room for is fetched; the rest is a warning', array(200, 1, 3, 'photo_skipped'), array(
    $r->status(), $fetches, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_resource WHERE fk_i_item_id = $withPhoto")->fetch_row()[0], $r->body()['warnings'][0]['code'] ?? null,
));
$fetches = 0;
$r = $call('PATCH', 'listings/' . $withPhoto, array('photo_urls' => array('https://photos.example.com/c.jpg')), $sueToken);
pin('a full listing fetches nothing', array(200, 0), array($r->status(), $fetches));
$roomy  = (int) ($call('POST', 'listings', $listing(array('title' => 'Room for photos')), $sueToken)->body()['data']['id'] ?? 0);
$etag   = (string) $call('GET', 'listings/' . $roomy, null, $sueToken)->header('ETag');
$tomGet = $call('GET', 'listings/' . $roomy, null, $tomToken);
pin('another user with the write scope gets the plain ETag, not the stored version', array(200, $call('GET', 'listings/' . $roomy)->header('ETag'), false), array($tomGet->status(), $tomGet->header('ETag'), $tomGet->header('ETag') === $etag));
$staged = static fn (): int => count(glob($lwRoot . 'stage/qqfile_api_*') ?: array());
$files  = $staged();
$fetches = 0;
$GLOBALS['lw_fetch_in_transaction'] = array();
$r = $call('PATCH', 'listings/' . $roomy, array('photo_urls' => array('https://photos.example.com/d.jpg')), $sueToken, array('If-Match' => '"stale"'));
pin('a stale If-Match is 412; the photo fetched before it is not left in the temp folder', array('412 precondition_failed', 1, $files), array($code($r), $fetches, $staged()));
$GLOBALS['lw_fetch_in_transaction'] = array();
$r = $call('PATCH', 'listings/' . $roomy, array('photo_urls' => array('https://photos.example.com/d.jpg')), $sueToken, array('If-Match' => $etag));
pin('with a current one the photo is added, fetched outside the If-Match transaction', array(200, 1, array(false), $files), array(
    $r->status(), count($r->body()['data']['photos'] ?? array()), $GLOBALS['lw_fetch_in_transaction'], $staged(),
));
$fetches = 0;
$r = $call('PATCH', 'listings/' . $roomy, array('photo_urls' => array('https://photos.example.com/e.jpg'), 'category_id' => 999999), $sueToken);
pin('an edit refused after the fetch leaves no file either', array(422, 1, $files), array($r->status(), $fetches, $staged()));
$settings = new ApiSettings(true, userKeys: true);

harness_section('a save is all or nothing');
$GLOBALS['lw_fail_posted'] = true;
$logged  = ini_set('error_log', '/dev/null');
$threwUp = $call('POST', 'listings', $listing(array('title' => 'Half saved')), $sueToken)->status() === 500;
ini_set('error_log', (string) $logged);
unset($GLOBALS['lw_fail_posted']);
pin('a failure part way through rolls the listing back', array(true, 0, 0), array(
    $threwUp, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_description WHERE s_title = 'Half saved'")->fetch_row()[0],
    (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item i LEFT JOIN {$p}t_item_description d ON d.fk_i_item_id = i.pk_i_id WHERE d.fk_i_item_id IS NULL")->fetch_row()[0],
));

harness_section('a save rolled back sends no e-mail and leaves no photo behind');
osc_add_filter('init_send_mail', static fn ($mail) => new HeldMailer(true));
osc_add_hook('hook_email_item_validation', static function ($item): void {
    osc_sendMail(array('to' => 'sue@example.test', 'to_name' => 'Sue', 'subject' => 'Activate your listing', 'body' => 'Click.', 'layout' => false));
});
$uploads = static function () use ($lwRoot): int {
    $n = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lwRoot . 'uploads/', FilesystemIterator::SKIP_DOTS)) as $f) {
        $n += $f->isFile() ? 1 : 0;
    }

    return $n;
};
Preference::getInstance()->set('moderate_items', '0');
Preference::getInstance()->set('mailserver_mail_from', 'site@example.test');
osc_reset_preferences();
$token  = (string) $call('POST', 'photos', null, $sueToken, array(), $photoFile($jpeg))->body()['data']['token'];
$before = $uploads();
$fired  = array();
$GLOBALS['lw_fail_posted'] = true;
try {
    $call('POST', 'listings', $listing(array('title' => 'Rolled back with a photo', 'photo_tokens' => array($token))), $sueToken);
} catch (RuntimeException $e) {
}
unset($GLOBALS['lw_fail_posted']);
pin('the e-mail was written but not sent', array(1, 1, array()), array($fired['hook_email_item_validation'] ?? 0, $fired['uploaded_file'] ?? 0, HeldMailer::$sent));
pin('the photo\'s copies in the uploads folder are removed', $before, $uploads());
$r = $call('POST', 'listings', $listing(array('title' => 'Saved with a photo', 'photo_tokens' => array($token))), $sueToken);
pin('the staged photo is still there, so its token works again', array(201, 1), array($r->status(), count($r->body()['data']['photos'] ?? array())));
pin('the e-mail goes out once the save is committed', array('Activate your listing'), HeldMailer::$sent);
Preference::getInstance()->set('moderate_items', '-1');
osc_reset_preferences();

harness_section('bodies');
$caps   = array();
$reader = static function (int $cap) use (&$caps, $jpeg): ?string {
    $caps[] = $cap;

    return (string) file_get_contents($jpeg);
};
$r = $call('POST', 'photos', null, $sueToken, array('Content-Type' => 'image/jpeg'), array(), null, $reader);
pin('an upload route reads up to 16 MB', array(200, array(Request::MAX_UPLOAD)), array($r->status(), $caps));
$caps = array();
pin('any other route reads 1 MB at most, even for an image', array(415, array(Request::MAX_BODY)), array(
    $call('POST', 'listings', null, $sueToken, array('Content-Type' => 'image/jpeg'), array(), null, $reader)->status(), $caps,
));
$r = $call('PATCH', 'listings/' . $foreign, null, $sueToken, array('Content-Type' => Request::MERGE_PATCH), array(), (string) json_encode(array('price' => '77')));
pin('PATCH takes a JSON Merge Patch and says so', array(200, '77.00', Request::MERGE_PATCH), array($r->status(), $r->body()['data']['price']['amount'] ?? null, $r->header('Accept-Patch')));
$r = $call('POST', 'listings', null, $sueToken, array('Content-Type' => Request::MERGE_PATCH), array(), (string) json_encode($listing()));
pin('POST does not', '415 unsupported_media_type', $code($r));

harness_section('the photo stage');
$stageRows = static fn (int $userId): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_upload_tmp WHERE s_token = 'api:$userId'")->fetch_row()[0];
$admin->query("DELETE FROM {$p}t_item_upload_tmp WHERE s_token = 'api:$tom'");
for ($i = 0; $i < PhotoStage::MAX_PENDING; $i++) {
    $admin->query("INSERT INTO {$p}t_item_upload_tmp (s_token, s_uuid, s_file, dt_date) VALUES ('api:$tom', '" . md5((string) $i) . "', 'none', NOW())");
}
$extra = $lwRoot . 'extra.jpg';
copy($jpeg, $extra);
try {
    (new PhotoStage($lwRoot . 'stage/', new SystemClock()))->stage($tom, new PhotoFile($extra, 'jpg'));
    $overflow = false;
} catch (OverflowException $e) {
    $overflow = true;
}
pin('one photo past the cap is taken back out, even when it raced the count', array(true, PhotoStage::MAX_PENDING), array($overflow, $stageRows($tom)));
pin('the API answers 422 limit for a photo past the cap', array(422, 'limit'), (static fn (Response $r): array => array($r->status(), $r->body()['errors'][0]['code'] ?? null))($call('POST', 'photos', null, $tomToken, array(), $photoFile($jpeg))));
$admin->query("DELETE FROM {$p}t_item_upload_tmp WHERE s_token = 'api:$tom'");
$legacy = ItemTmpUpload::getInstance();
$legacy->add('web-form', 'u1', 'a.jpg');
$legacy->add('web-form', 'u2', 'b.jpg');
pin('the legacy ItemTmpUpload model answers as before', array(true, false, false, 0, 1, 0, 1), array(
    $legacy->belongsToToken('web-form', 'a.jpg'), $legacy->belongsToToken('other', 'a.jpg'), $legacy->belongsToToken('', ''),
    $legacy->deleteByTokenFile('other', 'a.jpg'), $legacy->deleteByTokenFile('web-form', 'a.jpg'),
    $legacy->pruneBefore('2000-01-01 00:00:00'), $legacy->deleteByToken('web-form'),
));
$stageDir = $lwRoot . 'stage/';
foreach (array('kept.jpg', 'old.jpg') as $name) {
    copy($jpeg, $stageDir . $name);
}
$now = time();
\mindstellar\listing\UploadTmpStore::stage('web-form', 'u1', 'kept.jpg', $now);
\mindstellar\listing\UploadTmpStore::stage('web-form', 'u2', 'old.jpg', $now - \mindstellar\listing\UploadTmpStore::TTL - 1);
\mindstellar\listing\UploadTmpStore::stage('web-form', 'u3', 'gone.jpg', $now);
pin('the shared stage lists only the owner\'s unexpired files that still exist', array(array('u1'), array()), array(
    array_keys(\mindstellar\listing\UploadTmpStore::staged('web-form', array('u1', 'u2', 'u3'), $now, $stageDir)),
    \mindstellar\listing\UploadTmpStore::staged('other', array('u1'), $now, $stageDir),
));
pin('a staged file is discarded only by its owner, row and file together', array(false, true, true, false), array(
    \mindstellar\listing\UploadTmpStore::discard('other', 'kept.jpg', $stageDir),
    is_file($stageDir . 'kept.jpg') && \mindstellar\listing\UploadTmpStore::discard('web-form', 'kept.jpg', $stageDir),
    !is_file($stageDir . 'kept.jpg'),
    \mindstellar\listing\UploadTmpStore::owns('web-form', 'kept.jpg'),
));
\mindstellar\listing\UploadTmpStore::removeOwner('web-form');
@unlink($stageDir . 'old.jpg');
copy($jpeg, $stageDir . '../outside.jpg');
\mindstellar\listing\UploadTmpStore::add('web-form', 'u4', '../outside.jpg', date('Y-m-d H:i:s', $now));
pin('a file name that climbs out of the stage folder is neither listed nor deleted', array(array(), false, true), array(
    \mindstellar\listing\UploadTmpStore::staged('web-form', array('u4'), $now, $stageDir),
    \mindstellar\listing\UploadTmpStore::discard('web-form', '../outside.jpg', $stageDir),
    is_file($stageDir . '../outside.jpg'),
));
\mindstellar\listing\UploadTmpStore::removeOwner('web-form');
@unlink($stageDir . '../outside.jpg');
pin('stage counts each owner\'s files on their own', array(1, 1, 2), array(
    \mindstellar\listing\UploadTmpStore::stage('owner-a', 'a1', 'a1.jpg', $now),
    \mindstellar\listing\UploadTmpStore::stage('owner-b', 'b1', 'b1.jpg', $now),
    \mindstellar\listing\UploadTmpStore::stage('owner-a', 'a2', 'a2.jpg', $now),
));
\mindstellar\listing\UploadTmpStore::removeOwner('owner-a');
\mindstellar\listing\UploadTmpStore::removeOwner('owner-b');
$fetcher = ImageFetcher::curlOptions('https://photos.example.com/car.jpg', '93.184.216.34', 1024, fopen('php://memory', 'w'));
pin('a download never goes through a proxy, so it reaches the checked address', array('', '*'), array($fetcher[CURLOPT_PROXY] ?? null, $fetcher[CURLOPT_NOPROXY] ?? null));
pin('a download that crawls is dropped', array(ImageFetcher::LOW_SPEED, ImageFetcher::LOW_SPEED_TIME), array($fetcher[CURLOPT_LOW_SPEED_LIMIT] ?? null, $fetcher[CURLOPT_LOW_SPEED_TIME] ?? null));
$batches   = array();
$sideFetch = static function (array $jobs, int $max, int $timeout) use ($jpeg, &$batches): array {
    $batches[] = array(array_column($jobs, 'ip'), $timeout);
    $out       = array();
    foreach ($jobs as $key => $job) {
        $out[$key] = $key === 1 ? ImageFetcher::FAILED : (copy($jpeg, $job['file']) ? null : 'copy failed');
    }

    return $out;
};
$sideIntake = new \mindstellar\api\write\PhotoIntake(
    new PhotoStage($lwRoot . 'stage/', new SystemClock()),
    new ImageFetcher(new AddressGuard(static fn (string $host): array => $host === 'intranet.example.com' ? array('10.0.0.5') : array('93.184.216.34')), $sideFetch),
    api_test_limiter(static fn () => 1),
    new RatePolicy(new ApiSettings(true, photoUrls: true)),
    true,
    2048 * 1024
);
$problem = static function (callable $fn): ?array {
    try {
        $fn();
    } catch (\mindstellar\api\ProblemException $e) {
        return array($e->response()->status(), $e->response()->body()['errors'][0]['pointer'] ?? null, $e->response()->body()['errors'][0]['message'] ?? null);
    }

    return null;
};
$failed = $problem(static fn () => $sideIntake->batch(array('photo_urls' => array('https://photos.example.com/a.jpg', 'https://photos.example.com/b.jpg', 'https://photos.example.com/c.jpg')), $sue, null));
pin('URL downloads go to the fetcher in one batch, each pinned to its checked address, with one timeout each', array(array(array('93.184.216.34', '93.184.216.34', '93.184.216.34'), ImageFetcher::TIMEOUT)), $batches);
pin('a failed download is 422 at its pointer and says only that it failed', array(422, '/photo_urls/1', 'the photo could not be downloaded'), $failed);
$batches = array();
$refused = $problem(static fn () => $sideIntake->batch(array('photo_urls' => array('https://photos.example.com/a.jpg', 'https://intranet.example.com/b.jpg')), $sue, null));
pin('every address is checked before any download starts', array(array(422, '/photo_urls/1'), array()), array(array_slice((array) $refused, 0, 2), $batches));
$guarded = new ImageFetcher(new AddressGuard(static fn (string $host): array => $host === 'inside.example' ? array('10.0.0.5') : array()), static fn (): array => array());
pin('a private address and one that does not resolve get the same answer', array(1 => ImageFetcher::REFUSED, 2 => ImageFetcher::REFUSED), $guarded->fetchAll(array(1 => 'https://inside.example/a.jpg', 2 => 'https://nowhere.example/a.jpg'), array(1 => '/unused', 2 => '/unused'), 10));
pin('...and the API answers only that', 'the address is not one the site downloads from', $refused[2] ?? null);
$admin->query("DELETE FROM {$p}t_rate_counter");
$fetchBucket = (new RatePolicy(new ApiSettings(true, photoUrls: true)))->photoFetch($sue);
$realLimiter = RateLimiter::sampled(new SystemClock());
$qFetchCount = harness_query_count(static fn () => $realLimiter->hit($fetchBucket, true, 3));
pin('three fetches are counted in the fetch limit with one write', array(1, 3), array($qFetchCount, \mindstellar\security\RateLimit::count('api_photo_fetch', (string) $sue, 3600)));
$admin->query("DELETE FROM {$p}t_rate_counter");
$settings              = new ApiSettings(true, userKeys: true, photoUrls: true);
$GLOBALS['lw_limiter'] = $realLimiter;
$threeUrls             = array('https://photos.example.com/a.jpg', 'https://photos.example.com/b.jpg', 'https://photos.example.com/c.jpg');
$r = $call('POST', 'listings', $listing(array('title' => 'Three by URL', 'photo_urls' => $threeUrls)), $sueToken);
pin('a listing posted with three photo URLs counts three fetches, not one', array(201, 3, 3), array(
    $r->status(), count($r->body()['data']['photos'] ?? array()), \mindstellar\security\RateLimit::count('api_photo_fetch', (string) $sue, 3600),
));
\mindstellar\security\RateLimit::add('api_photo_fetch', (string) $sue, RatePolicy::PHOTO_FETCHES_PER_HOUR - 5, 3600);
pin('three photo URLs with two fetches left in the hour are 429', '429 rate_limited', $code($call('POST', 'listings', $listing(array('title' => 'One too many by URL', 'photo_urls' => $threeUrls)), $sueToken)));
unset($GLOBALS['lw_limiter']);
$settings = new ApiSettings(true, userKeys: true);
$admin->query("DELETE FROM {$p}t_rate_counter");

harness_section('queries');
$qMade = 0;
$qPost = harness_query_count(static function () use ($call, $listing, $sueToken, &$qMade): void {
    $qMade = (int) ($call('POST', 'listings', $listing(array('title' => 'Counted car')), $sueToken)->body()['data']['id'] ?? 0);
});
$qPatch = harness_query_count(static fn () => $call('PATCH', 'listings/' . $qMade, array('price' => '999'), $sueToken));
echo "  POST /listings: $qPost queries, PATCH: $qPatch\n";
pin('POST /listings, no photos: 25 queries (one checks the sign-in is live, one checks the places and one reads them; ban rules come from the cache)', 25, $qPost);
pin('PATCH /listings/{id}, no photos: 19 queries (an unchanged location and custom field are not rewritten; edited_item reuses the locked row and the texts)', 19, $qPatch);
$qGet     = harness_query_count(static fn () => $call('GET', 'listings/' . $qMade, null, $sueToken));
$qEtag    = (string) $call('GET', 'listings/' . $qMade, null, $sueToken)->header('ETag');
$qMatched = harness_query_count(static fn () => $call('PATCH', 'listings/' . $qMade, array('price' => '998'), $sueToken, array('If-Match' => $qEtag)));
echo "  GET /listings/{id} as its owner: $qGet queries, PATCH with If-Match: $qMatched\n";
pin('GET /listings/{id} as its owner: 6 queries (sign-in, row version for the ETag, t_item, texts, stats and location, photos; the seller is the signed-in user)', 6, $qGet);
pin('PATCH with If-Match: 28 queries, the 19 plus the owner check, 5 locked row hashes (photos too), the new version and the outer transaction', 28, $qMatched);

$writes = static function (): array {
    $db  = DBConnectionClass::newInstance()->getOsclassDb();
    $out = array();
    foreach (array('Com_update', 'Com_replace') as $name) {
        $out[$name] = (int) ($db->query("SHOW SESSION STATUS LIKE '$name'")->fetch_assoc()['Value'] ?? -1);
    }

    return $out;
};
$delta = static function (array $before) use ($writes): array {
    $after = $writes();

    return array($after['Com_update'] - $before['Com_update'], $after['Com_replace'] - $before['Com_replace']);
};
$before = $writes();
$call('PATCH', 'listings/' . $qMade, array('price' => '997'), $sueToken);
pin('a price edit updates t_item only: the unchanged location and custom field are not rewritten', array(1, 0), $delta($before));
$before = $writes();
$r = $call('PATCH', 'listings/' . $qMade, array('address' => '9 New Road', 'custom_fields' => array((string) $colour => 'blue')), $sueToken);
pin('a changed address and custom field are written', array(2, 1, '9 New Road'), array_merge($delta($before), array($r->body()['data']['location']['address'] ?? null)));
pin('and stored', 'blue', $admin->query("SELECT s_value FROM {$p}t_item_meta WHERE fk_i_item_id = $qMade AND fk_i_field_id = $colour")->fetch_assoc()['s_value'] ?? null);

harness_section('deleting a listing');
pin('another seller cannot delete it', '403 not_owner', $code($call('DELETE', 'listings/' . $made, null, $tomToken)));
$writer = (new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock()))->create('key', 'writer', array('listings:write'), KeyOwner::user($sue))->token();
pin('listings:write alone may not delete', '403 insufficient_scope', $code($call('DELETE', 'listings/' . $made, null, $writer)));
$fired = array();
pin('the owner deletes it: 204', 204, $call('DELETE', 'listings/' . $made, null, $sueToken)->status());
pin('the row is gone and the hooks fired once', array(null, 1, 1), array($itemRow($made), $fired['before_delete_item'] ?? 0, $fired['after_delete_item'] ?? 0));
pin('again it is 404', 404, $call('DELETE', 'listings/' . $made, null, $sueToken)->status());

harness_section('comments');
$fired = array();
$r     = $call('POST', 'listings/' . $withPhoto . '/comments', array('title' => 'Hi', 'body' => 'Is it still for sale?'), $tomToken);
$commentId = (int) ($r->body()['data']['id'] ?? 0);
pin('a comment is posted through the form\'s action', array(201, 'Is it still for sale?', $tom, 1), array($r->status(), $r->body()['data']['body'] ?? null, $r->body()['data']['author']['user_id'] ?? null, $fired['add_comment'] ?? 0));
pin('after the hook anti-spam plugins use, with a Location', array(1, 'http://localhost/api/v1/comments/' . $commentId), array($fired['pre_item_add_comment_post'] ?? 0, $r->header('Location')));
pin('matches the schema', array(), $schemaErrors('SavedComment', $r));
$one = $call('GET', 'comments/' . $commentId, null, $sueToken);
pin('Location can be read', array(200, $commentId, 'Is it still for sale?'), array($one->status(), $one->body()['data']['id'] ?? null, $one->body()['data']['body'] ?? null));
pin('matches the schema', array(), $schemaErrors('CommentDocument', $one));
Preference::getInstance()->set('moderate_comments', '0');
osc_reset_preferences();
$r = $call('POST', 'listings/' . $withPhoto . '/comments', array('body' => 'Second question'), $tomToken);
$waiting = (int) ($r->body()['data']['id'] ?? 0);
pin('a comment waiting for approval: its author reads it, others get 404', array(200, 404), array($call('GET', 'comments/' . $waiting, null, $tomToken)->status(), $call('GET', 'comments/' . $waiting, null, $sueToken)->status()));
pin('with moderation on it waits, and says so', array(201, 'comment_pending', '0'), array(
    $r->status(), $r->body()['warnings'][0]['code'] ?? null, $admin->query("SELECT b_active FROM {$p}t_item_comment WHERE pk_i_id = " . (int) $r->body()['data']['id'])->fetch_row()[0],
));
Preference::getInstance()->set('moderate_comments', '-1');
pin('an empty body is 422', 422, $call('POST', 'listings/' . $withPhoto . '/comments', array('body' => '<b></b>'), $tomToken)->status());
pin('a pending listing takes no comments from others', 404, $call('POST', 'listings/' . $pendingId . '/comments', array('body' => 'Hello'), $tomToken)->status());
Preference::getInstance()->set('enabled_comments', '0');
osc_reset_preferences();
pin('with comments off it is 403', '403 feature_disabled', $code($call('POST', 'listings/' . $withPhoto . '/comments', array('body' => 'Hello'), $tomToken)));
Preference::getInstance()->set('enabled_comments', '1');
osc_reset_preferences();
pin('only the author deletes a comment', '403 not_owner', $code($call('DELETE', 'comments/' . $commentId, null, $sueToken)));
$fired = array();
pin('the author does: 204', array(204, 1), array($call('DELETE', 'comments/' . $commentId, null, $tomToken)->status(), $fired['pre_item_delete_comment_post'] ?? 0));
pin('a deleted comment is gone from the table', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_comment WHERE pk_i_id = $commentId")->fetch_row()[0]);

harness_section('saved searches');
$r = $call('POST', 'account/alerts', array('filters' => array('category' => array($cars), 'q' => 'hatchback', 'with_photos' => true)), $sueToken);
$alertId = (int) ($r->body()['data']['id'] ?? 0);
pin('an alert is saved from GET /listings filters', array(201, array($cars), 'hatchback', true, true), array(
    $r->status(), $r->body()['data']['filters']['category'] ?? null, $r->body()['data']['filters']['q'] ?? null, $r->body()['data']['filters']['with_photos'] ?? null, $r->body()['data']['active'] ?? null,
));
pin('matches the schema', array(), $schemaErrors('AlertDocument', $r));
pin('Location names it', 'http://localhost/api/v1/account/alerts/' . $alertId, $r->header('Location'));
pin('a created alert can be read at its Location', array(200, $alertId), (static fn (Response $one): array => array($one->status(), $one->body()['data']['id'] ?? null))($call('GET', 'account/alerts/' . $alertId, null, $sueToken)));
pin('by its owner only', 404, $call('GET', 'account/alerts/' . $alertId, null, $tomToken)->status());
pin('an alert made through the API is the account page alert too', array($alertId), array_map('intval', array_column(Alerts::getInstance()->findByUser($sue), 'pk_i_id')));
$again = $call('POST', 'account/alerts', array('filters' => array('q' => 'hatchback', 'category' => (string) $cars, 'with_photos' => true)), $sueToken);
pin('the same search again answers the existing alert', array(200, $alertId), array($again->status(), $again->body()['data']['id'] ?? null));
$list = $call('GET', 'account/alerts', null, $sueToken);
pin('GET lists it', array(200, array($alertId)), array($list->status(), array_column($list->body()['data'] ?? array(), 'id')));
pin('matches the schema', array(), $schemaErrors('AlertList', $list));
pin('no filter at all is 422', 422, $call('POST', 'account/alerts', array('filters' => array()), $sueToken)->status());
pin('an unknown category is 422', 422, $call('POST', 'account/alerts', array('filters' => array('category' => 'nope')), $sueToken)->status());
pin('an unknown filter is 422', 422, $call('POST', 'account/alerts', array('filters' => array('colour' => 'red')), $sueToken)->status());
pin('another user cannot stop it', 404, $call('DELETE', 'account/alerts/' . $alertId, null, $tomToken)->status());
pin('its owner can', 204, $call('DELETE', 'account/alerts/' . $alertId, null, $sueToken)->status());
pin('a deleted alert is no longer listed', array(), $call('GET', 'account/alerts', null, $sueToken)->body()['data']);
Preference::getInstance()->set('alerts_max_per_user', '2');
osc_reset_preferences();
$saveAlert = static fn (string $q): Response => $call('POST', 'account/alerts', array('filters' => array('q' => $q)), $sueToken);
pin('up to the site\'s number of saved searches is fine', array(201, 201), array($saveAlert('one')->status(), $saveAlert('two')->status()));
$over = $saveAlert('three');
pin('one more is 422 at the root, saying why', array(422, '/', 'maxItems'), array($over->status(), $over->body()['errors'][0]['pointer'] ?? null, $over->body()['errors'][0]['code'] ?? null));
pin('a refused alert is not saved', 2, count(Alerts::getInstance()->findByUser($sue)));
pin('the same search again still answers the one there', 200, $saveAlert('two')->status());
$webToken = base64_encode(osc_encrypt_alert(\mindstellar\search\AlertEnvelope::fromValues(array('sPattern' => 'four'), array('sPattern' => 'four'))));
View::getInstance()->_exportVariableToView('_loggedUser', $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $sue")->fetch_assoc());
pin('the search page\'s form gets its "too many" answer, -5', array(-5, 2), array(osc_subscribe_alert($webToken, ''), count(Alerts::getInstance()->findByUser($sue))));
$first = (int) Alerts::getInstance()->findByUser($sue)[0]['pk_i_id'];
$call('DELETE', 'account/alerts/' . $first, null, $sueToken);
pin('after deleting one, the form saves again', 1, osc_subscribe_alert($webToken, ''));
View::getInstance()->_exportVariableToView('_loggedUser', null);
Preference::getInstance()->set('alerts_max_per_user', '0');
osc_reset_preferences();
pin('0 means no limit', 201, $saveAlert('five')->status());

harness_section('the account\'s API access page');
$pageFor = static function (ApiSettings $settings) use ($facts): AccountAccess {

    return (new ApiServices($settings, new Scopes(), new ApiCredential(), new UserRows(), new SystemClock(), RateLimiter::fromSite(new SystemClock()), $facts, new WriteLinks()))->access()->accountAccess();
};
$page     = $pageFor($settings);
$sessions = $page->sessions($sue);
$types    = array_count_values(array_column($sessions, 'type'));
check('the sessions list shows the sign-ins and the keys', ($types['token'] ?? 0) >= 1 && ($types['key'] ?? 0) >= 2);
check('no secret is in the list', !str_contains((string) json_encode($sessions), $readOnly) && !str_contains((string) json_encode($sessions), substr($sueToken, 4, 40)));
check('the menu entry shows for a user with access', $page->relevant($sue));
$userRow = $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $sue")->fetch_assoc();
$threw   = static function (callable $fn): string {
    try {
        $fn();
    } catch (\InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return '';
};
pin('making a key is off unless the site allows it', 'This site does not let users make API keys.', $threw(static fn () => $pageFor(new ApiSettings(true))->createKey($userRow, 'open sesame', 'Script', array('listings:read'), date('Y-m-d', time() + 86400 * 30))));
$settings = new ApiSettings(true, userKeys: true);
$page     = $pageFor($settings);
pin('a wrong password is refused', 'The password is not right.', $threw(static fn () => $page->createKey($userRow, 'wrong', 'Script', array('listings:read'), date('Y-m-d', time() + 86400 * 30))));
check('account:write cannot go on a key', $threw(static fn () => $page->createKey($userRow, 'open sesame', 'Script', array('account:write'), date('Y-m-d', time() + 86400 * 30))) !== '');
pin('an expiry past a year is refused', 'Choose an expiry date within a year.', $threw(static fn () => $page->createKey($userRow, 'open sesame', 'Script', array('listings:read'), date('Y-m-d', time() + 86400 * 400))));
$made = $page->createKey($userRow, 'open sesame', 'Backup script', array('listings:read', 'account:read'), date('Y-m-d', time() + 86400 * 30));
pin('a key made on the page works on the API', 200, $call('GET', 'account', null, $made)->status());
$keyRow = array_values(array_filter($page->sessions($sue), static fn (array $s): bool => $s['label'] === 'Backup script'))[0] ?? array('id' => '');
check('a key made on the page is listed by its prefix', str_starts_with($made, (string) ($keyRow['prefix'] ?? '-')));
pin('revoking it from the page ends it', array(true, 401), array($page->end($sue, $keyRow['id']), $call('GET', 'account', null, $made)->status()));
$family = array_values(array_filter($page->sessions($sue), static fn (array $s): bool => $s['type'] === 'token'))[0]['id'] ?? '';
check('another user cannot end it', !($pageFor($settings)->end($tom, $family)));
pin('ending a sign-in revokes its refresh family', array(true, 0), array(
    $page->end($sue, $family), (int) $admin->query("SELECT COUNT(*) FROM {$p}t_api_credential WHERE s_family = '" . $admin->real_escape_string($family) . "' AND dt_revoked IS NULL")->fetch_row()[0],
));
check('an unknown session is refused', !($page->end($sue, 'nope')));
$settings = new ApiSettings(true, userKeys: true);
$web      = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebUser.php');
preg_match("/case 'api_access_post':.*?break;/s", $web, $postCase);
check('the page\'s POST checks the CSRF token', isset($postCase[0]) && str_contains($postCase[0], 'osc_csrf_check()'));
preg_match('/private function apiAccessPost\(.*?\n    }\n/s', $web, $postMethod);
check('the key page does nothing while the API is off', isset($postMethod[0]) && str_contains($postMethod[0], 'if (!osc_api_enabled())'));
$partial = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/gui/account/user-api_access-content.php');
pin('every form on the page posts the CSRF token', substr_count($partial, '<form'), substr_count($partial, 'osc_csrf_token_form()'));
check('no session was started', session_status() !== PHP_SESSION_ACTIVE);

exit(harness_result());
