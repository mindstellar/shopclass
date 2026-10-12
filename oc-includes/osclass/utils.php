<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\utility\Deprecate;
use mindstellar\utility\FileSystem;
use mindstellar\utility\Utils;
use ReCaptcha\ReCaptcha;

/**
 * check if the item is expired
 *
 * @param string $dt_expiration Datetime the listing expires
 *
 * @return bool
 */
function osc_isExpired($dt_expiration)
{
    $now = date('YmdHis');

    $dt_expiration = str_replace(array(' ', '-', ':'), '', $dt_expiration);

    return !($dt_expiration > $now);
}

/**
 * Whether a listing counts toward the category, location and user totals: enabled,
 * active, not spam, and premium or not expired. The daily recount uses the same rule.
 *
 * @param array<string,mixed> $item
 *
 * @return bool
 */
function osc_item_is_counted(array $item): bool
{
    return \mindstellar\listing\ListingStatus::isLive($item);
}

/**
 * Remove resources from disk
 *
 * @param int|array<int,int>       $id       Resource id; an array uses its first element
 * @param bool                     $admin    Whether the deletion is being logged as an admin action
 * @param array<string,mixed>|null $resource Resource row the caller already read; looked up when omitted
 *
 * @return false|void False on a demo install, where nothing is deleted
 */
function osc_deleteResource($id, $admin, $resource = null)
{
    if (\mindstellar\security\Demo::active()) {
        return false;
    }
    if (is_array($id)) {
        $id = $id[0];
    }
    // $resource lets a caller hand over the row it already read: a listing delete unlinks
    // the files after its transaction removed the rows.
    if (!is_array($resource)) {
        $resource = ItemResource::getInstance()->findByPrimaryKey($id);
    }
    if (is_array($resource)) {
        $actor = \mindstellar\auth\Actor::fromSession((bool) $admin);
        (new \mindstellar\listing\PhotoService())->removeFiles($resource, $actor);
    }
}

/**
 * Tries to delete the directory recursively.
 *
 * @param string $path
 *
 * @return bool False on a path containing .., a path that is not a directory, or a failed removal
 */
function osc_deleteDir($path)
{
    return (new FileSystem())->deleteDir($path);
}

/**
 * Serialize the data (usefull at plugins activation)
 *
 * @param mixed $data
 *
 * @return mixed The serialized string, or the value unchanged when it is neither array nor object
 */
function osc_serialize($data)
{
    if (!is_serialized($data)) {
        if (is_array($data) || is_object($data)) {
            return serialize($data);
        }
    }

    return $data;
}

/**
 * Unserialize the data (usefull at plugins activation)
 *
 * @param mixed $data
 *
 * @return mixed The unserialized value, false on a malformed payload, or the value unchanged
 */
function osc_unserialize($data)
{
    if (is_serialized($data)) { // don't attempt to unserialize data that wasn't serialized going in
        return @unserialize($data);
    }

    return $data;
}

/**
 * Checks is $data is serialized or not
 *
 * @param mixed $data
 *
 * @return bool False if not serialized and true if it was.
 */
