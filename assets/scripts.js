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

    const $form = $('form#slickplan-importer');

    $form.on('change', '#slickplan-importer-form-page_type', function () {
        const label = (this.value === '@') ? 'Continue...' : 'Import Pages';
        $form.children('input[type="submit"]').val(label);
        $form.prev('div.notice').find('strong').text(label);
    });

    if (!window.SLICKPLAN_JSON) {
        return;
    }

    const $window = $(window);
    const $summary = $form.find('.slickplan-summary');
    const $progress = $('#slickplan-progressbar');

    $progress.progressbar({
        value: 0,
        change: function() {
            $progress.find('.progress-label').text($progress.progressbar('value') + '%');
        }
    });

    const _pages = [];
    let _importIndex = 0;

    const _generatePagesFlatArray = function (pages, parent) {
        $.each(pages, function (index, data) {
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

    const _addMenuID = function (parent_id, mlid) {
        for (let i = 0; i < _pages.length; ++i) {
            if (_pages[i].parent === parent_id) {
                _pages[i].mlid = mlid;
            }
        }
    };

    const _importPage = function (page) {
        const html = ('' + slickplan_ajax.html).replace('{title}', page.title);
        const $element = $(html).appendTo($summary);
        $summary.scrollTop(Number.MAX_SAFE_INTEGER);
        const percent = Math.round((_importIndex / _pages.length) * 100);
        $progress.progressbar('value', percent);
        $.ajax({
            url: slickplan_ajax.ajaxurl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'slickplan-importer',
                slickplan: {
                    page: page.id,
                    parent: page.parent ? page.parent : '',
                    mlid: page.mlid ? page.mlid : 0,
                    last: (_pages && _pages[_importIndex + 1]) ? 0 : 1
                },
                _ajax_nonce: slickplan_ajax.nonce
            },
            success: function (data) {
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
                    }
                    $window.trigger('resize');
                }
            },
            error: function () {
                alert('Unknown error, please delete imported pages and try again.');
            }
        });
    };

    const types = ['home', '1', 'util', 'foot'];
    for (let i = 0; i < types.length; ++i) {
        if (window.SLICKPLAN_JSON[types[i]] && window.SLICKPLAN_JSON[types[i]].length) {
            _generatePagesFlatArray(window.SLICKPLAN_JSON[types[i]]);
        }
    }

    $window.on('load resize', function() {
        const top = $summary.offset().top;
        $summary.hide();
        const form_height = $form.height();
        $summary.show();
        const height = $window.height() - form_height - top - 5;
        $summary.height(height);
    });

    _importIndex = 0;
    if (_pages && _pages[_importIndex]) {
        _importPage(_pages[_importIndex]);
    }
});
