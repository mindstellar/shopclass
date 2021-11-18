<?php
    #########################USER_PULIC_PROFILE####################
    use shopclass\includes\classes\tfcAdsLoop;
    use shopclass\includes\frm\tfcContactForm;

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
?>
<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <?php $contactEnabled = false;
        if ( osc_logged_user_id() != osc_user_id() ) {
            if ( osc_reg_user_can_contact() && osc_is_web_user_logged_in() || ! osc_reg_user_can_contact() ) {
                $contactEnabled = true;
            }
        } ?>

    <div class="profile-sidebar col-md-4 tfc-item">
        <!-- SIDEBAR USERPIC -->
        <div class="profile-userpic">

            <img src="<?php echo tfc_user_profile_pic_url( osc_user_id() ); ?>" class="img-responsive" alt="">
        </div>
        <!-- END SIDEBAR USERPIC -->
        <!-- SIDEBAR USER TITLE -->
        <div class="profile-usertitle">

            <div class="profile-usertitle-name">
                <?php echo osc_user_name() . "'s Profile"; ?>
            </div>
            <div class="profile-usertitle-job text-primary">
                <?php echo( osc_user_is_company() ? __( 'Company' , 'shopclass' ) : __( 'Individual' , 'shopclass' ) ); ?>
            </div>
        </div>
        <!-- END SIDEBAR USER TITLE -->
        <!-- SIDEBAR BUTTONS -->
        <div class="profile-userbuttons">
            <button type="button" class="btn btn-danger btn-sm" data-toggle="modal" data-target="#contactseller"><i
                        class="fa fa-envelope"></i><?php _e( 'Contact User' , 'shopclass' ); ?></button>
        </div>
        <!-- END SIDEBAR BUTTONS -->
        <!-- SIDEBAR MENU -->
        <div class="profile-usermenu">
            <ul class="nav">
                <li>
                    <div class="nav-link"><?php echo osc_user_info(); ?></div>
                </li>
                <li>
                    <div class="nav-link"><strong><i class="fa fa-user"></i><?php _e( ' Full name' ); ?>
                            :</strong> <?php echo osc_user_name(); ?></div>
                </li>
                <li>
                    <div class="nav-link"><strong><i class="fa fa-home"></i><?php _e( ' Address' ); ?>
                            :</strong> <?php echo $address; ?></div>
                </li>
                <li>
                    <div class="nav-link"><strong><i class="fa fa-map-marker"></i><?php _e( ' Location' ); ?>
                            :</strong> <?php echo $location; ?></div>
                </li>
                <li>
                    <div class="nav-link"><strong><i class="fa fa-globe"></i><?php _e( ' Website' ); ?>
                            :</strong> <?php echo osc_user_website(); ?></div>
                </li>
                <li>
                    <div class="nav-link"><strong><i class="fa fa-phone"></i><?php _e( ' Phone No' ); ?>
                            :</strong> <?php echo osc_user_phone(); ?></div>
                </li>
            </ul>
        </div>

    </div>
    <div class="main col-md-8">
        <div class="tfc-item">
            <div class="panel-heading">
                <div class="panel-title"><?php _e( 'Latest ads from this seller' ); ?></div>
            </div>
            <div class="panel-body">
                <?php if ( osc_count_items() > 0 ) { ?>
                    <div id="description" class="latest_ads">
                        <?php $class = "even"; ?>
                        <?php while ( osc_has_items() ) {
                            tfcAdsLoop::newInstance()->renderItem( tfcAdsLoop::newInstance()->getItemProperty( 'item' ) , 'list' ); ?>
                        <?php } ?>
                    </div>
                    <div class="col-md-6 col-md-offset-5">
                        <?php echo tfc_pagination_items(); ?>
                    </div>
                <?php } ?>
            </div>

        </div>
    </div>
</div>
<!-- Modal Contact Seller -->

