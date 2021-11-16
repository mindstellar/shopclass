/*
 * Shopclass Default JS
 */
function imagePreview() {
    var xOffset = 200;
    var yOffset = 35;
    $('a.img-zoom').hover(function(e) {
        //console.log('javascriptworking');
        var adTitle = $(this).find('img').attr('alt');
        $('body').append("<div id='img-trail'><legend>" + adTitle + "</legend><img src='" + $(this).data('rel') + "' alt='' /></div>");
        $('#img-trail').css('top', (e.pageY + xOffset) + 'px').css('left', (e.pageX + yOffset) + 'px').fadeIn('normal');
    }, function() {
        $('#img-trail').remove();
    });

    $('a.img-zoom').mousemove(function(e) {
        $('#img-trail').css('top', (e.pageY - xOffset) + 'px').css('left', (e.pageX + yOffset) + 'px');
    });
}
$(document).ready(function(){
    imagePreview();
    $('[data-toggle="tooltip"]').tooltip();
});