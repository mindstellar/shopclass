<?php

    #########################USER_PROFILE####################
    use shopclass\includes\classes\tfcAdsLoop;

    $address = '';
    if ( osc_user_address() != '' ) {
        if ( osc_user_city_area() != '' ) {
            $address = osc_user_address() . ", " . osc_user_city_area();
        } else {
            $address = osc_user_address();
        }
    } else {
        $address = osc_user_city_area();
    }
    $location_array = array ();
    if ( trim( osc_user_city() . " " . osc_user_zip() ) != '' ) {
        $location_array[] = trim( osc_user_city() . " " . osc_user_zip() );
    }
    if ( osc_user_region() != '' ) {
        $location_array[] = osc_user_region();
    }
    if ( osc_user_country() != '' ) {
        $location_array[] = osc_user_country();
    }
    $location = implode( ", " , $location_array );
    unset( $location_array );
    $userId = osc_logged_user_id();

    osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-4 profile">
        <?php include 'user-sidebar.php'; ?>
    </div>
    <div class="col-md-8">
        <div class="content user_account">
            <div class="user-stats row card-box cardbox-info modal-body">
                <p class="lead col-md-12 text-center"><?php _e( 'Your Stats' , 'shopclass' ); ?>:</p>
                <div class="col-sm-4 text-center">
                    <p><i class="fa fa-user-plus fa-4x"></i></p>
                    <p class="fa-2x"><?php echo tfc_total_user_items( $userId ); ?></p>
                    <p><?php _e( 'Your Total Active Ads' , 'shopclass' ); ?></p>
                </div>
                <div class="col-sm-4 text-center">
                    <p><i class="fa fa-heart fa-4x"></i></p>
                    <p class="fa-2x"><?php echo tfc_favourite_user_items( $userId ); ?></p>
                    <p><?php _e( 'Your Favourite Ads' , 'shopclass' ); ?></p>
                </div>
                <div class="col-sm-4 text-center">
                    <p><i class="fa fa-eye fa-4x"></i></p>
                    <?php ?>
                    <p class="fa-2x"><?php echo tfc_total_user_item_views( $userId ); ?></p>

                    <p><?php _e( 'Total Views On Ads' , 'shopclass' ); ?></p>
                </div>
            </div>
            <hr>
            <div class="user-social-connection row card-box cardbox-warning">
                <div class="col-md-12 text-center modal-body">

                    <p class="lead"><?php _e( 'Manage your social profile' , 'shopclass' ); ?>:</p>

                    <?php tfc_login_button( 'Google' ); ?>
                    <?php tfc_login_button( 'Facebook' ); ?>
                    <?php tfc_login_button( 'Twitter' ); ?>

                </div>
            </div>
            <hr>
            <div class="user-latest-items row card-box cardbox-default">
                <div class="col-md-12 modal-body">
                    <?php echo '<p class="lead text-center">' . __( 'Your Last Posted Ad' , 'shopclass' ) . '</p>'; ?>
                    <?php
                        $userSearch = new Search();
                        $userSearch->fromUser( osc_logged_user_id() );
                        $userSearch->order( 'pk_i_id' , 'DESC' );
                        $userSearch->limit( 0 , 1 );
                        $items = $userSearch->doSearch();
                        View::newInstance()->_exportVariableToView( 'items' , $items );

                        if ( osc_count_items() > 0 ) { ?>
                            <?php while ( osc_has_items() ) { ?>
                                <?php tfcAdsLoop::newInstance()->renderItem( tfcAdsLoop::newInstance()->getItemProperty( 'item' ) , 'list' ); ?>
                            <?php } ?>
                        <?php } else {
                            echo '<h2>' . __( 'You have not published any Ad' , 'shopclass' ) . '</h2>';
                        } ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
</body>
</html>