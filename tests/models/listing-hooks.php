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
 * Posting, editing and deleting a listing fire the same hooks in the same order from the web
 * path (ItemActions, as CWebItem calls it) and from the API.
 *
 * Usage:  php tests/models/listing-hooks.php        (standalone, own scratch database)
 *         php tests/run-models.php listing-hooks    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-doubles.php';

// This file loads page helpers that earlier files in the suite stub, so under the runner it
// runs in a process of its own.
if (defined('MODELS_RUNNER')) {
    $lhOut = array();
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1', $lhOut, $lhCode);
    $lhOut = implode("\n", $lhOut);
    echo $lhOut, "\n";
    $lhFound = preg_match('/RESULT: (\d+) passed, (\d+) failed/', $lhOut, $lhM) === 1;
    $lhFail  = $lhFound ? (int)$lhM[2] : 0;
    if (!$lhFound || ($lhCode !== 0 && $lhFail === 0)) {
        $lhFail = max(1, $lhFail);
    }
    $GLOBALS['okCount']   += $lhFound ? (int)$lhM[1] : 0;
    $GLOBALS['failCount'] += $lhFail;
    if ($lhFail > 0) {
        $GLOBALS['failLabels'][] = 'listing-hooks: ' . $lhFail . ' failed (exit ' . $lhCode . ')';
    }

    return;
}

$lhRoot = sys_get_temp_dir() . '/osc-listing-hooks-' . getmypid() . '/';
@mkdir($lhRoot . 'uploads/', 0777, true);
@mkdir($lhRoot . 'stage/', 0777, true);
define('UPLOADS_PATH', $lhRoot . 'uploads/');
register_shutdown_function(static function () use ($lhRoot): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lhRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($lhRoot);
});

require_once __DIR__ . '/../lib/scratchdb.php';

$admin = scratchdb_session('osc_models_listing_hooks');

foreach (array(
    'OSC_CACHE_TTL'   => 60,
    'WEB_PATH'        => 'http://localhost/',
    'REL_WEB_URL'     => '/',
    'PLUGINS_PATH'    => ABS_PATH . 'oc-content/plugins/',
    'OC_ADMIN'        => false,
    'OSC_DEBUG'       => false,
    'OSC_CSRF_SECRET' => 'listing-hooks-test-secret',
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
        return $GLOBALS['lhRoot'];
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
use mindstellar\api\read\SiteFacts;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\schema\Schema;
use mindstellar\api\schema\Validator;
use mindstellar\api\serializer\Links;
use mindstellar\api\write\ImageFetcher;
use mindstellar\api\write\PhotoStage;
use mindstellar\apiaccess\ApiSettings;
use mindstellar\apiaccess\Scopes;
use mindstellar\auth\Actor;
use mindstellar\listing\PhotoService;
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

$jpeg = $lhRoot . 'car.jpg';
$img  = imagecreatetruecolor(64, 48);
imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 200, 30, 30));
imagejpeg($img, $jpeg, 80);
$fake = $lhRoot . 'fake.jpg';
file_put_contents($fake, "<?php echo 'not an image';");

/* ----------------------------------------------------------------------------
 * The kernel, wired as ApiServices wires the site's, one per request.
 * ------------------------------------------------------------------------- */
