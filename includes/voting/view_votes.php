<?php if ( ! defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
        $path = osc_base_url().'/oc-content/plugins/voting/'; ?>
        <div class="votes_stars">
            <?php if ( isset( $vote ) ) {
                if( $vote['can_vote'] ) { ?>
                <div class="votes_vote">
                    <div class="votes_txt_vote listing-rating"><?php _e('Rate Listing:', 'shopclass');?></div>
                    <div class="votes_star">
                        <div>
                            <span class="font_vote" data-toggle="tooltip" title="5 <?php _e('Star','shopclass') ?>">
                            <i class="fa fa-star-o aPs vote5"></i>
                            <i class="fa fa-star aPs vote5"></i>
                            </span>
                            <span class="font_vote" data-toggle="tooltip" title="4 <?php _e('Star','shopclass') ?>">
                            <i class="fa fa-star-o aPs vote4"></i>
                            <i class="fa fa-star aPs vote4"></i>
                            </span>
                            <span class="font_vote" data-toggle="tooltip" title="3 <?php _e('Star','shopclass') ?>">
                            <i class="fa fa-star-o aPs vote3"></i>
                            <i class="fa fa-star aPs vote3"></i>
                            </span>
                            <span class="font_vote" data-toggle="tooltip" title="2 <?php _e('Star','shopclass') ?>">
                            <i class="fa fa-star-o aPs vote2" ></i>
                            <i class="fa fa-star aPs vote2"></i>
                            </span>
                            <span class="font_vote" data-toggle="tooltip" title="1 <?php _e('Star','shopclass') ?>">
                            <i class="fa fa-star-o aPs vote1"></i>
                            <i class="fa fa-star aPs vote1"></i>
                            </span>
                        </div>
                    </div>
                </div>
                <?php }
            } ?>
            <div class="votes_results listing-results" itemprop="aggregateRating" itemscope itemtype="http://schema.org/AggregateRating">
                <span><?php _e('Current Rating:', 'shopclass');?>  </span>
                <?php
                    if ( isset( $vote ) ) {
                        $avg_vote = $vote['vote'];
                    }
                    else {$avg_vote = 0;}
                ?>
                <i class="fa <?php 
                    tfc_voting_star(1, $avg_vote);
                ?>"></i>
                <i class="fa <?php 
                    tfc_voting_star(2, $avg_vote);
                 ?>"></i>
                <i class="fa <?php 
                    tfc_voting_star(3, $avg_vote);
                 ?>"></i>
                <i class="fa <?php 
                    tfc_voting_star(4, $avg_vote);
                 ?>"></i>
                <i class="fa <?php 
                    tfc_voting_star(5, $avg_vote);
                 ?>"></i>
                <?php if ( isset( $vote ) ) {
                    if ($vote['total'] !== 0)
                        { ?>
                        <span>
                            <span style="display:none" >
                                <span itemprop="ratingValue"><?php if ( isset( $avg_vote ) ) {
                                        echo $avg_vote;
                                    } ?></span>/5
                            </span>
                            <span itemprop="reviewCount" ><?php echo $vote['total']?></span> <?php _e('Vote','shopclass') ?>
                        </span>
                    <?php }
                } ?>
            </div>
        </div>