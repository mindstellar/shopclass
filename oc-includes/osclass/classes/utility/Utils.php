<?php
/**
 * Created by Navjot Tomer (Mindstellar).
 * User: navjottomer
 * Date: 08/05/20
 * Time: 6:26 PM
 * License is provided in root directory.
 */

namespace mindstellar\utility;

use Category;
use City;
use Country;
use DateTimeZone;
use Item;
use mindstellar\job\JobWorker;
use mindstellar\location\LocationRecountJobs;
use Params;
use Preference;
use Region;
use Rewrite;
use RuntimeException;
use Session;
use Translation;

/**
 * Class Utils
 * Utility class contains some useful static methods
 * Most functions derived from old Utils file, some of them may have been tweaked
 *
 * @package mindstellar\utility
 */
class Utils
{
    /**
     * VERY BASIC
     * Perform a POST request, so we could launch fake-cron calls and other core-system calls without annoying the user
     *
     * @param string               $target_url
     * @param array<string,mixed>  $query_data http_build_query compatible query_data
     *                                         https://www.php.net/manual/en/function.http-build-query.php
     *
     * @return bool|int false on error, or the number of bytes sent.
     * @throws RuntimeException when allow_url_fopen is disabled
     */
    public static function doRequest($target_url, $query_data)
    {
        if (ini_get('allow_url_fopen') === false) {
            throw new RuntimeException(__('Is allow_url_fopen enabled?'));
        }
        // parse the given URL
        $parsed_url = parse_url($target_url);

        if ($parsed_url === false || !isset($parsed_url['host'], $parsed_url['path'])) {
            return false;
        }
        // extract host, path, port:
        $host = $parsed_url['host'];
        $path = $parsed_url['path'];
        $port = 80;
        if (isset($parsed_url['port'])) {
            $port = $parsed_url['port'];
        }

        if (isset($parsed_url['scheme']) && $parsed_url['scheme'] === 'https') {
            $host = 'ssl://' . $host;
            $port = 443;
        }
        $fp = fsockopen($host, $port);

        if ($fp === false) {
            return false;
        }
        $data              = http_build_query($query_data);
        $out               = 'POST ' . $path . ' HTTP/1.1' . PHP_EOL;
        $out               .= 'Host: ' . $parsed_url['host'] . PHP_EOL;
        $out               .= 'Referer: Shopclass ' . OSCLASS_VERSION . PHP_EOL;
        $out               .= 'Content-type: application/x-www-form-urlencoded' . PHP_EOL;
        $out               .= 'Content-Length: ' . strlen($data) . PHP_EOL;
        $out               .= 'Connection: close' . PHP_EOL . PHP_EOL;
        $out               .= $data;
        $number_bytes_sent = fwrite($fp, $out);
        fclose($fp);

        return $number_bytes_sent; // or false on fwrite() error
    }

