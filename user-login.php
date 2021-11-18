<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="row">
    <div class="col-md-8 col-md-offset-2 tfc-item">
        <p class="text-center"><i class="fa fa-user fa-5x"></i></p>
        <div class="col-md-6">
            <div style="display:none" id="login-alert" class="alert alert-danger col-sm-12"></div>
            <form id="loginform" class="form-horizontal" action="<?php echo osc_base_url( true ); ?>" method="post">
                <p class="lead form-group"><?php _e( 'LogIn to your dashboard' , 'shopclass' ); ?></p>
                <input type="hidden" name="page" value="login"/>
                <input type="hidden" name="action" value="login_post"/>
                <div class="form-group">
                    <label for="email" class="control-label"><?php _e( 'Your Email' , 'shopclass' ); ?></label>
                    <input id="email" type="text" name="email" class="form-control" value=""
                           placeholder="<?php echo osc_esc_html( __( 'example@gmail.com' , 'shopclass' ) ); ?>">
                    <span class="help-block"></span>
                </div>
                <div class="form-group">
                    <label for="password" class="control-label"><?php _e( 'Password' , 'shopclass' ); ?></label>
                    <input id="password" type="password" name="password" class="form-control"
                           placeholder="<?php echo osc_esc_html( __( 'Password' , 'shopclass' ) ); ?>">
                    <span class="help-block"></span>
                </div>
                <div class="form-group">
                    <div class="checkbox">
                        <label>
                            <?php UserForm::rememberme_login_checkbox(); ?><?php _e( 'Remember Me' , 'shopclass' ); ?>
                        </label>
                        <p class="help-block"><?php _e( '(if this is a private computer)' , 'shopclass' ); ?></p>
                    </div>
                    <button type="submit"
                            class="btn btn-success btn-block"><?php _e( 'Submit' , 'shopclass' ); ?></button>

                    <?php
                        echo '<div class="btn-block text-center h3">' . __( 'Or' , 'shopclass' ) . '</div>';
                        echo ( osc_get_preference( 'tfcGoogleEnabled' , 'shopclass_theme' ) ) ? '<a href="' . osc_base_url( true ) . '?tfclogin=Google" class="btn btn-block btn-googleplus"><i class="fa fa-google-plus"></i> ' . __( 'Login with Google' , 'shopclass' ) . '</a>' : '';

                        echo ( osc_get_preference( 'tfcFacebookEnabled' , 'shopclass_theme' ) ) ? '<a href="' . osc_base_url( true ) . '?tfclogin=Facebook" class="btn btn-block btn-facebook"><i class="fa fa-facebook"></i> ' . __( 'Login with Facebook' , 'shopclass' ) . '</a>' : '';

                        echo ( osc_get_preference( 'tfcTwitterEnabled' , 'shopclass_theme' ) ) ? '<a href="' . osc_base_url( true ) . '?tfclogin=Twitter" class=" btn btn-block btn-twitter"><i class="fa fa-twitter"></i> ' . __( 'Login with Twitter' , 'shopclass' ) . '</a>' : '';
                    ?>
                </div>
            </form>

        </div>
        <div class="visible-xs-block visible-sm-block">
            <hr>
        </div>
        <div class="col-md-6">

            <p class="lead"><?php _e( 'Register now for' , 'shopclass' ); ?> <span
                        class="text-success"><?php _e( 'FREE' , 'shopclass' ); ?></span></p>
            <ul class="list-unstyled" style="line-height: 2">
                <li><span class="fa fa-check text-success"></span> <?php _e( 'See all your listings' , 'shopclass' ); ?>
                </li>
                <li>
                    <span class="fa fa-check text-success"></span> <?php _e( 'Upload your avatar/logo' , 'shopclass' ); ?>
                </li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Move listing to top' , 'shopclass' ); ?>
                </li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Save your favorites' , 'shopclass' ); ?>
                </li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Fast submission' , 'shopclass' ); ?></li>
                <li><span class="fa fa-check text-success"></span> <?php _e( 'Edit your listings' , 'shopclass' ); ?>
                </li>

            </ul>
            <p><a href="<?php echo osc_register_account_url(); ?>"
                  class="btn btn-info btn-block"><?php _e( 'Register now!' , 'shopclass' ); ?></a></p>
        </div>
        <div class="form-group">
            <div class="col-md-12">
                <div class="text-small" style="border-top: 1px solid#888; padding-top:15px;">
                    <?php _e( "Forget you password" , 'shopclass' ); ?>?
                    <a href="<?php echo osc_recover_user_password_url(); ?>"><?php _e( "Reset Here!" , 'shopclass' ); ?></a>
                </div>
            </div>
        </div>
    </div>

</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
</body>
</html>