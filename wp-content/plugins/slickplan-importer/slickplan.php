<?php
/*
Plugin Name: Slickplan Importer
Plugin URI: http://wordpress.org/extend/plugins/slickplan-importer/
Description: Import pages from a <a href="http://slickplan.com" target="_blank">Slickplan</a>’s XML export file. To use go to the <a href="import.php">Tools -> Import</a> screen and select Slickplan.
Author: Slickplan.com <info@slickplan.com>
Author URI: http://slickplan.com/
Version: 2.0.0
License: GNU General Public License Version 3 - http://www.gnu.org/licenses/gpl-3.0.html
*/

// Check if WP_LOAD_IMPORTERS is present
if (!defined('WP_LOAD_IMPORTERS')) {
    return;
}

function_exists('ob_start') and ob_start();
function_exists('set_time_limit') and set_time_limit(600);

// Include required files
require_once ABSPATH . 'wp-admin/includes/import.php';
require_once ABSPATH . 'wp-admin/includes/translation-install.php';

if (!class_exists('WP_Importer')) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (is_file($class_wp_importer)) {
        require_once $class_wp_importer;
    }
}

if (class_exists('WP_Importer') and !class_exists('Slickplan_Importer')) {

    /**
     * Slickplan's plugin directory path.
     *
     * @var string
     */
    define('SLICKPLAN_PLUGIN_PATH', plugin_dir_path(__FILE__) . DIRECTORY_SEPARATOR);

    /**
     * Slickplan's plugin importer ID.
     *
     * @var string
     */
    define('SLICKPLAN_PLUGIN_ID', 'slickplan');

    /**
     * Slickplan's plugin importer database option key.
     *
     * @var string
     */
    define('SLICKPLAN_PLUGIN_OPTION', 'slickplan_importer');

    /**
     * A string to prepend to indented pages.
     *
     * @var string
     */
    define('SLICKPLAN_PLUGIN_INDENT', '&#8212;');

    /**
     * Class Slickplan_Importer
     */
    class Slickplan_Importer extends WP_Importer
    {

        /**
         * Menu order increment.
         *
         * @var int
         */
        private $_order = 0;

        /**
         * An array of import options.
         *
         * @var int
         */
        private $_options = array();

        /**
         * An array of imported pages or errors.
         *
         * @var int
         */
        private $_summary = array();

        /**
         * Importer page routing.
         */
        public function dispatch()
        {
            $step = isset($_GET['step']) ? $_GET['step'] : null;

            $this->_displayHeader();

            if ($step === 'map' or ($step === 'upload' and isset($_FILES) and !empty($_FILES))) {
                if ($step === 'map') {
                    $result = $this->_displayImportOptions();
                } else {
                    check_admin_referer('import-upload');
                    $result = $this->handleFileUpload();
                }
                if (is_wp_error($result)) {
                    $this->_displayError($result->get_error_message());
                }
            } elseif ($step === 'done') {
                $this->_displaySummary();
            } else {
                $this->_displayUploadForm();
            }

            $this->_displayFooter();
        }

        /**
         * Handle file upload and prepare to import pages.
         */
        public function handleFileUpload()
        {
            $file = wp_import_handle_upload();
            if (isset($file['error'])) {
                return $this->_displayError($file['error']);
            }

            if (isset($file['file'], $file['id']) and is_file($file['file'])) {
                $xml_content = file_get_contents($file['file']);
                wp_import_cleanup($file['id']);

                $result = $this->_parseSlickplanXml($xml_content);
                if (is_wp_error($result)) {
                    $this->_displayError($result->get_error_message());
                } else {
                    update_option(SLICKPLAN_PLUGIN_OPTION, $result);
                    wp_redirect($this->_getAdminUrl('map'));
                    exit;
                }
            }
        }

        /**
         * Get admin URL.
         *
         * @param string $step
         * @return string
         */
        private function _getAdminUrl($step = null)
        {
            $url = 'admin.php?import=' . SLICKPLAN_PLUGIN_ID;
            if ($step) {
                $url .= '&step=' . $step;
            }
            return get_admin_url(null, $url);
        }

        /**
         * Display importer options and page mapping.
         */
        private function _displayImportOptions()
        {
            $slickplan = get_option(SLICKPLAN_PLUGIN_OPTION, null);
            if (!$slickplan or !$this->_isCorrectSlickplanXmlFile($slickplan, true)) {
                return new WP_Error(SLICKPLAN_PLUGIN_ID, 'Invalid file content.');
            }
            if (isset($_POST['slickplan_importer']) and is_array($_POST['slickplan_importer'])) {
                $form = $_POST['slickplan_importer'];
                if (
                    isset($form['settings_language'], $slickplan['settings']['language'])
                    and $form['settings_language']
                ) {
                    $this->_changeLanguage($slickplan['settings']['language']);
                }
                if (isset($form['settings_title']) and $form['settings_title']) {
                    $title = (isset($slickplan['settings']['title']) and $slickplan['settings']['title'])
                        ? $slickplan['settings']['title']
                        : $slickplan['title'];
                    update_option('blogname', $title);
                }
                if (isset($form['settings_tagline']) and $form['settings_tagline']) {
                    update_option('blogdescription', $slickplan['settings']['tagline']);
                }
                $this->_options = array(
                    'titles' => isset($form['titles_change']) ? $form['titles_change'] : '',
                    'content' => isset($form['content']) ? $form['content'] : '',
                    'content_files' => (isset($form['content_files']) and $form['content_files']),
                    'users' => isset($form['users_map']) ? $form['users_map'] : array(),
                );
                foreach (array('home', '1', 'util', 'foot') as $type) {
                    if (isset($slickplan['sitemap'][$type]) and is_array($slickplan['sitemap'][$type])) {
                        $this->_importPages($slickplan['sitemap'][$type]);
                    }
                }
                update_option(SLICKPLAN_PLUGIN_OPTION, array(
                    'summary' => $this->_getMultidimensionalArray($this->_summary, 0, true),
                ));
                do_action('import_done', SLICKPLAN_PLUGIN_OPTION);
                wp_redirect($this->_getAdminUrl('done'));
                exit;
            }

            require_once SLICKPLAN_PLUGIN_PATH . 'view.php';
        }

        /**
         * Import pages into WordPress.
         *
         * @param array $pages
         * @param int $parent_id
         */
        private function _importPages(array $pages, $parent_id = 0)
        {
            foreach ($pages as $page) {
                $this->_importPage($page, $parent_id);
            }
        }

        /**
         * Import single page into WordPress.
         *
         * @param array $data
         * @param int $parent_id
         */
        private function _importPage(array $data, $parent_id = 0)
        {
            $this->_order += 10;

            $post_title = (isset($data['contents']['page_title']) and $data['contents']['page_title'])
                ? $data['contents']['page_title']
                : (isset($data['text']) ? $data['text'] : '');
            $page = array(
                'post_status' => 'publish',
                'post_content' => '',
                'post_type' => 'page',
                'post_title' => $this->_getFormattedTitle($post_title, $this->_options['titles']),
                'menu_order' => $this->_order,
                'post_parent' => $parent_id,
            );

            // Set post content
            if ($this->_options['content'] === 'desc') {
                if (isset($data['desc']) and !empty($data['desc'])) {
                    $page['post_content'] = $data['desc'];
                }
            } elseif ($this->_options['content'] === 'contents') {
                if (
                    isset($data['contents']['body'])
                    and is_array($data['contents']['body'])
                    and count($data['contents']['body'])
                ) {
                    $page['post_content'] = $this->_getFormattedContent($data['contents']['body']);
                }
            }

            // Set the SEO meta values
            if (
                isset($data['contents']['meta_title'])
                or isset($data['contents']['meta_description'])
                or isset($data['contents']['meta_focus_keyword'])
            ) {
                if (class_exists('WPSEO_Meta') and method_exists('WPSEO_Meta', 'set_value')) {
                    if (isset($data['contents']['meta_title']) and $data['contents']['meta_title']) {
                        WPSEO_Meta::set_value('yoast_wpseo_title', $data['contents']['meta_title']);
                    }
                    if (isset($data['contents']['meta_description']) and $data['contents']['meta_description']) {
                        WPSEO_Meta::set_value('yoast_wpseo_metadesc', $data['contents']['meta_description']);
                    }
                    if (isset($data['contents']['meta_focus_keyword']) and $data['contents']['meta_focus_keyword']) {
                        WPSEO_Meta::set_value('yoast_wpseo_focuskw', $data['contents']['meta_focus_keyword']);
                    }
                }
            }

            // Set url slug
            if (isset($data['contents']['url_slug']) and $data['contents']['url_slug']) {
                $page['post_name'] = $data['contents']['url_slug'];
            }

            // Set post author
            if (isset(
                $data['contents']['assignee']['@value'],
                $this->_options['users'][$data['contents']['assignee']['@value']]
            )) {
                $page['post_author'] = $this->_options['users'][$data['contents']['assignee']['@value']];
            }

            // Set post status
            if (isset($data['contents']['status']) and $data['contents']['status'] === 'draft') {
                $page['post_status'] = $data['contents']['status'];
            }

            $page_id = wp_insert_post($page);
            $page['ID'] = is_wp_error($page_id) ? false : $page_id;
            if (is_wp_error($page_id)) {
                $page['ID'] = false;
                $page['error'] = $page_id->get_error_message();
                $this->_summary[] = $page;
            } else {
                $page['ID'] = $page_id;
                $this->_summary[] = $page;
                if (isset($data['childs']) and is_array($data['childs'])) {
                    $this->_importPages($data['childs'], $page_id);
                }
            }
        }

        /**
         * Get formatted HTML content.
         *
         * @param array $content
         */
        private function _getFormattedContent(array $contents)
        {
            $post_content = array();
            foreach ($contents as $type => $content) {
                if (isset($content['content'])) {
                    $content = array($content);
                }
                foreach ($content as $element) {
                    if (!isset($element['content'])) {
                        continue;
                    }
                    $html = '';
                    switch ($type) {
                        case 'wysiwyg':
                            $html .= $element['content'];
                            break;
                        case 'text':
                            $html .= htmlspecialchars($element['content']);
                            break;
                        case 'image':
                            if (isset($element['content']['type'], $element['content']['url'])) {
                                $attrs = array(
                                    'alt' => isset($element['content']['alt'])
                                        ? $element['content']['alt']
                                        : '',
                                    'title' => isset($element['content']['title'])
                                        ? $element['content']['title']
                                        : '',
                                    'file_name' => isset($element['content']['file_name'])
                                        ? $element['content']['file_name']
                                        : '',
                                );
                                if ($element['content']['type'] === 'library') {
                                    $src = $this->_addMedia($element['content']['url'], true, $attrs);
                                } else {
                                    $src = $element['content']['url'];
                                }
                                if ($src and !is_wp_error($src)) {
                                    $html .= '<img src="' . esc_url($src) . '" alt="' . esc_url($attrs['alt'])
                                        . '" title="' . esc_url($attrs['title']) . '" />';
                                }
                            }
                            break;
                        case 'video':
                        case 'file':
                            if (isset($element['content']['type'], $element['content']['url'])) {
                                $attrs = array(
                                    'description' => isset($element['content']['description'])
                                        ? $element['content']['description']
                                        : '',
                                    'file_name' => isset($element['content']['file_name'])
                                        ? $element['content']['file_name']
                                        : '',
                                );
                                if ($element['content']['type'] === 'library') {
                                    $src = $this->_addMedia($element['content']['url'], true, $attrs);
                                    $name = basename($src);
                                } else {
                                    $src = $element['content']['url'];
                                    $name = $src;
                                }
                                if ($src and !is_wp_error($src)) {
                                    $name = $attrs['description']
                                        ? $attrs['description']
                                        : ($attrs['file_name'] ? $attrs['file_name'] : $name);
                                    $html .= '<a href="' . esc_url($src) . '" title="'
                                        . esc_url($attrs['description']) . '">' . $name . '</a>';
                                }
                            }
                            break;
                        case 'table':
                            if (isset($element['content']['data'])) {
                                if (!is_array($element['content']['data'])) {
                                    $element['content']['data'] = @json_decode($element['content']['data'], true);
                                }
                                if (is_array($element['content']['data'])) {
                                    $html .= '<table>';
                                    foreach ($element['content']['data'] as $row) {
                                        $html .= '<tr>';
                                        foreach ($row as $cell) {
                                            $html .= '<td>' . $cell . '</td>';
                                        }
                                        $html .= '</tr>';
                                    }
                                    $html .= '<table>';
                                }
                            }
                            break;
                    }
                    if ($html) {
                        $prepend = '';
                        $append = '';
                        if (isset($element['options']['tag']) and $element['options']['tag']) {
                            $element['options']['tag'] = preg_replace('/[^a-z]+/', '',
                                strtolower($element['options']['tag']));
                            if ($element['options']['tag']) {
                                $prepend = '<' . $element['options']['tag'];
                                if (isset($element['options']['tag_id']) and $element['options']['tag_id']) {
                                    $prepend .= ' id="' . esc_attr($element['options']['tag_id']) . '"';
                                }
                                if (isset($element['options']['tag_class']) and $element['options']['tag_class']) {
                                    $prepend .= ' class="' . esc_attr($element['options']['tag_class']) . '"';
                                }
                                $prepend .= '>';
                            }
                        }
                        if (isset($element['options']['tag']) and $element['options']['tag']) {
                            $append = '</' . $element['options']['tag'] . '>';
                        }
                        $post_content[] = $prepend . $html . $append;
                    }
                }
            }
            return implode("\n\n", $post_content);
        }

        /**
         * Reformat title.
         *
         * @param $title
         * @param $type
         * @return string
         */
        private function _getFormattedTitle($title, $type)
        {
            if ($type === 'ucfirst') {
                if (function_exists('mb_strtolower')) {
                    $title = mb_strtolower($title);
                    $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
                } else {
                    $title = ucfirst(strtolower($title));
                }
            } elseif ($type === 'ucwords') {
                if (function_exists('mb_convert_case')) {
                    $title = mb_convert_case($title, MB_CASE_TITLE);
                } else {
                    $title = ucwords(strtolower($title));
                }
            }
            return $title;
        }

        /**
         * Add a file to Media Library from URL
         *
         * @param $url
         * @param bool $return_url
         * @return bool|int|mixed|object|string
         */
        private function _addMedia($url, $return_url = true, array $attrs = array())
        {
            if (!$this->_options['content_files']) {
                return false;
            }
            $tmp = download_url($url);
            $file_array = array(
                'name' => isset($attrs['file_name']) ? $attrs['file_name'] : basename($url),
                'tmp_name' => $tmp,
            );

            // Check for download errors
            if (is_wp_error($tmp)) {
                @unlink($file_array['tmp_name']);
                return $tmp;
            }

            $options = array();
            if (isset($attrs['title']) and $attrs['title']) {
                $options['post_title'] = $attrs['title'];
            }
            if (isset($attrs['alt']) and $attrs['alt']) {
                $options['post_content'] = $attrs['alt'];
            }
            if (isset($attrs['file_name']) and $attrs['file_name']) {
                $options['post_content'] = $attrs['file_name'];
            }

            $id = media_handle_sideload(
                $file_array,
                0,
                isset($attrs['description']) ? $attrs['description'] : '',
                $options
            );

            // Check for handle sideload errors.
            if (is_wp_error($id)) {
                @unlink($file_array['tmp_name']);
                return $id;
            }

            if ($return_url) {
                return wp_get_attachment_url($id);
            }
            return $id;
        }

        /**
         * Display importer errors.
         */
        private function _displayError($errors)
        {
            if (is_array($errors)) {
                $errors = implode("\n\n", $errors);
            }
            $errors = trim($errors);
            if ($errors) {
                echo '<div class="error">', wpautop($errors), '</div>';
            }
            $this->_displayUploadForm(!!$errors);
        }

        /**
         * Display importer HTML form.
         *
         * @param bool $hide_info
         */
        private function _displayUploadForm($hide_info = false)
        {
            echo '<div class="narrow">';
            if (!$hide_info) {
                echo '<div class="updated" style="border-color: #FFBA00">',
                '<p>This importer allows you to import pages structure from a ',
                    '<a href="http://slickplan.com" target="_blank">Slickplan</a>&#8217;s ',
                    'XML file into your WordPress site.</p>',
                '<p>Pick a XML file to upload and click Import.</p>',
                '</div>';
            }
            wp_import_upload_form($this->_getAdminUrl('upload'));
            echo '</div>';
        }

        /**
         * Display import summary.
         */
        private function _displaySummary()
        {
            $message = sprintf(
                'All done. Thank you for using <a href="%s">Slickplan</a> Importer! '
                    . 'Click <a href="%s">here</a> to see your pages.',
                'http://slickplan.com/',
                get_admin_url(null, 'edit.php?post_type=page')
            );
            echo '<div class="updated">', wpautop($message), '</div>';

            $slickplan = get_option(SLICKPLAN_PLUGIN_OPTION, null);
            if (isset($slickplan['summary']) and is_array($slickplan['summary'])) {
                $this->_displaySummaryArray($slickplan['summary']);
                update_option(SLICKPLAN_PLUGIN_OPTION, '');
            }
        }

        /**
         * Display summary pages.
         *
         * @param string $array
         * @param integer $indent
         */
        private function _displaySummaryArray(array $array)
        {
            echo '<ul class="ul-disc">';
            foreach ($array as $page) {
                echo '<li>';
                if ($page['post_status'] === 'draft') {
                    $page['post_title'] .= ' (draft)';
                }
                if (isset($page['ID']) and $page['ID']) {
                    echo '<a href="',
                         get_admin_url(null, 'post.php?post=' . $page['ID'] . '&action=edit') . '">',
                         $page['post_title'] . '</a>';
                } elseif (isset($page['error']) and $page['error']) {
                    echo $page['post_title'], ' - <span style="color: #e00;">', $page['error'] . '</span>';
                }
                if (isset($page['childs']) and is_array($page['childs']) and count($page['childs'])) {
                    $this->_displaySummaryArray($page['childs']);
                }
            }
            echo '</ul>';
        }

        /**
         * Display importer page header HTML.
         *
         * @param string $step
         */
        private function _displayHeader($step = null)
        {
            echo '<div class="wrap">',
            '<h2>Slickplan XML Importer';
            if ($step === 'map') {
                echo ' (Step 2)';
            }
            echo '</h2>';

            if (!class_exists('DomDocument') or version_compare(PHP_VERSION, '5.0.0', '<')) {
                $this->_displayError('Sorry! This importer requires PHP5 and DomDocument extensions.');
            }
        }

        /**
         * Display importer page footer HTML.
         */
        private function _displayFooter()
        {
            echo '</div>';
        }

        /**
         * Display checkbox.
         *
         * @param $name
         * @param string $label
         * @param bool $checked
         * @param string $description
         */
        private function _displayCheckbox(
            $name,
            $label = '',
            $checked = false,
            $description = '',
            $value = '1',
            $type = 'checkbox',
            $class = ''
        ) {
            $id = sanitize_title('slickplan-importer-form-' . $name . '-' . $value);
            $attrs = array(
                'type' => $type,
                'name' => 'slickplan_importer[' . $name . ']',
                'value' => $value,
                'id' => $id,
            );
            if ($class) {
                $attrs['class'] = $class;
            }
            if ($checked) {
                $attrs['checked'] = 'checked';
            }

            $html = '<label for="' . $id . '"><input';
            foreach ($attrs as $key => $value) {
                $html .= ' ' . $key . '="' . esc_attr($value) . '"';
            }
            $html .= '>' . $label . '</label>';
            if ($description) {
                $html .= '<br><span class="description">(' . $description . ')</span>';
            }
            return $html;
        }

        /**
         * Display radio element.
         *
         * @param $name
         * @param string $label
         * @param bool $checked
         * @param string $description
         */
        private function _displayRadio(
            $name,
            $label = '',
            $value = '',
            $description = '',
            $checked = false,
            $class = ''
        ) {
            return $this->_displayCheckbox($name, $label, $checked, $description, $value, 'radio', $class);
        }

        /**
         * Display dropdown element with users.
         *
         * @param $name
         * @param string $selected
         */
        private function _displayUsersDropdown($name, $selected = '')
        {
            wp_dropdown_users(array(
                'selected' => $selected,
                'name' => 'slickplan_importer[' . $name . ']',
            ));
        }

        /**
         * Parse Slickplan's XML file. Converts an XML DOMDocument to an array.
         *
         * @param string $input_xml
         * @return array
         */
        private function _parseSlickplanXml($input_xml)
        {
            $input_xml = trim($input_xml);
            if (substr($input_xml, 0, 5) === '<?xml') {
                try {
                    $xml = new DomDocument('1.0', 'UTF-8');
                    $xml->xmlStandalone = false;
                    $xml->formatOutput = true;
                    $xml->loadXML($input_xml);
                } catch (Exception $e) {
                    return new WP_Error(SLICKPLAN_PLUGIN_ID, 'XML parse error: ' . $e->getMessage());
                }
                if (isset($xml->documentElement->tagName) and $xml->documentElement->tagName === 'sitemap') {
                    $array = $this->_parseSlickplanXmlNode($xml->documentElement);
                    if ($this->_isCorrectSlickplanXmlFile($array)) {
                        if (isset($array['diagram'])) {
                            unset($array['diagram']);
                        }
                        $array['sitemap'] = $this->_getMultidimensionalArrayHelper($array);
                        $array['users'] = array();
                        foreach ($array['section'] as $section) {
                            if (isset($section['cells']['cell']) and is_array($section['cells']['cell'])) {
                                foreach ($section['cells']['cell'] as $cell) {
                                    if (isset(
                                        $cell['contents']['assignee']['@value'],
                                        $cell['contents']['assignee']['@attributes']
                                    )) {
                                        $array['users'][$cell['contents']['assignee']['@value']]
                                            = $cell['contents']['assignee']['@attributes'];
                                    }
                                }
                            }
                        }
                        unset($array['section']);
                        return $array;
                    }
                }
            }
            return new WP_Error(SLICKPLAN_PLUGIN_ID, 'Invalid file format.');
        }

        /**
         * Parse single node XML element.
         *
         * @param DOMElement $node
         * @return array|string
         */
        private function _parseSlickplanXmlNode($node)
        {
            if (isset($node->nodeType)) {
                if ($node->nodeType === XML_CDATA_SECTION_NODE or $node->nodeType === XML_TEXT_NODE) {
                    return trim($node->textContent);
                } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                    $output = array();
                    for ($i = 0, $j = $node->childNodes->length; $i < $j; ++$i) {
                        $child_node = $node->childNodes->item($i);
                        $value = $this->_parseSlickplanXmlNode($child_node);
                        if (isset($child_node->tagName)) {
                            if (!isset($output[$child_node->tagName])) {
                                $output[$child_node->tagName] = array();
                            }
                            $output[$child_node->tagName][] = $value;
                        } elseif ($value !== '') {
                            $output = $value;
                        }
                    }

                    if (is_array($output)) {
                        foreach ($output as $tag => $value) {
                            if (is_array($value) and count($value) === 1) {
                                $output[$tag] = $value[0];
                            }
                        }
                        if (empty($output)) {
                            $output = '';
                        }
                    }

                    if ($node->attributes->length) {
                        $attributes = array();
                        foreach ($node->attributes as $attr_name => $attr_node) {
                            $attributes[$attr_name] = (string)$attr_node->value;
                        }
                        if (!is_array($output)) {
                            $output = array(
                                '@value' => $output,
                            );
                        }
                        $output['@attributes'] = $attributes;
                    }
                    return $output;
                }
            }
            return array();
        }

        /**
         * Check if the array is from a correct Slickplan XML file.
         *
         * @param array $array
         * @param bool $parsed
         * @return bool
         */
        private function _isCorrectSlickplanXmlFile($array, $parsed = false)
        {
            $first_test = (
                $array
                and is_array($array)
                and isset($array['title'], $array['version'], $array['link'])
                and is_string($array['link']) and substr($array['link'], 0, 17) === 'http://slickplan.'
            );
            if ($first_test) {
                if ($parsed) {
                    if (isset($array['sitemap']) and is_array($array['sitemap'])) {
                        return true;
                    }
                } elseif (isset($array['section'][0]['options']['id'], $array['section'][0]['cells'])) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Get multidimensional array, put all child pages as nested array of the parent page.
         *
         * @param array $array
         * @return array
         */
        private function _getMultidimensionalArrayHelper(array $array)
        {
            $cells = array();
            $main_section_key = -1;
            $relation_section_cell = array();
            foreach ($array['section'] as $section_key => $section) {
                if (
                    isset($section['@attributes']['id'], $section['cells']['cell'])
                    and is_array($section['cells']['cell'])
                ) {
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        if (isset($cell['@attributes']['id'])) {
                            $cell_id = $cell['@attributes']['id'];
                            if (isset($cell['section']) and $cell['section']) {
                                $relation_section_cell[$cell['section']] = $cell_id;
                            }
                        } else {
                            unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                        }
                    }
                } else {
                    unset($array['section'][$section_key]);
                }
            }
            foreach ($array['section'] as $section_key => $section) {
                $section_id = $section['@attributes']['id'];
                if ($section_id !== 'svgmainsection') {
                    $remove = true;
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        $cell['level'] = (string)$cell['level'];
                        if ($cell['level'] === 'home') {
                            unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                        } elseif ($cell['level'] === '1' and isset($relation_section_cell[$section_id])) {
                            $array['section'][$section_key]['cells']['cell'][$cell_key]['parent']
                                = $relation_section_cell[$section_id];
                            $remove = false;
                            $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] *= 10;
                        }
                    }
                    if ($remove) {
                        unset($array['section'][$section_key]);
                    }
                } else {
                    $main_section_key = $section_key;
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        $array['section'][$section_key]['cells']['cell'][$cell_key]['order'] /= 1000;
                    }
                }
            }
            foreach ($array['section'] as $section_key => $section) {
                $section_cells = array();
                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                    $section_cells[] = $cell;
                }
                usort($section_cells, array($this, '_sortPages'));
                $array['section'][$section_key]['cells']['cell'] = $section_cells;
                $cells = array_merge($cells, $section_cells);
                unset($section_cells);
            }
            $multi_array = array();
            if (isset($array['section'][$main_section_key]['cells']['cell'])) {
                foreach ($array['section'][$main_section_key]['cells']['cell'] as $cell) {
                    if ($cell['level'] === 'home' or $cell['level'] === 'util' or $cell['level'] === 'foot'
                        or $cell['level'] === '1' or $cell['level'] === 1
                    ) {
                        $childs = $this->_getMultidimensionalArray($cells, $cell['@attributes']['id']);
                        if ($childs) {
                            $cell['childs'] = $childs;
                        }
                        if (!isset($multi_array[$cell['level']]) or !is_array($multi_array[$cell['level']])) {
                            $multi_array[$cell['level']] = array();
                        }
                        $multi_array[$cell['level']][] = $cell;
                    }
                }
            }
            unset($array, $cells, $relation_section_cell);
            return $multi_array;
        }

        /**
         * Put all child pages as nested array of the parent page.
         *
         * @param array $array
         * @param $parent
         * @param $summary
         * @return array
         */
        private function _getMultidimensionalArray(array $array, $parent, $summary = false)
        {
            $cells = array();
            $parent_key = $summary ? 'post_parent' : 'parent';
            foreach ($array as $cell) {
                if (isset($cell[$parent_key]) and $cell[$parent_key] === $parent) {
                    $cell_id = $summary ? $cell['ID'] : $cell['@attributes']['id'];
                    $childs = $this->_getMultidimensionalArray($array, $cell_id, $summary);
                    if ($childs) {
                        $cell['childs'] = $childs;
                    }
                    $cells[] = $cell;
                }
            }
            return $cells;
        }

        /**
         * Sort cells.
         *
         * @param array $a
         * @param array $b
         * @return int
         */
        private function _sortPages(array &$a, array &$b)
        {
            if (isset($a['order'], $b['order'])) {
                return ($a['order'] < $b['order']) ? -1 : 1;
            }
            return 0;
        }

        /**
         * Change the WordPress language
         *
         * @param $language
         */
        private function _changeLanguage($language)
        {
            $new_language = false;
            if ($language !== get_locale()) {
                $languages = get_available_languages();
                if (in_array($language, $languages)) {
                    $new_language = $language;
                } elseif (wp_can_install_language_pack()) {
                    $languages = wp_get_available_translations();
                    if (is_array($languages) and isset($languages[$language])) {
                        $new_language = wp_download_language_pack($language);
                    }
                }
            }
            if ($new_language) {
                update_option('WPLANG', $new_language);
                load_default_textdomain($new_language);
            }
        }

    }

    /**
     * Slickplan Importer initialization
     */
    function slickplan_importer_init()
    {
        register_importer(
            SLICKPLAN_PLUGIN_ID,
            'Slickplan',
            'Import pages from the <a href="http://slickplan.com" target="_blank">Slickplan</a>&#8217;s XML file.',
            array(
                new Slickplan_Importer,
                'dispatch',
            )
        );
    }

    /**
     * Plugin activation hook. Register an option.
     */
    function slickplan_importer_activation_hook()
    {
        add_option(SLICKPLAN_PLUGIN_OPTION, '', '', 'no');
    }

    /**
     * Plugin deactivation hook. Removes an option from database.
     */
    function slickplan_importer_deactivation_hook()
    {
        delete_option(SLICKPLAN_PLUGIN_OPTION);
    }

    add_action('admin_init', 'slickplan_importer_init');

    register_activation_hook($file, 'slickplan_importer_activation_hook');
    register_deactivation_hook($file, 'slickplan_importer_deactivation_hook');

}