<div class="updated" style="border-color: #FFBA00">
    <p>Your file has been uploaded. Please review the import options below and click <strong>Import Pages</strong> button to finish import.</p>
</div>

<form action="" method="post" id="slickplan-importer">
    <table class="form-table">
        <tbody>
            <tr>
                <th scope="row">
                    Website Settings
                </th>
                <td>
                    <?php
                    $title = (isset($slickplan['settings']['title']) and $slickplan['settings']['title'])
                        ? $slickplan['settings']['title']
                        : $slickplan['title'];
                    $checkboxes = array();
                    if ($title) {
                        $checkboxes[] = $this->_displayCheckbox(
                            'settings_title',
                            'Set website title to <cite>&bdquo;' . $title . '&rdquo;</cite>',
                            false,
                            'It will change the Site Title in General Settings'
                        );
                    }
                    if (isset($slickplan['settings']['tagline']) and $slickplan['settings']['tagline']) {
                        $checkboxes[] = $this->_displayCheckbox(
                            'settings_tagline',
                            'Set website tagline to <cite>&bdquo;' . $slickplan['settings']['tagline'] . '&rdquo;</cite>',
                            false,
                            'It will change the Tagline in General Settings'
                        );
                    }
                    if (isset($slickplan['settings']['language']) and $slickplan['settings']['language']) {
                        $languages = wp_get_available_translations();
                        if (is_array($languages) and isset($languages[$slickplan['settings']['language']])) {
                            $checkboxes[] = $this->_displayCheckbox(
                                'settings_language',
                                'Set website language to <cite>&bdquo;' . $languages[$slickplan['settings']['language']]['native_name'] . '&rdquo;</cite>',
                                false,
                                'It will change the Site Language in General Settings'
                            );
                        }
                    }
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
                    $radios = array();
                    $radios[] = $this->_displayRadio(
                        'titles_change',
                        'No change',
                        '',
                        '',
                        true
                    );
                    $radios[] = $this->_displayRadio(
                        'titles_change',
                        'Make just the first character uppercase',
                        'ucfirst',
                        'This is an example page title'
                    );
                    $radios[] = $this->_displayRadio(
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
                    Pages Settings
                </th>
                <td id="slickplan-page-content-radios">
                    <?php
                    $radios = array();
                    $radios[] = $this->_displayRadio(
                            'content',
                            'Import page content',
                            'contents',
                            '',
                            true
                        )
                        . '<br class="content-suboption-br">'
                        . '<span class="content-suboption" style="display: inline-block; padding: 3px 0 0 20px;">'
                        . $this->_displayCheckbox(
                            'content_files',
                            'Import files to media library',
                            true,
                            'Downloading files may take a while'
                        )
                        . '</span>';
                    $radios[] = $this->_displayRadio(
                        'content',
                        'Import page notes as content',
                        'desc'
                    );
                    $radios[] = $this->_displayRadio(
                        'content',
                        'Don&#8217;t import any content',
                        ''
                    );
                    echo implode('<br><br>', $radios);
                    ?>
                </td>
            </tr>
            <?php if (isset($slickplan['users']) and is_array($slickplan['users']) and count($slickplan['users'])) { ?>
                <tr><td colspan="2"><hr></td></tr>
                <tr>
                    <th scope="row">
                        Users Mapping
                    </th>
                    <td>
                        <table class="users-mapping">
                            <tbody>
                                <?php
                                foreach ($slickplan['users'] as $user_id => $data) {
                                    $name = array();
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
                                    <tr>
                                        <td><?php echo implode(' ', $name); ?>:</td>
                                        <td><?php $this->_displayUsersDropdown('users_map][' . $user_id); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php } ?>
            <tr><td colspan="2"><hr></td></tr>
        </tbody>
    </table>
    <?php if (isset($slickplan['sitemap']) and is_array($slickplan['sitemap']) and count($slickplan['sitemap'])) { ?>
        <input class="button button-primary" type="submit" value="Import Pages">
        <a href="<?php echo esc_attr($this->_getAdminUrl()); ?>" class="button button-secondary">Cancel</a>
    <?php } else { ?>
        <a href="<?php echo esc_attr($this->_getAdminUrl()); ?>" class="button button-secondary">Back</a>
    <?php } ?>
</form>

<style type="text/css">
    <?php require SLICKPLAN_PLUGIN_PATH . 'assets/styles.css'; ?>
</style>

<script type="text/javascript">
    <?php require SLICKPLAN_PLUGIN_PATH . 'assets/scripts.js'; ?>
</script>