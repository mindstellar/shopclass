<?php

/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 30/06/20
 * Time: 9:21 PM
 * License is provided in root directory.
 */

namespace mindstellar\utility;

/**
 * Class Sanitize
 * Provide common sanitization methods using PHP filter_var() method where possible
 *
 * @package mindstellar\utility
 */
class Sanitize
{
    /**
     * Sanitised String
     *
     * @param       $value
     * @param array $options
     *
     * @return bool|string
     * @deprecated use Sanitize::string() instead will be removed in the next 6.x release
     */
    public function filterString($value, ...$options)
    {
        return $this->string($value, ...$options);
    }

    /**
     * Sanitised String
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string
     */
    public function string($value, ...$options)
    {
        // utf8 safe sanitize
        $options = array_merge(
            [
                'flags' => FILTER_FLAG_NO_ENCODE_QUOTES,
                'options' => [
                    'default' => '',
                ],
            ],
            $options
        );

        return filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS, $options);
    }

    /**
     * Sanitised Price
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string
     */
    public function price($value, ...$options)
    {
        // sanitize price to float up to 2 decimal places, merge with default options
        if ($value) {
            $options = array_merge(
                [
                    'flags'   => FILTER_FLAG_ALLOW_FRACTION,
                    'options' => [
                        'decimal_separator' => '.',
                        'decimal_places'    => 2,
                        'min_range'         => 0,
                        'max_range'         => 9999999999.99,
                    ],
                ],
                $options
            );
            $value   = filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, $options);
            // round to 2 decimal places
            $value = round($value, 2);
        }

        return $value;
    }

    /**
     * Sanitize a html safe string
     *
     * @param string $value
     */
    public function html($value)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize title utf8 string
     *
     * @param string $value
     *
     * @return string
     */
    public function title($value)
    {
        if (!$value) {
            return '';
        }

        // Decode HTML entities first (to handle cases like &ndash; &rsquo;)
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

        // Strip any remaining HTML tags
        $value = strip_tags($value);

        // Replace specific HTML entities with correct characters
        $replaceMap = [
            '–' => '-',  // ndash
            '’' => "'",  // rsquo
            '“' => '"',  // ldquo
            '”' => '"',  // rdquo
            '…' => '...', // ellipsis
        ];
        $value = strtr($value, $replaceMap);

        // Remove any non-alphanumeric characters except spaces, hyphens, quotes, and dots
        $value = preg_replace('/[^\p{L}\p{N}\s\-\'".]/u', '', $value);

        // Normalize multiple spaces to a single space
        $value = preg_replace('/\s+/', ' ', $value);

        // Trim spaces
        $value = trim($value);

        // Convert to safe HTML output (prevents XSS)
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitised Int
     *
     * @param       $value
     * @param array $options
     *
     * @return bool|int
     * @deprecated use Sanitize::int() instead will be removed in the next 6.x release
     */
    public function filterInt($value, ...$options)
    {
        return $this->int($value);
    }

    /**
     * Sanitised Int
     *
     * @param mixed $value
     */
    public function int($value)
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitised website URL
     *
     * @param mixed $value
     */
    public function websiteUrl($value)
    {
        if ($value) {
            //remove invalid chars from url
            $value = $this->url($value);
            //remove possible xss attempts
            $value = str_replace(['<', '>', '"', '\'', '%3C', '%3E', '%22', '%27'], '', $value);
            //check if it has http:// or https://
            if (strpos($value, 'http') !== 0) {
                $value = 'https://' . $value;
            }
        }

        return $value;
    }

    /**
     * Sanitised URL
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string
     */
    public function url($value, ...$options)
    {
        $options = array_merge(
            [
                'default' => '',
            ],
            $options
        );

        return filter_var($value, FILTER_SANITIZE_URL, $options);
    }

    /**
     * Sanitised float
     *
     * @param       $value
     * @param array $options
     *
     * @return float
     * @deprecated use Sanitize::float() instead will be removed in the next major 6.x release
     */
    public function filterFloat($value, ...$options)
    {
        return $this->float($value, ...$options);
    }

    /**
     * Sanitised Float
     *
     * @param mixed $value
     * @param array $options
     *
     * @return float;
     */
    public function float($value, ...$options)
    {
        $options = array_merge(
            [
                'flags'   => FILTER_FLAG_ALLOW_FRACTION,
                'options' => [
                    'min_range' => 0,
                    'max_range' => 65535,
                ],

            ],
            $options
        );

        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, $options);
    }

    /**
     * Sanitised encoded
     *
     * @param       $value
     * @param array $options
     *
     * @return string
     * @deprecated use Sanitize::encoded() instead will be removed in the next major 6.x release
     */
    public function filterEncoded($value, ...$options)
    {
        return $this->encoded($value, ...$options);
    }

    /**
     * Sanitised Encoded
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string
     */
    public function encoded($value, ...$options)
    {
        $options = array_merge(
            [
                'default' => '',
            ],
            $options
        );

        return filter_var($value, FILTER_SANITIZE_ENCODED, $options);
    }

    /**
     * Sanitised Email
     *
     * @param       $value
     * @param array $options
     *
     * @return string
     * @deprecated use Sanitize::email() instead will be removed in the next major 6.x release
     */
    public function filterEmail($value, ...$options)
    {
        return $this->email($value, ...$options);
    }

    /**
     * Sanitised Email
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string
     */
    public function email($value, ...$options)
    {
        $options = array_merge(
            [
                'default' => '',
            ],
            $options
        );

        return filter_var($value, FILTER_SANITIZE_EMAIL, $options);
    }

    /**
     * Sanitised Quotes
     *
     * @param       $value
     * @param array $options
     *
     * @return bool|string
     * @deprecated use Sanitize::quotes() instead will be removed in the next major 6.x release
     */
    public function filterQuotes($value, ...$options)
    {
        return $this->quotes($value);
    }

    /**
     * Add Slashes
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string
     */
    public function quotes($value)
    {
        return addslashes($value);
    }

    /**
     * Sanitised URL
     *
     * @param       $value
     * @param array $options
     *
     * @return bool|string
     * @deprecated use Sanitize::url() instead will be removed in the next major 6.x release
     */
    public function filterURL($value, ...$options)
    {
        return $this->url($value, ...$options);
    }

    /**
     * Sanitize string that's all-caps
     *
     * @param string $value value to sanitize
     *
     * @return string sanitized
     */
    public function allcaps($value)
    {
        $sanitizedString = $this->string($value);
        if ($sanitizedString != false) {
            return ucfirst(strtolower($sanitizedString));
        }

        return '';
    }

    /**
     * Sanitize a username
     *
     * @param string $value
     *
     * @return string sanitized
     */
    public function username($value)
    {
        $sanitizedString = $this->string($value);
        if ($sanitizedString) {
            // Sanitize username, trim leading/trailing spaces and replace space with underscore.
            $value = preg_replace('/[^a-zA-Z0-9_\.]/', '', $value);
            $value = preg_replace('/[\s]+/', '_', $value);
            return trim($value);
        }

        return '';
    }

    /**
     * Format phone number. Remove non-numeric characters.
     *
     * @param string $value value to sanitize
     *
     * @return string sanitized
     */
    public function phone($value)
    {
        $value = $this->string($value);
        if ($value) {
            $value = preg_replace('/[^+0-9]/', '', $value);
            // check if the first character is a +
            if (strpos($value, '+') === 0) {
                $value = '+' . str_replace('+', '', $value);
            } else {
                $value = str_replace('+', '', $value);
            }

            return $value;
        }

        return '';
    }
}
