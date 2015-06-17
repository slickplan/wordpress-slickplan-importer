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

    if (!window.SLICKPLAN_JSON) {
        return;
    }

    var $form = $('form#slickplan-importer');
    var $summary = $form.find('.slickplan-summary');
    var $progress = $('#slickplan-progressbar');

    $progress.progressbar({
        value: 0,
        change: function() {
            $progress.find('.progress-label').text($progress.progressbar('value') + '%');
        }
    });

    var _pages = [];
    var _importIndex = 0;

    var _generatePagesFlatArray = function(pages, parent) {
        $.each(pages, function(index, data) {
            if (data.id) {
                _pages.push({
                    id: data.id,
                    parent: parent,
                    title: data.title
                });
                if (data.childs) {
                    _generatePagesFlatArray(data.childs, data.id);
                }
            }
        });
    };

    var _addMenuID = function(parent_id, mlid) {
        for (var i = 0; i < _pages.length; ++i) {
            if (_pages[i].parent === parent_id) {
                _pages[i].mlid = mlid;
            }
        }
    };

    var _importPage = function(page) {
        var html = ('' + slickplan_ajax.html).replace('{title}', page.title);
        var $element = $(html).appendTo($summary);
        var percent = Math.round((_importIndex / _pages.length) * 100);
        $progress.progressbar('value', percent);
        $.post(slickplan_ajax.ajaxurl, {
            action: 'slickplan-importer',
            slickplan: {
                page: page.id,
                parent: page.parent ? page.parent : '',
                mlid: page.mlid ? page.mlid : 0,
                last: (_pages && _pages[_importIndex + 1]) ? 0 : 1
            },
            _ajax_nonce: slickplan_ajax.nonce
        }, function(data) {
            if (data && data.html) {
                $element.replaceWith(data.html);
                ++_importIndex;
                if (data) {
                    if (data.mlid) {
                        _addMenuID(page.id, data.mlid);
                    }
                }
                if (_pages && _pages[_importIndex]) {
                    _importPage(_pages[_importIndex]);
                } else {
                    $progress.progressbar('value', 100);
                    $form.find('h3').text('Success!');
                    $form.find('.slickplan-show-summary').show();
                    $(window).scrollTop(0);
                    setTimeout(function() {
                        $progress.remove();
                    }, 500);
                }
            }
        }, 'json');
    };

    var types = ['home', '1', 'util', 'foot'];
    for (var i = 0; i < types.length; ++i) {
        if (window.SLICKPLAN_JSON[types[i]] && window.SLICKPLAN_JSON[types[i]].length) {
            _generatePagesFlatArray(window.SLICKPLAN_JSON[types[i]]);
        }
    }

    $(window).load(function() {
        _importIndex = 0;
        if (_pages && _pages[_importIndex]) {
            $(window).scrollTop(0);
            _importPage(_pages[_importIndex]);
        }
    });

});