function is_serialized($data)
{
    // if it isn't a string, it isn't serialized
    if (!is_string($data)) {
        return false;
    }
    $data = trim($data);
    if ('N;' === $data) {
        return true;
    }
    if (!preg_match('/^([adObis]):/', $data, $badions)) {
        return false;
    }
    switch ($badions[1]) {
        case 'a':
        case 'O':
        case 's':
            if (preg_match("/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data)) {
                return true;
            }
            break;
        case 'b':
        case 'i':
        case 'd':
            if (preg_match("/^{$badions[1]}:[0-9.E-]+;\$/", $data)) {
                return true;
            }
            break;
    }

    return false;
}

/**
 * VERY BASIC
 * Perform a POST request, so we could launch fake-cron calls and other core-system calls without annoying the user
 *
 * @param string             $url
 * @param array<string,mixed> $_data
 *
 * @return bool|int False on error, or the number of bytes sent.
 */
function osc_doRequest($url, $_data)
{
    return Utils::doRequest($url, $_data);
}

// Old mail names kept for plugins; the code is in mindstellar\mail\Mailer. Edit the class, not these.

/**
 * Seconds PHPMailer may wait on one SMTP connect or command.
 *
 * @see \mindstellar\mail\Mailer::smtpTimeoutSeconds()
 */
function osc_phpmailer_smtp_timeout_seconds()
{
    return \mindstellar\mail\Mailer::smtpTimeoutSeconds();
}

/**
 * Apply {@see osc_phpmailer_smtp_timeout_seconds()} to a PHPMailer instance
 * and to the SMTP object it will use on send().
 *
 * @see \mindstellar\mail\Mailer::limitSmtpWait()
 */
function osc_phpmailer_limit_smtp_wait($mail)
{
    \mindstellar\mail\Mailer::limitSmtpWait($mail);
}

/**
 * A visitor's uploaded file, ready to attach to a mail straight from PHP's temporary upload.
 *
 * @see \mindstellar\mail\Mailer::uploadAttachment()
 */
function osc_mail_upload_attachment($field)
{
    return \mindstellar\mail\Mailer::uploadAttachment($field);
}

/**
 * Send one email through PHPMailer, using the site's configured mail transport.
 *
 * @see \mindstellar\mail\Mailer::send()
 */
function osc_sendMail($params)
{
    return \mindstellar\mail\Mailer::send($params);
}

/**
 * Keep mail links off an address taken from the request Host header. With OSC_CLI_URL set,
 * links to the request address point at it instead; without it, a mail that carries a secret
 * link (params 'secret_link') is refused.
 *
 * @see \mindstellar\mail\Mailer::linksTrusted()
 */
function osc_mail_links_trusted($mail, array $params = array()): bool
{
    return \mindstellar\mail\Mailer::linksTrusted($mail, $params);
}

/**
 * Expand the {PLACEHOLDER} tokens in an email body: the caller's pairs first, then
 * the site-wide ones.
 *
 * @see \mindstellar\mail\Mailer::beauty()
 */
function osc_mailBeauty($text, $params)
{
    return \mindstellar\mail\Mailer::beauty($text, $params);
}

/**
 * A plain-text copy of an HTML mail body.
 *
 * @see \mindstellar\mail\Mailer::plainText()
 */
function _osc_mail_text($html)
{
    return \mindstellar\mail\Mailer::plainText($html);
}

/**
 * The plain copy a mail is sent with.
 *
 * @see \mindstellar\mail\Mailer::altBody()
 */
function _osc_mail_alt_body(array $params)
{
    return \mindstellar\mail\Mailer::altBody($params);
}

/**
 * Wrap an e-mail body in the site's e-mail layout: a theme's templates/email-layout.php,
 * else core's. A body that is already a whole HTML document, or a send with
 * 'layout' => false, goes out as it is.
 *
 * @see \mindstellar\mail\Mailer::layout()
 */
function osc_mail_layout(string $body, array $params = array()): string
{
    return \mindstellar\mail\Mailer::layout($body, $params);
}

/**
 * The e-mail layout file: templates/email-layout.php in the active theme or its parent,
 * else core's own.
 *
 * @see \mindstellar\mail\Mailer::layoutFile()
 */
function osc_mail_layout_file(?array $bases = null): string
{
    return \mindstellar\mail\Mailer::layoutFile($bases);
}

/**
 * Create a directory, warning instead of throwing when it cannot be created.
 *
 * @param string $dir
 * @param int    $mode
 * @param bool   $recursive Accepted for backwards compatibility; the filesystem helper always recurses
 *
 * @return bool
 */
function osc_mkdir($dir, $mode = 0755, $recursive = true)
{

    try {
        (new FileSystem())->mkdir($dir, $mode);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }

    return true;
}

/**
 * Copy a file, warning instead of throwing on failure.
 *
 * @param string $source
 * @param string $dest
 *
 * @return bool
 */
function osc_copy($source, $dest)
{
    try {
        (new FileSystem())->copy($source, $dest);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }

    return true;
}

/**
 * Copy a file.
 *
 * @param string $file1
 * @param string $file2
 *
 * @return bool
 * @deprecated since 4.0.0, removed in 8.0; use copy()
 */
function osc_copyemz($file1, $file2)
{
    return @copy($file1, $file2);
}

/**
 * Whether a directory is writable. Before 7.0 it walked the whole tree.
 *
 * @param string $dir
 *
 * @return bool
 * @deprecated since 4.0.0, removed in 8.0; use is_writable()
 */
function osc_check_dir_writable($dir = ABS_PATH)
{
    return is_writable($dir);
}

/**
 * Make a directory writable (0755) when it is not. Before 7.0 it walked the whole tree.
 *
 * @param string $dir
 *
 * @return bool
 * @deprecated since 4.0.0, removed in 8.0; use chmod()
 */
function osc_change_permissions($dir = ABS_PATH)
{
    return is_writable($dir) || @chmod($dir, 0755);
}

/**
 * Dump osclass database into path file
 *
 * @param string $path Directory to write into, with a trailing separator
 * @param string $file Filename to write
 *
 * @return int 1 on success; -1 empty path, -2 no dump handle, -3 no tables, -4 path not writable
 */
function osc_dbdump($path, $file)
{
    if (!is_writable($path)) {
        return -4;
    }

    //checking connection
    $dump = Dump::getInstance();
    if (!$dump) {
        return -2;
    }

    $path       .= $file;
    $result     = $dump->showTables();
    $fileSystem = new FileSystem();
    if (!$result) {
        $_str = '';
        $_str .= '/* no tables in ' . DB_NAME . ' */';
        $_str .= "\n";

        try {
            $fileSystem->writeToFile($path, $_str, true);
        } catch (Exception $e) {
            trigger_error($e->getMessage(), E_USER_WARNING);
        }

        return -3;
    }

    $_str =
        '/* OSCLASS MYSQL Autobackup ('
        . date(osc_date_format() ?: 'Y-m-d')
        . ' '
        . date(osc_time_format() ?: 'H:i:s')
        . ') */' . "\n";

    try {
        $fileSystem->writeToFile($path, $_str, true);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);
    }

    $tables = array();
    foreach ($result as $_table) {
        $tables[] = current($_table);
    }

    // Default Shopclass tables first, in order, so no problem when importing them back.
    foreach (\mindstellar\backup\DatabaseDump::order($tables, DB_TABLE_PREFIX) as $table) {
        $dump->table_structure($path, $table);
        $dump->table_data($path, $table);
    }

    return 1;
}

