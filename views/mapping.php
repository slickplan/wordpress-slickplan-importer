<?php defined('SLICKPLAN_PLUGIN_PATH') or exit('No direct access allowed'); ?>

<div class="notice notice-info notice-alt">
    <p>You can import your content as new pages, posts, or custom posts. It is also possible to exclude some pages or overwrite existing posts/pages.</p>
</div>

<div id="slickplan-floating" class="hidden">
    <div><a href="#" class="select-all">Select All</a></div>
    <div><a href="#" class="deselect-all">Deselect All</a></div>
    <div>|</div>
    <div>Selected (<span class="counter"></span>):</div>
    <select name="custom_action">
        <option value="new">New&hellip;</option>
        <option value="overwrite">Overwrite&hellip;</option>
        <option value="exclude">Exclude</option>
    </select>
    <?php
    $this->displayPageTypesDropdown('custom_type', false, '', true);
    $hierarchicalPostTypes = get_post_types(['hierarchical' => true]);
    $statuses = ['publish', 'pending', 'draft', 'future', 'private'];
    foreach ($this->getAvailablePageTypes() as $pageType) {
        $name = 'custom_list_' . $pageType['key'];
        if (in_array($pageType['key'], $hierarchicalPostTypes, true)) {
            wp_dropdown_pages([
                'name' => $name,
                'post_type' => $pageType['key'],
                'post_status' => $statuses,
            ]);
        } else {
            $posts = get_posts([
                'numberposts' => 999999,
                'posts_per_page' => -1,
                'post_type' => $pageType['key'],
                'post_status' => $statuses,
            ]);
            ?>
            <select name="<?= $name ?>">
                <?php foreach ($posts as $single) { ?>
                    <option value="<?php echo esc_attr($single->ID); ?>"><?php echo esc_html($single->post_title); ?></option>
                <?php } ?>
            </select>
            <?php
        }
    }
    ?>
    <button class="button button-secondary" type="button">Apply</button>
</div>

<form action="" method="post" id="slickplan-importer">
    <?php wp_nonce_field(); ?>
    <table class="wp-list-table widefat fixed striped table-view-list pages">
        <thead>
            <tr>
                <td class="check-column"></td>
                <th scope="col" class="column-primary">
                    Page title
                    <a href="#" class="collapse-all" title="Collapse All"><span class="dashicons dashicons-arrow-up"></span></a>
                    <a href="#" class="expand-all" title="Expand All"><span class="dashicons dashicons-arrow-down"></span></a>
                </th>
                <th scope="col">Import as&hellip;</th>
            </tr>
        </thead>
        <tbody id="slickplan-mapping-list">
            <?php
            foreach (['home', 'main', 'util', 'foot'] as $type) {
                if (isset($xml['sitemap'][$type]) and is_array($xml['sitemap'][$type])) {
                    $this->renderPagesTable($xml['sitemap'][$type]);
                }
            }
            ?>
        </tbody>
    </table>
    <br>
    <?php if (isset($xml['sitemap']) and is_array($xml['sitemap']) and count($xml['sitemap'])) { ?>
        <input class="button button-primary" type="submit" value="Import Pages">
    <?php } ?>
    <a href="<?php echo esc_attr($this->_getAdminUrl('options')); ?>" class="button button-secondary">Back</a>
    <input type="hidden" name="slickplan_importer[json]" value="<?= esc_attr(json_encode($mappingJson)); ?>" id="slickplan-map-json">
</form>

<script type="text/html" id="slickplan-pages"></script>
<script type="text/html" id="slickplan-types"></script>
