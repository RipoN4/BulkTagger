<?php
/*
* Plugin Name: Bulk Tagger
* Description: Add tags in bulk using safe batch processing with detailed per-tag stats.
* Version: 2.0
* Author: RipoN
*/

if (!defined('ABSPATH')) exit;

class Bulk_Tag_Adder {

    private $batch_size = 100;
    private $sleep_seconds = 1;

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'scripts']);
        add_action('wp_ajax_bta_process', [$this, 'process']);
    }

    public function menu() {
        add_submenu_page(
            'tools.php',
            'Bulk Tag Adder',
            'Bulk Tag Adder',
            'manage_options',
            'bulk-tag-adder',
            [$this, 'page']
        );
    }

    public function scripts($hook) {
        if ($hook !== 'tools_page_bulk-tag-adder') return;

        wp_enqueue_script('jquery');

        wp_localize_script('jquery', 'bta_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bta_nonce')
        ]);
    }

    public function page() { ?>
        <div class="wrap">
            <h1>Bulk Tagger</h1>

            <table class="form-table">
                <tr>
                    <th>Tags (comma separated)</th>
                    <td>
                        <textarea id="bta_tags" rows="3" style="width:400px;" placeholder="India, Bollywood"></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Post Status</th>
                    <td>
                        <select id="bta_status">
                            <option value="publish">Publish</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending</option>
                            <option value="future">Scheduled</option>
                            <option value="all">All</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Limit Posts</th>
                    <td>
                        <input type="number" id="bta_limit" value="500" min="1">
                    </td>
                </tr>
                <tr>
                    <th>Random Order</th>
                    <td>
                        <input type="checkbox" id="bta_random" checked>
                    </td>
                </tr>
            </table>

            <button class="button button-primary" id="bta_start">Start</button>

            <h2>Progress</h2>
            <div id="bta_log" style="background:#fff;padding:10px;border:1px solid #ccc;max-height:300px;overflow:auto;"></div>

<script>
jQuery(function($){

    let offset = 0;
    let running = false;
    let totalStats = {};

    $('#bta_start').on('click', function(){

        if (running) return;

        running = true;
        offset = 0;
        totalStats = {};
        $('#bta_log').html('<strong>Starting...</strong><br>');

        processBatch();
    });

    function processBatch() {

        $.post(bta_ajax.ajax_url, {
            action: 'bta_process',
            nonce: bta_ajax.nonce,
            tags: $('#bta_tags').val(),
            status: $('#bta_status').val(),
            limit: $('#bta_limit').val(),
            random: $('#bta_random').is(':checked'),
            offset: offset
        }, function(response){

            if (!response.success) {
                $('#bta_log').append('Error occurred<br>');
                running = false;
                return;
            }

            let data = response.data;

            $('#bta_log').append(data.message + "<br>");

            offset += data.processed;

            // accumulate stats
            for (let tag in data.stats) {
                if (!totalStats[tag]) {
                    totalStats[tag] = {added:0, skipped:0};
                }
                totalStats[tag].added += data.stats[tag].added;
                totalStats[tag].skipped += data.stats[tag].skipped;
            }

            if (!data.done) {
                setTimeout(processBatch, 1000);
            } else {

                $('#bta_log').append("<hr><strong>FINAL SUMMARY</strong><br>");

                for (let tag in totalStats) {
                    $('#bta_log').append(
                        tag + " → Added: " + totalStats[tag].added +
                        " | Skipped: " + totalStats[tag].skipped + "<br>"
                    );
                }

                $('#bta_log').append("<br><strong>Finished.</strong><br>");
                running = false;
            }

        });
    }

});
</script>

        </div>
    <?php }

    public function process() {

        check_ajax_referer('bta_nonce', 'nonce');

        $tags_input = sanitize_text_field($_POST['tags']);
        $status = sanitize_text_field($_POST['status']);
        $limit = intval($_POST['limit']);
        $random = ($_POST['random'] === 'true');
        $offset = intval($_POST['offset']);

        if (empty($tags_input)) wp_send_json_error();

        $tag_names = array_filter(array_map('trim', explode(',', $tags_input)));

        $term_map = [];

        foreach ($tag_names as $tag_name) {

            $slug = sanitize_title($tag_name);

            $existing = get_term_by('slug', $slug, 'post_tag');

            if ($existing && !is_wp_error($existing)) {
                $term_id = (int) $existing->term_id;
            } else {
                $created = wp_insert_term($tag_name, 'post_tag', ['slug' => $slug]);

                if (is_wp_error($created)) continue;

                $term_id = (int) $created['term_id'];
            }

            $term_map[$term_id] = $tag_name;
        }

        $orderby = $random ? 'rand' : 'date';

        $args = [
            'post_type'      => 'post',
            'posts_per_page' => $this->batch_size,
            'offset'         => $offset,
            'orderby'        => $orderby,
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];

        if ($status !== 'all') {
            $args['post_status'] = $status;
        } else {
            $args['post_status'] = ['publish','draft','pending','future'];
        }

        $query = new WP_Query($args);

        $processed = 0;
        $stats = [];

        foreach ($term_map as $term_id => $tag_name) {
            $stats[$tag_name] = ['added' => 0, 'skipped' => 0];
        }

        foreach ($query->posts as $post_id) {

            if ($offset + $processed >= $limit) break;

            foreach ($term_map as $term_id => $tag_name) {

                if (!has_term($term_id, 'post_tag', $post_id)) {
                    wp_add_object_terms($post_id, (int)$term_id, 'post_tag');
                    $stats[$tag_name]['added']++;
                } else {
                    $stats[$tag_name]['skipped']++;
                }

            }

            $processed++;
        }

        sleep($this->sleep_seconds);

        $done = ($offset + $processed >= $limit || $processed === 0);

        wp_send_json_success([
            'processed' => $processed,
            'done'      => $done,
            'message'   => "Batch Processed: {$processed}",
            'stats'     => $stats
        ]);
    }
}

new Bulk_Tag_Adder();