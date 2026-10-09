<?php

/**
 * Created by Navjot Tomer (Mindstellar).
 * User: navjottomer
 * Date: 01/07/20
 * Time: 3:07 PM
 * License is provided in root directory.
 */

namespace mindstellar\utility;

use Category;
use City;
use Country;
use mindstellar\security\ActionThrottle;
use mindstellar\security\AddressGuard;
use Region;

/**
 * Class Validate
 *
 * @package mindstellar\utility
 */
class Validate
{
    /**
     * Whether a theme or plugin name is a safe folder name. Letters, digits, dots,
     * underscores and hyphens only, and not made only of dots, so '..' is refused.
     *
     * @param mixed $name
     *
     * @return bool
     */
    public static function packageName($name): bool
    {
        return is_string($name) && preg_match('/^(?!\.+$)[a-zA-Z0-9._-]+$/', $name) === 1;
    }

    /**
     * Whether $url is a valid URL with an http or https scheme. FILTER_VALIDATE_URL alone
     * also passes javascript: and data: URLs.
     *
     * @param mixed $url
     *
     * @return bool
     */
    public static function httpUrl($url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * Validate using filter_var
     * common method to validate value
     * Validate before using these values, this will only sanitize the requested param
     *
     * @param mixed               $value
     * @param string              $type    What type is the variable (bool, domain, email, float, int, ip, url)
     * @param array<string,mixed> $options Options for filter_var
     *                                     https://www.php.net/manual/en/filter.filters.validate.php
     *
     * @return false|int|float|string will return false on failure
     */
    private function filter($value, $type = 'string', $options = [])
    {
        return $this->filterVar($value, $this->getFilter($type), $options);
    }

    /**
     * Validate bool using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return bool
     */
    public function filterBool($value, $options = [])
    {
        return $this->filter($value, 'bool', $options);
    }

    /**
     * Validate domain using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return false|string
     */
    public function filterDomain($value, $options = [])
    {
        return $this->filter($value, 'domain', $options);
    }

    /**
     * Validate email using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return false|string
     */
    public function filterEmail($value, $options = [])
    {
        return $this->filter($value, 'email', $options);
    }

    /**
     * Validate float using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return false|float
     */
    public function filterFloat($value, $options = [])
    {
        return $this->filter($value, 'float', $options);
    }

    /**
     * Validate int using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return false|int
     */
    public function filterInt($value, $options = [])
    {
        return $this->filter($value, 'int', $options);
    }

    /**
     * Validate IP using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return false|string
     */
    public function filterIP($value, $options = [])
    {
        return $this->filter($value, 'ip', $options);
    }

    /**
     * Validate URL using filter_var
     *
     * @param mixed                  $value
     * @param array<string,mixed>    $options
     *
     * @return false|string
     */
    public function filterURL($value, $options = [])
    {
        return $this->filter($value, 'url', $options);
    }

    /**
     * Thin wrapper over filter_var(), so every validator funnels through one place.
     *
     * @param mixed               $value
     * @param int                 $type    a FILTER_VALIDATE_* constant
     * @param array<string,mixed> $options
     *
     * @return false|int|float|string will return false on failure
     */
    private function filterVar($value, $type, $options = [])
    {
        return filter_var($value, $type, $options);
    }

    /**
     * Private function to get filter type
     *
     * @param string $type
     *
     * @return int
     */
    private function getFilter($type)
    {
        switch ($type) {
            case 'bool':
                $filter = FILTER_VALIDATE_BOOLEAN;
                break;
            case 'domain':
                $filter = FILTER_VALIDATE_DOMAIN;
                break;
            case 'email':
                $filter = FILTER_VALIDATE_EMAIL;
                break;
            case 'float':
                $filter = FILTER_VALIDATE_FLOAT;
                break;
            case 'int':
                $filter = FILTER_VALIDATE_INT;
                break;
            case 'ip':
                $filter = FILTER_VALIDATE_IP;
                break;
            case 'url':
                $filter = FILTER_VALIDATE_URL;
                break;
            default:
                $filter = FILTER_VALIDATE_BOOLEAN;
        }

        return $filter;
    }

    /**
     * Validate the text with a minimum of non-punctuation characters (international)
     *
     * @param string  $value
     * @param integer $count
     * @param boolean $required
     *
     * @return boolean
     */
    public function text($value = '', $count = 1, $required = true)
    {
        if ($required || $value) {
            if (!preg_match("/([\p{L}\p{N}]){" . $count . '}/iu', strip_tags($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate one or more digits (no sign, no periods)
     *
     * @param mixed $value
     *
     * @return boolean
     */
    public function int($value)
    {
        return is_scalar($value) && preg_match('/^[0-9]+$/', (string) $value) === 1;
    }

    /**
     * Validate one or more numbers (no periods), must be more than 0.
     *
     * @param mixed $value
     *
     * @return boolean
     */
    public function nozero($value)
    {
        return $this->int($value) && $value > 0;
    }

    /**
     * Validate $value is a number or a numeric string
     *
     * @param mixed   $value
     * @param boolean $required when false, an empty value passes
     *
     * @return boolean
     */
    public function number($value, $required = true)
    {
        if (!$required && ($value === null || $value === '')) {
            return true;
        }

        return is_numeric($value);
    }

    /**
     * Validate $value is a number phone,
     * with $count length
     *
     * @param string  $value
     * @param int     $count
     * @param boolean $required
     *
     * @return boolean
     */
    public function phone($value = null, $count = 10, $required = false)
    {
        if ($required || $value != '') {
            if (!preg_match("/([\p{Nd}][^\p{Nd}]*){" . $count . '}/i', strip_tags($value))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate if $value is more than $min
     *
     * @param string $value
     * @param int    $min
     *
     * @return boolean
     */
    public function min($value = null, $min = 6)
    {
        return !(mb_strlen($value, 'UTF-8') < $min);
    }

    /**
     * Validate if $value is less than $max
     *
     * @param string $value
     * @param int    $max
     *
     * @return boolean
     */
    public function max($value = null, $max = 255)
    {
        return !(mb_strlen($value, 'UTF-8') > $max);
    }

    /**
     * Validate if $value belongs at range between min to max
     *
     * @param string $value
     * @param int    $min
     * @param int    $max
     *
     * @return boolean
     */
    public function range($value, $min = 6, $max = 255)
    {
        return mb_strlen($value, 'UTF-8') >= $min && mb_strlen($value, 'UTF-8') <= $max;
    }

    /**
     * Validate if exist $city, $region, $country in db
     *
     * @param int|string  $city     city id
     * @param string|null $sCity    free-text city name, used when no id was picked
     * @param int|string  $region   region id
     * @param string|null $sRegion  free-text region name
     * @param string      $country  country code
     * @param string|null $sCountry free-text country name
     *
     * @return boolean
     */
    public function location($city, $sCity, $region, $sRegion, $country, $sCountry)
    {
        if ($this->nozero($city) && $this->nozero($region) && $this->text($country, 2)) {
            $data      = Country::getInstance()->findByCode($country);
            $countryId = $data['pk_c_code'];
            if ($countryId) {
                $data     = Region::getInstance()->findByPrimaryKey($region);
                $regionId = $data['pk_i_id'];
                if ((int) $data['b_active'] === 1) {
                    $data = City::getInstance()->findByPrimaryKey($city);
                    if ((int) $data['b_active'] === 1 && (string) $data['fk_i_region_id'] === (string) $regionId
                        && strtolower($data['fk_c_country_code']) === strtolower($countryId)
                    ) {
                        return true;
                    }
                }
            }
        } elseif ($sCity && $this->nozero($region) && $this->text($country, 2)) {
            return true;
        } elseif ($sCity && $sRegion && $this->text($country, 2)) {
            return true;
        } elseif ($sCity && $sRegion && $sCountry) {
            return true;
        }

        return false;
    }

    /**
     * Validate if exist category $value and is enabled in db
     *
     * @param int $value
     *
     * @return boolean
     */
    public function category($value)
    {
        if ($this->nozero($value)) {
            $data = Category::getInstance()->findByPrimaryKey($value);
            if (isset($data['b_enabled']) && (int) $data['b_enabled'] === 1) {
                if (osc_selectable_parent_categories()) {
                    return true;
                }

                if ($data['fk_i_parent_id'] !== null && $data['fk_i_parent_id'] !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a public address answers a HEAD request with 200, 301 or 302 within 3 seconds.
     */
    private static function answers(string $url): bool
    {
        if (!(new AddressGuard())->check($url)['ok']) {
            return false;
        }

        return in_array((new FileSystem())->head($url, 3), array(200, 301, 302), true);
    }

    /**
     * Validate if $value url is a valid url.
     *
     * @param string  $value
     * @param boolean $required
     * @param bool    $get_headers also ask the address with a HEAD request and want 200, 301 or 302;
     *                             a private or reserved host fails without being asked
     *
     * @return boolean
     */
    public function url($value, $required = false, $get_headers = false)
    {
        if ($required || $value !== '') {
            $sanitizedValue = (new Sanitize())->url($value);

            $success = $this->filterURL($sanitizedValue);

            if ($success) {
                if ($get_headers && !self::answers($sanitizedValue)) {
                    return false;
                }
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate time between two items added/comments
     *
     * @param string $type
     *
     * @return boolean
     */
    public function delay($type = 'item')
    {
        if ($type === 'item') {
            $delay   = (int) osc_item_spam_delay();
            $context = 'item_post';
        } else {
            $delay   = (int) osc_comment_spam_delay();
            $context = 'comment_post';
        }

        // Allowed when this address has not posted of this kind within the delay window.
        return $delay <= 0 || !ActionThrottle::exceeded($context, 1, $delay);
    }

    /**
     * Validate locale code string
     *
     * @param string $string
     * @param bool   $admin  check the admin locales instead of the public ones
     *
     * @return bool
     */
    public function localeCode($string, $admin = false)
    {
        if (strlen($string) === 5) {
            if ($admin) {
                return in_array($string, array_column(osc_get_admin_locales(), 'pk_c_code'), true);
            }

            return in_array($string, array_column(osc_get_locales(), 'pk_c_code'), true);
        }

        return false;
    }

    /**
     * Validate an email address
     * Source: http://www.linuxjournal.com/article/9585?page=0,3
     *
     * @param string  $email
     * @param boolean $required
     *
     * @return boolean
     */
    public function email($email, $required = true)
    {
        if ($required || $email !== '') {
            // Test for the minimum length the email can be
            if (strlen($email) < 3) {
                return false;
            }

            // Test for an @ character after the first position
            if (strpos($email, '@', 1) === false) {
                return false;
            }

            // Split out the local and domain parts
            list($local, $domain) = explode('@', $email, 2);

            // LOCAL PART
            // Test for invalid characters
            if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local)) {
                return false;
            }

            // DOMAIN PART
            // Test for sequences of periods
            if (preg_match('/\.{2,}/', $domain)) {
                return false;
            }
            // Test for leading and trailing periods and whitespace
            if (trim($domain, " \t\n\r\0\x0B.") !== $domain) {
                return false;
            }
            // Split the domain into subs
            $subs = explode('.', $domain);
            // Assume the domain will have at least two subs
            if (2 > count($subs)) {
                return false;
            }
            // Loop through each sub
            foreach ($subs as $sub) {
                // Test for leading and trailing hyphens and whitespace
                if (trim($sub, " \t\n\r\0\x0B-") !== $sub) {
                    return false;
                }
                // Test for invalid characters
                if (!preg_match('/^[a-z0-9-]+$/i', $sub)) {
                    return false;
                }
            }

            // Congratulations your email made it!
            return true;
        }

        return true;
    }

    /**
     * validate username, accept letters plus underline, without separators
     *
     * @param string $value
     * @param int    $min   minimum length
     *
     * @return bool
     */
    public function username($value, $min = 1)
    {
        return mb_strlen($value, 'UTF-8') >= $min && preg_match('/^\w+$/', $value);
    }
}
