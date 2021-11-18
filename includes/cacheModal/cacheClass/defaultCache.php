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
     * Created by PhpStorm.
     * User: navjottomer
     * Date: 19/07/18
     * Time: 3:18 AM
     */

    /**
     * Class defaultCache
     */
    class defaultCache extends tfcAbstractCache {
        /**
         * @param $key
         *
         * @return mixed
         */
        function tfcFetch( $key ) {
            return false;
        }

        /**
         * @param $key
         * @param $data
         * @param $ttl
         *
         * @return mixed
         */
        function tfcStore( $key , $data , $ttl ) {
            return true;
        }

        /**
         * @param $key
         *
         * @return mixed
         */
        function tfcDelete( $key ) {
            return false;
        }

        /**
         * @return bool
         */
        function flush() {
            return true;
        }

        /**
         * @param $key
         *
         * @return bool
         */
        function tfcExists( $key ) {
            return false;
        }
    }