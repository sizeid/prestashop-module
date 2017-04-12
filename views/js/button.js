$(function () {
    var button = $('.sizeid-button-wrap');
    var placement = $('.attribute_fieldset .selector');
    if (button.length > 0 && placement.length > 0) {
        button.addClass('moved');
        placement.after(button);
    }
});
