<?php
    /*
     * Shopclass - Free and open source theme for Osclass with premium features
     * Maintained and supported by Mindstellar Community
     * https://github.com/mindstellar/shopclass
     * Copyright (c) 2021.  Mindstellar
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
     *
     *                     GNU GENERAL PUBLIC LICENSE
     *                        Version 3, 29 June 2007
     *
     *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
     *  Everyone is permitted to copy and distribute verbatim copies
     *  of this license document, but changing it is not allowed.
     *
     *  You should have received a copy of the GNU Affero General Public
     *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
     *
     */
	/**
	 * User: navjottomer
	 * Date: 03/04/19
	 * Time: 4:14 PM
	 */

	namespace shopclass\includes\classes;


	/**
	 * Class tfcFormatting
	 * @package shopclass\includes\classes
	 */
	class tfcFormatting {

		private static $instance;

		/**
		 * @return tfcFormatting
		 */
		public static function newInstance() {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self;
			}

			return self::$instance;
		}
		/**
		 * @param $string
		 * @param bool $remove_breaks
		 *
		 * @return string
		 */
		public function strip_all_tags( $string, $remove_breaks = false ) {
			$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
			$string = strip_tags( $string );

			if ( $remove_breaks ) {
				$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
			}

			return trim( $string );
		}

		/**
		 * @param $str
		 * @param bool $keep_newlines
		 *
		 * @return mixed|null|string|string[]
		 */
		public function sanatize_text( $str, $keep_newlines = false ) {
			if ( is_object( $str ) || is_array( $str ) ) {
				return '';
			}

			$str = (string) $str;

			$filtered = $this->check_invalid_utf8( $str );

			if ( strpos( $filtered, '<' ) !== false ) {
				$filtered = $this->pre_kses_less_than( $filtered );
				// This will strip extra whitespace for us.
				$filtered = $this->strip_all_tags( $filtered, false );

				// Use html entities in a special case to make sure no later
				// newline stripping stage could lead to a functional tag
				$filtered = str_replace( "<\n", "&lt;\n", $filtered );
			}

			if ( ! $keep_newlines ) {
				$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
			}
			$filtered = trim( $filtered );

			$found = false;
			while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
				$filtered = str_replace( $match[0], '', $filtered );
				$found    = true;
			}

			if ( $found ) {
				// Strip out the whitespace that may now exist after removing the octets.
				$filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
			}

			return $filtered;
		}

		/**
		 * @param $text
		 *
		 * @return null|string|string[]
		 */
		public function pre_kses_less_than( $text ) {
			return preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', array($this,'pre_kses_less_than_callback'), $text );
		}

		/**
		 * @param $matches
		 *
		 * @return mixed
		 */
		public function pre_kses_less_than_callback( $matches ) {
			if ( false === strpos( $matches[0], '>' ) ) {
				return osc_esc_html( $matches[0] );
			}
			return $matches[0];
		}
		/**
		 * @param $string
		 * @param bool $strip
		 *
		 * @return string
		 */
		public function check_invalid_utf8( $string, $strip = false ) {
			$string = (string) $string;

			if ( 0 === strlen( $string ) ) {
				return '';
			}

			// Check for support for utf8 in the installed PCRE library once and store the result in a static
			static $utf8_pcre = null;
			if ( ! isset( $utf8_pcre ) ) {
				$utf8_pcre = @preg_match( '/^./u', 'a' );
			}
			// We can't demand utf8 in the PCRE installation, so just return the string in those cases
			if ( ! $utf8_pcre ) {
				return $string;
			}

			// preg_match fails when it encounters invalid UTF8 in $string
			if ( 1 === @preg_match( '/^./us', $string ) ) {
				return $string;
			}

			// Attempt to strip the bad chars if requested (not recommended)
			if ( $strip && function_exists( 'iconv' ) ) {
				return iconv( 'utf-8', 'utf-8', $string );
			}

			return '';
		}
	}