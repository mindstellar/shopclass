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

    use shopclass\includes\cacheModal\tfcAbstractCache;

    /**
     * Created by Navjot Tomer.
     * User: navjottomer
     * Date: 15/10/17
     * Time: 9:59 AM
     */

    /**
     * Class memcache
     */
    class memcached extends tfcAbstractCache {

        // Memcache object
        private $connection;

        /**
         * tfcCache constructor.
         *
         */
        function __construct() {
            $this->connection = new \Memcached();
            global $memcached_config;
            if ( ! isset( $memcached_config ) ) {
                $memcached_config = array ();
            }
            $this->setConnection( $memcached_config );

        }

        /**
         * @param array $memcached_config
         *
         * @return memcached
         * @internal param Memcached $connection
         *
         */
        public function setConnection( $memcached_config = array () ) {
            if ( empty( $memcached_config ) ) {
                $memcached_config[] = array ( 'host' => '127.0.0.1' , 'port' => '112111' , 'weight' => '1' );
            }
            foreach ( $memcached_config as $config ) {
                $this->connection->addServer( $config[ 'host' ] , $config[ 'port' ] , $config[ 'weight' ] );
            }

            return $this;
        }

        /**
         * @param $key
         * @param $data
         * @param $ttl
         *
         * @return bool
         */
        function tfcStore( $key , $data , $ttl ) {

            return $this->connection->set( $key , $data , $ttl );

        }

        /**
         * @param $key
         *
         * @return array|string
         */
        function tfcFetch( $key ) {

            return $this->connection->get( $key );

        }

        /**
         * @param $key
         *
         * @return bool
         */
        function tfcDelete( $key ) {

            return $this->connection->delete( $key );

        }

        /**
         * @return array
         */
        public function tfcGetStats() {
            return $this->connection->getStats();
        }

        /**
         * @return bool
         */
        function flush() {

            return $this->connection->flush();

        }


        /**
         * @param $key
         *
         * @return bool
         */
        function tfcExists( $key ) {
            if ( $this->connection->get( $key ) ) {
                return true;
            } else {
                return false;
            }
        }
    }