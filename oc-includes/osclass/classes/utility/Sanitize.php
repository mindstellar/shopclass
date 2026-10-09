<?php

/**
 * Created by Navjot Tomer (Mindstellar).
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
    /** Built on first use by richHtml(). */
    private static ?\HTMLPurifier $purifier = null;

    /**
     * Sanitised String
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string|false
     * @deprecated since 5.1.0 use Sanitize::string() instead, to be removed in 7.0.0
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
     * @return string|false
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
     * @return mixed Rounded to two decimals, or the value unchanged when it is falsy.
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
     *
     * @return string
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
     * @param mixed $value
     * @param array $options unused; kept for signature compatibility
     *
     * @return int
     * @deprecated since 5.1.0 use Sanitize::int() instead, to be removed in 7.0.0
     */
    public function filterInt($value, ...$options)
    {
        return $this->int($value);
    }

    /**
     * Sanitised Int: the value as PHP reads it as a whole number, so "12abc" is 12,
     * "1.5" is 1 and anything that is not a number is 0.
     *
     * @param mixed $value
     *
     * @return int
     */
    public function int($value): int
    {
        if (is_array($value) || is_object($value) || $value === null) {
            return 0;
        }
        if (is_float($value) && !is_finite($value)) {
            return 0;
        }

        return (int) (is_string($value) ? trim($value) : $value);
    }

    /**
     * Sanitised website URL
     *
     * @param mixed $value
     *
     * @return mixed Sanitised URL with a scheme prefixed, or the value unchanged when it is falsy.
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
     * @param array $options unused; kept for signature compatibility
     *
     * @return string
     */
    public function url($value, ...$options): string
    {
        return is_scalar($value) ? (string) filter_var((string) $value, FILTER_SANITIZE_URL) : '';
    }

    /**
     * Sanitised float
     *
     * @param mixed $value
     * @param array $options
     *
     * @return string|false
     * @deprecated since 5.1.0 use Sanitize::float() instead, to be removed in 7.0.0
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
     * @return string|false
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
     * @param mixed $value
     * @param array $options
     *
     * @return string|false
     * @deprecated since 5.1.0 use Sanitize::encoded() instead, to be removed in 7.0.0
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
     * @return string|false
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
     * @param mixed $value
     * @param array $options
     *
     * @return string|false
     * @deprecated since 5.1.0 use Sanitize::email() instead, to be removed in 7.0.0
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
     * @return string|false
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
     * @param mixed $value
     * @param array $options unused; kept for signature compatibility
     *
     * @return string
     * @deprecated since 5.1.0 use Sanitize::quotes() instead, to be removed in 7.0.0
     */
    public function filterQuotes($value, ...$options)
    {
        return $this->quotes($value);
    }

    /**
     * Add Slashes
     *
     * @param mixed $value
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
     * @param mixed $value
     * @param array $options
     *
     * @return string
     * @deprecated since 5.1.0 use Sanitize::url() instead, to be removed in 7.0.0
     */
    public function filterURL($value, ...$options)
    {
        return $this->url($value, ...$options);
    }

    /**
     * Lower-case a value written entirely in capitals, keeping its first letter upper case.
     * A value with any lower-case letter is returned as it is, so "McDonald" keeps its case.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function allcaps($value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (!mb_check_encoding($value, 'UTF-8')
            || !preg_match('/\p{Lu}/u', $value)
            || preg_match('/\p{Ll}/u', $value)
        ) {
            return $value;
        }
        $lower = mb_strtolower($value, 'UTF-8');

        return mb_convert_case(mb_substr($lower, 0, 1, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')
            . mb_substr($lower, 1, null, 'UTF-8');
    }

    /**
     * Tidy a person or place name: trimmed, all-caps lowered, and each word started
     * with a capital. Letters already in capitals are kept.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function name($value): string
    {
        $value = $this->allcaps(trim(is_scalar($value) ? (string) $value : ''));
        $named = preg_replace_callback(
            '/(^|\s)(\p{Ll})/u',
            static fn (array $m): string => $m[1] . mb_convert_case($m[2], MB_CASE_TITLE, 'UTF-8'),
            $value
        );

        return $named ?? ucwords($value);
    }

    /**
     * Sanitize a username: whitespace becomes '_', anything but ASCII letters, digits,
     * '_' and '.' is dropped, and runs of '_' collapse to one.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function username($value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        $value = (string) preg_replace('/\s+/', '_', $value);
        $value = (string) preg_replace('/[^A-Za-z0-9_.]/', '', $value);

        return (string) preg_replace('/_{2,}/', '_', $value);
    }

    /**
     * Sanitize a phone number without reformatting it for any one country: digits, a leading
     * '+', and the separators people type (space, '-', '.', '/', brackets) between digits are
     * kept; anything else is dropped. A value with no digit becomes ''.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function phone($value): string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');
        $plus  = strpos($value, '+') === 0;
        $value = (string) preg_replace('/[^0-9 ().\/-]+/', ' ', $value);
        $value = (string) preg_replace('/\([^0-9]*\)/', ' ', $value);
        $value = (string) preg_replace('/(?<![0-9])[.\/-]+(?![0-9])/', ' ', $value);
        $value = trim((string) preg_replace('/\s+/', ' ', $value), ' -./');
        if (!preg_match('/[0-9]/', $value)) {
            return '';
        }

        return ($plus ? '+' : '') . $value;
    }

    /**
     * Turn a value into a URL-safe slug: tags, accents, entities and punctuation removed,
     * whitespace collapsed to single hyphens.
     *
     * @param mixed $value
     *
     * @return string
     */
    public function slug($value): string
    {
        return (string) (new Formatting())->formatSlug(is_scalar($value) ? (string) $value : '');
    }

    /**
     * Reduce a value to plain text, taking every tag out along with what it contained.
     * It is the filter Params::getParam() runs over request data, so the result is
     * escaped the same way. Arrays are walked; other non-strings are returned unchanged.
     *
     * @param mixed $value
     *
     * @return mixed same shape as $value
     */
    public function text($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'text'], $value);
        }
        if (!is_string($value)) {
            return $value;
        }

        return \Params::purifyText($value);
    }

    /**
     * Sanitize rich text to the markup a Shopclass editor can produce: inline formatting,
     * lists, links, headings, quotes, tables, images and colour spans. Scripts, iframes,
     * event handlers and any URL scheme but http, https and mailto are removed.
     * Arrays are walked, so a per-locale map can be passed in. Unlike html(), this keeps markup.
     *
     * @param mixed $value
     *
     * @return mixed same shape as $value
     */
    public function richHtml($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'richHtml'], $value);
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        return self::purifier()->purify($value);
    }

    /**
     * The purifier richHtml() uses, built once per request.
     */
    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier !== null) {
            return self::$purifier;
        }
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', osc_apply_filter('sanitize_html_allowed', implode(',', [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li',
            'a[href|title|rel]', 'h3', 'h4', 'blockquote', 'hr',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'span[style]', 'img[src|alt|width|height]',
        ])));
        // Only what the colour and alignment buttons write; other CSS can build overlays.
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'text-align',
            'font-weight', 'font-style', 'text-decoration',
        ]);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        // Outbound links get nofollow so descriptions are not worth spamming; URI.Host tells
        // the purifier which links point back into this site.
        $config->set('HTML.Nofollow', true);
        $host = function_exists('osc_base_url') ? parse_url((string) osc_base_url(), PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            $config->set('URI.Host', $host);
        }
        \mindstellar\security\PurifierCache::apply($config);

        return self::$purifier = new \HTMLPurifier($config);
    }
}
