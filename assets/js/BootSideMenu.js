(function ( $ ) {

	$.fn.BootSideMenu = function( options ) {

		var oldCode, newCode, side;

		newCode = "";

		var settings = $.extend({
			side:"left",
			autoClose:true
		}, options );

		side = settings.side;
		autoClose = settings.autoClose;

		this.addClass("container bt_sidebar");

		if(side=="left"){
			this.addClass("bt_sidebar-left");
		}else if(side=="right"){
			this.addClass("bt_sidebar-right");
		}else{
			this.addClass("bt_sidebar-left");	
		}

		oldCode = this.html();

		newCode += "<div class=\"row\">\n";
		newCode += "	<div class=\"col-xs-12 col-sm-12 col-md-12 col-lg1-12\" data-side=\""+side+"\">\n"+ oldCode+" </div>\n";
		newCode += "</div>";
		newCode += "<div class=\"toggler bg-primary\">\n";
		newCode += "	<span class=\"fa fa-chevron-right\">&nbsp;</span>";
		newCode += "</div>\n";

		this.html(newCode);

		if(autoClose){
			$(this).find(".toggler").trigger("click");
		}

	};


	$(document).on('click','.toggler', function(){
		var toggler = $(this);
		var container = toggler.parent();
		var listaClassi = container[0].classList;
		var side = getSide(listaClassi);
		var containerWidth = container.width();
		var status = container.attr('data-status');
		if(!status){
			status = "opened";
		}
		doAnimation(container, containerWidth, side, status);
	});

//restituisce il lato del bt_sidebar in base alla classe che trova settata
function getSide(listaClassi){
	var side;
	for(var i = 0; i<listaClassi.length; i++){
		if(listaClassi[i]=='bt_sidebar-left'){
			side = "left";
			break;
		}else if(listaClassi[i]=='bt_sidebar-right'){
			side = "right";
			break;
		}else{
			side = null;
		}
	}
	return side;
}
//esegue l'animazione
function doAnimation(container, containerWidth, bt_sidebarSide, bt_sidebarStatus){
	var toggler = container.children()[1];
	if(bt_sidebarStatus=="opened"){
		if(bt_sidebarSide=="left"){
			container.animate({
				left:-(containerWidth+2)
			});
			toggleArrow(toggler, "left");
		}else if(bt_sidebarSide=="right"){
			container.animate({
				right:- (containerWidth +2)
			});
			toggleArrow(toggler, "right");
		}
		container.attr('data-status', 'closed');
	}else{
		if(bt_sidebarSide=="left"){
			container.animate({
				left:0
			});
			toggleArrow(toggler, "right");
		}else if(bt_sidebarSide=="right"){
			container.animate({
				right:0
			});
			toggleArrow(toggler, "left");
		}
		container.attr('data-status', 'opened');

	}

}

function toggleArrow(toggler, side){
		$(toggler);
}

}( jQuery ));

