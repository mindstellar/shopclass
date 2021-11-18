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
     * Time: 10:00 AM
     */

    /**
     * Class APCuCache
     */
    class apcu extends tfcAbstractCache {

        /**
         * @param $key
         *
         * @return mixed
         */
        function tfcFetch( $key ) {
            return apcu_fetch( $key );
        }

        /**
         * @param $key
         * @param $data
         * @param $ttl
         *
         * @return array|bool
         */
        function tfcStore( $key , $data , $ttl ) {

            return apcu_store( $key , $data , $ttl );

        }

        /**
         * @param $key
         *
         * @return bool|string[]
         */
        function tfcDelete( $key ) {

            return apcu_delete( $key );

        }

        /**
         * @return bool
         */
        function flush() {

            return apcu_clear_cache();

        }

        /**
         * @param $key
         *
         * @return bool
         */
        function tfcExists( $key ) {
            return apcu_exists( $key );
        }
    }