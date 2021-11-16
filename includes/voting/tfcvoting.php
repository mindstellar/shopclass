<?php 
    /**
     * Show form to vote an item. (itemDetail)
     */
    function tfc_voting_item_detail()
    {
        if (osc_is_this_category('voting', osc_item_category_id()) && osc_get_preference('item_voting', 'voting') == '1' ) {
            $aux_vote  = ModelVoting::newInstance()->getItemAvgRating( osc_item_id() );
            $aux_count = ModelVoting::newInstance()->getItemNumberOfVotes( osc_item_id() );
            $vote['vote']  = $aux_vote['vote'];
            $vote['total'] = $aux_count['total'];

            if( osc_logged_user_id() == 0 ) {
                $hash   = $_SERVER['HTTP_USER_AGENT'].$_SERVER['REMOTE_ADDR'];
                $hash = sha1($hash);
            } else {
                $hash = null;
            }

            $vote['can_vote'] = true;
            if(osc_get_preference('user', 'voting') == 1) {
                if(!osc_is_web_user_logged_in()) {
                    $vote['can_vote'] = false;
                }
            }

            if(!can_vote(osc_item_id(), osc_logged_user_id(), $hash) ){
                $vote['can_vote'] = false;
            }
            require 'item_detail.php';
            osc_add_hook('footer_scripts_loaded','tfc_voting_js');
         }
    }
    /**
    * Print Meta info of item reviews
    */
    function tfc_voting_item_meta(){
    if (osc_is_this_category('voting', osc_item_category_id()) && osc_get_preference('item_voting', 'voting') == '1' ) {
            $aux_vote  = ModelVoting::newInstance()->getItemAvgRating( osc_item_id() );
            $aux_count = ModelVoting::newInstance()->getItemNumberOfVotes( osc_item_id() );
            $vote['vote']  = $aux_vote['vote'];
            $vote['total'] = $aux_count['total'];

			if ( isset( $vote ) ) {
                $avg_vote = $vote['vote'];
            }
            else {$avg_vote = 0;}
            if( $avg_vote > 0 ){
             echo '"aggregateRating": {';
			 echo '  "@type": "AggregateRating",';
             echo '  "ratingValue": "'.$avg_vote.'",';
             echo '  "ratingCount": "'.$vote['total'].'"';
             echo '},';
            }
         }
    }

/**
 * @param null $item
 */
function tfc_voting_item_detail_user($item=null )
    {
        $userId = null;

        if($item == null) {
            $userId = osc_item_user_id();
        } else if(is_numeric($item) ) {
            $userId = $item;
        } else if( is_array($item) ) {
            $userId = $item['fk_i_user_id'];
        } else {
            exit;
        }

        if( osc_get_preference('user_voting', 'voting') == 1 && is_numeric($userId) && isset($userId) && $userId > 0) {
            // obtener el avg de las votaciones
            $aux_vote  = ModelVoting::newInstance()->getUserAvgRating($userId);
            $aux_count = ModelVoting::newInstance()->getUserNumberOfVotes($userId);
            $vote['vote']   = $aux_vote['vote'];
            $vote['total']  = $aux_count['total'];
            $vote['userId'] = $userId;

            $vote['can_vote'] = false;
            if(osc_is_web_user_logged_in() && can_vote_user($userId, osc_logged_user_id()) ) {
                $vote['can_vote'] = true;
            }
            require 'item_detail_user.php';
            osc_add_hook('footer_scripts_loaded','tfc_voting_user_js');

        }
    }

/**
 * @param $star
 * @param $avg_vote
 * @return bool
 */
function tfc_voting_star($star, $avg_vote)
    {
        $star_ok = 'fa-star';
        $star_no = 'fa-star-o';
        $star_md = 'fa-star-half-o';

        if( $avg_vote >= $star) {
            echo $star_ok;
        } else {
            $aux = 1+($avg_vote - $star);
            if($aux <= 0){
                echo $star_no;
                return true;
            }
            if($aux >=1) {
                echo $star_no;
            } else {
                if($aux <= 0.5){
                    echo $star_md;
                }else{
                    echo $star_ok;
                }
            }
        }
        return true;
    }

/**
 *
 */
function tfc_voting_js(){
?>
<script>
    $(function(){
        $('.aPs').click(function(){
            var params = '';
            var vote   = 0;
            if( $(this).hasClass('vote1') ) vote = 1;
            if( $(this).hasClass('vote2') ) vote = 2;
            if( $(this).hasClass('vote3') ) vote = 3;
            if( $(this).hasClass('vote4') ) vote = 4;
            if( $(this).hasClass('vote5') ) vote = 5;

            var itemId = <?php echo osc_item_id(); ?>;
            params = 'itemId='+itemId+'&vote='+vote;

            $.ajax({
                type: "POST",
                url: '<?php echo osc_base_url(true); ?>?page=ajax&action=runhook&hook=tfc_voting&'+params,
                dataType: 'text',
                beforeSend: function(){
                    $('#voting_plugin').hide();
                    $('#voting_loading').fadeIn('slow');
                },
                success: function(data){
                    $('#voting_loading').fadeOut('slow', function(){
                        $('#voting_plugin').html(data).fadeIn('slow');
                    });
                }
            });
        });
    });
</script>
<?php
}

