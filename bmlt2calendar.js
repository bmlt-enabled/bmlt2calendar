special=jQuery.event.special;
delete special.touchstart;
delete special.touchmove;
delete special.wheel;
delete special.mousewheel;
var bmltCalendarDefaultView = (jQuery(window).width() < 765) ? 'listWeek' : 'month';
jQuery(document).on('wpfc_fullcalendar_args', function(event,args) {
    args.defaultView=bmltCalendarDefaultView;
    args.windowResize=function(args) {
            let special=jQuery.event.special;
            delete special.touchstart;
            delete special.touchmove;
            delete special.wheel;
            delete special.mousewheel;
            args.calendar.optionsManager.add({'contentHeight':'auto'});
            args.calendar.changeView((jQuery(window).width() < 765) ? 'listWeek' : 'month')
    };
    if (bmlt2calendar.config.meetingsOnly) args.eventSources.shift();
        args.eventSources.push({
            url:bmlt2calendar.config.url, 
            color: bmlt2calendar.config.color, textColor: bmlt2calendar.config.textColor});
        args.eventRender = function(eventObj,el) {
            if (eventObj.hasOwnProperty('description')) {tippy(el[0], {content: eventObj.description, theme : WPFC.tippy_theme,
					placement : WPFC.tippy_placement, allowHTML: true} );}  };
})