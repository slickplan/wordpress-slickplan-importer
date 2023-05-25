jQuery(document).ready(function($) {
    const $form = $('form#slickplan-importer');
    const $mappingTable = $('#slickplan-mapping-list');
    const $mappingInput = $('#slickplan-map-json');

    $('#slickplan-page-content-radios')
        .find('input[type="radio"]').on('change', function () {
            $(this).closest('td')
                .find('.content-suboption').css('display', this.value === 'contents' ? 'inline-block' : 'none')
                .end()
                .find('.content-suboption-br').css('display', this.value === 'contents' ? 'block' : 'none');
        })
        .filter(':checked')
        .trigger('change');

    $('#slickplan-importer-form-page_type')
        .on('change', function () {
            const label = this.value === '@' ? 'Next Step' : 'Import Pages';
            $form.children('input[type="submit"]').val(label);
            $form.prev('div.notice').find('strong').text(label);
            $form.find('input[name*="create_menu"]').closest('div')
                .toggle(
                    this.value !== '@'
                    && !/\(non-hierarchical\)/.test($(this).find('option:selected').text())
                );
        })
        .trigger('change');

    if ($mappingTable.length && $mappingInput.length) {
        const $pinnedBar = $('#slickplan-floating');
        const $selectAction = $pinnedBar.find('select[name*="custom_action"]');
        const $selectType = $pinnedBar.find('select[name*="custom_type"]');
        const $selectTypes = $pinnedBar.find('select[name*="custom_list_"]').hide();

        const mapping = $mappingInput.val() ? JSON.parse($mappingInput.val()) : {};
        $mappingTable.find('tr[data-id]').each(function () {
            if (!mapping[this.dataset.id]) {
                mapping[this.dataset.id] = {
                    type: 'new',
                    value: 'page'
                };
            }
        });

        const postTypeName = function (type) {
            const option = $selectType.find('option[value="' + type + '"]').first();
            if (option.length) {
                return option.text().replace('(non-hierarchical)', '').trim();
            }
            return type;
        };

        const updateDisplay = function (cellIds) {
            if (!cellIds) {
                cellIds = Object.keys(mapping);
            }
            cellIds.forEach(function (cellId) {
                let html = '';
                if (mapping[cellId]) {
                    if (mapping[cellId].type === 'new') {
                        html += '<span style="opacity: 0.75; color: green;">&rarr;</span> New ';
                        html += '<strong>' + postTypeName(mapping[cellId].value) + '</strong> ';
                    } else if (mapping[cellId].type === 'exclude') {
                        html += '<span style="opacity: 0.75; color: red;">&times;</span> <span style="opacity: 0.5">Exclude</span> ';
                    } else if (mapping[cellId].type === 'overwrite') {
                        html += '<span style="opacity: 0.75; color: blue;">&rarr;</span> Overwrite ';
                        html += '<strong>' + postTypeName(mapping[cellId].value) + ':</strong> ';
                        html += $selectTypes.filter('[name="custom_list_' + mapping[cellId].value + '"]').find('option[value="' + mapping[cellId].id + '"]').text().replace(/&nbsp;/g, ' ').trim();
                    }
                } else {
                    html += 'New <strong>' + postTypeName('page') + '</strong> ';
                }
                html += '<a href="#" class="change-page">change</a>'
                $mappingTable.find('tr[data-id="' + cellId + '"] > td:last').html(html);
            });

            const count = Object.keys(mapping).filter(function (key) {
                return mapping[key] && mapping[key].type !== 'exclude';
            }).length;
            $form.children('input.button[type="submit"]').val('Import Pages (' + count + ')').attr('disabled', count < 1);

            $mappingInput.val(JSON.stringify(mapping));
        };

        $mappingTable.closest('table')
            .find('thead:first')
            .on('click', 'a.collapse-all', function (e) {
                e.preventDefault();
                $mappingTable.find('tr[class]').show();
                $mappingTable.find('.dashicons-arrow-up').removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
                $mappingTable.find('.select-childs-page').hide();
                $mappingTable.find('tr[class]').hide();
            })
            .on('click', 'a.expand-all', function (e) {
                e.preventDefault();
                $mappingTable.find('tr[class]').show();
                $mappingTable.find('.dashicons-arrow-down').removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
                $mappingTable.find('.select-childs-page').show();
            });

        $mappingTable
            .on('click', '.change-page', function (e) {
                e.preventDefault();
                const $this = $(this);
                const id = $this.closest('tr[data-id]').data('id');
                $mappingTable.find('input[type="checkbox"]').prop('checked', false)
                    .filter('#' + id)
                    .prop('checked', true)
                    .trigger('change');
                if (mapping[id]) {
                    $selectAction.val(mapping[id].type).trigger('change');
                    $selectType.val(mapping[id].value).trigger('change');
                    if (mapping[id].id) {
                        $selectTypes.filter('[name$="_' + mapping[id].value + '"]').val(mapping[id].id).trigger('change');
                    }
                }
            })
            .on('click', '.collapse-page', function (e) {
                e.preventDefault();
                const $this = $(this);
                const $icon = $this.find('span:first');
                const $childTrs = $this.closest('tr').siblings('tr.' + $this.closest('tr[data-id]').data('id'));
                if ($icon.hasClass('dashicons-arrow-up')) {
                    $icon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
                    $childTrs.hide();
                    $this.siblings('.select-childs-page').hide();
                } else {
                    $icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
                    $childTrs.show();
                    $this.siblings('.select-childs-page').show();
                }
            })
            .on('click', '.select-childs-page', function (e) {
                e.preventDefault();
                const $this = $(this);
                const $childTrs = $this.closest('tr').siblings('tr.' + $this.closest('tr[data-id]').data('id') + ':visible');
                if ($childTrs.length) {
                    $childTrs.find('input[type="checkbox"]').prop('checked', true).last().trigger('change');
                }
            });

        $mappingTable.closest('form').on('change', 'input[type="checkbox"]', function () {
            const $checkboxes = $mappingTable.find('input[type="checkbox"]');
            $checkboxes.each(function () {
                $(this).closest('tr').toggleClass('active', this.checked);
            });
            const count = $checkboxes.filter(':checked').length;
            $pinnedBar.toggleClass('hidden', !count);
            if (count > 0) {
                $pinnedBar.find('span.counter').text(count);
                if (count > 1 && $selectAction.val() === 'overwrite') {
                    $selectAction.val('new').trigger('change');
                }
                $selectAction.find('option[value="overwrite"]').prop('disabled', count > 1);
            }
        });

        $selectAction.on('change', function () {
            if (this.value === 'exclude') {
                $selectType.hide();
                $selectTypes.hide();
            } else {
                $selectType.show().trigger('change');
            }
        });

        $selectType.on('change', function () {
            $selectTypes.hide();
            if ($selectAction.val() === 'overwrite') {
                $selectTypes.filter('[name="custom_list_' + this.value + '"]').show();
            }
        });

        $pinnedBar
            .on('click', 'a.select-all', function (e) {
                e.preventDefault();
                $mappingTable.find('input[type="checkbox"]').prop('checked', true).last().trigger('change');
            })
            .on('click', 'a.deselect-all', function (e) {
                e.preventDefault();
                $mappingTable.find('input[type="checkbox"]').prop('checked', false).last().trigger('change');
            })
            .on('click', 'button', function (e) {
                e.preventDefault();
                const $checked = $mappingTable.find('input[type="checkbox"]:checked');
                const action = $selectAction.val();
                if (action === 'exclude') {
                    $checked.each(function () {
                        mapping[this.id] = {
                            type: 'exclude'
                        };
                    });
                } else if (action === 'overwrite' && $selectTypes.filter(':visible:first').val()) {
                    $checked.each(function () {
                        mapping[this.id] = {
                            type: 'overwrite',
                            value: $selectType.val(),
                            id: $selectTypes.filter(':visible:first').val()
                        };
                    });
                } else {
                    $checked.each(function () {
                        mapping[this.id] = {
                            type: 'new',
                            value: $selectType.val()
                        };
                    });
                }
                $checked.prop('checked', false).last().trigger('change');
                updateDisplay();
            });

        updateDisplay();
    }

    if (window.SLICKPLAN_JSON) {
        const $window = $(window);
        const $summary = $form.find('.slickplan-summary');
        const $progress = $('#slickplan-progressbar');

        const _types = window.SLICKPLAN_JSON.types;
        const _pages = window.SLICKPLAN_JSON.pages;
        const _pageTypes = Object.keys(_pages);

        const sumPages = Object.values(_pages).reduce(function (sum, entries) {
            sum += entries.length;
            return sum;
        }, 0);

        $progress.progressbar({
            value: 0,
            change: function () {
                $progress.find('.progress-label').text($progress.progressbar('value') + '%');
            }
        });

        const _addMenuID = function (pageType, parent_id, mlid) {
            for (let i = 0; i < _pages[pageType].length; ++i) {
                if (_pages[pageType][i].parent === parent_id) {
                    _pages[pageType][i].mlid = mlid;
                }
            }
        };

        const _importPage = function (pageTypeIndex, pageIndex) {
            const pageType = _pageTypes[pageTypeIndex];
            const page = _pages[pageType][pageIndex];
            if (!pageType || !page) {
                return;
            }
            const html = ('' + slickplan_ajax.html).replace('{{SLICKPLAN_ACTION}}', page.overwrite  ? 'Overwriting' : 'Importing')
                .replace('{{SLICKPLAN_TITLE}}', page.title)
                .replace('{{SLICKPLAN_TYPE}}', _types[pageType] || pageType);
            const $element = $(html).appendTo($summary);
            $summary.scrollTop(Number.MAX_SAFE_INTEGER);
            let sumProcessedPages = pageIndex;
            for (let i = 0; i < pageTypeIndex; ++i) {
                sumProcessedPages += _pages[_pageTypes[i]].length;
            }
            const percent = Math.round((sumProcessedPages / sumPages) * 100);
            $progress.progressbar('value', percent);
            const isLastPage = _pages[pageType].length === pageIndex + 1;
            const isLastType = _pageTypes.length === pageTypeIndex + 1;
            $.ajax({
                url: slickplan_ajax.ajaxurl,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'slickplan-importer',
                    slickplan: {
                        type: pageType,
                        page: page.id,
                        parent: page.parent || '',
                        overwrite: page.overwrite || '',
                        mlid: page.mlid || 0,
                        last: (isLastPage && isLastType) ? 1 : 0
                    },
                    _ajax_nonce: slickplan_ajax.nonce
                },
                success: function (data) {
                    if (data && data.html) {
                        $element.replaceWith(data.html);
                        if (data.mlid) {
                            _addMenuID(pageType, page.id, data.mlid);
                        }
                        if (isLastPage && isLastType) {
                            $progress.progressbar('value', 100);
                            $form.find('h3').text('Success!');
                            $form.find('.slickplan-show-summary').show();
                            window.SLICKPLAN_JSON = null;
                        } else {
                            _importPage(isLastPage ? pageTypeIndex + 1 : pageTypeIndex, isLastPage ? 0 : pageIndex + 1);
                        }
                        $window.trigger('resize');
                    }
                },
                error: function () {
                    alert('Unknown error, please delete imported pages and try again.');
                }
            });
        };

        $window.on('load resize', function () {
            const top = $summary.offset().top;
            $summary.hide();
            const form_height = $form.height();
            $summary.show();
            const height = $window.height() - form_height - top;
            $summary.height(height);
        });

        _importPage(0, 0);
    }
});
