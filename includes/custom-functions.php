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

//You custom functions goes here
//You can include any custom function here this file can be found in shopclass/includes/.... directory
/////////////..................................//////////////////

##Function to add thousand seprator on item price. 
    /**
     * @param $price
     *
     * @return string
     */
    function new_osc_format_price( $price ) {
        $currencySymbol = ( osc_item_is_premium() ) ? osc_premium_currency_symbol() : osc_item_currency_symbol();
        $price          = str_replace( $currencySymbol , '' , $price );
        setlocale( LC_MONETARY , 'en_US.UTF-8' ); //Set Locale to your desired currency format.
        $currencyFormat = money_format( '%!.0n' , (double) $price ) . ' ' . $currencySymbol;

        return $currencyFormat;
    }
