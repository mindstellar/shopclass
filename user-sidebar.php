<?php $username = explode( ' ' , osc_logged_user_name() ); ?>

<div class="profile-sidebar card-box cardbox-default">
    <!-- SIDEBAR USERPIC -->
    <div class="row">
        <div class="col-md-12 col-sm-6">
            <div class="profile-userpic">

                <img src="<?php echo tfc_user_profile_pic_url( osc_logged_user_id() ); ?>" class="img-responsive"
                     alt="">
            </div>
            <!-- END SIDEBAR USERPIC -->
            <!-- SIDEBAR USER TITLE -->
            <div class="profile-usertitle">

                <div class="profile-usertitle-name">
                    <?php echo $username[ 0 ] . "'s Dashboard"; ?>
                </div>
                <div class="profile-usertitle-job text-primary">
                    <?php echo( osc_user_is_company() ? __( 'Company' , 'shopclass' ) : __( 'Individual' , 'shopclass' ) ); ?>
                </div>
            </div>
            <!-- END SIDEBAR USER TITLE -->
            <!-- SIDEBAR BUTTONS -->
            <div class="profile-userbuttons">
                <a href="<?php echo osc_item_post_url_in_category(); ?>" class="btn btn-success btn-sm"><i
                            class="fa fa-plus-circle"></i><?php _e( 'New Ad' , 'shopclass' ); ?></a>
                <a href="<?php echo osc_user_logout_url(); ?>" class="btn btn-danger btn-sm"><i
                            class="fa fa-sign-out"></i><?php _e( 'Logout' , 'shopclass' ); ?></a>
            </div>
        </div>    <!-- END SIDEBAR BUTTONS -->
        <div class="col-md-12 col-sm-6">
            <!-- SIDEBAR MENU -->
            <div class="profile-usermenu">
                <ul class="nav">
                    <?php tfc_private_user_menu(); ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- END MENU -->
    <div class="text-center user-dashboard-widget"><?php osc_show_widgets( 'user-dashboard' ); ?></div>
</div>