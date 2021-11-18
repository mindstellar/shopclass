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

    use shopclass\includes\classes\tfcAdsLoop;

    if ( $_SERVER[ 'HTTP_X_REQUESTED_WITH' ] == 'XMLHttpRequest' ) {
        $start_limit = Params::getParam( 'offset' );
        $end_limit   = Params::getParam( 'limit' );
        $showas      = Params::getParam( 'showas' );
        if ( $showas !== 'gallery' ) {
            $showas = 'list';
        }
        $listClass    = Params::getParam( 'listclass' ) ? Params::getParam( 'listclass' ) : '';
        $galleryClass = Params::getParam( 'galleryclass' ) ? Params::getParam( 'galleryclass' ) : 'col-md-4 col-sm-6 col-xs-12';

        $mSearch = new Search();
        //limiting number of ads
        $mSearch->limit( $start_limit , $end_limit ); // fetch number of ads to show set in ajax request

        //Searching with all enabled conditions
        $aItems = $mSearch->doSearch( true , false );

        $global_items = View::newInstance()->_get( 'items' ); //save existing item array
        View::newInstance()->_exportVariableToView( 'items' , $aItems ); //exporting our searched item array

        if ( osc_count_items() > 0 ) {


            while ( osc_has_items() ) {
                tfcAdsLoop::newInstance()->
                setListClass( $listClass )->
                setGalleryClass( $galleryClass )->
                renderItem(                 tfcAdsLoop::newInstance()->
                getItemProperty( 'item' ) , $showas );
            }

        } else {
            header( "HTTP/1.0 204 No Content" );
        }
        //calling stored item array
        View::newInstance()->_exportVariableToView( 'items' , $global_items ); //restore original item array
    }