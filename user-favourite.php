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

    osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<?php
    $adminoptions = false;
?>
<div class="row">
    <div class="col-md-4">
        <?php tfc_path( 'user-sidebar.php' ); ?>
    </div>
    <div class="col-md-8">
        <div class="tfc-item">
            <div class="panel-heading">
                <div class="panel-title"><?php _e( 'Your favourite' , 'shopclass' ); ?></div>
            </div>
            <div class="content user_account panel-body">

                <?php if ( osc_count_items() == 0 ) { ?>
                    <h3><?php _e( 'Nothing interested you yet!' , 'shopclass' ); ?></h3>
                <?php } else { ?>
                    <?php while ( osc_has_items() ) { ?>
                        <div class="item col-md-12">
                            <p class="delete-btn" data-toggle="tooltip" data-animation="true" data-placement="top"
                               data-original-title="<?php
                                   _e( /** @lang text */ 'Delete from your favourite list' , 'shopclass' ) ?>"><a
                                        class="delete"
                                        onclick="javascript:return confirm('<?php _e( 'This action can not be undone. Are you sure you want to continue?' , 'shopclass' ); ?>')"
                                        href="<?php echo tfc_favourite_url() . '&delete=' . osc_item_id(); ?>"><i
                                            class="fa fa-times"></i></a>
                            </p>
                            <?php tfcAdsLoop::newInstance()->renderItem( tfcAdsLoop::newInstance()->getItemProperty( 'item' ) , 'list' ); ?>
                        </div>
                    <?php } ?>

                    <div class="col-md-6 col-md-offset-5">
                        <?php echo tfc_pagination_items( array (
                                                             'url'       => osc_route_url( 'shopclass-favourite' , array ( 'iPage' => '{PAGE}' ) ) ,
                                                             'first_url' => tfc_favourite_url()
                                                         ) ); ?>
                    </div>
                <?php } ?>

            </div>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
</body>
</html>