/**
 *
 */
function tfc_voting_user_js(){
?>
<script>
    $(function(){
        $('.aPvu').click(function(){
            var params = '';
            var vote   = 0;
            if( $(this).hasClass('vote1') ) vote = 1;
            if( $(this).hasClass('vote2') ) vote = 2;
            if( $(this).hasClass('vote3') ) vote = 3;
            if( $(this).hasClass('vote4') ) vote = 4;
            if( $(this).hasClass('vote5') ) vote = 5;

            var userId = <?php echo osc_item_user_id(); ?>;
            params = 'userId='+userId+'&vote='+vote;

            $.ajax({
                type: "POST",
                url: '<?php echo osc_base_url(true); ?>?page=ajax&action=runhook&hook=tfc_voting&'+params,
                dataType: 'text',
                beforeSend: function(){
                    $('#voting_plugin_user').hide();
                    $('#voting_loading_user').fadeIn('slow');
                },
                success: function(data){
                    $('#voting_loading_user').fadeOut('slow', function(){
                        $('#voting_plugin_user').html(data).fadeIn('slow');
                    });
                }
            });
        });
    });
    </script>
<?php
}

/**
 *
 */
function tfc_ajax_voting(){
/**
 *  Recive and save votes from frontend.
 */

$votedUserId = (Params::getParam("userId") == '')  ? null : Params::getParam("userId");
$itemId      = (Params::getParam("itemId") == '')  ? null : Params::getParam("itemId");
$iVote       = (Params::getParam("vote") == '')  ? null : Params::getParam("vote");

$userId = osc_logged_user_id();
$hash   = '';

// Vote Users
if(isset($iVote) && is_numeric($iVote) && isset($votedUserId) && is_numeric($votedUserId) )
{
    if( $iVote<=5 && $iVote>=1){
        if(can_vote_user($votedUserId, $userId)) {
            ModelVoting::newInstance()->insertUserVote($votedUserId, $userId, $iVote);
        }
    }
    // return updated voting
    $aux_vote  = ModelVoting::newInstance()->getUserAvgRating($votedUserId);
    $aux_count = ModelVoting::newInstance()->getUserNumberOfVotes($votedUserId);
    $vote['vote']  = $aux_vote['vote'];
    $vote['total'] = $aux_count['total'];
    $vote['userId'] = $votedUserId;

    $vote['can_vote'] = true;
    if(!osc_is_web_user_logged_in() || !can_vote_user($votedUserId, $userId) ) {
        $vote['can_vote'] = false;
    }

    require 'view_votes_user.php';
}

// Vote Items
if(isset($iVote) && is_numeric($iVote) && isset($itemId) && is_numeric($itemId) )
{
    if( $iVote<=5 && $iVote>=1){
        if( $userId == 0 ) {
            $userId = 'NULL';
            $hash   = $_SERVER['HTTP_USER_AGENT'].$_SERVER['REMOTE_ADDR'];
            $hash = sha1($hash);
        } else {
            $hash = null;
        }

        $open = osc_get_preference('open', 'voting');
        $user = osc_get_preference('user', 'voting');
        if($open == 1) {
            if(can_vote($itemId, $userId, $hash)) {
                ModelVoting::newInstance()->insertItemVote($itemId, $userId, $iVote, $hash);
            }
        } else if($user == 1 && is_null($hash) ) {
            if(can_vote($itemId, $userId, $hash)) {
                ModelVoting::newInstance()->insertItemVote($itemId, $userId, $iVote, $hash);
            }
        }
    }
    // return updated voting
    $item = Item::newInstance()->findByPrimaryKey($itemId);
    View::newInstance()->_exportVariableToView('item', $item);
    if (osc_is_this_category('voting', osc_item_category_id())) {
        $aux_vote  = ModelVoting::newInstance()->getItemAvgRating(osc_item_id());
        $aux_count = ModelVoting::newInstance()->getItemNumberOfVotes(osc_item_id());
        $vote['vote']  = $aux_vote['vote'];
        $vote['total'] = $aux_count['total'];

        $vote['can_vote'] = true;
        if(osc_get_preference('user', 'voting') == 1) {
            if(!osc_is_web_user_logged_in()) {
                $vote['can_vote'] = false;
            }
        }
        if(!can_vote(osc_item_id(), osc_logged_user_id(), $hash) ){
            $vote['can_vote'] = false;

        }

        require 'view_votes.php';
    }
}
}
osc_add_hook('ajax_tfc_voting','tfc_ajax_voting');
osc_remove_hook('item_detail', 'voting_item_detail');
osc_remove_hook('item_detail', 'voting_item_detail_user');