<div class="modal fade" id="contactseller" tabindex="-1" role="dialog" aria-labelledby="modalContactSeller"
     aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
                <i class="fa fa-envelope-o fa-fw"></i><?php _e( "Contact Seller" , 'shopclass' ); ?>
            </div>
            <form action="<?php echo osc_base_url( true ); ?>" method="post" name="contact_form" class="form-group"
                  id="contact_form">
                <input type="hidden" name="action" value="contact_post"/>
                <input type="hidden" name="page" value="user"/>
                <input type="hidden" name="id" value="<?php echo osc_user_id(); ?>"/>
                <div class="modal-body">
                    <div id="contact" class="tfc-form">
                        <?php if ( ( osc_logged_user_id() == osc_user_id() ) && osc_logged_user_id() != 0 ) { ?>
                            <p>
                                <?php _e( "It's your own page, you cannot use this feature." , 'shopclass' ); ?>
                            </p>
                        <?php } else if ( osc_reg_user_can_contact() && ! osc_is_web_user_logged_in() ) { ?>
                            <p>
                                <?php _e( "You must login or register a new free account in order to contact the advertiser" , 'shopclass' ); ?>
                            </p>
                            <p class="contact_button">
                                <strong><a class="btn btn-default"
                                           href="<?php echo osc_user_login_url(); ?>"><?php _e( 'Login' , 'shopclass' ); ?></a></strong>
                                <strong><a class="btn btn-default"
                                           href="<?php echo osc_register_account_url(); ?>"><?php _e( 'Register for a free account' , 'shopclass' ); ?></a></strong>
                            </p>
                        <?php } else { ?>
                            <p class="name"><?php _e( 'Name' , 'shopclass' ) ?>: <?php echo osc_user_name(); ?></p>
                            <?php if ( osc_user_phone() != '' ) { ?>
                                <p class="phone"><?php _e( "Tel" , 'shopclass' ); ?>
                                    .: <?php echo osc_user_phone(); ?></p>
                            <?php } ?>
                            <ul id="error_list"></ul>
                            <?php osc_prepare_user_info(); ?>
                            <fieldset class="tfc-form">
                                <div class="form-group">
                                    <label for="yourName"><?php _e( 'Your name' , 'shopclass' ); ?>:</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-user"></span>
                                        </span>
                                        <?php tfcContactForm::your_name(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="yourEmail"><?php _e( 'Your e-mail address' , 'shopclass' ); ?>:</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-envelope"></span>
                                        </span>
                                        <?php tfcContactForm::your_email(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="phoneNumber"><?php _e( 'Phone number' , 'shopclass' ); ?>
                                        (<?php _e( 'optional' , 'shopclass' ); ?>):</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-phone"></span>
                                        </span>
                                        <?php tfcContactForm::your_phone_number(); ?>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="message"><?php _e( 'Message' , 'shopclass' ); ?>:</label>
                                    <div class="input-group">
                                        <span class="input-group-addon"><span class="fa fa-comment"></span>
                                        </span>
                                        <?php tfcContactForm::your_message(); ?>
                                    </div>
                                </div>
                                <?php osc_run_hook( 'tf_after_form' ); ?>
                                <?php osc_show_recaptcha(); ?>
                            </fieldset>
                        <?php } ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-success"
                            type="submit" <?php echo ( $contactEnabled ) ? '' : 'disabled'; ?>><?php _e( 'Send' , 'shopclass' ); ?></button>
                    <button type="button" class="btn btn-warning" data-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script>
    $("form[name=contact_form]").validate({
        rules: {
            message: {
                required: true,
                minlength: 20
            },
            yourEmail: {
                required: true,
                email: true
            },
            yourName: {
                required: true,
                minlength: 5
            }

        },
        messages: {
            yourEmail: {
                required: "<?php _e( "This field is required" ); ?>.",
                email: "<?php _e( "Invalid email address" ); ?>."
            },
            message: {
                required: "<?php _e( "This field is required" ); ?>.",
                minlength: "<?php _e( "Your Message is too short" ); ?>."
            },
            yourName: {
                required: "<?php _e( "This field is required" ); ?>.",
                minlength: "<?php _e( "Your Name is too short" ); ?>."
            }
        },
        highlight: function (element) {
            $(element).closest('.form-group').addClass('has-error');
        },
        unhighlight: function (element) {
            $(element).closest('.form-group').removeClass('has-error');
        },
        errorElement: 'span',
        errorClass: 'help-block',
        errorPlacement: function (error, element) {
            if (element.parent('.input-group').length) {
                error.insertAfter(element.parent());
            } else {
                error.insertAfter(element);
            }
        }
    });
</script>
</body>
</html>