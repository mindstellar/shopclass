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
	 * Created by Navjot Tomer.
	 * User: navjottomer
	 * Date: 15/10/17
	 * Time: 10:39 AM
	 */

	namespace shopclass\includes\cacheModal;

	/**
	 * Class tfcCache
	 */
	class tfcCache extends tfcAbstractCache {
		private static $instance;
		public $class;
		/**
		 * @var bool
		 */
		private $cachHit;
		/**
		 * @var string
		 */
		private $activeCacheKey;
		/**
		 * @var int
		 */
		private $outputCacheTimeToLive;

		/**
		 * tfcCache constructor.
		 */
		public function __construct() {

			if ( defined( 'TFC_CACHE' ) ) {

				$definedCache = strtolower( TFC_CACHE );
				$className    = __NAMESPACE__ . '\\' . 'cacheClass\\' . $definedCache;
				$this->class  = new $className();
			} else {
				$this->class
					= new cacheClass\defaultCache();
			}

		}

		/**
		 *
		 * @param $key
		 *
		 * @param int $ttl
		 *
		 * @return mixed
		 */
		public static function tfcOutputCacheStart( $key , $ttl = 300 ) {
			self::runCache()->activeCacheKey        = $key;
			self::runCache()->outputCacheTimeToLive = $ttl;
			if ( self::runCache()->tfcExists( $key ) && $key ) {
				self::runCache()->cachHit = true;
				echo '<!-- '.self::runCache()->activeCacheKey.' Content Cache Start --!>';
			} else {
				ob_start();
			}

			return;
		}

		/**
		 * @return tfcCache
		 */
		public static function runCache() {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * @param $key
		 *
		 * @return bool
		 */
		function tfcExists( $key ) {
            return $this->class->tfcExists(crc32(WEB_PATH) . $key) === true;
        }

		/**
		 * End Output Cache
		 */
		public static function tfcOutputCacheEnd() {
			if ( self::runCache()->cachHit !== true ) {
				$bufferedData = ob_get_clean();
                self::runCache()->tfcStore( self::runCache()->activeCacheKey , $bufferedData , self::runCache()->outputCacheTimeToLive );
				echo $bufferedData;
				unset ( $bufferedData );
			} else {
				echo '<!-- '.self::runCache()->activeCacheKey.' Content Cache End --!>';
				self::runCache()->cachHit = false;
			}
			self::runCache()->activeCacheKey        = null;
			self::runCache()->outputCacheTimeToLive = null;
			return;
		}

        /**
         * @param $key
         * @param $data
         * @param $ttl
         *
         * @return mixed
         */
		public function tfcStore( $key , $data , $ttl ) {
			return $this->class->tfcStore( crc32( WEB_PATH ) . $key , $data , $ttl );
		}

		/**
		 * @param $key
		 *
		 * @return mixed
		 */
		public function tfcDelete( $key ) {
			return $this->class->tfcDelete( crc32( WEB_PATH ) . $key );
		}

		/**
		 * @param $class
		 *
		 * @return tfcCache
		 */
		public function setClass( $class ) {
			$this->class = $class;

			return $this;
		}

		/**
		 * @param $key
		 * @param $data
		 * @param $ttl
		 *
		 * @return mixed
		 */
		public function tfcGet( $key , $data , $ttl ) {
			$result = $this->tfcExists( $key );
			if ( $result !== false ) {
				return $this->tfcFetch( $key );
			}

            $this->tfcStore( $key , $data , $ttl );

            return $data;
        }

		/**
		 * @param $key
		 *
		 * @return mixed
		 */
		public function tfcFetch( $key ) {
			return $this->class->tfcFetch( crc32( WEB_PATH ) . $key );
		}

		/**
		 * @return bool
		 */
		function flush() {
			return $this->class->flush();
		}
	}