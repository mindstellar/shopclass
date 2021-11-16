<?php if ( ! defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.'); ?>
<style>
    #wrapper_voting_plugin{
        margin-top:10px;
        padding: 5px;
    }

    .votes_txt_vote {
        display: inline;
        float: left;
    }

    .votes_results {
        display: inline;
        float: left;
    }

    .votes_results img {
        height: auto;
        vertical-align: middle;
        width: auto;
        border: 0 none;
        margin: 0;
        padding: 0;
        float: left;
    }

    .votes_star .vote1 {
        width: 15px;
        z-index: 14;
    }
    .votes_star .vote2 {
        width: 30px;
        z-index: 13;
    }
    .votes_star .vote3 {
        width: 45px;
        z-index: 12;
    }
    .votes_star .vote4 {
        width: 60px;
        z-index: 11;
    }
    .votes_star .vote5 {
        width: 75px;
        z-index: 10;
    }

    .votes_star a {
        display: block;
        height: 19px;
        position: absolute;
    }

    .votes_star {
        background: url("<?php echo osc_base_url().'/oc-content/plugins/'.  osc_plugin_folder(__FILE__);?>img/ico_vot_vo.gif") repeat scroll 0 0 transparent;
        display: inline;
        float: left;
        height: 20px;
        margin: 0 4px 0 3px;
        position: relative;
        width: 76px;
    }

    .votes_vote {
        display: inline;
        float: left;
        margin-right: 5px;
    }

    .votes_star a:hover {
        background: url("<?php echo osc_base_url().'/oc-content/plugins/'.  osc_plugin_folder(__FILE__);?>img/ico_vot_ov.gif") repeat-x scroll 0 0 transparent;
    }

    #voting_plugin {
        position: relative;
    }
</style>

<div class="box location">
    <h3><strong><?php _e("Best users voted", 'voting') ; ?></strong></h3>
    <ul>
    <?php
        $count = 0;
        if ( isset( $results ) ) {
            foreach($results as $user_vote):
                $avg_vote = $user_vote['avg_vote'];
                if($avg_vote==5) {
                    $tooltip  = __('Essential', 'voting');
                } else if($avg_vote>=4 && $avg_vote<5) {
                    $tooltip = __('Very interesting', 'voting');
                } else if($avg_vote>=3 && $avg_vote<4) {
                    $tooltip = __('Interesting', 'voting');
                } else if($avg_vote>=2 && $avg_vote<3) {
                    $tooltip = __('Uninteresting', 'voting');
                } else if($avg_vote>=1 && $avg_vote<2) {
                    $tooltip = __('Without interest', 'voting');
                } else {
                    $tooltip = __('Without information', 'voting');
                }
                $total    = $user_vote['num_votes'];
                $user    = User::newInstance()->findByPrimaryKey($user_vote['user_id']);
                View::newInstance()->_exportVariableToView('user', $user) ;
        ?>
            <li>
                <?php if($count+1 < count($results)){?>
                <div style="border-bottom:1px #000000 dashed;">
                <?php } else { ?>
                <div>
                <?php } ?>
                    <p style="text-align: center;"><a href="<?php echo osc_user_public_profile_url(); ?>"><?php echo osc_user_name(); ?></a></p>
                    <p style="text-align: center;">
                        <img title="<?php echo $tooltip; ?>" src="<?php voting_star(1, $avg_vote); ?>">
                        <img title="<?php echo $tooltip; ?>" src="<?php voting_star(2, $avg_vote); ?>">
                        <img title="<?php echo $tooltip; ?>" src="<?php voting_star(3, $avg_vote); ?>">
                        <img title="<?php echo $tooltip; ?>" src="<?php voting_star(4, $avg_vote); ?>">
                        <img title="<?php echo $tooltip; ?>" src="<?php voting_star(5, $avg_vote); ?>">
                        <span style="position:relative; top:-5px;padding-right: 4px; padding-left: 4px; margin-bottom: 3px;"><?php echo $total;?> <?php _e('votes', 'voting');?></span>
                    </p>
                </div>
            </li>
        <?php
                $count++;
                View::newInstance()->_erase('user') ;
            endforeach;
        }
    ?>
    </ul>
</div>