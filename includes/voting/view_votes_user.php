<?php if ( ! defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
        $_userId = null;
    if ( isset( $vote ) ) {
        if( isset($vote['userId']) ) {
            $_userId = $vote['userId'];
        } else if(Params::getParam('userId')!=''){
            $_userId = Params::getParam('userId');
        }
    }
        if($_userId==null) {
            exit;
        }

        $path = osc_base_url().'/oc-content/plugins/voting/'; ?>
        <div class="votes_stars">
            <?php if ( isset( $vote ) ) {
                if( $vote['can_vote'] ) { ?>
                <div class="votes_vote">
				<div class="votes_txt_vote"><?php _e('Rate User:', 'shopclass');?></div>
                    <div class="votes_star">
                        <div>
                            <span class="font_vote">
                            <i class="fa fa-star-o aPvu vote5"></i>
                            <i class="fa fa-star aPvu vote5"></i>
                            </span>
                            <span class="font_vote">
                            <i class="fa fa-star-o aPvu vote4"></i>
                            <i class="fa fa-star aPvu vote4"></i>
                            </span>
                           <span class="font_vote">
                            <i class="fa fa-star-o aPvu vote3"></i>
                            <i class="fa fa-star aPvu vote3"></i>
                            </span>
                            <span class="font_vote">
                            <i class="fa fa-star-o aPvu vote2"></i>
                            <i class="fa fa-star aPvu vote2"></i>
                            </span>
                            <span class="font_vote">
                            <i class="fa fa-star-o aPvu vote1"></i>
                            <i class="fa fa-star aPvu vote1"></i>
                            </span>
                        </div>
                    </div>
                </div>
                <?php }
            } ?>
            
            <div class="votes_results">
            <div class="votes_txt_vote"><?php _e('Current User Rating:', 'shopclass');?></div>

                <?php
                    if ( isset( $vote ) ) {
                        $avg_vote = $vote['vote'];
                    }
                    else {
                        $avg_vote = false;
                    }
                ?>
                <i class="fa <?php tfc_voting_star(1, $avg_vote); ?>"></i>
                <i class="fa <?php tfc_voting_star(2, $avg_vote); ?>"></i>
                <i class="fa <?php tfc_voting_star(3, $avg_vote); ?>"></i>
                <i class="fa <?php tfc_voting_star(4, $avg_vote); ?>"></i>
                <i class="fa <?php tfc_voting_star(5, $avg_vote); ?>"></i>
                <span><?php if ( isset( $vote ) ) {
                        echo $vote['total'];
                    } ?> <?php _e( 'votes', 'voting');?></span>
            </div>
        </div>