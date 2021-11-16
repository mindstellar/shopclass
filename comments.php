<?php if ( osc_comments_enabled() ) {
	if ( tfc_getPref( 'enable_disqus' ) ) { ?>
        <div class="panel panel-primary">
            <div class="panel-heading">
                <h4 class="panel-title">
                    <i class="fa fa-comments-o"></i> <?php _e( 'Comments' , 'shopclass' ); ?>
                </h4>
            </div>
            <div class="panel-body">
                <div id="comments">
                    <div id="disqus_thread"></div>
                    <script type="text/javascript">
                        /* * * CONFIGURATION VARIABLES: EDIT BEFORE PASTING INTO YOUR WEBPAGE * * */
                        var disqus_shortname = '<?php echo tfc_getPref( 'disqus_shortname' ); ?>'; // required: replace example with your forum shortname
                        var disqus_identifier = '<?php echo osc_item_id(); ?>';
                        var disqus_url = '<?php echo osc_item_url(); ?>';
                        /* * * DON'T EDIT BELOW THIS LINE * * */
                        (function () {
                            var dsq = document.createElement('script');
                            dsq.type = 'text/javascript';
                            dsq.async = true;
                            dsq.src = 'https://' + disqus_shortname + '.disqus.com/embed.js';
                            (document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0]).appendChild(dsq);
                        })();
                    </script>
                    <noscript>Please enable JavaScript to view the <a href="http://disqus.com/?ref_noscript"
                                                                      rel="nofollow">comments powered by Disqus.</a>
                    </noscript>
                </div>
            </div>
        </div>
	<?php } else { ?>
		<?php
		$showComment     = false;
		$showCommentForm = false;
		if ( osc_reg_user_post_comments() && osc_is_web_user_logged_in() || ! osc_reg_user_post_comments() ) {
			$showCommentForm = true;
		}
		if ( osc_count_item_comments() >= 1 ) {
			$showComment = true;
		}
		?>
		<?php if ( $showCommentForm || $showComment ) { ?>
            <div id="tfc-comment">
                <div class="comment-header clearfix">
                    <h4 class="pull-left"><?php _e( 'Comments' , 'shopclass' ); ?></h4>
                    <button id="toggleComment"
                            class="btn btn-sm btn-default pull-right" <?php echo ( $showCommentForm ) ? '' : 'disabled'; ?>>
						<?php _e( 'Add Comment' , 'shopclass' ); ?>
                    </button>
                </div>
                <div class="border-top-2p"></div>
            </div>
			<?php if ( $showCommentForm ) ?>
                <div id="toggleCommentForm" style="display:none" class="comment-form">

                <div class="col-md-12">
                <div id="comments">
                <form action="<?php osc_base_url( true ); ?>" method="post" name="comment_form" id="comment_form">
            <fieldset>
                <div id="comment_error_list">
                    <div id="ajax-alert"></div>
                </div>
                <input type="hidden" name="id" value="<?php echo osc_item_id(); ?>"/>
                <div class="form-group">
					<?php if ( osc_is_web_user_logged_in() ) { ?>
                        <input type="hidden" name="authorName"
                               value="<?php echo osc_esc_html( osc_logged_user_name() ); ?>"/>
                        <input type="hidden" name="authorEmail" value="<?php echo osc_logged_user_email(); ?>"/>
					<?php } else { ?>
                        <label for="authorName"><?php _e( 'Your name' , 'shopclass' ); ?>:</label>
                        <div class="input-group">
                                <span class="input-group-addon"><span class="fa fa-user"></span>
                                </span>
                            <input class="form-control" id="authorName" type="text" name="authorName" value=""
                                   autocomplete="off"
                                   placeholder="<?php echo osc_esc_html( __( 'Enter your name' , 'shopclass' ) ); ?>">
                        </div>
                        <label for="authorEmail"><?php _e( 'Your e-mail' , 'shopclass' ); ?>:</label>
                        <div class="input-group">
                                <span class="input-group-addon"><span class="fa fa-envelope"></span>
                                </span>
                            <input class="form-control" id="authorEmail" type="text" name="authorEmail" value=""
                                   placeholder="<?php echo osc_esc_html( __( 'abc@example.com' , 'shopclass' ) ); ?>">
                        </div>
					<?php }; ?>
                    <label for="title"><?php _e( 'Title' , 'shopclass' ); ?>:</label>
                    <div class="input-group">
                                <span class="input-group-addon"><span class="fa fa-tag"></span>
                                </span>
                        <input class="form-control" id="title" type="text" name="title" value=""
                               placeholder="<?php echo osc_esc_html( __( 'Enter title' , 'shopclass' ) ); ?>">
                    </div>
                    <label for="body"><?php _e( 'Comment' , 'shopclass' ); ?>:</label>
                    <div class="input-group">
                                <span class="input-group-addon"><span class="fa fa-comment"></span>
                                </span>
                        <textarea class="form-control" id="body" name="body" rows="8"
                                  placeholder="<?php echo osc_esc_html( __( 'Enter your comment' , 'shopclass' ) ); ?>"></textarea>
                    </div>
                </div>
                <div class="clearfix form-group">
					<?php $recaptchaId = 'recaptcha2';
						osc_run_hook( 'tf_after_comment_form' , $recaptchaId ); ?>
                </div>
            </fieldset>
            <button class="btn btn-default add-comment form-group"><?php _e( 'Send' , 'shopclass' ); ?></button>
            <button class="btn btn-default comment-processing" style="display:none"><i
                        class="fa fa-cog fa-spin fa-fw"></i><?php _e( 'Please Wait' , 'shopclass' ); ?>...
            </button>
            </form>
            <hr>
            </div>
            </div>
            </div>
			<?php
			$commentJs = function () {
				echo '<script>$("#toggleComment").click(function() {
  										$("#toggleCommentForm").slideToggle( "slow");});</script>';
			};
			osc_add_hook( 'footer_scripts_loaded' , $commentJs );
			osc_add_hook( 'footer_scripts_loaded' , 'tfc_ajax_comment_js' );
		}
		if ( $showComment ) { ?>
            <div class="comments_list">
				<?php $class = "panel-info";
					while ( osc_has_item_comments() ) { ?>
                        <div class="media comment <?php echo $class; ?>">
                            <div class="media-left">
                                <img alt="" src="<?php echo tfc_user_profile_pic_url( osc_comment_user_id() ); ?>"
                                     width="64" height="64" class="media-object img-circle">
                            </div>
                            <div class="media-body">
                                <h4 class="media-heading"><em><span
                                                class="text-capitalize text-primary"><?php echo osc_comment_author_name(); ?></span>:
                                    </em>
                                    <small><?php echo osc_comment_title(); ?></small>
                                </h4>
                                <div class="comment-reply"><?php echo osc_comment_body(); ?></div>
								<?php if ( osc_comment_user_id() && ( osc_comment_user_id() == osc_logged_user_id() ) ) { ?>
                                    <p>
                                        <a rel="nofollow" href="<?php echo osc_delete_comment_url(); ?>"
                                           title="<?php _e( 'Delete your comment' , 'shopclass' ); ?>"><?php _e( 'Delete' , 'shopclass' ); ?></a>
                                    </p>
								<?php } ?>
                            </div>
                        </div>
						<?php $class = ( $class == 'odd' ) ? 'odd' : 'even'; ?>
					<?php } ?>
                <div class="comment-pagination">
					<?php echo tfc_comments_pagination(); ?>
                </div>
            </div>
		<?php }
	}
} ?>