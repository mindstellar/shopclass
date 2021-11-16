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

	namespace shopclass\includes\cacheModal\cacheClass;

	use Exception;
	use shopclass\includes\cacheModal\tfcAbstractCache;

	/**
	 * Created by Navjot Tomer.
	 * User: navjottomer
	 * Date: 15/10/17
	 * Time: 7:41 AM
	 */

	/**
	 * Class FileCache
	 */
	class file extends tfcAbstractCache {

		/**
		 * FileCache constructor.
		 */
		public function __construct() {
			if ( ! ( is_dir( osc_uploads_path() . 'shopclass_cache' ) ) ) {
				mkdir( osc_uploads_path() . 'shopclass_cache' );
			}
		}


		/**
		 * @param $key
		 * @param $data
		 * @param $ttl
		 *
		 * @return mixed|void
		 * @throws Exception
		 */
		function tfcStore( $key , $data , $ttl ) {

			// Opening the file in read/write mode
			$h = fopen( $this->getFileName( $key ) , 'a+' );
			if ( ! $h ) {
				throw new Exception( 'Could not write to cache' );
			}

			flock( $h , LOCK_EX ); // exclusive lock, will get released when the file is closed

			fseek( $h , 0 ); // go to the beginning of the file

			// truncate the file
			ftruncate( $h , 0 );

			// Serializing along with the TTL
			$data = serialize(
				array (
					time() + $ttl ,
					$data
				)
			);
			if ( fwrite( $h , $data ) === false ) {
				throw new Exception( 'Could not write to cache' );
			}
			fclose( $h );

		}


		/**
		 * General function to find the filename for a certain key
		 *
		 * @param $key
		 *
		 * @return string
		 */
		private function getFileName( $key ) {
			//return ini_get('session.save_path') . '/s_cache' . md5($key);
			return osc_uploads_path() . 'shopclass_cache/s_cache' . $key;

		}

		/**
		 * @param $key
		 *
		 * @return bool
		 */
		function tfcFetch( $key ) {

			$filename = $this->getFileName( $key );
			if ( ! file_exists( $filename ) ) {
				return false;
			}
			$h = fopen( $filename , 'r' );

			if ( ! $h ) {
				return false;
			}

			// Getting a shared lock
			flock( $h , LOCK_SH );

			$data = file_get_contents( $filename );
			fclose( $h );

			$data = unserialize( $data );
			if ( ! $data ) {

				// If unserializing somehow didn't work out, we'll delete the file
				@unlink( $filename );

				return false;

			}

			if ( time() > $data[ 0 ] ) {

				if ( file_exists( $filename ) ) {
					// Unlinking when the file was expired
					unlink( $filename );
				}

				return false;

			}

			return $data[ 1 ];
		}

		/**
		 * @param $key
		 *
		 * @return bool
		 */
		function tfcDelete( $key ) {

			$filename = $this->getFileName( $key );
			if ( file_exists( $filename ) ) {
				return unlink( $filename );
			} else {
				return false;
			}

		}

		/**
		 * @return bool
		 */
		function flush() {
			$cachedir = osc_uploads_path() . 'shopclass_cache/';
			$prefix   = 's_cache';
			chdir( $cachedir );
			$matches = glob( $prefix . '*' , GLOB_MARK );
			if ( is_array( $matches ) && ! empty( $matches ) ) {
				foreach ( $matches as $match ) {
					if ( is_file( $cachedir . $match ) ) {

						@unlink( $cachedir . $match );

					}
				}

			}

			return true;
		}

		/**
		 * @param $key
		 *
		 * @return bool
		 */
		function tfcExists( $key ) {
			if ( file_exists( $this->getFileName( $key ) ) ) {
				return true;
			}

			return false;
		}
	}