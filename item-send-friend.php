<?php osc_current_web_theme_path( 'head.php' ); ?>
<body>
<?php osc_current_web_theme_path( 'header.php' ); ?>
<div class="container panel tfc-item">
    <div class="container">
        <legend><?php _e( 'Send to a friend' , 'shopclass' ); ?></legend>
        <div class="row">
            <div class="col-md-8">
                <div class="well well-sm">
                    <form id="sendfriend" name="sendfriend" action="<?php echo osc_base_url( true ); ?>" method="post">
                        <input type="hidden" name="action" value="send_friend_post"/>
                        <input type="hidden" name="page" value="item"/>
                        <input type="hidden" name="id" value="<?php echo osc_item_id(); ?>"/>
                        <fieldset>
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        <label><?php _e( 'Item' , 'shopclass' ); ?>: <a
                                                    href="<?php echo osc_item_url(); ?>"><?php echo osc_item_title(); ?></a></label>
                                    </div>

                                </div>

                                <div class="col-md-6">
									<?php if ( osc_is_web_user_logged_in() ) { ?>
                                        <input type="hidden" name="yourName"
                                               value="<?php echo osc_esc_html( osc_logged_user_name() ); ?>"/>
                                        <input type="hidden" name="yourEmail"
                                               value="<?php osc_logged_user_email(); ?>"/>
									<?php } else { ?>

                                        <div class="form-group">
                                            <label for="name">
												<?php _e( 'Your name' , 'shopclass' ); ?></label>
                                            <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-user"></span>
												</span>
                                                <input class="form-control" id="yourName" type="text" name="yourName"
                                                       value=""
                                                       placeholder="<?php echo osc_esc_html( __( 'Enter name' , 'shopclass' ) ); ?>">
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <label for="email">
												<?php _e( 'E-mail address' , 'shopclass' ); ?></label>
                                            <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-envelope"></span>
												</span>
                                                <input id="yourEmail" type="text" name="yourEmail" class="form-control"
                                                       placeholder="<?php echo osc_esc_html( __( 'Enter E-mail' , 'shopclass' ) ); ?>"
                                                       value="">
                                            </div>
                                        </div>
									<?php }; ?>
                                    <div class="form-group">
                                        <label for="subject">
											<?php _e( 'Friend Name' , 'shopclass' ); ?></label>
                                        <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-user"></span>
												</span>
                                            <input class="form-control" id="friendName" type="text" name="friendName"
                                                   placeholder="<?php echo osc_esc_html( __( 'Enter Friend Name' , 'shopclass' ) ); ?>"
                                                   value="">
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="email">
											<?php _e( 'Friend E-mail' , 'shopclass' ); ?></label>
                                        <div class="input-group">
												<span class="input-group-addon"><span class="fa fa-envelope"></span>
												</span>
                                            <input id="friendEmail" type="text" name="friendEmail" class="form-control"
                                                   placeholder="<?php echo osc_esc_html( __( 'Enter Friend E-mail' , 'shopclass' ) ); ?>"
                                                   value="">
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="name">
											<?php _e( 'Message' , 'shopclass' ); ?></label>
                                        <textarea id="message" name="message" class="form-control" rows="12" cols="25"
                                                  placeholder="<?php echo osc_esc_html( __( 'Enter your message' , 'shopclass' ) ); ?>"></textarea>
                                    </div>
                                </div>
                                <div class="col-md-12">
									<?php osc_run_hook( 'tf_after_form' ); ?>
									<?php osc_show_recaptcha(); ?>
									<?php osc_run_hook( 'user_register_form' ); ?>
                                    <hr>
                                    <button type="submit" class="btn btn-primary col-md-offset-3">
										<?php _e( 'Send Message' , 'shopclass' ); ?></button>
                                </div>
                    </form>
                </div>
                </fieldset>
            </div>
        </div>
        <div class="col-md-4 padding-top">
            <div class="profile-userpic">
                <img src="<?php echo tfc_user_profile_pic_url( osc_item_user_id() ); ?>"
                     class="img-responsive" alt="">
            </div>
            <div class="profile-usertitle">
                <div class="profile-usertitle-name">
					<?php echo osc_item_contact_name(); ?>
                </div>
                <div class="profile-usertitle-job text-primary">
					<?php if ( osc_user_is_company() ) {
						echo( __( 'Company' , 'shopclass' ) );
					} else {
						echo( __( 'Individual' , 'shopclass' ) );
					} ?>
                </div>
				<?php if ( function_exists( 'tfc_voting_item_detail_user' ) ) {
					echo '<div class="tex-center">';
					tfc_voting_item_detail_user();
					echo '</div>';
				} ?>
            </div>
        </div>
    </div>
</div>
</div>
<?php osc_current_web_theme_path( 'footer.php' ); ?>
<script>
    $(document).ready(function () {
        $("form[name=sendfriend]").validate({
            rules: {
                yourName: {
                    required: true
                },
                yourEmail: {
                    required: true,
                    email: true
                },
                friendName: {
                    required: true
                },
                friendEmail: {
                    required: true,
                    email: true
                },
                message: {
                    required: true
                }
            },
            messages: {
                yourName: {
                    required: "<?php _e( "Your name: this field is required" ); ?>."
                },
                yourEmail: {
                    email: "<?php _e( "Invalid email address" ); ?>.",
                    required: "<?php _e( "Email: this field is required" ); ?>."
                },
                friendName: {
                    required: "<?php _e( "Friend's name: this field is required" ); ?>."
                },
                friendEmail: {
                    required: "<?php _e( "Friend's email: this field is required" ); ?>.",
                    email: "<?php _e( "Invalid friend's email address" ); ?>."
                },
                message: "<?php _e( "Message: this field is required" ); ?>."

            },
            highlight: function (element) {
                $(element).closest('.form-group').addClass('has-warning');
            },
            unhighlight: function (element) {
                $(element).closest('.form-group').removeClass('has-warning');
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
    });

</script>
</body>
</html>
