<?php defined('SLICKPLAN_PLUGIN_PATH') or exit('No direct access allowed'); ?>

<?php $is_summary = (isset($xml['summary']) and $xml['summary']); ?>

<?php if (!empty($this->pages)) { ?>
    <script type="text/javascript">
        window.SLICKPLAN_JSON = <?php echo wp_json_encode(['pages' => $this->pages, 'types' => $this->getPostTypes(true)]); ?>;
    </script>
<?php } ?>

<form action="#" method="post" id="slickplan-importer">
    <?php wp_nonce_field(); ?>
    <h3><?php echo $is_summary ? 'Success!' : 'Importing Pages&hellip;'; ?></h3>
    <div class="notice notice-success notice-alt slickplan-show-summary"<?php if (!$is_summary) { ?> style="display: none"<?php } ?>>
        <p>Pages have been imported. Thank you for using <a href="https://slickplan.com/" target="_blank">Slickplan</a> Importer.</p>
    </div>
    <?php if (!$is_summary) { ?>
        <div id="slickplan-progressbar" class="progressbar"><div class="progress-label">0%</div></div>
    <?php } ?>
    <hr>
    <div class="slickplan-summary"><?php if ($is_summary) echo $xml['summary']; ?></div>
</form>
