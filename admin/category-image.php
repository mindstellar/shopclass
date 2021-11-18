<?php
    //Get all categories which have sub-categories;
    $dao = new DAO();
    $dao->dao->select( 'Distinct pk_i_id' )->from( Category::newInstance()->getTableName() )->where( 'fk_i_parent_id IS NULL' );
    $rows   = $dao->dao->get();
    $result = $rows->result(); ?>
<div class="tfc-dashboard">
    <h2 class="render-title separate-top">Upload Category Images</h2>

    <div class="clear"></div>
    <form class="col-100"
          action="<?php echo osc_admin_render_theme_url( 'oc-content/themes/shopclass/admin/category-image.php' ); ?>"
          method="post" enctype="multipart/form-data">
        <input type="hidden" name="action_specific" value="tfc_category_image"/>
        <fieldset>
            <div class="form-horizontal">
                <div class="form-row">
                    <div class="form-label">Choose Category</div>
                    <div class="form-controls">
                        <select name="image_category" id="image_category">
                            <option value=''> Choose Category</option>
                            <?php foreach ( $result as $category ) {
                                $categorynameurl = tfc_category_name_url( $category[ 'pk_i_id' ] ); ?>
                                <option value='<?php echo $category[ 'pk_i_id' ]; ?>'><?php echo $categorynameurl[ 'name' ]; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label">Upload image</div>
                    <div class="form-controls">
                        <input type="file" name="tfcimagecat" id="package"/>
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
        <p class="help-box">Image are saved in <?php echo osc_uploads_path() . 'categorypics'; ?></p>
        <?php

            foreach ( $result as $category ) {
                $categorynameurl = tfc_category_name_url( $category[ 'pk_i_id' ] );
                ?>
                <div class="grid-20 float-left"><h5><?php echo $categorynameurl[ 'name' ]; ?></h5>
                    <img class="clider-image" width="200"
                         src="<?php echo tfc_category_image_url( $category[ 'pk_i_id' ] ); ?>">
                </div>
            <?php }
        ?>
    </div>