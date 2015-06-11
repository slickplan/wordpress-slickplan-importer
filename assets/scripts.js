jQuery(document).ready(function($) {

    $('#slickplan-page-content-radios')
        .find('input[type="radio"]').on('change', function() {
            $(this).closest('td')
                .find('.content-suboption').css(
                    'display',
                    (this.value === 'contents') ? 'inline-block' : 'none'
                )
                .end()
                .find('.content-suboption-br').css(
                    'display',
                    (this.value === 'contents') ? 'block' : 'none'
                );
        })
        .filter(':checked')
        .trigger('change');

});