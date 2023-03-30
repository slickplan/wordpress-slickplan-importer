<?php defined('SLICKPLAN_PLUGIN_PATH') or exit('No direct access allowed'); ?>

<div class="notice notice-success notice-alt">
    <p>
        Your file has been uploaded, <b><?php echo count($xml['pages']); ?></b> pages detected.
        Please review the import options below and click <strong>Import Pages</strong> button.
    </p>
</div>

<form action="" method="post" id="slickplan-importer">
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    Settings
                </th>
                <td>
                    <div class="is-flex">
                        <?php $this->displayPageTypesDropdown('page_type', false); ?>
                    </div>
                    <br>
                    <?php
                    $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
                        ? $xml['settings']['title']
                        : $xml['title'];
                    $checkboxes = [];
                    if ($title) {
                        $checkboxes[] = $this->displayCheckbox(
                            'settings_title',
                            'Update site title to: <cite>' . esc_html($title) . '</cite>',
                            false,
                            'General Settings &rarr; Site Title'
                        );
                    }
                    if (isset($xml['settings']['tagline']) and $xml['settings']['tagline']) {
                        $checkboxes[] = $this->displayCheckbox(
                            'settings_tagline',
                            'Update tagline to: <cite>' . esc_html($xml['settings']['tagline']) . '</cite>',
                            false,
                            'General Settings &rarr; Tagline'
                        );
                    }
                    if (isset($xml['settings']['language']) and $xml['settings']['language']) {
                        $languages = wp_get_available_translations();
                        if (is_array($languages) and isset($languages[$xml['settings']['language']])) {
                            $checkboxes[] = $this->displayCheckbox(
                                'settings_language',
                                'Update site language to <cite>' . esc_html($languages[$xml['settings']['language']]['native_name']) . '</cite>',
                                false,
                                'General Settings &rarr; Site Language'
                            );
                        }
                    }
                    $checkboxes[] = $this->displayCheckbox(
                        'create_menu',
                        'Create menu from imported pages, menu name: ',
                        false,
                        ['<input type="text" name="slickplan_importer[menu_name]" value="Slickplan">']
                    );
                    echo implode('<br><br>', $checkboxes);
                    ?>
                </td>
            </tr>
            <tr><td colspan="2"><hr></td></tr>
            <tr>
                <th scope="row">
                    Pages Titles
                </th>
                <td id="slickplan-page-titles-radios">
                    <?php
                    $radios = [];
                    $radios[] = $this->displayRadio(
                        'titles_change',
                        'No change',
                        '',
                        '',
                        true
                    );
                    $radios[] = $this->displayRadio(
                        'titles_change',
                        'Make just the first character uppercase',
                        'ucfirst',
                        'This is an example page title'
                    );
                    $radios[] = $this->displayRadio(
                        'titles_change',
                        'Uppercase the first character of each word',
                        'ucwords',
                        'This Is An Example Page Title'
                    );
                    echo implode('<br><br>', $radios);
                    ?>
                </td>
            </tr>
            <tr><td colspan="2"><hr></td></tr>
            <tr>
                <th scope="row">
                    Content Settings
                </th>
                <td id="slickplan-page-content-radios">
                    <?php
                    $radio = $this->displayRadio(
                        'content',
                        'Import page content from Content Planner',
                        'contents',
                        '',
                        true
                    );
                    if (isset($content_languages) and count($content_languages) > 1) {
                        $radio .= '<br class="content-suboption-br">'
                            . '<span class="content-suboption" style="display: inline-block; padding: 5px 0 0 20px;">'
                            . $this->displayDropdown(
                                'content_lang',
                                'Language',
                                $content_languages
                            )
                            . '</span>';
                    }
                    if (isset($no_of_files) and $no_of_files) {
                        $radio .= '<br class="content-suboption-br">'
                            . '<span class="content-suboption" style="display: inline-block; padding: 5px 0 0 20px;">'
                            . $this->displayCheckbox(
                                'content_files',
                                'Import files to Media Library',
                                true,
                                'Downloading files may take a while'
                                    . ((isset($filesize_total) and $filesize_total)
                                        ? ', approx. total size: ' . size_format($filesize_total)
                                        : ''
                                    )
                            )
                            . '</span>';
                    }
                    $radios = [$radio];
                    $radios[] = $this->displayRadio(
                        'content',
                        'Import notes as page content',
                        'desc'
                    );
                    $radios[] = $this->displayRadio(
                        'content',
                        'Don&#8217;t import any content',
                        ''
                    );
                    echo implode('<br><br>', $radios);
                    ?>
                </td>
            </tr>
            <?php if (isset($xml['users']) and is_array($xml['users']) and count($xml['users'])) { ?>
                <tr><td colspan="2"><hr></td></tr>
                <tr>
                    <th scope="row">
                        Users Mapping
                    </th>
                    <td style="margin-trim: block-end;">
                        <?php
                        $margin = '0';
                        foreach ($xml['users'] as $user_id => $data) {
                            $name = [];
                            if (isset($data['firstName']) and $data['firstName']) {
                                $name[] = $data['firstName'];
                            }
                            if (isset($data['lastName']) and $data['lastName']) {
                                $name[] = $data['lastName'];
                            }
                            if (isset($data['email']) and $data['email']) {
                                if (count($name)) {
                                    $data['email'] = '(' . $data['email'] . ')';
                                }
                                $name[] = $data['email'];
                            }
                            if (!count($name)) {
                                $name[] = $user_id;
                            }
                            ?>
                            <div class="is-flex" style="margin-top: <?php echo $margin; ?>">
                                <p><?php echo implode(' ', $name); ?>:</p>
                                <?php $this->displayUsersDropdown('users_map][' . $user_id); ?>
                            </div>
                            <?php $margin = '5px'; ?>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            <tr><td colspan="2"><hr></td></tr>
        </tbody>
    </table>
    <?php if (isset($xml['sitemap']) and is_array($xml['sitemap']) and count($xml['sitemap'])) { ?>
        <input class="button button-primary" type="submit" value="Import Pages">
        <a href="<?php echo esc_attr($this->_getAdminUrl()); ?>" class="button button-secondary">Cancel</a>
    <?php } else { ?>
        <a href="<?php echo esc_attr($this->_getAdminUrl()); ?>" class="button button-secondary">Back</a>
    <?php } ?>
</form>
