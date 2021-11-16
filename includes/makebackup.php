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

	define( 'ABS_PATH' , dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/' );
	require_once ABS_PATH . 'config.php';
	if ( $_SERVER[ 'HTTP_X_REQUESTED_WITH' ] == 'XMLHttpRequest' ) {
		if ( $_POST[ 'token' ] == md5( DB_NAME . DB_USER . DB_PASSWORD . DB_HOST . WEB_PATH ) ) {
			$file     = $_POST[ 'editfilename' ];
			$filepath = urldecode( $_POST[ 'editdirectory' ] );
			if ( file_exists( $filepath . $file ) ) {
				$backupcontent = file_get_contents( $filepath . $file );
				file_put_contents( $filepath . $file . '.bak' , $backupcontent );

				echo '<div class="flashmessage flashmessage-ok" style="display: block;">We did backup successfully!!</div>';
			} else {
				echo '<div class="flashmessage flashmessage-warning" style="display: block;">File not found !!</div>';
			}
		} else {
			echo '<div class="flashmessage flashmessage-error" style="display: block;">Security Error!!</div>';
		}
	} else {
		echo '<div class="flashmessage flashmessage-error" style="display: block;">Not a valid request!!</div>';
	};