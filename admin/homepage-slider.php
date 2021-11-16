<?php use shopclass\includes\classes\tfcFilesClass; ?>
<div class="tfc-dashboard">
    <h2 class="render-title "> HomePage Slider </h2>
    <form action="<?php echo osc_admin_render_theme_url( 'oc-content/themes/shopclass/admin/homepage-slider.php' ); ?>"
          method="post">
        <input type="hidden" name="action_specific" value="slider_settings"/>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"> Slider </div>
                    <div class="form-controls">
                        <div class="form-label-selection">
                            <select name="enable_slider" id="enable_slider">
                                <option <?php if ( osc_get_preference( 'enable_slider' , 'shopclass_theme' ) == 0 ) {
									echo 'selected="selected"';
								} ?> value='0'> Disable </option>
                                <option <?php if ( osc_get_preference( 'enable_slider' , 'shopclass_theme' ) == 1 ) {
									echo 'selected="selected"';
								} ?> value='1'> Enable </option>
                            </select>
                            <div class="help-box"> Enable or Disable Slider </div>
                            <div class="help-box"> This will enable slider in homepage hero unit </div>

                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" value="Save changes"
                           class="btn btn-submit">
                </div>
            </div>
        </fieldset>
    </form>
</div>
<h2 class="render-title separate-top">Upload Slider Images</h2>
<h3 class="render-title">Default Image home-try.jpg can not be deleted/replaced here, you need to manually replace that image with your image, keeping same filename.</h3>

<div class="clear"></div>
<form class="col-100"
      action="<?php echo osc_admin_render_theme_url( 'oc-content/themes/shopclass/admin/homepage-slider.php' ); ?>"
      method="post" enctype="multipart/form-data">
    <input type="hidden" name="action_specific" value="tfc_upload_image"/>
    <fieldset>
        <div class="form-horizontal">
            <div class="form-row">
                <div class="form-label">Upload image</div>
                <div class="form-controls">
                    <input type="file" name="tfcimage" id="package"/>
                </div>
            </div>
            <div class="form-actions">
                <input id="button_save" type="submit"
                       value="Upload" class="btn btn-submit">
            </div>
        </div>
    </fieldset>
</form>
<div class="clear"></div>
<div class="row">
    <p class="help-box">Image are saved in <?php echo osc_uploads_path() . 'shopclass_slider'; ?></p>
	<?php
		$dir      = osc_uploads_path() . '/shopclass_slider/';
		$filelist = tfcFilesClass::newInstance()
		                         ->setEditDirectory( $dir )
		                         ->setValidFiles( array ( 'jpeg' , 'jpg' ) )
		                         ->scanFilenames();

		if ( ! empty( $filelist ) ) {
			echo '<div class="slider-images">';
			foreach ( $filelist as $image ) {
				echo '<div class="bk-image grid-20 float-left">';
				echo '<a class="btn text-center" href="' . osc_admin_base_url( true ) . '?action_specific=tfc_delete_image&image_name=' . $image . '">';
				echo 'Delete Image' . '</a>';
				echo '<img class="slider-image" width="200" src=' . REL_WEB_URL . str_replace( ABS_PATH , '' , osc_uploads_path() . 'shopclass_slider/' . $image ) . '>';
				echo '</div>';
			}
			echo '</div>';
		}
	?>
</div>