    /**
     * Check if we loaded some specific module of apache
     *
     * @param string $mod
     *
     * @return bool
     */
    public static function apacheModLoaded($mod)
    {
        if (function_exists('apache_get_modules')) {
            $modules = apache_get_modules();
            if (in_array($mod, $modules)) {
                return true;
            }
        } elseif (function_exists('phpinfo')) {
            ob_start();
            phpinfo(INFO_MODULES);
            $content = ob_get_clean();
            if (stripos($content, $mod) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change current osclass version to given param number
     *
     * @param string|null $version
     *
     * @return array<string,mixed>|bool the refreshed preferences, or false when no version was given
     */
    public static function changeOsclassVersionTo($version = null)
    {
        if ($version) {
            Preference::getInstance()->replace('version', $version);
            return Preference::getInstance()->toArray();
        }

        return false;
    }

    /**
     * Un-quotes a quoted string
     *
     * @param string|array $data
     *
     * @return string|array a string or array of string with backslashes stripped off.
     * (\' becomes ' and so on.)
     * Double backslashes (\\) are made into a single
     * backslash (\).
     */
    public static function stripSlashesExtended($data)
    {
        if (is_array($data)) {
            foreach ($data as $k => &$v) {
                $v = self::stripSlashesExtended($v);
            }
        } else {
            $data = stripslashes($data);
        }

        return $data;
    }

    /**
     * replace double slash with single slash
     *
     * @param string $path
     *
     * @return string
     */
    public static function replaceDoubleSlash($path)
    {
        return str_replace('//', '/', $path);
    }

    /**
     * Prepare Price for osclass
     *
     * @param int|float $price stored price, in millionths
     *
     * @return string
     */
    public static function preparePrice($price)
    {
        return number_format(
            $price / 1000000,
            osc_locale_num_dec(),
            osc_locale_dec_point(),
            osc_locale_thousands_sep()
        );
    }

    /**
     * Compare version
     * Returns
     *      0  if both are equal,
     *      1  if A > B, and
     *      -1 if A < B.
     *
     * @param string $a
     * @param string $b
     * @param string $operator test for a particular relationship.
     *                         The possible operators are: <, lt, <=, le, >, gt, >=, ge, ==, =, eq, !=, <>, ne
     *                         respectively.
     *
     * @return int|bool
     * @link https://www.php.net/manual/en/function.version-compare.php
     */
    public static function versionCompare($a, $b, $operator = null)
    {
        return version_compare($a, $b, $operator);
    }

    /**
     * Update category stats
     *
     * @return void
     */
    public static function updateAllCategoriesStats()
    {
        $categoryTotal = array();
        $aCategories   = Category::getInstance()->toTreeAll();

        foreach ($aCategories as &$category) {
            if ($category['fk_i_parent_id'] === null) {
                self::recursiveCategoryStats($category, $categoryTotal);
            }
        }
        unset($category);

        // Bound and written in chunks; a site with no categories writes nothing.
        foreach (array_chunk($categoryTotal, 500, true) as $chunk) {
            try {
                \mindstellar\category\CategoryStore::writeCounts($chunk);
            } catch (\mindstellar\database\DbException $e) {
                return;
            }
        }
    }

    /**
     * Return Category Stats in array
     *
     * @param array<string,mixed> $aux           category row, with a nested 'categories' list
     * @param array<mixed>        $categoryTotal accumulator, filled in place
     *
     * @return int
     */
    public static function recursiveCategoryStats(&$aux, &$categoryTotal)
    {
        $count_items = Item::getInstance()->numItems($aux);
        if (is_array($aux['categories'])) {
            foreach ($aux['categories'] as &$cat) {
                $count_items += self::recursiveCategoryStats($cat, $categoryTotal);
            }
            unset($cat);
        }
        $categoryTotal[$aux['pk_i_id']] = $count_items;

        return $count_items;
    }

    /**
     * Recount items for a given a category id, then walk up to its parents.
     *
     * @param int $id
     *
     * @return void
     * @throws \InvalidArgumentException when $id is not numeric
     */
    public static function updateCategoryStatsById($id)
    {
        if (!is_numeric($id)) {
            throw new \InvalidArgumentException(__('Category id is not a valid integer'));
        }
        $category = Category::getInstance()->findByPrimaryKey($id);
        if (!$category) {
            return;
        }
        $categoryTotal = self::subtreeItemCount($category);

        try {
            \mindstellar\category\CategoryStore::writeCounts(array((int)$id => (int)$categoryTotal));
        } catch (\mindstellar\database\DbException $e) {
            // A failed write leaves the old count, as the legacy query did; the parents still update.
        }

        if ($category['fk_i_parent_id'] != 0) {
            self::updateCategoryStatsById($category['fk_i_parent_id']);
        }
    }

    /**
     * Live listings in a category and every category below it, at any depth.
     *
     * @param array<string,mixed> $category
     * @param array<int,bool>     $seen     ids already counted, so a parent loop cannot recurse forever
     *
     * @return int
     */
    private static function subtreeItemCount(array $category, array &$seen = array())
    {
        $id = (int)$category['pk_i_id'];
        if (isset($seen[$id])) {
            return 0;
        }
        $seen[$id] = true;

        $total = (int)Item::getInstance()->numItems($category);
        foreach (Category::getInstance()->findSubcategories($id) as $sub) {
            $total += self::subtreeItemCount($sub, $seen);
        }

        return $total;
    }

    /**
     * Return indexed array fo php supported timezone
     *
     * @return array
     */
    public static function timezoneList()
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * Recount listings per country, region and city on the job queue.
     *
     * @param bool       $force queue a full recount when none is queued
     * @param int|string $limit unused; kept for callers of the old signature
     *
     * @return int locations still to count
     */
    public static function updateLocationStats($force = false, $limit = 1000)
    {
        if ($force) {
            return LocationRecountJobs::queue();
        }
        if (LocationRecountJobs::pending() > 0) {
            JobWorker::run(10);
        }

        return LocationRecountJobs::pending();
    }

    /**
     * Translate current categories to new locale
     *
     * @param string $locale
     *
     * @return void
     */
    public static function translateCategories($locale)
    {
        $old_locale = Session::getInstance()->_get('adminLocale');
        Session::getInstance()->_set('adminLocale', $locale);
        Translation::getInstance()->_load(osc_translations_path() . $locale . '/core.mo', 'cat_' . $locale);
        $catManager     = Category::getInstance();
        $old_categories = $catManager->_findNameIDByLocale($old_locale);
        $tmp_categories = $catManager->_findNameIDByLocale($locale);
        foreach ($tmp_categories as $category) {
            $new_categories[$category['pk_i_id']] = $category['s_name'];
        }
        unset($tmp_categories);
        foreach ($old_categories as $category) {
            if (!isset($new_categories[$category['pk_i_id']])) {
                $fieldsDescription['s_name']           = __($category['s_name'], 'cat_' . $locale);
                $fieldsDescription['s_description']    = '';
                $fieldsDescription['fk_i_category_id'] = $category['pk_i_id'];
                $fieldsDescription['fk_c_locale_code'] = $locale;
                $slug                                  = osc_sanitizeString(
                    osc_apply_filter('slug', $fieldsDescription['s_name'])
                );
                $slug_tmp                              = $slug;
                $slug_unique                           = 1;
                while (true) {
                    if (!$catManager->findBySlug($slug)) {
                        break;
                    }

                    $slug = $slug_tmp . '_' . $slug_unique;
                    $slug_unique++;
                }
                $fieldsDescription['s_slug'] = $slug;
                $catManager->insertDescription($fieldsDescription);
            }
        }
        Session::getInstance()->_set('adminLocale', $old_locale);
    }

    /**
     * The client's IP address: REMOTE_ADDR only. Forwarded-for headers are written by the
     * client, so a site behind a proxy has the proxy set REMOTE_ADDR instead.
     *
     * @return string
     */
    public static function getClientIp()
    {
        return (string)Params::getServerParam('REMOTE_ADDR');
    }

    /**
     * Prune null or empty array element
     *
     * @param array<array-key,mixed> $input pruned in place
     *
     * @return void
     */
    public static function pruneArray(&$input)
    {
        foreach ($input as $key => &$value) {
            if (is_array($value)) {
                self::pruneArray($value);
                if (empty($input[$key])) {
                    unset($input[$key]);
                }
            } elseif ($value === '' || $value === false || $value === null) {
                unset($input[$key]);
            }
        }
    }

    /**
     * Redirect to the given url and end the request.
     *
     * @param string   $url
     * @param int|null $http_response_code
     *
     * @return never
     */
    public static function redirectTo($url, $http_response_code = null)
    {
        // Carry any pending flash messages across the redirect in their signed cookie,
        // while headers can still be sent (before the Location header below).
        Session::getInstance()->_flushFlashMessages();
        Session::getInstance()->_flushFormData();
        if (ob_get_length() > 0) {
            ob_end_flush();
        }
        // Strip CR/LF so a URL carrying a decoded newline (e.g. a search pattern
        // with %0D%0A) cannot trip PHP's header guard and drop the redirect.
        $url = str_replace(array("\r", "\n"), '', (string) $url);
        if ($http_response_code !== null) {
            header('Location: ' . $url, true, $http_response_code);
        } else {
            header('Location: ' . $url);
        }
        exit;
    }

    /**
     * Calculate location slug
     *
     * @param string $type
     *
     * @return bool|int|mixed
     */
    public static function calculateLocationSlug($type)
    {
        $field = 'pk_i_id';
        switch ($type) {
            case 'country':
                $manager = Country::getInstance();
                $field   = 'pk_c_code';
                break;
            case 'region':
                $manager = Region::getInstance();
                break;
            case 'city':
                $manager = City::getInstance();
                break;
            default:
                return false;
        }
        $locations         = $manager->listByEmptySlug();
        $locations_changed = 0;
        foreach ($locations as $location) {
            $slug_tmp    = $slug = osc_sanitizeString($location['s_name']);
            $slug_unique = 1;
            while (true) {
                $location_slug = $manager->findBySlug($slug);
                if (!isset($location_slug[$field])) {
                    break;
                }

                $slug = $slug_tmp . '-' . $slug_unique;
                $slug_unique++;
            }
            $locations_changed += $manager->update(array('s_slug' => $slug), array($field => $location[$field]));
        }

        return $locations_changed;
    }

    /**
     * Check if protocol is ssl
     *
     * @return bool
     */
    public static function isSsl()
    {
        return ((isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
            || (isset($_SERVER['HTTPS'])
                && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1)));
    }

    /**
     * Used to encode a field for Amazon Auth
     * (taken from the Amazon S3 PHP example library)
     *
     * @param string $str hex-encoded input
     *
     * @return string
     */
    public static function hex2b64($str)
    {
        return base64_encode(hex2bin($str));
    }

    /**
     * Calculate HMAC-SHA1
     *
     * @param string $key
     * @param string $data
     *
     * @return string
     */
    public static function hmacsha1($key, $data)
    {
        return hash_hmac('sha1', $data, $key);
    }

    /**
     * Calculate base64 encoded HMAC-SHA1
     *
     * @param string $key
     * @param string $data
     *
     * @return string
     */
    public static function hmacSha1B64($key, $data)
    {
        return base64_encode(hash_hmac('sha1', $data, $key, true));
    }

    /**
     * The best available referring URL on this site: the rewrite layer's, then the session's,
     * then the header. An off-site candidate is skipped, so callers can redirect to the result.
     *
     * @return string
     */
    public static function getHttpReferer()
    {
        $candidates = array(
            (string) Rewrite::getInstance()->get_http_referer(),
            (string) Session::getInstance()->_getReferer(),
            (string) Params::getServerParam('HTTP_REFERER', false, false),
        );
        foreach ($candidates as $url) {
            if (self::isLocalUrl($url)) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Whether $url is an http(s) URL on this site's host.
     *
     * @param string $url
     *
     * @return bool
     */
    public static function isLocalUrl($url)
    {
        $url = (string) $url;
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme   = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host     = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url(osc_base_url(), PHP_URL_HOST);

        return in_array($scheme, array('http', 'https'), true)
            && is_string($host) && is_string($baseHost)
            && strcasecmp($host, $baseHost) === 0;
    }
}