/**
 * determine if a string can represent a number in hexadecimal
 *
 * @param string $hex
 *
 * @return boolean true if the string is a hex, otherwise false
 * @since 3.0
 *
 */
function is_hex($hex)
{
    // regex is for weenies
    $hex = strtolower(trim(ltrim($hex, '0')));
    if (empty($hex)) {
        $hex = 0;
    }
    $dec = hexdec($hex);

    return ($hex === dechex($dec));
}

/**
 * Download a URL to a local path, warning instead of throwing on failure.
 *
 * @param string                   $sourceFile     URL to fetch
 * @param string                   $downloadedFile Destination path
 * @param array<string,mixed>|null $post_data      POST body; null issues a GET
 *
 * @return bool False on a 404, a truncated transfer or a checksum mismatch
 */
function osc_downloadFile($sourceFile, $downloadedFile, $post_data = null)
{
    try {
        // downloadFile() reports a 404, a truncated transfer or a checksum mismatch by
        // returning false, so the return value has to be propagated, not discarded.
        return (bool) (new FileSystem())->downloadFile($sourceFile, $downloadedFile, $post_data);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }
}

/**
 * Shopclass file_get_contents implementation
 *
 * @param string                   $url
 * @param array<string,mixed>|null $post_data POST body; null issues a GET
 * @param bool $verify_ssl verify the peer's TLS certificate. Defaults to true so
 *                         every caller authenticates the peer; pass false only at
 *                         a call site that genuinely must talk to a bad cert.
 * @param int  $timeout    total transfer timeout in seconds; 0 leaves no overall
 *                         limit, but getContents() still aborts a stalled transfer.
 *
 * @return bool|string
 */
function osc_file_get_contents($url, $post_data = null, $verify_ssl = true, $timeout = 0)
{
    try {
        return (new FileSystem())->getContents($url, $post_data, $verify_ssl, (int)$timeout);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return false;
    }
}

/**
 * Check if we loaded some specific module of apache
 *
 * @param string $mod
 *
 * @return bool
 */
function apache_mod_loaded($mod)
{
    return Utils::apacheModLoaded($mod);
}

/**
 * Change version to param number
 *
 * @param string|null $version
 *
 * @return void
 */
