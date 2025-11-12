<?php
/*
Plugin Name: Slickplan Importer
Plugin URI: https://wordpress.org/extend/plugins/slickplan-importer/
Description: Quickly import your <a href="https://slickplan.com" target="_blank">Slickplan</a> project into your WordPress site. To use go to the <a href="import.php">Tools -> Import</a> screen and select Slickplan.
Author: Slickplan.com <info@slickplan.com>
Author URI: https://slickplan.com/
Version: 2.5.3
License: GPL-3.0 - https://www.gnu.org/licenses/gpl-3.0.html
*/

function_exists('ob_start') and ob_start();
function_exists('set_time_limit') and set_time_limit(600);

require_once ABSPATH . 'wp-admin/includes/import.php';
require_once ABSPATH . 'wp-admin/includes/translation-install.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

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
     * Slickplan's plugin directory URL.
     *
     * @var string
     */
    define('SLICKPLAN_PLUGIN_URL', plugin_dir_url(__FILE__));

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
        public array $pages = [];
        public array $pagesIds = [];

        private int $_order = 0;
        private array $_options = [];
        private array $_summary = [];
        private array $_files = [];
        private bool $_has_unparsed_internal_links = false;
        private ?WP_oEmbed $_wp_oembed = null;
        private ?string $_temp_file = null;
        private array $_postTypes = [];
        private array $_sectionsMap = [];

        public function __construct()
        {
            parent::__construct();

            $tempDir = get_temp_dir();
	        if (is_dir($tempDir) && wp_is_writable($tempDir)) {
                $tempFile = $tempDir . SLICKPLAN_PLUGIN_OPTION . '.json';
                if (!file_exists($tempFile) || (is_file($tempFile) && wp_is_writable($tempFile))) {
                    $this->_temp_file = $tempFile;
                }
            }
        }

        public function dispatch()
        {
            // Check if WP_LOAD_IMPORTERS is present
            if (!defined('WP_LOAD_IMPORTERS')) {
                return;
            }

            $this->_displayHeader();

            if (is_wp_error($xml = $this->_getPluginData())) {
                return $xml;
            }

            $step = $_GET['step'] ?? null;
            if (
                ($step === 'options' and isset($xml['pages']))
                or ($step === 'upload' and !empty($_FILES))
            ) {
                $result = null;
                if ($step === 'options' and isset($xml['pages'])) {
                    $result = $this->_displayImportOptions();
                } else {
                    check_admin_referer('import-upload');
                    $this->handleFileUpload();
                }
                if (is_wp_error($result)) {
                    $this->_displayError($result->get_error_message());
                }
            } elseif ($step === 'mapping' and isset($xml['pages'], $xml['import_form'])) {
                $this->_options['titles'] = $xml['import_form']['titles_change'] ?? '';
                $this->_displayImportMapping();
            } elseif ($step === 'import' and isset($xml['pages'], $xml['import_form'])) {
                $this->_displayAjaxImporter();
            } elseif ($step === 'done' and isset($xml['summary'])) {
                $this->_displaySummary();
            } else {
                $this->_displayUploadForm();
            }

            $this->_displayFooter();
        }

        public function handleFileUpload()
        {
            $file = wp_import_handle_upload();
            if (isset($file['error'])) {
                $this->_displayError($file['error']);
                return;
            }

            if (isset($file['file'], $file['id']) and is_file($file['file'])) {
                $xml_content = file_get_contents($file['file']);
                wp_import_cleanup($file['id']);

                $result = $this->_parseSlickplanXml($xml_content);

                if (is_wp_error($result)) {
                    $this->_displayError($result->get_error_message());
                } else {
                    $this->setPluginData($result, $this->_getAdminUrl('options'));
                }
            }
        }

        public function getSummaryRow(array $page = [], array $form = []): string
        {
            $html = '<div style="margin: 10px 0;">{{SLICKPLAN_ACTION}} {{SLICKPLAN_TYPE}}: „<b>{{SLICKPLAN_TITLE}}</b>”&hellip;<br />';
            if (!empty($page)) {
                $html = strtr($html, [
                    '{{SLICKPLAN_ACTION}}' => ($form['overwrite'] ?? false) ? 'Overwriting' : 'Importing',
                    '{{SLICKPLAN_TITLE}}' => $page['post_title'],
                    '{{SLICKPLAN_TYPE}}' => $this->_getPostTypeName($form['type']) ?: $form['type'],
                ]);
            }
            if (isset($page['error']) and $page['error']) {
                $html .= '<span style="color: #e00"><span class="dashicons dashicons-no-alt"></span> ' . $page['error'] . '</span>';
            } elseif (isset($page['url'])) {
                if (!isset($page['url_href']) or !$page['url_href']) {
                    $page['url_href'] = $page['url'];
                }
                $html .= '<span class="dashicons dashicons-yes" style="color: #0d0"></span> '
                    . '<a href="' . esc_url($page['url_href']) . '">' . $page['url'] . '</a>';
            } elseif (empty($page) or ($page['loading'] ?? false)) {
                $html .= '<span class="dashicons dashicons-update"></span>';
            }
            if (isset($page['files']) and is_array($page['files']) and count($page['files'])) {
                $files = [];
                foreach ($page['files'] as $file) {
                    if (isset($file['url']) and $file['url']) {
                        $files[] = '<span class="dashicons dashicons-yes" style="color: #0d0"></span> <a href="'
                            . $file['url'] . '" target="_blank">' . $file['filename'] . '</a>';
                    } elseif (isset($file['error']) and $file['error']) {
                        $files[] = '<span style="color: #e00"><span class="dashicons dashicons-no-alt"></span> '
                            . $file['filename'] . ' - ' . $file['error'] . '</span>';
                    }
                }
                $html .= '<div style="border-left: 5px solid rgba(0, 0, 0, 0.05); margin-left: 5px; '
                    . 'padding: 5px 0 5px 11px;">Files:<br />' . implode('<br />', $files) . '</div>';
            }
            $html .= '<div>';
            return $html;
        }

        public function ajaxImport(array $form): array
        {
            if (is_wp_error($xml = $this->_getPluginData())) {
                return $xml;
            }

            $result = [];
            if (isset($xml['import_options'])) {
                $this->_options = $xml['import_options'];
                if (isset($xml['pages'][$form['page']]) and is_array($xml['pages'][$form['page']])) {
                    $mlid = (isset($form['mlid']) and $form['mlid'])
                        ? (int) $form['mlid']
                        : 0;
                    $page = $this->_importPage($xml, $form, $mlid);
                    if (isset($page['ID']) and $page['ID']) {
                        $page['files'] = $this->_files;
                        $result = [
                            'mlid' => $page['ID'],
                            'html' => $this->getSummaryRow($page, $form),
                        ];
                    } else {
                        $result = $page;
                    }
                }
                if (isset($form['last']) and $form['last']) {
                    $result['last'] = $form['last'];
                    $this->_checkForInternalLinks();
                    $this->setPluginData();
                } else {
                    $xml['import_options'] = $this->_options;
                    $this->setPluginData($xml);
                }
            }
            return $result;
        }

        private function _getAdminUrl(?string $step = null): string
        {
            $url = 'admin.php?import=' . SLICKPLAN_PLUGIN_ID;
            if ($step) {
                $url .= '&step=' . $step;
            }
            return get_admin_url(null, $url);
        }

        private function _getPluginData(bool $validate = false)
        {
            if ($this->_temp_file) {
                $xml = is_file($this->_temp_file)
                    ? wp_json_file_decode($this->_temp_file, ['associative' => true])
                    : null;
            } else {
                $xml = get_option(SLICKPLAN_PLUGIN_OPTION);
            }

            if ($validate && (!$xml or !$this->_isCorrectSlickplanXmlFile($xml, true))) {
                return new WP_Error(SLICKPLAN_PLUGIN_ID, 'Invalid file content.');
            }

            return is_array($xml) ? $xml : [];
        }

        public function setPluginData($data = null, $redirect = null)
        {
            if ($this->_temp_file) {
                if (is_null($data)) {
                    wp_delete_file($this->_temp_file);
                } else {
                    file_put_contents($this->_temp_file, wp_json_encode($data));
                }
            } else {
                if (is_null($data)) {
                    delete_option(SLICKPLAN_PLUGIN_OPTION);
                } else {
                    update_option(SLICKPLAN_PLUGIN_OPTION, $data);
                }
            }
            if ($redirect) {
                wp_redirect($redirect);
                exit;
            }
        }

        private function _displayImportOptions()
        {
            if (is_wp_error($xml = $this->_getPluginData(true))) {
                return $xml;
            }

            if (isset($_POST['slickplan_importer']) and is_array($_POST['slickplan_importer'])) {
                if (isset($xml['mapping'])) {
                    unset($xml['mapping']);
                }
                $xml['import_form'] = array_merge($xml['import_form'] ?? [], $_POST['slickplan_importer']);
                $this->setPluginData($xml, $this->_getAdminUrl($_POST['slickplan_importer']['page_type'] === '@' ? 'mapping' : 'import'));
            }

            $allLanguages = $this->_getLanguages();
            $content_languages = [];
            $no_of_files = 0;
            $filesize_total = [];
            if (isset($xml['pages']) and is_array($xml['pages'])) {
                foreach ($xml['pages'] as $page) {
                    if (isset($page['contents'][0]['@attributes']['lang'])) {
                        foreach ($page['contents'] as $row) {
                            if (isset($row['@attributes']['lang'], $allLanguages[$row['@attributes']['lang']]['name']) and $row['@attributes']['lang']) {
                                $content_languages[$row['@attributes']['lang']] = $allLanguages[$row['@attributes']['lang']]['name'];
                            }
                            if (isset($row['body']) && is_array($row['body'])) {
                                $filesData = $this->_sumFiles($row['body']);
                                $no_of_files += $filesData[0];
                                $filesize_total = array_merge($filesize_total, $filesData[1]);
                            }
                        }
                    } elseif (isset($page['contents']['body']) and is_array($page['contents']['body'])) {
                        $filesData = $this->_sumFiles($page['contents']['body']);
                        $no_of_files += $filesData[0];
                        $filesize_total = array_merge($filesize_total, $filesData[1]);
                    }
                }
            }

            $filesize_total = (int)array_sum($filesize_total);

            require_once SLICKPLAN_PLUGIN_PATH . 'views/options.php';
        }

        private function _displayImportMapping()
        {
            global $wpdb;

            if (is_wp_error($xml = $this->_getPluginData(true))) {
                return $xml;
            }

            if (isset($_POST['slickplan_importer']) and is_array($_POST['slickplan_importer']) and wp_verify_nonce($_POST['_wpnonce'])) {
                $mapping = json_decode(stripslashes($_POST['slickplan_importer']['json']), true);
                $xml['mapping'] = [];
                foreach ($mapping as $mapped) {
                    $xml['mapping'][$mapped['cell']] = $mapped;
                    unset($xml['mapping'][$mapped['cell']]['cell']);
                }
                unset($_POST['slickplan_importer']['json'], $mapping);
                $xml['import_form'] = array_merge($xml['import_form'] ?? [], $_POST['slickplan_importer']);

                $this->setPluginData($xml, $this->_getAdminUrl('import'));
            }

            $pageTypes = array_map(function ($item) {
                return $item['key'];
            }, $this->getAvailablePageTypes());

    		$existingPosts = $wpdb->get_results("
                SELECT `postmeta`.`post_id`, `postmeta`.`meta_value`, `posts`.`post_type`
                FROM `{$wpdb->postmeta}` AS `postmeta`
                JOIN `{$wpdb->posts}` AS `posts` ON `posts`.`ID` = `postmeta`.`post_id`
                WHERE `postmeta`.`meta_key` = '_slickplan_id'
                ORDER BY `postmeta`.`meta_id` ASC
            ");
            $mappingJson = [];
            foreach ($existingPosts as $post) {
                if (isset($xml['pages'][$post->meta_value])) {
                    $mappingJson[$post->meta_value] = [
                        'cell' => $post->meta_value,
                        'type' => 'overwrite',
                        'value' => $post->post_type,
                        'id' => $post->post_id,
                    ];
                }
            }

            require_once SLICKPLAN_PLUGIN_PATH . 'views/mapping.php';
        }

        private function _displayAjaxImporter()
        {
            if (is_wp_error($xml = $this->_getPluginData())) {
                return $xml;
            }

            $this->_doImport($xml);

            $postType = $xml['import_form']['page_type'] ?? 'page';

            $this->pages = [];

            if ($postType === '@') {
                $this->pagesIds = [];
                foreach (['home', '1', 'main', 'util', 'foot'] as $level) {
                    if (!empty($xml['sitemap'][$level])) {
                        $this->_getAjaxImporterBranchDataWithMapping($xml, $xml['sitemap'][$level]);
                    }
                }
            } else {
                $parent = $this->_isHierarchicalType($postType) ? null : false;
                foreach (['home', '1', 'main', 'util', 'foot'] as $level) {
                    if (!empty($xml['sitemap'][$level])) {
                        $this->_getAjaxImporterBranchData($postType, $xml['sitemap'][$level], $parent);
                    }
                }
            }

            require_once SLICKPLAN_PLUGIN_PATH . 'views/import.php';
        }

        private function _getAjaxImporterBranchData($postType, $items, $parent = null)
        {
            if (!array_key_exists($postType, $this->pages)) {
                $this->pages[$postType] = [];
            }
            foreach ($items as $item) {
                $this->pages[$postType][] = [
                    'id' => $item['id'],
                    'parent' => $parent ?: null,
                    'title' => $this->_getFormattedTitle($item),
                ];
                if (!empty($item['childs'])) {
                    $this->_getAjaxImporterBranchData($postType, $item['childs'], $parent === false ? false : $item['id']);
                }
            }
        }

        private function _getAjaxImporterBranchDataWithMapping($xml, $items)
        {
            foreach ($items as $item) {
                $pageMap = $xml['mapping'][$item['id']] ?? ['type' => 'new', 'value' => 'page'];
                if ($pageMap['type'] !== 'exclude') {
                    $postType = $pageMap['value'] ?? 'page';
                    if (!array_key_exists($postType, $this->pages)) {
                        $this->pages[$postType] = [];
                        $this->pagesIds[$postType] = [];
                    }
                    $page = [
                        'id' => $item['id'],
                        'title' => $this->_getFormattedTitle($item),
                    ];
                    if ($pageMap['type'] === 'overwrite') {
                        $page['overwrite'] = $pageMap['id'];
                    } elseif ($this->_isHierarchicalType($postType)) {
                        $currentId = $item['id'];
                        while ($parent = $xml['pages'][$currentId]['parent'] ?? null) {
                            if (in_array($parent, $this->pagesIds[$postType], true)) {
                                $page['parent'] = $parent;
                                break;
                            }
                            $currentId = $parent;
                        }
                    }
                    $this->pages[$postType][] = $page;
                    $this->pagesIds[$postType][] = $page['id'];
                }
                if (!empty($item['childs'])) {
                    $this->_getAjaxImporterBranchDataWithMapping($xml, $item['childs']);
                }
            }
        }

        private function _doImport(array $xml)
        {
            $form = $xml['import_form'];

            if (
                isset($form['settings_language'], $xml['settings']['language'])
                and $form['settings_language']
            ) {
                $this->_changeLanguage($xml['settings']['language']);
            }

            if (isset($form['settings_title']) and $form['settings_title']) {
                $title = (isset($xml['settings']['title']) and $xml['settings']['title'])
                    ? $xml['settings']['title']
                    : $xml['title'];
                update_option('blogname', $title);
            }

            if (isset($form['settings_tagline']) and $form['settings_tagline']) {
                update_option('blogdescription', $xml['settings']['tagline']);
            }

            $this->_options = [
                'titles' => $form['titles_change'] ?? '',
                'content' => $form['content'] ?? '',
                'content_lang' => $form['content_lang'] ?? '',
                'content_files' => (($form['content'] ?? '') === 'contents' && ($form['content_files'] ?? '')),
                'create_menu' => (isset($form['create_menu']) and $form['create_menu']),
                'post_type' => $form['page_type'] ?? 'page',
                'users' => $form['users_map'] ?? [],
                'internal_links' => [],
                'imported_pages' => [],
            ];

            if ($this->_options['create_menu'] && $this->_isHierarchicalType($this->_options['post_type']) && '@' !== $this->_options['post_type']) {
                $menu_name = $menu_name_orig = (isset($form['menu_name']) and $form['menu_name'])
                    ? $form['menu_name']
                    : 'Slickplan';
                $i = 0;
                while (get_term_by('name', $menu_name, 'nav_menu')) {
                    $menu_name = $menu_name_orig . ' ' . (++$i);
                }
                $this->_options['create_menu'] = wp_create_nav_menu($menu_name);
            } else {
                $this->_options['create_menu'] = false;
            }

            if (isset($form['content_lang']) && $form['content_lang']) {
                foreach ($xml['pages'] as $pageId => $pageData) {
                    if (
                        isset($xml['pages'][$pageId]['contents'])
                        && is_array($xml['pages'][$pageId]['contents'])
                        && array_keys($xml['pages'][$pageId]['contents']) === range(0, count($xml['pages'][$pageId]['contents']) - 1)
                    ) {
                        $xml['pages'][$pageId]['contents'] = array_reduce($xml['pages'][$pageId]['contents'], function ($result, $item) use ($form) {
                            if ($item['@attributes']['lang'] === $form['content_lang']) {
                                return $item;
                            }
                            return $result;
                        }, []);
                    }
                }
            }

            $xml['import_options'] = $this->_options;
            $this->setPluginData($xml);
        }

        private function _importPage(array $xml, array $form, int $parent_id = 0): array
        {
            $pageType = $form['type'];
            $cellId = $form['page'];
            $data = $xml['pages'][$cellId];

            $isHierarchical = $this->_isHierarchicalType($pageType);

            $this->_order += 10;

            if ($parent_id && !$isHierarchical) {
                $parent_id = 0;
            }

            $page = [
                'post_status' => 'publish',
                'post_content' => '',
                'post_type' => $pageType,
                'post_title' => $this->_getFormattedTitle($data),
                'menu_order' => $this->_order,
                'post_parent' => $parent_id,
            ];

            if (isset($data['contents']) && is_array($data['contents']) && array_is_list($data['contents'])) {
                $lang = $xml['import_form']['content_lang'] ?? 'en_US';
                $contents = [];
                foreach ($data['contents'] as $contentsItem) {
                    if (($contentsItem['@attributes']['lang'] ?? 'en_US') === $lang) {
                        $contents = $contentsItem;
                        break;
                    }
                }
                $data['contents'] = $contents;
            }

            // Set post content
            if (($this->_options['content'] ?? null) === 'desc') {
                if (isset($data['desc']) and !empty($data['desc'])) {
                    $page['post_content'] = $this->_getFormattedContent($data['desc']);
                }
            } elseif (($this->_options['content'] ?? null) === 'contents') {
                if (
                    isset($data['contents']['body'])
                    and is_array($data['contents']['body'])
                    and count($data['contents']['body'])
                ) {
                    $page['post_content'] = $this->_getFormattedContent($data['contents']['body']);
                }
            }

            // Set url slug
            if (isset($data['contents']['url_slug']) and $data['contents']['url_slug']) {
                $page['post_name'] = $this->_metaSlug($data['contents']['url_slug'], $page['post_title']);
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

            // Check if page has internal links, we need to replace them later
            $this->_has_unparsed_internal_links = false;
            if ($page['post_content']) {
                $updated_content = $this->_parseInternalLinks($page['post_content']);
                if ($updated_content) {
                    $page['post_content'] = $updated_content;
                }
            }

            if ($form['overwrite'] ?? null) {
                $page['ID'] = $form['overwrite'];
                $page_id = wp_update_post($page, true);
            } else {
                $page_id = wp_insert_post($page, true);
            }
            if (is_wp_error($page_id)) {
                $page['ID'] = false;
                $page['error'] = $page_id->get_error_message();
                $this->_summary[] = $this->getSummaryRow($page, ['type' => $pageType]);
            } else {
                $page['ID'] = (int) $page_id;

                update_post_meta($page['ID'], '_slickplan_id', $cellId);

                // Add page to nav menu
                if ($this->_options['create_menu'] ?? null) {
                    $menu_parent = 0;
                    if ($page['post_parent']) {
                        $menu_items = (array) wp_get_nav_menu_items($this->_options['create_menu'], [
                            'post_status' => 'publish,draft',
                        ]);
                        foreach ($menu_items as $menu_item) {
                            if ($page['post_parent'] === intval($menu_item->object_id)) {
                                $menu_parent = (int) $menu_item->ID;
                                break;
                            }
                        }
                    }
                    wp_update_nav_menu_item($this->_options['create_menu'], 0, [
                        'menu-item-title' => $page['post_title'],
                        'menu-item-object' => 'page',
                        'menu-item-object-id' => $page['ID'],
                        'menu-item-type' => 'post_type',
                        'menu-item-status' => 'publish',
                        'menu-item-parent-id' => $menu_parent,
                    ]);
                }

                if (isset($data['contents']) && is_array($data['contents'])) {
                    // SEO by Yoast integration
                    $this->_importPageYoast($data['contents'], $page['ID']);
                    // All In One SEO Pack integration
                    $this->_importPageAioSeo($data['contents'], $page['ID']);
                }

                if (
                    ($featuredImage = $data['contents']['meta_featured_image'] ?? null)
                    && filter_var($featuredImage['url'] ?? '', FILTER_VALIDATE_URL)
                ) {
                    $file = $this->_addMedia($featuredImage['url'], [
                        'alt' => $featuredImage['alt'] ?? '',
                        'title' => $featuredImage['title'] ?? '',
                        'file_name' => $featuredImage['file_name'] ?? '',
                    ], true);
                    if ($file['id'] ?? null) {
                        set_post_thumbnail($page['ID'], $file['id']);
                    }
                }

                $page['url'] = get_permalink($page['ID']);

                // Save page permalink
                if (isset($data['id'])) {
                    $this->_options['imported_pages'][$data['id']] = $page['url'];
                }

                // Check if page has unparsed internal links, we need to replace them later
                if ($this->_has_unparsed_internal_links) {
                    $this->_options['internal_links'][] = $page['ID'];
                }

                $page['url'] = '/' . ltrim(str_replace(home_url('/'), '', $page['url']), '/');
                $page['url_href'] = get_admin_url(null, 'post.php?post=' . $page['ID'] . '&action=edit');
                $this->_summary[] = $this->getSummaryRow($page, ['type' => $pageType]);
            }
            return $page;
        }

        private function _importPageYoast($data, $pageId)
        {
            if (!class_exists('WPSEO_Meta') or !method_exists('WPSEO_Meta', 'set_value')) {
                return;
            }

            if ($seoValue = $this->_metaTitle($data['meta_title'] ?? '')) {
                WPSEO_Meta::set_value('title', $seoValue, $pageId);
            }
            if ($seoValue = $data['meta_description'] ?? '') {
                WPSEO_Meta::set_value('metadesc', $seoValue, $pageId);
            }
            if ($seoValue = $data['meta_focus_keyword'] ?? '') {
                WPSEO_Meta::set_value('focuskw', $seoValue, $pageId);
            }
            if ($seoValue = $data['canonical_url'] ?? '') {
                WPSEO_Meta::set_value('canonical', WPSEO_Utils::sanitize_url($seoValue), $pageId);
            }
            if (!empty($seoValue = array_filter(explode(',', $data['meta_robots'] ?? '')))) {
                if (in_array('noindex', $seoValue, true)) {
                    WPSEO_Meta::set_value('meta-robots-noindex', '1', $pageId);
                }
                if (in_array('nofollow', $seoValue, true)) {
                    WPSEO_Meta::set_value('meta-robots-nofollow', '1', $pageId);
                }
                $seoValue = array_filter($seoValue, function ($item) {
                    return $item !== 'noindex' && $item !== 'nofollow';
                });
                if (!empty($seoValue)) {
                    WPSEO_Meta::set_value('meta-robots-adv', implode(',', $seoValue), $pageId);
                }
            }
        }

        private function _importPageAioSeo($data, $pageId)
        {
            if (
                !defined('AIOSEO_VERSION')
                or !version_compare(AIOSEO_VERSION, '4.0.0', '>=')
                or !method_exists('\\AIOSEO\\Plugin\\Common\\Models\\Post', 'savePost')
            ) {
                return;
            }

            $seoData = [];
            if ($seoValue = $this->_metaTitle($data['meta_title'] ?? '', 'aio')) {
                $seoData['title'] = $seoValue;
            }
            if ($seoValue = $data['meta_description'] ?? '') {
                $seoData['description'] = $seoValue;
            }
            if (!empty($seoValue = array_filter(array_map('trim', explode(',', $data['meta_focus_keyword'] ?? ''))))) {
                $seoData['keywords'] = wp_json_encode($seoValue);
            }
            if ($seoValue = $data['canonical_url'] ?? '') {
                $seoData['canonicalUrl'] = $seoValue;
            }
            if (!empty($seoValue = array_filter(explode(',', $data['meta_robots'] ?? '')))) {
                $seoData['default'] = false;
                $seoData['noindex'] = in_array('noindex', $seoValue, true);
                $seoData['nofollow'] = in_array('nofollow', $seoValue, true);
                $seoData['noarchive'] = in_array('noarchive', $seoValue, true);
                $seoData['notranslate'] = in_array('notranslate', $seoValue, true);
                $seoData['noimageindex'] = in_array('noimageindex', $seoValue, true);
                $seoData['nosnippet'] = in_array('nosnippet', $seoValue, true);
                $seoData['noodp'] = in_array('noodp', $seoValue, true);
            }
            if (!empty($seoData)) {
                \AIOSEO\Plugin\Common\Models\Post::savePost($pageId, $seoData);
            }
        }

        private function _parseInternalLinks($content, bool $force_parse = false)
        {
            preg_match_all('/href="slickplan:([a-z0-9]+)"/isU', $content, $internal_links);
            if (isset($internal_links[1]) and is_array($internal_links[1]) and count($internal_links[1])) {
                $internal_links = array_unique($internal_links[1]);
                $links_replace = [];
                foreach ($internal_links as $cell_id) {
                    if (
                        isset($this->_options['imported_pages'][$cell_id])
                        and $this->_options['imported_pages'][$cell_id]
                    ) {
                        $links_replace['="slickplan:' . $cell_id . '"'] = '="'
                            . esc_attr($this->_options['imported_pages'][$cell_id]) . '"';
                    } elseif ($force_parse) {
                        $links_replace['="slickplan:' . $cell_id . '"'] = '="#"';
                    } else {
                        $this->_has_unparsed_internal_links = true;
                    }
                }
                if (count($links_replace)) {
                    return strtr($content, $links_replace);
                }
            }
            return false;
        }

        private function _checkForInternalLinks(): void
        {
            if (isset($this->_options['internal_links']) and is_array($this->_options['internal_links'])) {
                foreach ($this->_options['internal_links'] as $page_id) {
                    $page = get_post($page_id);
                    if (isset($page->post_content)) {
                        $page_content = $this->_parseInternalLinks($page->post_content, true);
                        if ($page_content) {
                            wp_update_post([
                                'ID' => $page_id,
                                'post_content' => $page_content,
                            ]);
                        }
                    }
                }
            }
        }

        private function _getPrependAppend(array $element): array
        {
            $prepend = '';
            $append = '';
            if (isset($element['options']['tag']) and $element['options']['tag']) {
                if ($element['options']['tag'] === 'html') {
                    $prepend = $element['options']['tag_html_before'] ?? '';
                    $append = $element['options']['tag_html_after'] ?? '';
                } else {
                    if (function_exists('mb_strtolower')) {
                        $tag = mb_strtolower($element['options']['tag']);
                    } else {
                        $tag = strtolower($element['options']['tag']);
                    }
                    $tag = preg_replace('/[^a-z]+/', '', $tag);
                    $prepend = '<' . $tag;
                    if (isset($element['options']['tag_id']) and $element['options']['tag_id']) {
                        $prepend .= ' id="' . esc_attr($element['options']['tag_id']) . '"';
                    }
                    if (isset($element['options']['tag_class']) and $element['options']['tag_class']) {
                        $prepend .= ' class="' . esc_attr($element['options']['tag_class']) . '"';
                    }
                    $prepend .= '>';
                    $append = '</' . $tag . '>';
                }
            }
            return [$prepend, $append];
        }

        private function _getFormattedContent($contents): string
        {
            if (!is_array($contents)) {
                return $contents;
            }
            $post_content = [];
            foreach ($contents as $element) {
                if (!isset($element['content']) or !isset($element['type'])) {
                    continue;
                }
                if ($this->shouldUseGutenberg()) {
                    $post_content[] = $this->_getGutenbergBlock($element);
                    continue;
                }
                $html = '';
                switch ($element['type']) {
                    case 'wysiwyg':
                    case 'code':
                        $html .= $element['content'];
                        break;
                    case 'text':
                        $html .= htmlspecialchars($element['content']);
                        break;
                    case 'image':
                    case 'video':
                    case 'file':
                        if ($element['type'] === 'image') {
                            foreach ($this->_getMediaElementArray($element) as $item) {
                                if (isset($item['type'], $item['url'])) {
                                    $attrs = [
                                        'alt' => $item['alt'] ?? '',
                                        'title' => $item['title'] ?? '',
                                        'file_name' => $item['file_name'] ?? '',
                                    ];
                                    if ($item['type'] === 'library') {
                                        $item = $this->_addMedia($item['url'], $attrs);
                                    }
                                    if ($item and !is_wp_error($item) and isset($item['url'])) {
                                        $html .= '<img src="' . esc_url($item['url'])
                                            .'" alt="' . esc_attr($attrs['alt'])
                                            .'" title="' . esc_attr($attrs['title'])
                                            .'" />';
                                    }
                                }
                            }
                        } else {
                            foreach ($this->_getMediaElementArray($element) as $item) {
                                if (isset($item['type'], $item['url'])) {
                                    $attrs = [
                                        'description' => $item['description'] ?? '',
                                        'file_name' => $item['file_name'] ?? '',
                                    ];
                                    if ($item['type'] === 'library') {
                                        $item = $this->_addMedia($item['url'], $attrs);
                                    }
                                    if ($item and !is_wp_error($item) and isset($item['url'])) {
                                        $name = $attrs['description'] ?: ($attrs['file_name'] ?: basename($item['url']));
                                        $html .= '<a href="' . esc_url($item['url'])
                                            .'" title="' . esc_attr($attrs['description'])
                                            .'">' . $name . '</a>';
                                    }
                                }
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
                                $tag = (isset($element['content']['thead']) and $element['content']['thead']) ? 'th' : 'td';
                                foreach ($element['content']['data'] as $row) {
                                    $html .= '<tr>';
                                    foreach ($row as $cell) {
                                        $html .= '<'.$tag.'>'.$cell.'</'.$tag.'>';
                                    }
                                    $html .= '</tr>';
                                    $tag = 'td';
                                }
                                $html .= '<table>';
                            }
                        }
                        break;
                }
                if ($html) {
                    list($prepend, $append) = $this->_getPrependAppend($element);
                    $post_content[] = $prepend . $html . $append;
                }
            }
            $post_content = array_map('trim', $post_content);
            $post_content = array_filter($post_content);
            return implode("\n\n", $post_content);
        }

        private function _getMediaElementArray(array $element): array
        {
            $items = $element['content']['contentelement'] ?? $element['content'] ?? null;

            return isset($items['type'])
                ? [$items]
                : (isset($items[0]['type']) ? $items : []);
        }

        private function _getGutenbergBlock(array $element): string
        {
            list($prepend, $append) = $this->_getPrependAppend($element);
            switch ($element['type']) {
                case 'wysiwyg':
                    return $prepend . "\n" . $element['content'] . "\n" . $append;
                case 'code':
                    return get_comment_delimited_block_content(
                        'core/code',
                        null,
                        '<pre class="wp-block-code"><code>' . esc_html($element['content']) . '</code></pre>'
                    );
                case 'text':
                    if ($prepend or $append) {
                        return $prepend . esc_html($element['content']) . $append;
                    }
                    return get_comment_delimited_block_content(
                        'core/preformatted',
                        null,
                        '<pre class="wp-block-preformatted">' . esc_html($element['content']) . '</pre>'
                    );
                case 'image':
                    $html = [];
                    foreach ($this->_getMediaElementArray($element) as $item) {
                        if (isset($item['type'], $item['url'])) {
                            $attrs = [
                                'alt' => $item['alt'] ?? '',
                                'title' => $item['title'] ?? '',
                                'file_name' => $item['file_name'] ?? '',
                            ];
                            if ($item['type'] === 'library') {
                                $attachment = $this->_addMedia($item['url'], $attrs);
                                if ($attachment and !is_wp_error($attachment) and isset($attachment['id'], $attachment['url'])) {
                                    $img = '<img src="' . esc_url($attachment['url'])
                                        .'" alt="' . esc_attr($attrs['alt'])
                                        .'" title="' . esc_attr($attrs['title'])
                                        .'" class="wp-image-' . esc_attr($attachment['id']) . '"'
                                        .' />';
                                    $html[] = get_comment_delimited_block_content(
                                        'core/image',
                                        [
                                            'id' => $attachment['id'],
                                            'sizeSlug' => 'large',
                                            'linkDestination' => 'none',
                                            'className' => 'is-style-default',
                                        ],
                                        '<figure class="wp-block-image size-large is-style-default">' . $img . '</figure>'
                                    );
                                }
                            } else {
                                $img = '<img src="' . esc_url($item['url'])
                                    .'" alt="' . esc_attr($attrs['alt'])
                                    .'" title="' . esc_attr($attrs['title'])
                                    .' />';
                                $html[] = get_comment_delimited_block_content(
                                    'core/image',
                                    [
                                        'sizeSlug' => 'large',
                                    ],
                                    '<figure class="wp-block-image size-large">' . $img . '</figure>'
                                );
                            }
                        }
                    }
                    return implode("\n\n", $html);
                case 'file':
                case 'video':
                    $html = [];
                    foreach ($this->_getMediaElementArray($element) as $item) {
                        if (isset($item['type'], $item['url'])) {
                            $attrs = [
                                'description' => $item['description'] ?? '',
                                'file_name' => $item['file_name'] ?? '',
                            ];
                            if ($item['type'] === 'library') {
                                $attachment = $this->_addMedia($item['url'], $attrs);
                                if ($attachment and !is_wp_error($attachment) and isset($attachment['id'], $attachment['url'])) {
                                    if ($element['type'] === 'file') {
                                        $name = $attrs['description'] ?: ($attrs['file_name'] ?: basename($item['url']));
                                        $html[] = get_comment_delimited_block_content(
                                            'core/file',
                                            [
                                                'id' => $attachment['id'],
                                                'href' => $attachment['url'],
                                            ],
                                            '<div class="wp-block-file"><a href="' . esc_url($attachment['url']) . '">' . $name . '</a>'
                                                . '<a href="' . esc_url($attachment['url']) . '" class="wp-block-file__button" download>Download</a>'
                                                . '</div>'
                                        );
                                    } else {
                                        $html[] = get_comment_delimited_block_content(
                                            'core/video',
                                            [
                                                'id' => $attachment['id'],
                                            ],
                                            '<figure class="wp-block-video"><video controls src="'.esc_url($attachment['url']).'"></video></figure>'
                                        );
                                    }
                                }
                            } else {
                                $this->_wp_oembed = $this->_wp_oembed ?: new WP_oEmbed();
                                if (
                                    ($provider = $this->_wp_oembed->get_data($item['url']))
                                    && $provider->type === 'video'
                                ) {
                                    $providerSlug = sanitize_title($provider->provider_name);
                                    $html[] = get_comment_delimited_block_content(
                                        'core/embed',
                                        [
                                            'url' => $item['url'],
                                            'type' => $provider->type,
                                            'providerNameSlug' => $providerSlug,
                                            'responsive' => true,
                                            'className' => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
                                        ],
                                        "<figure class=\"wp-block-embed is-type-{$provider->type} is-provider-{$providerSlug} wp-block-embed-{$providerSlug} wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\">"
                                            . esc_html($item['url']) . '</div></figure>'
                                    );
                                } else {
                                    $name = $attrs['description'] ?: ($attrs['file_name'] ?: basename($item['url']));
                                    if ($element['type'] === 'file') {
                                        $html[] = '<div class="wp-block-file"><a href="'.esc_url($item['url'])
                                            .'" title="'.esc_attr($attrs['description'])
                                            .'" download>'.$name.'</a></div>';
                                    } else {
                                        $html[] = '<div><a href="'.esc_url($item['url']).'">'.$name.'</a></div>';
                                    }
                                }
                            }
                        }
                    }
                    return implode("\n\n", $html);
                case 'table':
                    if (isset($element['content']['data'])) {
                        if (!is_array($element['content']['data'])) {
                            $element['content']['data'] = @json_decode($element['content']['data'], true);
                        }
                        if (is_array($element['content']['data']) and count($element['content']['data'])) {
                            $html = '';
                            $tag = (isset($element['content']['thead']) and $element['content']['thead']) ? 'th' : 'td';
                            foreach ($element['content']['data'] as $row) {
                                $html .= '<tr>';
                                foreach ($row as $cell) {
                                    $html .= "<{$tag}>" . esc_html($cell) . "</{$tag}>";
                                }
                                $html .= '</tr>';
                                $tag = 'td';
                            }
                            return get_comment_delimited_block_content(
                                'core/table',
                                null,
                                '<figure class="wp-block-table"><table><tbody>' . $html . '</tbody></table></figure>'
                            );
                        }
                    }
                    break;
            }
            return '';
        }

        private function _getFormattedTitle(array $data): string
        {
            $title = ($data['contents']['page_title'] ?? null)
                ? $data['contents']['page_title']
                : ($data['text'] ?? $data['title'] ?? '');
            if (($this->_options['titles'] ?? null) === 'ucfirst') {
                if (function_exists('mb_strtolower')) {
                    $title = mb_strtolower($title);
                    $title = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
                } else {
                    $title = ucfirst(strtolower($title));
                }
            } elseif (($this->_options['titles'] ?? null) === 'ucwords') {
                if (function_exists('mb_convert_case')) {
                    $title = mb_convert_case($title, MB_CASE_TITLE);
                } else {
                    $title = ucwords(strtolower($title));
                }
            }
            return $title;
        }

        private function _addMedia($url, array $attrs = [], bool $forceAdd = false)
        {
            if (!($this->_options['content_files'] ?? null) && !$forceAdd) {
                return false;
            }

            $tmp = download_url($url);
            $file_array = [
                'name' => $attrs['file_name'] ?? basename($url),
                'tmp_name' => $tmp,
            ];
            $file_array['filename'] = $file_array['name'];

            if (is_wp_error($tmp)) {
                @unlink($file_array['tmp_name']);
                $file_array['error'] = $tmp->get_error_message();
                $this->_files[] = $file_array;
                return $tmp;
            }

            $options = [];
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
                $attrs['description'] ?? '',
                $options
            );

            if (is_wp_error($id)) {
                @unlink($file_array['tmp_name']);
                $file_array['error'] = $id->get_error_message();
                $this->_files[] = $file_array;
                return $id;
            }

            $file_array['url'] = wp_get_attachment_url($id);
            $file_array['id'] = (int) $id;
            $this->_files[] = $file_array;

            return $file_array;
        }

        private function _displayError($errors)
        {
            if (is_array($errors)) {
                $errors = implode("\n\n", $errors);
            }
            $errors = trim($errors);
            if ($errors) {
                echo '<div class="error">', wpautop($errors), '</div>';
            }
            $this->_displayUploadForm((bool) $errors);
        }

        private function _displayUploadForm(bool $hide_info = false)
        {
            $this->setPluginData();
            echo '<div class="narrow">';
            if (!$hide_info) {
                echo '<div class="notice notice-warning notice-alt">',
                    '<p>To get started, please export your website planning project into the <a href="https://slickplan.com" target="_blank">Slickplan</a> XML file format.<br>Once you have done so, please select the exported file and proceed with uploading it here:</p>',
                    '</div>';
            }
            ob_start();
            wp_import_upload_form($this->_getAdminUrl('upload'));
            $form_html = ob_get_clean();
            $form_html = preg_replace('/<label for="upload">.+<\/label>/sU', '', $form_html);
            $form_html = str_replace('<input type="file"', '<br><br><input type="file"', $form_html);
            echo $form_html;
            echo '</div>';
        }

        /**
         * Display import summary.
         */
        private function _displaySummary()
        {
            if (is_wp_error($xml = $this->_getPluginData())) {
                return $xml;
            }

            if (isset($xml['summary']) and $xml['summary']) {
                require_once SLICKPLAN_PLUGIN_PATH . 'views/import.php';
            }

            $this->setPluginData();
        }

        private function _displayHeader()
        {
            echo '<div class="wrap"> <h2>Slickplan Importer</h2>';

            if (!class_exists('DomDocument') or version_compare(PHP_VERSION, '7.1.0', '<')) {
                $this->_displayError('Sorry! This importer requires PHP 7.1+ and DOM extension.');
            }
        }

        private function _displayFooter()
        {
            echo '</div>';
        }

        public function displayDropdown(string $name, ?string $label = null, array $options = [], $selected = null): string
        {
            $html = '';
            $attrs = [];
            if ($name) {
                $attrs['name'] = 'slickplan_importer[' . $name . ']';
                $attrs['id'] = sanitize_title('slickplan-importer-form-' . $name);
            }
            if ($label) {
                $html .= '<label';
                if ($attr['id'] ?? null) {
                    $html .= ' for="' . $attrs['id'] . '"';
                }
                $html .= '>' . $label . ':</label> ';
            }
            $html .= '<select';
            foreach ($attrs as $key => $value) {
                $html .= ' ' . $key . '="' . esc_attr($value) . '"';
            }
            $html .= '>';
            foreach ($options as $value => $name) {
                if (is_null($name)) {
                    $html .= '<option disabled>───</option>';
                    continue;
                }
                $value = $name['key'] ?? $name['name'] ?? $value;
                $name = $name['value'] ?? $name['label'] ?? $name;
                $html .= '<option value="' . esc_attr($value) . '"'
                    . ($selected === $value ? ' selected' : '')
                    . '>' . esc_attr($name) . '</option>';
            }
            $html .= '</select>';
            return $html;
        }

        public function displayCheckbox(
            $name,
            string $label = '',
            bool $checked = false,
            $description = '',
            $value = '1',
            string $type = 'checkbox',
            string $class = '',
            string $append = ''
        ): string {
            $id = sanitize_title('slickplan-importer-form-' . $name . '-' . $value);
            $attrs = [
                'type' => $type,
                'name' => 'slickplan_importer[' . $name . ']',
                'value' => $value,
                'id' => $id,
            ];
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
            $html .= '> ' . $label . '</label>';
            if (!empty($append)) {
                $html .= $append;
            }
            if ($description) {
                $html .= '<br><span class="description">(' . $description . ')</span>';
            }
            return $html;
        }

        public function displayRadio(
            $name,
            string $label = '',
            $value = '',
            $description = '',
            bool $checked = false,
            string $class = ''
        ): string {
            return $this->displayCheckbox($name, $label, $checked, $description, $value, 'radio', $class);
        }

        public function displayUsersDropdown($name, $selected = '')
        {
            wp_dropdown_users([
                'selected' => $selected,
                'name' => 'slickplan_importer[' . esc_attr($name) . ']',
            ]);
        }

        public function displayPageTypesDropdown($name = null, $includeManually = false, $label = 'Import as', $singularName = false, $value = null)
        {
            $options = $this->getAvailablePageTypes($singularName);
            if ($includeManually) {
                $options[] = null;
                $options[] = ['key' => '@', 'value' => 'Select per page (next step)'];
            }
            echo $this->displayDropdown($name, $label, $options, $value ?: 'page');
        }

        public function getAvailablePageTypes($singularName = false)
        {
            $options = $this->getPostTypes();
            usort($options, function ($a, $b) use ($singularName) {
                $a = $singularName ? $a->labels->singular_name : $a->label;
                $b = $singularName ? $b->labels->singular_name : $b->label;
                return 0 > strcmp($a, $b) ? -1 : 1;
            });
            return array_map(function ($item) use ($singularName) {
                $label = $singularName ? $item->labels->singular_name : $item->label;
                if (!$item->hierarchical) {
                    $label .= ' (non-hierarchical)';
                }
                return ['key' => $item->name, 'value' => $label];
            }, $options);
        }

        public function renderPagesTable($pages, $parents = [])
        {
            foreach ($pages as $page) {
                $childs = (!empty($page['childs']) && is_array($page['childs'])) ? $page['childs'] : null;
                ?>
                <tr data-id="<?php echo esc_attr($page['id']); ?>" <?php if (count($parents)) echo 'class="' . esc_attr(implode(' ', $parents)) . '"'; ?>>
                    <th scope="row" class="check-column">
                        <input type="checkbox" id="<?php echo esc_attr($page['id']); ?>">
                    </th>
                    <td class="column-title column-primary">
                        <strong style="display: inline-block; font-weight: 400;">
                            <label for="<?php echo esc_attr($page['id']); ?>"><?php echo str_repeat('— ', count($parents)); ?> <?php echo esc_html($this->_getFormattedTitle($page)); ?></label>
                        </strong>
                        <?php if ($childs) { ?>
                            <a href="#" class="collapse-page" title="Expand/Collapse childs"><span class="dashicons dashicons-arrow-up"></span></a>
                            <a href="#" class="select-childs-page" title="Select visible childs"><span class="dashicons dashicons-insert-before"></span></a>
                        <?php } ?>
                    </td>
                    <td></td>
                </tr>
                <?php
                if ($childs) {
                    $this->renderPagesTable($childs, array_merge($parents, [$page['id']]));
                }
            }
        }

        private function _parseSlickplanXml(string $input_xml)
        {
            $input_xml = trim($input_xml);
            if (str_starts_with($input_xml, '<?xml')) {
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
                        if (isset($array['section']['options'])) {
                            if (!isset($array['section']['options']['id']) and isset($array['section']['@attributes']['id'])) {
                                $array['section']['options']['id'] = $array['section']['@attributes']['id'];
                            }
                            $array['section'] = [$array['section']];
                        }
                        foreach ($array['section'] as $section_key => $section) {
                            if (isset($section['cells']['cell']['text'])) {
                                $array['section'][$section_key]['cells']['cell'] = [$section['cells']['cell']];
                            }
                        }
                        $array['sitemap'] = $this->_getMultidimensionalArrayHelper($array);
                        $array['users'] = [];
                        $array['pages'] = [];
                        foreach ($array['section'] as $section_key => $section) {
                            $isMainSection = $section['options']['id'] === 'svgmainsection';
                            if (isset($section['cells']['cell']) and is_array($section['cells']['cell'])) {
                                foreach ($section['cells']['cell'] as $cell_key => $cell) {
                                    if (
                                        isset($section['options']['id'], $cell['level'])
                                        and $cell['level'] === 'home'
                                        and !$isMainSection
                                    ) {
                                        unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                                    }
                                    if (isset(
                                        $cell['contents']['assignee']['@value'],
                                        $cell['contents']['assignee']['@attributes']
                                    )) {
                                        $array['users'][$cell['contents']['assignee']['@value']] = $cell['contents']['assignee']['@attributes'];
                                    }
                                    if (isset($cell['@attributes']['id'])) {
                                        if (!$isMainSection && !isset($cell['parent']) && array_key_exists($section['options']['id'], $this->_sectionsMap)) {
                                            $cell['parent'] = $this->_sectionsMap[$section['options']['id']];
                                        }
                                        $cell['level'] = $this->_getCellLevel($cell);
                                        $array['pages'][$cell['@attributes']['id']] = $cell;
                                    }
                                }
                            }
                        }
                        unset($array['section']);
                        return $array;
                    }
                }
            }
            return new WP_Error(SLICKPLAN_PLUGIN_ID, 'Invalid file format. Please use XML file exported from '
                . 'Slickplan (<a href="https://help.slickplan.com/hc/en-us/articles/202487180-How-do-I-export-my-sitemaps-" '
                . 'target="_blank">Need help?</a>)');
        }

        private function _parseSlickplanXmlNode($node)
        {
            if (isset($node->nodeType)) {
                if ($node->nodeType === XML_CDATA_SECTION_NODE or $node->nodeType === XML_TEXT_NODE) {
                    return trim(htmlspecialchars_decode($node->textContent));
                } elseif ($node->nodeType === XML_ELEMENT_NODE) {
                    $output = [];
                    for ($i = 0, $j = $node->childNodes->length; $i < $j; ++$i) {
                        $child_node = $node->childNodes->item($i);
                        $value = $this->_parseSlickplanXmlNode($child_node);
                        if (isset($child_node->tagName)) {
                            if ($node->tagName === 'body' and is_array($value)) {
                                $value['type'] = $child_node->tagName;
                                $output[] = $value;
                            } else {
                                if (!isset($output[$child_node->tagName])) {
                                    $output[$child_node->tagName] = [];
                                }
                                $output[$child_node->tagName][] = $value;
                            }
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
                        $attributes = [];
                        foreach ($node->attributes as $attr_name => $attr_node) {
                            $attributes[$attr_name] = (string)$attr_node->value;
                        }
                        if (!is_array($output)) {
                            $output = [
                                '@value' => $output,
                            ];
                        }
                        $output['@attributes'] = $attributes;
                    }
                    return $output;
                }
            }
            return [];
        }

        private function _isCorrectSlickplanXmlFile(array $array, bool $parsed = false): bool
        {
            $first_test = (
                $array
                and is_array($array)
                and isset($array['title'], $array['version'], $array['link'])
                and is_string($array['link']) and strstr($array['link'], 'slickplan.')
            );
            if ($first_test) {
                if ($parsed) {
                    if (isset($array['sitemap']) and is_array($array['sitemap'])) {
                        return true;
                    }
                } elseif (
                    (isset($array['section']['cells']) or isset($array['section'][0]['cells']))
                    and (
                        isset($array['section']['options']['id'])
                        or isset($array['section'][0]['options']['id'])
                        or isset($array['section']['@attributes']['id'])
                    )
                ) {
                    return true;
                }
            }
            return false;
        }

        public function getPostTypes($jsonFormat = false)
        {
            if (empty($this->_postTypes)) {
                $this->_postTypes = get_post_types(['public' => true], 'objects');
                foreach ($this->_postTypes as $type => $item) {
                    if (
                        $item->name === 'attachment'
                        || ($item->capability_type !== 'post' && $item->capability_type !== 'page')
                    ) {
                        unset($this->_postTypes[$type]);
                    }
                }
            }

            if ($jsonFormat) {
                $types = [];
                foreach ($this->_postTypes as $type => $postType) {
                    $types[$type] = $postType->labels->singular_name;
                }
                return $types;
            }

            return $this->_postTypes;
        }

        private function _getPostType($type)
        {
            $postTypes = $this->getPostTypes();

            return $postTypes[$type] ?? null;
        }

        private function _isHierarchicalType($type)
        {
            return ($postType = $this->_getPostType($type)) && $postType->hierarchical;
        }

        private function _getPostTypeName($type, $singularName = true)
        {
            if ($postType = $this->_getPostType($type)) {
                return $singularName ? $postType->labels->singular_name : $postType->label;
            }

            return null;
        }

        private function _getMultidimensionalArrayHelper(array $array): array
        {
            $this->_sectionsMap = [];
            $cells = [];
            $main_section_key = -1;
            foreach ($array['section'] as $section_key => $section) {
                if (
                    isset($section['@attributes']['id'], $section['cells']['cell'])
                    and is_array($section['cells']['cell'])
                ) {
                    foreach ($section['cells']['cell'] as $cell_key => $cell) {
                        if (isset($cell['@attributes']['id'])) {
                            $cell_id = $cell['@attributes']['id'];
                            if (isset($cell['section']) and $cell['section']) {
                                $this->_sectionsMap[$cell['section']] = $cell_id;
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
                        $cell['level'] = $this->_getCellLevel($cell);
                        if ($cell['level'] === 'home') {
                            unset($array['section'][$section_key]['cells']['cell'][$cell_key]);
                        } elseif ($cell['level'] === '1' and isset($this->_sectionsMap[$section_id])) {
                            $array['section'][$section_key]['cells']['cell'][$cell_key]['parent'] = $this->_sectionsMap[$section_id];
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
                $section_cells = [];
                foreach ($section['cells']['cell'] as $cell) {
                    $section_cells[] = $cell;
                }
                usort($section_cells, [$this, '_sortPages']);
                $array['section'][$section_key]['cells']['cell'] = $section_cells;
                $cells = array_merge($cells, $section_cells);
                unset($section_cells);
            }
            $multi_array = [];
            if (isset($array['section'][$main_section_key]['cells']['cell'])) {
                foreach ($array['section'][$main_section_key]['cells']['cell'] as $cell) {
                    $level = $this->_getCellLevel($cell);
                    if (isset($cell['@attributes']['id']) and in_array($level, ['home', 'util', '1', 'foot'], true)) {
                        $level = $level === '1' ? 'main' : $cell['level'];
                        if (!isset($multi_array[$level]) or !is_array($multi_array[$level])) {
                            $multi_array[$level] = [];
                        }
                        $childs = $this->_getMultidimensionalArray($cells, $cell['@attributes']['id']);
                        $cell = [
                            'id' => $cell['@attributes']['id'],
                            'title' => $this->_getFormattedTitle($cell),
                        ];
                        if ($childs) {
                            $cell['childs'] = $childs;
                        }
                        $multi_array[$level][] = $cell;
                    }
                }
            }
            unset($array, $cells);
            return $multi_array;
        }

        private function _getCellLevel(array $cell): string
        {
            $cell['level'] = (string) (empty($cell['level']) ? '' : $cell['level']);

            if (isset($cell['level']) and in_array($cell['level'], ['home', 'util', '1', 'foot'], true)) {
                return $cell['level'];
            }

            if (isset($cell['parent']) and $cell['parent']) {
              return 'main';
            }

            return '1';
        }

        private function _getMultidimensionalArray(array $array, string $parent): array
        {
            $cells = [];
            foreach ($array as $cell) {
                if (isset($cell['parent'], $cell['@attributes']['id']) and $cell['parent'] === $parent) {
                    $childs = $this->_getMultidimensionalArray($array, $cell['@attributes']['id']);
                    $cell = [
                        'id' => $cell['@attributes']['id'],
                        'title' => $this->_getFormattedTitle($cell),
                    ];
                    if ($childs) {
                        $cell['childs'] = $childs;
                    }
                    $cells[] = $cell;
                }
            }
            return $cells;
        }

        private function _sortPages(array $a, array $b): int
        {
            if (isset($a['order'], $b['order'])) {
                return ($a['order'] < $b['order']) ? -1 : 1;
            }
            return 0;
        }

        private function _changeLanguage(string $language)
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

        private function _metaTitle($title, string $type = 'yoast'): string
        {
            return strtr(
                $title ?: '%page_name% %separator% %project_name%',
                $type === 'aio'
                    ? ['%page_name%' => '#post_title', '%separator%' => '#separator_sa', '%project_name%' => '#site_title']
                    : ['%page_name%' => '%%title%%', '%separator%' => '%%sep%%', '%project_name%' => '%%sitename%%']
            );
        }

        private function _metaSlug($slug, $pageName): string
        {
            $slug = $slug ?: '%page_name%';
            $slug = str_replace('%page_name%', $pageName, $slug);
            $slug = str_replace('%separator%', '-', $slug);

            return $slug ? sanitize_title($slug) : '';
        }

        private function _sumFiles(array $bodyItems): array
        {
            $no_of_files = 0;
            $filesize_total = [];
            foreach ($bodyItems as $body) {
                if (isset($body['type']) and ($body['type'] === 'image' or $body['type'] === 'video' or $body['type'] === 'file')) {
                    foreach ($this->_getMediaElementArray($body) as $item) {
                        if (isset($item['type']) and $item['type'] === 'library') {
                            ++$no_of_files;
                        }
                        if (isset($item['file_size'], $item['file_id']) and $item['file_size']) {
                            $filesize_total[$item['file_id']] = (int)$item['file_size'];
                        }
                    }
                }
            }
            return [$no_of_files, $filesize_total];
        }

        private function _getLanguages()
        {
            return wp_json_file_decode(SLICKPLAN_PLUGIN_PATH . 'assets/languages.json', ['associative' => true]);
        }

        private function shouldUseGutenberg(): bool
        {
            static $use = null;
            if ($use === null) {
                $use = (
                    function_exists('use_block_editor_for_post_type')
                    && function_exists('get_comment_delimited_block_content')
                    && use_block_editor_for_post_type('page')
                );
            }
            return $use;
        }
    }

    add_action('admin_init', 'slickplan_importer_init');

    register_activation_hook(__FILE__, 'slickplan_importer_activation_hook');
    register_deactivation_hook(__FILE__, 'slickplan_importer_deactivation_hook');

    add_action('wp_ajax_slickplan-importer', 'slickplan_importer_ajax_action');
    add_action('wp_ajax_nopriv_slickplan-importer', 'slickplan_importer_ajax_action');


    $slickplan = new Slickplan_Importer;

    /**
     * Slickplan Importer initialization
     */
    function slickplan_importer_init()
    {
        global $slickplan;

        register_importer(
            SLICKPLAN_PLUGIN_ID,
            'Slickplan',
            'The Slickplan Importer plugin allows you to quickly import your '
                . '<a href="https://slickplan.com" target="_blank">Slickplan</a> project into your WordPress site.',
            [$slickplan, 'dispatch']
        );

        $plugin = get_plugin_data(__FILE__);
        $version = $plugin['Version'] ?? false;

        wp_enqueue_script(SLICKPLAN_PLUGIN_ID . '-scripts', SLICKPLAN_PLUGIN_URL . 'assets/scripts.js', [
            'json2',
            'jquery',
            'jquery-ui-core',
            'jquery-ui-progressbar',
        ], $version, true);

        wp_enqueue_style(SLICKPLAN_PLUGIN_ID . '-styles', SLICKPLAN_PLUGIN_URL . 'assets/styles.css', [], $version);

        wp_localize_script(SLICKPLAN_PLUGIN_ID . '-scripts', 'slickplan_ajax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(SLICKPLAN_PLUGIN_OPTION),
            'html' => $slickplan->getSummaryRow(),
        ]);
    }

    function slickplan_importer_activation_hook()
    {
        add_option(SLICKPLAN_PLUGIN_OPTION, '', '', 'no');
    }

    function slickplan_importer_deactivation_hook()
    {
        global $slickplan;
        $slickplan->setPluginData();
        delete_option(SLICKPLAN_PLUGIN_OPTION);
    }

    function slickplan_importer_ajax_action()
    {
        global $slickplan;
        if (defined('WP_DEBUG') and WP_DEBUG) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        }
        check_ajax_referer(SLICKPLAN_PLUGIN_OPTION);
        $result = [];
        if (isset($_POST['slickplan']) and is_array($_POST['slickplan'])) {
            $result = $slickplan->ajaxImport($_POST['slickplan']);
        }
        wp_send_json($result);
    }

    if (!function_exists('array_is_list')) {
        function array_is_list(array $a)
        {
            return $a === [] || (array_keys($a) === range(0, count($a) - 1));
        }
    }
}
