<?php
/**
 * Created by Mindstellar Community.
 * User: navjottomer
 * Date: 01/07/20
 * Time: 2:40 PM
 * License is provided in root directory.
 */

namespace mindstellar\utility;

/**
 * Class Escape
 *
 * @package mindstellar\utility
 */
class Escape
{
    /**
     * Escape single quotes, double quotes, <, >, & and line endings
     *
     * @param string $str
     *
     * @return string
     * @version 2.4
     */
    public static function js($str)
    {
        static $sNewLines = '<br><br/><br />';
        static $aNewLines = array('<br>', '<br/>', '<br />');
        $str = strip_tags($str, $sNewLines);
        $str = str_replace("\r", '', $str);
        $str = addslashes($str);
        $str = str_replace(array("\n", $aNewLines), '\n', $str);

        return $str;
    }

    /**
     * Escape given characters
     *
     * @param string $str
     * @param string $chars
     *
     * @return string
     * @since 5.1
     */
    public static function escapeChars($str, $chars = '"')
    {
        return preg_replace('/[' . $chars . ']/', '\\\$0', $str);
    }

    /**
     * Escape unicode characters
     *
     * @param string $str
     *
     * @return string
     */
    public static function unicode($str)
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }

    /**
     * Escape html keep encoded entities
     *
     * @param string $str
     *
     * @return string
     * @since 5.1
     */
    public static function html($str)
    {
        $str = self::entities($str);

        return htmlspecialchars($str, ENT_QUOTES);
    }

    /**
     * Escape html entities
     *
     * @param string $str
     *
     * @return string
     */
    public static function entities($str)
    {
        return preg_replace_callback('/&#([0-9a-zA-Z]*);/', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }

    /**
     * Escape html string for use in javascript
     *
     * @param string $str
     *
     * @return string
     * @since 5.1
     */
    public static function jsHtml($str)
    {
        $str = self::entities($str);

        return preg_replace('/</', '\\x3C', $str);
    }

}