function osc_changeVersionTo($version = null)
{
    Utils::changeOsclassVersionTo($version);
}

/**
 * Whether the in-app self-updater (downloading a package and overwriting core
 * files) is disabled for this installation. Set on immutable deployments — the
 * Docker image sets OSC_DISABLE_SELF_UPDATE=1 — where the running code is baked
 * into an image and a file-writing upgrade would be discarded on the next
 * redeploy while leaving the database ahead of the code. Those installs update
 * by deploying a newer image; the entrypoint's `db:upgrade` migrates the schema.
 *
 * @return bool
 */
function osc_self_update_disabled()
{
    // An edge image is updated by pulling the image again, never by a release zip.
    if (\mindstellar\upgrade\BuildInfo::isEdge()) {
        return true;
    }
    if (defined('OSC_DISABLE_SELF_UPDATE')) {
        return (bool) OSC_DISABLE_SELF_UPDATE;
    }

    return filter_var(getenv('OSC_DISABLE_SELF_UPDATE'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Whether installing/updating market packages (plugins/themes) is disabled for
 * this installation. Distinct from osc_self_update_disabled(): that flag stops
 * core from overwriting itself on an immutable deployment. Packages are not
 * core — on a deployment where oc-content is a persistent volume, a package
 * write survives a redeploy just fine, so this defaults to enabled and is only
 * set where the site owner has no persistent oc-content to write into.
 *
 * @return bool
 */
function osc_package_installs_disabled()
{
    if (defined('OSC_DISABLE_PACKAGE_INSTALLS')) {
        return (bool) OSC_DISABLE_PACKAGE_INSTALLS;
    }

    return filter_var(getenv('OSC_DISABLE_PACKAGE_INSTALLS'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Whether the admin may change plugins and themes from the market at all: not on a demo site,
 * and not where package installs are disabled.
 *
 * @return bool
 */
function osc_market_changes_blocked()
{
    return \mindstellar\security\Demo::active() || osc_package_installs_disabled();
}

/**
 * Whether restoring a backup from the admin is turned off (OSC_DISABLE_WEB_RESTORE in
 * config.php, or the environment). Making backups is not affected.
 *
 * @return bool
 */
function osc_web_restore_disabled()
{
    if (defined('OSC_DISABLE_WEB_RESTORE')) {
        return (bool) OSC_DISABLE_WEB_RESTORE;
    }

    return filter_var(getenv('OSC_DISABLE_WEB_RESTORE'), FILTER_VALIDATE_BOOLEAN);
}

/**
 * Strip backslashes from a string, or from every value of an array, recursively.
 *
 * @param string|array<mixed> $array
 *
 * @return string|array<mixed> Same shape as the input
 */
function strip_slashes_extended($array)
{
    return Utils::stripSlashesExtended($array);
}

/**
 * Unzip's a specified ZIP file to a location
 *
 * @param string $file Full path of the zip file
 * @param string $to   Full path where it is going to be unzipped
 *
 * @return int
 *  0 - destination folder not writable (or not exist and cannot be created)
 *  1 - everything was OK
 *  2 - zip is empty
 *  -1 : file could not be created (or error reading the file from the zip)
 */
function osc_unzip_file($file, $to)
{
    try {
        return (new \mindstellar\utility\Zip())->unzipFile($file, $to);
    } catch (Exception $e) {
        trigger_error($e->getMessage(), E_USER_WARNING);

        return 0;
    }
}

/**
 * Common interface to zip a specified folder to a file using ziparchive or pclzip
 *
 * @param string $archive_folder full path of the folder
 * @param string $archive_name   full path of the destination zip file
 *
 * @return bool
 */
function osc_zip_folder($archive_folder, $archive_name)
{
    return (new \mindstellar\utility\Zip())->zipFolder($archive_folder, $archive_name);
}

/**
 * Verify the reCAPTCHA token on the current POST request.
 *
 * @return bool False on a non-POST request, a missing token, or a failed verification
 */
function osc_check_recaptcha()
{
    if (strtoupper((string)Params::getServerParam('REQUEST_METHOD', false, false)) !== 'POST') {
        return false;
    }
    // Opaque token: skip HTMLPurifier (same idiom as installer passwords).
    $gReCaptchaResponse = Params::getParamString('g-recaptcha-response', false, false);
    if ($gReCaptchaResponse === '') {
        return false;
    }
    $recaptcha = new ReCaptcha(osc_recaptcha_private_key());
    $resp      = $recaptcha->verify($gReCaptchaResponse, Params::getServerParam('REMOTE_ADDR'));
    if ($resp->isSuccess()) {
        return true;
    }

    return false;
}

/**
 * Verifies the submitted captcha token for the active provider.
 *
 * reCAPTCHA leg delegates to osc_check_recaptcha(). Turnstile leg posts the
 * cf-turnstile-response token to Cloudflare's siteverify endpoint and fails
 * closed on every abnormal path: empty or oversize token, transport error,
 * timeout, non-JSON response, or a body whose success flag is not true.
 *
 * @return bool
 */
function osc_check_captcha()
{
    switch (osc_captcha_provider()) {
        case 'recaptcha':
            return osc_check_recaptcha();
        case 'turnstile':
            if (strtoupper((string)Params::getServerParam('REQUEST_METHOD', false, false)) !== 'POST') {
                return false;
            }
            $token = Params::getParamString('cf-turnstile-response', false, false);
            if ($token === '' || strlen($token) > 2048) {
                return false;
            }
            try {
                $raw = osc_file_get_contents(
                    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
                    array(
                        'secret'   => osc_turnstile_secret_key(),
                        'response' => $token,
                        'remoteip' => Params::getServerParam('REMOTE_ADDR'),
                    ),
                    true,
                    8
                );
            } catch (Exception $e) {
                return false;
            }
            if (!is_string($raw)) {
                return false;
            }
            $json = json_decode($raw, true);

            return is_array($json) && (($json['success'] ?? false) === true);
        default:
            return false;
    }
}

/**
 * replace double slash with single slash
 *
 * @param string $path
 *
 * @return string
 */
function osc_replace_double_slash($path)
{
    return Utils::replaceDoubleSlash($path);
}

/**
 * Format a stored integer price for display.
 *
 * @param int|string $price Price in the currency's smallest stored unit
 *
 * @return string
 */
function osc_prepare_price($price)
{
    return Utils::preparePrice($price);
}

/**
 * Recursive glob function
 *
 * @param string $pattern
 * @param int    $flags
 * @param string $path
 *
 * @return array<int,string> of files
 */
function rglob($pattern, $flags = 0, $path = '')
{
    if (!$path && ($dir = dirname($pattern)) !== '.') {
        if ($dir === '\\' || $dir === '/') {
            $dir = '';
        }

        return rglob(basename($pattern), $flags, $dir . '/');
    }
    $paths    = glob($path . '*', GLOB_ONLYDIR | GLOB_NOSORT);
    $files    = glob($path . $pattern, $flags);
    $fileList = [];
    foreach ($paths as $p) {
        $fileList[] = rglob($pattern, $flags, $p . '/');
    }
    $files = array_merge($files, ...$fileList);

    return $files;
}

/**
 * Whether a plugin's update URI advertises a version newer than the installed one.
 *
 * @param string      $update_uri
 * @param string|null $version Version currently installed
 *
 * @return bool Always false: the old market is gone.
 * @deprecated since 4.0.0 use mindstellar\market\PackageIndex::forPlugins()->pendingUpdates() instead
 * @see \mindstellar\market\PackageIndex::pendingUpdates()
 */
function osc_check_plugin_update($update_uri, $version = null)
{
    return false;
}

/**
 * Whether a theme's update URI advertises a version newer than the installed one.
 *
 * @param string      $update_uri
 * @param string|null $version Version currently installed
 *
 * @return bool Always false: the old market is gone.
 * @deprecated since 4.0.0 use mindstellar\market\PackageIndex::forThemes()->pendingUpdates() instead
 * @see \mindstellar\market\PackageIndex::pendingUpdates()
 */
function osc_check_theme_update($update_uri, $version = null)
{
    return false;
}

/**
 * Whether the translations repository has a newer version of an installed language.
 *
 * The published index describes every language at once, so it is read once per
 * request however many languages are installed -- the alternative was a request per
 * language, which is what made this worth disabling in the first place.
 *
 * Anything that stops the index being read means no update is offered: a site with no
 * outbound network is not a site with stale translations, it is a site that cannot be
 * told either way.
 *
 * @param string      $update_uri Locale code, e.g. 'fr_FR'
 * @param string|null $version    Version currently installed
 * @param bool        $disable    Kept for callers that passed it; true still short-circuits
 *
 * @return bool
 */
function osc_check_language_update($update_uri, $version = null, $disable = false)
{
    if ($disable) {
        return false;
    }

    static $published = null;

    if ($published === null) {
        $published = array();
        foreach (\mindstellar\language\LanguageService::published(static fn ($url) => @osc_file_get_contents($url)) ?? array() as $code => $locale) {
            if (isset($locale['version'])) {
                $published[$code] = (string) $locale['version'];
            }
        }
    }

    if ($version === null || !isset($published[$update_uri])) {
        return false;
    }

    return version_compare((string) $version, $published[$update_uri], '<');
}

/**
 * Update category stats
 *
 * @return void
 */
function osc_update_cat_stats()
{
    Utils::updateAllCategoriesStats();
}

/**
 * Recount items for a given a category id
 *
 * @param int $id
 *
 * @return void
 */
function osc_update_cat_stats_id($id)
{
    Utils::updateCategoryStatsById($id);
}

/**
 * Update locations stats. I moved this function from cron.daily.php:update_location_stats
 *
 * @param bool $force
 * @param int  $limit
 *
 * @return int
 * @since 3.1
 */
function osc_update_location_stats($force = false, $limit = 1000)
{
    return Utils::updateLocationStats($force, $limit);
}

/**
 * Translate current categories to new locale
 *
 * @param string $locale
 *
 * @return void
 */
function osc_translate_categories($locale)
{
    Utils::translateCategories($locale);
}

/**
 * The client's IP address for the current request.
 *
 * @return string
 */
function get_ip()
{
    return Utils::getClientIp();
}

/**
 * Flush pending flash messages, send a Location header and end the request.
 *
 * @param string   $url
 * @param int|null $code HTTP status to send with the redirect
 *
 * @return never
 */
function osc_redirect_to($url, $code = null)
{
    Utils::redirectTo($url, $code);
}

/**
 * Fill in the missing slugs for one location table, returning how many rows were updated.
 *
 * @param string $type One of country, region or city
 *
 * @return int|false False when $type is not a known location type
 */
function osc_calculate_location_slug($type)
{
    return Utils::calculateLocationSlug($type);
}

/**
 * Drop null and empty elements from an array in place, recursively.
 *
 * @param array<mixed> $input Modified by reference
 *
 * @return void
 */
function osc_prune_array(&$input)
{
    Utils::pruneArray($input);
}

/**
 * Whether a package's published descriptor lists the running core version as compatible.
 *
 * @param string $section         Market section, e.g. plugins or themes
 * @param string $element         URL of the package's JSON descriptor
 * @param string $osclass_version Core version to test against
 *
 * @return bool
 */
function osc_is_update_compatible($section, $element, $osclass_version = OSCLASS_VERSION)
{
    if ($element != '') {
        $data = array();
        $data = json_decode(osc_file_get_contents($element), true);
        if (isset($data['s_compatible'])) {
            $versions = explode(',', $data['s_compatible']);

            foreach ($versions as $_version) {
                if (version_compare((string) $osclass_version, trim($_version), '<=')) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Whether the current request arrived over HTTPS.
 *
 * @return bool
 */
function osc_is_ssl()
{
    return Utils::isSsl();
}

if (!function_exists('hex2b64')) {

    /**
     * Used to encode a field for Amazon Auth
     * (taken from the Amazon S3 PHP example library)
     *
     * @param string $str Hex string to re-encode
     *
     * @return string
     * @deprecated since 4.0.0
     * @see Utils::hex2b64()
     */
    function hex2b64($str)
    {
        Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'Utils::hex2b64()');

        return Utils::hex2b64($str);
    }
}

if (!function_exists('hmacsha1')) {
    /**
     * Calculate HMAC-SHA1 according to RFC2104
     * See http://www.faqs.org/rfcs/rfc2104.html
     *
     * @param string $key
     * @param string $data
     *
     * @return string
     * @deprecated since 4.0.0
     * @see Utils::hmacsha1()
     */
    function hmacsha1($key, $data)
    {
        Deprecate::deprecatedFunction(__FUNCTION__, '4.0.0', 'Utils::hmacsha1()');

        return Utils::hmacsha1($key, $data);
    }
}
