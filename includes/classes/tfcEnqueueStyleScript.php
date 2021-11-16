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
	namespace shopclass\includes\classes;
	use Scripts;

	/**
	 * Just an another wrapper for existing Scripts and Style class in osclass
	 * User: navjottomer
	 * Date: 15/08/18
	 * Time: 3:32 AM
	 */
	class tfcEnqueueStyleScript {
		private static $instance;
		private $styles;
		private $Scripts;
		private $scriptsLoaded;

		public function __construct() {
			$this->styles = array ();
			$this->Scripts = Scripts::newInstance();
			$this->scriptsLoaded = array ();
		}

		/**
		 * @return tfcEnqueueStyleScript
		 */
		public static function newInstance() {
			if ( ! self::$instance instanceof self ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Add style to be loaded
		 *
		 * @param string $id
		 * @param array|string $url
		 */
		public function addStyle( $id , $url ) {
			$this->styles[ $id ] = $url;
		}

		/**
		 * Remove style to not be loaded
		 *
		 * @param string $id
		 */
		public function removeStyle( $id ) {
			unset( $this->styles[ $id ] );
		}

		/**
		 * Get the css styles urls
		 */
		public function getStyles() {
			return $this->styles;
		}

		/**
		 * Print the HTML tags to load the styles
		 */
		public function printStyles() {
			foreach ( $this->styles as $id => $css ) {
				$class = null;
				if($id == 'main-css'){
					$class = 'class="changeme"';
				}
				echo '<link '.$class.' href="' . osc_apply_filter( 'style_url' , $css ) . '" rel="stylesheet" />' . PHP_EOL;
			}
		}

		/**
		 * Register Script for loading
		 *
		 * @param $id
		 * @param $url
		 * @param null $dependencies
		 */
		public function registerScript( $id , $url , $dependencies = null ) {
			osc_register_script( $id , $url , $dependencies );
		}

		/**
		 * Remove script to not be loaded
		 *
		 * @param string $id
		 */
		public function unregisterScript( $id ) {
			osc_unregister_script( $id );
		}

		/**
		 * Enqueu script to be loaded
		 *
		 * @param string $id
		 */
		public function enqueuScript( $id ) {
			osc_enqueue_script( $id );
		}

		/**
		 * Remove script to not be loaded
		 *
		 * @param string $id
		 */
		public function removeScript( $id ) {
			osc_remove_script( $id );
		}

		/**
		 *  Print the HTML tags to load the scripts
		 */
		public function printScripts() {
		    $this->Scripts->printScripts();
		}
	}