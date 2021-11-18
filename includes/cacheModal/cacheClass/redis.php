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
     * Date: 2019-07-04
     * Time: 10:05
     */

    namespace shopclass\includes\cacheModal\cacheClass;


    use Exception;
    use shopclass\includes\cacheModal\tfcAbstractCache;

    /**
     * Class redis
     * @package shopclass\includes\cacheModal\cacheClass
     */
    class redis extends tfcAbstractCache {

        private $connection;

        public function __construct() {
            $this->connection = new \Redis();
            global $redis_config;
            if ( ! isset( $redis_config ) ) {
                $redis_config = array ();
            }
            $this->setConnection( $redis_config );
        }

        /**
         * @param array $redis_config
         *
         * @return $this
         */
        private function setConnection( array $redis_config ) {
            if ( empty( $redis_config ) ) {
                $redis_config[] = array ( 'host' => '127.0.0.1' , 'port' => '6379' );
            }
            foreach ( $redis_config as $config ) {
                try {
                    $this->connection->connect( $config[ 'host' ] , $config[ 'port' ] );
                    $this->connection->setOption( \Redis::OPT_SERIALIZER , \Redis::SERIALIZER_PHP );
                } catch ( Exception $e ) {
                    throw new Exception( $e->getMessage() );
                }
            }

            return $this;
        }

        /**
         * @param $key
         *
         * @return mixed
         */
        function tfcFetch( $key ) {

            return $this->connection->get( $key );
        }

        /**
         * @param $key
         * @param $data
         * @param $ttl
         *
         * @return mixed
         */
        function tfcStore( $key , $data , $ttl ) {
            return $this->connection->setex( $key , $ttl , $data );
        }

        /**
         * @param $key
         *
         * @return mixed
         */
        function tfcDelete( $key ) {
            return $this->connection->del( $key );
        }

        /**
         * @return bool
         */
        function flush() {
            return $this->connection->flushAll();
        }

        /**
         * @param $key
         *
         * @return bool
         */
        function tfcExists( $key ) {
            return $this->connection->exists( $key );
        }
    }