$validator = new Validator(Schema::components());
$facts     = new SiteFacts('en_US', array('en_US' => array('name' => 'English', 'direction' => 'ltr')));
$settings  = new ApiSettings(true, userKeys: true);
$fetches   = 0;
$transport = static function (string $url, string $ip, string $file, int $max) use ($jpeg, &$fetches): ?string {
    $fetches++;

    return copy($jpeg, $file) ? null : 'copy failed';
};
$call = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $headers = array(), array $files = array(), ?string $raw = null, ?Closure $reader = null) use ($validator, $facts, &$settings, $lhRoot, $transport): Response {
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
        new PhotoStage($lhRoot . 'stage/', new SystemClock()),
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

    return $kernel->handle(new Request($method, 'v1/' . $path, array(), $headers, '192.0.2.60', $content, $files, $reader));
};
$login = static fn (string $user): string => (string) ($call('POST', 'auth/token', array('grant_type' => 'password', 'username' => $user, 'password' => 'open sesame'))->body()['access_token'] ?? '');
$code  = static fn (Response $r): string => $r->status() . ' ' . (string) ($r->body()['code'] ?? '');
$first = static fn (Response $r): string => (string) ($r->body()['errors'][0]['message'] ?? '');
$schemaErrors = static fn (string $schema, Response $r): array => $validator->check(Schema::ref($schema), $r->body());
$itemRow = static fn (int $id): ?array => $admin->query("SELECT * FROM {$p}t_item WHERE pk_i_id = $id")->fetch_assoc();
$photoFile = static function (string $path) use ($lhRoot): array {
    $copy = $lhRoot . 'up-' . bin2hex(random_bytes(4));
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

/** Every core hook and filter, recorded in the order it runs. */
$trace = null;
foreach (file(dirname(__DIR__) . '/fixtures/hook-names.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $name = (string) substr($line, (int) strpos($line, ' ') + 1);
    if (str_starts_with($name, 'api_') || str_starts_with($name, 'image_') || in_array($name, array('init', 'before_html', 'after_html'), true)) {
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
    $trace = array();
    try {
        $fn();
    } finally {
        $seen  = $trace;
        $trace = null;
    }

    return $seen;
};
$asUser = static function (?int $userId): void {
    WebIdentity::forget();
    Params::init();
    if ($userId !== null) {
        Session::getInstance()->_setEphemeral('userId', (string) $userId);
    }
};
$webForm = static fn (array $extra = array()): array => $extra + array(
    'catId'        => (string) $cars,
    'countryId'    => 'US',
    'regionId'     => (string) $region,
    'cityId'       => (string) $city,
    'title'        => array('en_US' => 'Red hatchback'),
    'description'  => array('en_US' => 'A small red car, one owner, full service history.'),
    'price'        => '1500.50',
    'currency'     => 'USD',
    'contactPhone' => '5550199',
    'meta'         => array((string) $colour => 'red'),
);
$sueToken = $login('sue');

harness_section('posting');
$webMade = 0;
$webPost = $record(static function () use ($asUser, $sue, $webForm, &$webMade): void {
    $asUser($sue);
    $actions = new ItemActions(false);
    $actions->prepareDataFrom($webForm(), true);
    $actions->add();
    $webMade = $actions->lastItemId();
});
$asUser(null);
check('the web path made a listing', $webMade > 0);
$apiMade = 0;
$apiPost = $record(static function () use ($call, $listing, $sueToken, &$apiMade): void {
    $apiMade = (int) ($call('POST', 'listings', $listing(), $sueToken)->body()['data']['id'] ?? 0);
});
check('the API made a listing', $apiMade > 0);
pin('the web post fires these, in order', array(
    'item_prepare_data', 'item_add_prepare_data', 'pre_item_add', 'pre_item_add_error', 'item_increase_stat', 'posted_item',
), $webPost);
pin('the API post fires the same, in the same order', $webPost, $apiPost);

harness_section('posting with a photo');
$photoCopy = static function () use ($jpeg, $lhRoot): string {
    $copy = $lhRoot . 'web-' . bin2hex(random_bytes(4)) . '.jpg';
    copy($jpeg, $copy);

    return $copy;
};
$webPhotoPost = $record(static function () use ($asUser, $sue, $webForm, $photoCopy): void {
    $asUser($sue);
    $actions = new ItemActions(false);
    $actions->prepareDataFrom($webForm(array('photos' => array($photoCopy()))), true);
    $actions->add();
});
$asUser(null);
$staged       = (string) ($call('POST', 'photos', null, $sueToken, array(), $photoFile($jpeg))->body()['data']['token'] ?? '');
$apiPhotoPost = $record(static fn () => $call('POST', 'listings', $listing(array('photo_tokens' => array($staged))), $sueToken));
pin('the web post with a photo resizes it before the save and stores it before the stats', array(
    'item_prepare_data', 'upload_image_extension', 'upload_image_mime', 'item_add_prepare_data', 'pre_item_add', 'pre_item_add_error', 'uploaded_file', 'invalidate_item_cache', 'item_increase_stat', 'posted_item',
), $webPhotoPost);
pin('the API post with a photo fires the same, in the same order', $webPhotoPost, $apiPhotoPost);

harness_section('editing');
$secretOf = static fn (int $id): string => (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = $id")->fetch_row()[0];
$webEdit = $record(static function () use ($asUser, $sue, $webForm, $webMade, $secretOf): void {
    $asUser($sue);
    $actions = new ItemActions(false);
    $actions->prepareDataFrom($webForm(array('id' => (string) $webMade, 'secret' => $secretOf($webMade), 'price' => '1200')), false);
    $actions->edit();
});
$asUser($sue);
$halfId = new ItemActions(false);
$halfId->prepareDataFrom($webForm(array('id' => $webMade . '.9', 'secret' => $secretOf($webMade))), false);
pin('an id like "12.9" is read as 12, the listing the owner check passed, not rounded to 13 by MySQL', $webMade, $halfId->data['idItem']);
$asUser(null);
$apiEdit = $record(static fn () => $call('PATCH', 'listings/' . $apiMade, array('price' => '1200'), $sueToken));
pin('the web edit fires these, in order', array(
    'item_prepare_data', 'item_edit_prepare_data', 'pre_item_edit', 'pre_item_edit_error', 'item_content_updated', 'edited_item', 'invalidate_item_cache',
), $webEdit);
pin('the API edit fires the same, in the same order', $webEdit, $apiEdit);

harness_section('deleting');
$webDelete = $record(static function () use ($asUser, $sue, $webMade, $secretOf): void {
    $asUser($sue);
    (new ItemActions(false))->delete($secretOf($webMade), $webMade);
});
$asUser(null);
$apiDelete = $record(static fn () => $call('DELETE', 'listings/' . $apiMade, null, $sueToken));
pin('the web delete fires these, in order', array('before_delete_item', 'delete_item', 'after_delete_item', 'invalidate_item_cache'), $webDelete);
pin('the API delete fires the same, in the same order', $webDelete, $apiDelete);

harness_section('photos added to a saved listing');
$photoListing = (int) ($call('POST', 'listings', $listing(array('title' => 'Photo target')), $sueToken)->body()['data']['id'] ?? 0);
$asUploaded   = static function (string $path): array {
    return array('name' => array(basename($path)), 'type' => array('image/*'), 'tmp_name' => array($path), 'error' => array(UPLOAD_ERR_OK), 'size' => array((int) filesize($path)));
};
$webAdd = $record(static fn () => (new PhotoService())->add($photoListing, $asUploaded($photoCopy()), Actor::user($sue)));
$apiAdd = $record(static fn () => $call('POST', 'listings/' . $photoListing . '/photos', null, $sueToken, array(), $photoFile($jpeg)));
pin('adding a photo is an edit: uploaded_file, then edited_item', array('upload_image_extension', 'upload_image_mime', 'uploaded_file', 'invalidate_item_cache', 'edited_item', 'invalidate_item_cache'), $webAdd);
pin('the API fires the same, in the same order', $webAdd, $apiAdd);

harness_section('deleting a photo');
$photoIds = static fn (int $item): array => array_map('intval', array_column($admin->query("SELECT pk_i_id FROM {$p}t_item_resource WHERE fk_i_item_id = $item ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC), 'pk_i_id'));
$logged   = static fn (int $photo): array => $admin->query("SELECT s_section, s_action, s_who FROM {$p}t_log WHERE fk_i_id = $photo AND s_action = 'deleteResource'")->fetch_all(MYSQLI_ASSOC);
list($first, $second) = $photoIds($photoListing);
check('a photo is not deleted through another listing', !(new PhotoService())->delete($first, $photoListing + 1, Actor::user($sue)) && in_array($first, $photoIds($photoListing), true));
$webRemove = $record(static fn () => (new PhotoService())->delete($first, $photoListing, Actor::user($sue)));
$apiRemove = $record(static fn () => $call('DELETE', 'listings/' . $photoListing . '/photos/' . $second, null, $sueToken));
pin('one delete_resource per photo, then the row goes', array('delete_resource', 'invalidate_item_cache'), $webRemove);
pin('the API fires the same', $webRemove, $apiRemove);
pin('both are logged alike', array(array(array('s_section' => 'item', 's_action' => 'deleteResource', 's_who' => 'user')), array(array('s_section' => 'item', 's_action' => 'deleteResource', 's_who' => 'user'))), array($logged($first), $logged($second)));
pin('and both rows are gone', array(), $photoIds($photoListing));

harness_section('web writes hold their e-mails until the save commits');
osc_add_filter('init_send_mail', static fn ($mail) => new HeldMailer(true));
osc_add_hook('posted_item', static function ($item): void {
    osc_sendMail(array('to' => 'sue@example.test', 'to_name' => 'Sue', 'subject' => 'Posted ' . $item['pk_i_id'], 'body' => 'Posted.', 'layout' => false));
    if (!empty($GLOBALS['lh_fail_posted'])) {
        throw new RuntimeException('A plugin failed.');
    }
});
Preference::getInstance()->set('mailserver_mail_from', 'site@example.test');
osc_reset_preferences();
$titled = static fn (string $title): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_item_description WHERE s_title = '" . $admin->real_escape_string($title) . "'")->fetch_row()[0];
$webPost = static function (string $title) use ($asUser, $sue, $webForm): void {
    $asUser($sue);
    $actions = new ItemActions(false);
    $actions->prepareDataFrom($webForm(array('title' => array('en_US' => $title))), true);
    $actions->add();
};
HeldMailer::$sent = array();
$GLOBALS['lh_fail_posted'] = true;
try {
    $webPost('Web post that fails');
    $threw = false;
} catch (RuntimeException $e) {
    $threw = true;
}
unset($GLOBALS['lh_fail_posted']);
$asUser(null);
pin('a plugin failing in posted_item rolls the web post back and its e-mail is not sent', array(true, 0, array()), array($threw, $titled('Web post that fails'), HeldMailer::$sent));
$webPost('Web post that saves');
$asUser(null);
pin('a saved web post sends its e-mail once', 1, count(HeldMailer::$sent));

harness_section('photos are resized before the save transaction opens');
$resizedIn = array();
osc_add_filter('upload_image_extension', static function ($ext) use (&$resizedIn) {
    $resizedIn[] = \mindstellar\database\Db::inTransaction();

    return $ext;
});
osc_add_hook('pre_item_add', static function (): void {
    if (!empty($GLOBALS['lh_fail_pre'])) {
        throw new RuntimeException('A plugin failed.');
    }
});
$photoPost = static function (string $title, string $photo) use ($asUser, $sue, $webForm): bool {
    $asUser($sue);
    $actions = new ItemActions(false);
    $actions->prepareDataFrom($webForm(array('title' => array('en_US' => $title), 'photos' => array($photo))), true);
    try {
        $actions->add();

        return true;
    } catch (RuntimeException $e) {
        return false;
    } finally {
        $asUser(null);
    }
};
$saved = $photoCopy();
pin('a saved post resized its photo outside any transaction and left no variants behind', array(true, array(false), array()), array($photoPost('Photo post that saves', $saved), $resizedIn, glob($saved . '_*')));
foreach (array('lh_fail_pre' => 'Photo post refused early', 'lh_fail_posted' => 'Photo post rolled back') as $flag => $title) {
    $resizedIn       = array();
    $failed          = $photoCopy();
    $before          = glob(UPLOADS_PATH . '*/*');
    $GLOBALS[$flag]  = true;
    $ok              = $photoPost($title, $failed);
    unset($GLOBALS[$flag]);
    pin($title . ': the prepared variants and stored files are gone', array(false, array(false), 0, array(), array()), array($ok, $resizedIn, $titled($title), glob($failed . '_*'), array_values(array_diff(glob(UPLOADS_PATH . '*/*'), $before))));
}

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
