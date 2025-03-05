<?php defined('SLICKPLAN_PLUGIN_PATH') or exit('No direct access allowed'); ?>

<div class="notice notice-success notice-alt">
    <p>
        Your file has been uploaded, <b><?php echo count($xml['pages']); ?></b> pages detected.
        Please review the import options below and click <strong>Import Pages</strong> button.
    </p>
</div>

<form action="" method="post" id="slickplan-importer">
    <?php wp_nonce_field(); ?>
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    Options:
                </th>
                <td>
                    <div class="is-flex">
                        <?php $this->displayPageTypesDropdown('page_type', true, 'Import as', false, $xml['import_form']['page_type'] ?? null); ?>
                    </div>
                    <br><hr><br>
                    <?php
                    $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
                        ? $xml['settings']['title']
                        : $xml['title'];
                    $checkboxes = [];
                    if ($title) {
                        $checkboxes[] = $this->displayCheckbox(
                            'settings_title',
                            'Update site title to: <cite>' . esc_html($title) . '</cite>',
                            (bool) ($xml['import_form']['settings_title'] ?? false),
                            'General Settings &rarr; Site Title'
                        );
                    }
                    if (isset($xml['settings']['tagline']) and $xml['settings']['tagline']) {
                        $checkboxes[] = $this->displayCheckbox(
                            'settings_tagline',
                            'Update tagline to: <cite>' . esc_html($xml['settings']['tagline']) . '</cite>',
                            (bool) ($xml['import_form']['settings_tagline'] ?? false),
                            'General Settings &rarr; Tagline'
                        );
                    }
                    if (isset($xml['settings']['language']) and $xml['settings']['language']) {
                        $languages = wp_get_available_translations();
                        if (is_array($languages) and isset($languages[$xml['settings']['language']])) {
                            $checkboxes[] = $this->displayCheckbox(
                                'settings_language',
                                'Update site language to <cite>' . esc_html($languages[$xml['settings']['language']]['native_name']) . '</cite>',
                                (bool) ($xml['import_form']['settings_language'] ?? false),
                                'General Settings &rarr; Site Language'
                            );
                        }
                    }
                    $checkboxes[] = $this->displayCheckbox(
                        'create_menu',
                        'Create menu from imported pages, menu name: ',
                        (bool) ($xml['import_form']['create_menu'] ?? false),
                        'Appearance &rarr; Menus',
                        '1',
                        'checkbox',
                        '',
                        '<input type="text" name="slickplan_importer[menu_name]" value="' . esc_attr($xml['import_form']['menu_name'] ?? 'Slickplan') . '">'
                    );
                    echo '<div>' . implode('</div><div style="margin-top: 15px;">', $checkboxes) . '</div>';
                    ?>
                </td>
            </tr>
            <tr><td colspan="2"><hr></td></tr>
            <tr>
                <th scope="row">
                    Format page titles:
                </th>
                <td id="slickplan-page-titles-radios">
                    <?php
                    $radios = [];
                    $radios[] = $this->displayRadio(
                        'titles_change',
                        'No change',
                        '',
                        '',
                        !($xml['import_form']['titles_change'] ?? false)
                    );
                    $radios[] = $this->displayRadio(
                        'titles_change',
                        'Capitalize first character of the first word',
                        'ucfirst',
                        'This is an example page title',
                        ($xml['import_form']['titles_change'] ?? null) === 'ucfirst'
                    );
                    $radios[] = $this->displayRadio(
                        'titles_change',
                        'Capitalize the first character of each word',
                        'ucwords',
                        'This Is An Example Page Title',
                        ($xml['import_form']['titles_change'] ?? null) === 'ucwords'
                    );
                    echo implode('<br><br>', $radios);
                    ?>
                </td>
            </tr>
            <tr><td colspan="2"><hr></td></tr>
            <tr>
                <th scope="row">
                    Content settings:
                </th>
                <td id="slickplan-page-content-radios">
                    <?php
                    $radio = $this->displayRadio(
                        'content',
                        'Import page contents from Content Planner',
                        'contents',
                        '',
                        ($xml['import_form']['content'] ?? null) === 'contents'
                    );
                    if (isset($content_languages) and count($content_languages) > 1) {
                        $radio .= '<br class="content-suboption-br">'
                            . '<span class="content-suboption" style="display: inline-block; padding: 5px 0 0 20px;">'
                            . $this->displayDropdown(
                                'content_lang',
                                'Language',
                                $content_languages,
                                $xml['import_form']['content_lang'] ?? null,
                            )
                            . '</span>';
                    }
                    if (isset($no_of_files) and $no_of_files) {
                        $radio .= '<br class="content-suboption-br">'
                            . '<span class="content-suboption" style="display: inline-block; padding: 5px 0 0 20px;">'
                            . $this->displayCheckbox(
                                'content_files',
                                'Import files to Media Library',
                                (bool) ($xml['import_form']['content_files'] ?? true),
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
                        'Import page notes as content',
                        'desc',
                        '',
                        ($xml['import_form']['content'] ?? null) === 'desc'
                    );
                    $radios[] = $this->displayRadio(
                        'content',
                        'Do not import any content',
                        '',
                        '',
                        !($xml['import_form']['content'] ?? null)
                    );
                    echo implode('<br><br>', $radios);
                    ?>
                </td>
            </tr>
            <?php if (isset($xml['users']) and is_array($xml['users']) and count($xml['users'])) { ?>
                <tr><td colspan="2"><hr></td></tr>
                <tr>
                    <th scope="row">
                        Users mapping:
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
                                <?php $this->displayUsersDropdown('users_map][' . $user_id, $xml['import_form']['users_map'][$user_id] ?? ''); ?>
                            </div>
                            <?php $margin = '5px'; ?>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <br>
    <?php if (isset($xml['sitemap']) and is_array($xml['sitemap']) and count($xml['sitemap'])) { ?>
        <input class="button button-primary" type="submit" value="Import Pages">
        <a href="<?php echo esc_attr($this->_getAdminUrl()); ?>" class="button button-secondary">Cancel</a>
    <?php } else { ?>
        <a href="<?php echo esc_attr($this->_getAdminUrl()); ?>" class="button button-secondary">Back</a>
    <?php } ?>
</form>
