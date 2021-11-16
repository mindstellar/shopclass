<?php if ( ! defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
$path = osc_base_url().'/oc-content/plugins/voting/'; ?>
<div class="wrapper_voting_plugin">
    <span id="voting_loading" style="display:none;"><i class="fa fa-spinner fa-spin" style="margin-left:20px;"></i> <?php _e('Loading', 'voting');?></span>
    <div id="voting_plugin">
        <?php include('view_votes.php');?>
    </div>
    <div style="clear:both;"></div>
</div>