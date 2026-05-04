<?php
/**
 * Plugin Name: EmQuest Package Builder
 * Description: Dashboard-friendly package builder for EmQuest travel packages.
 * Version: 0.3.0
 * Author: EmQuest
 */

if (!defined('ABSPATH')) exit;

class EmQuestPackageBuilder {
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'register_meta_box']);
        add_action('save_post_emquest_package', [$this, 'save_package_meta']);
        add_shortcode('emquest_package', [$this, 'render_package_shortcode']);
        add_shortcode('emquest_gallery', [$this, 'render_gallery_shortcode']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function register_post_type() {
        register_post_type('emquest_package', [
            'label' => 'EmQuest Packages',
            'public' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-palmtree',
            'supports' => ['title', 'editor', 'thumbnail'],
        ]);
    }

    private function get_filebird_taxonomies() {
        return ['nt_wmc_folder', 'fbv'];
    }

    private function get_folder_options() {
        $options = [];
        foreach ($this->get_filebird_taxonomies() as $tax) {
            if (!taxonomy_exists($tax)) continue;
            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
            if (is_wp_error($terms) || empty($terms)) continue;
            foreach ($terms as $term) {
                $options[] = [
                    'taxonomy' => $tax,
                    'id' => (int) $term->term_id,
                    'label' => $tax . ' → ' . $term->name,
                ];
            }
        }
        return $options;
    }

    public function register_meta_box() {
        add_meta_box('emquest_package_fields', 'Package Builder (V2)', [$this, 'render_meta_box'], 'emquest_package', 'normal', 'high');
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'emquest_package') return;
        wp_enqueue_script('emquest-package-builder-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', ['jquery'], '0.3.0', true);
    }

    private function get_meta($post_id, $key, $default = '') {
        $value = get_post_meta($post_id, $key, true);
        return $value === '' ? $default : $value;
    }

    public function render_meta_box($post) {
        wp_nonce_field('emquest_package_save', 'emquest_package_nonce');
        $folders = $this->get_folder_options();

        $meta = [
            'destination' => $this->get_meta($post->ID, '_emquest_destination'),
            'days_nights' => $this->get_meta($post->ID, '_emquest_days_nights'),
            'validity' => $this->get_meta($post->ID, '_emquest_validity'),
            'trip_info' => $this->get_meta($post->ID, '_emquest_trip_info'),
            'included' => $this->get_meta($post->ID, '_emquest_included'),
            'excluded' => $this->get_meta($post->ID, '_emquest_excluded'),
            'destination_folder_tax' => $this->get_meta($post->ID, '_emquest_destination_folder_tax'),
            'destination_folder_id' => $this->get_meta($post->ID, '_emquest_destination_folder_id'),
            'book_url' => $this->get_meta($post->ID, '_emquest_book_url'),
            'help_url' => $this->get_meta($post->ID, '_emquest_help_url'),
            'hotels' => $this->get_meta($post->ID, '_emquest_hotels', []),
        ];
        if (!is_array($meta['hotels'])) $meta['hotels'] = [];

        echo '<style>.emq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.emq-full{grid-column:1/-1}.emq-input,.emq-text,.emq-select{width:100%}.emq-hotel-card{border:1px solid #d7d7d7;padding:12px;margin:10px 0;background:#fff}.emq-hotel-head{display:flex;justify-content:space-between;align-items:center}.emq-btn{padding:6px 10px;cursor:pointer}.emq-btn-danger{background:#b42318;color:#fff;border:0}.emq-btn-add{background:#0f766e;color:#fff;border:0}</style>';

        echo '<div class="emq-grid">';
        $this->input('Main Destination', 'destination', $meta['destination']);
        $this->input('Days & Nights', 'days_nights', $meta['days_nights']);
        $this->input('Validity', 'validity', $meta['validity']);
        $this->folder_dropdown('Destination Gallery Folder', 'destination_folder', $meta['destination_folder_tax'], $meta['destination_folder_id'], $folders, true);
        $this->textarea('Trip Info', 'trip_info', $meta['trip_info'], true, 6);
        $this->textarea("What's Included (one line each)", 'included', $meta['included'], false, 6);
        $this->textarea("What's Excluded (one line each)", 'excluded', $meta['excluded'], false, 6);
        $this->input('Book Now URL', 'book_url', $meta['book_url']);
        $this->input('Get Help URL', 'help_url', $meta['help_url']);

        echo '<div class="emq-full"><h3>Hotel Options</h3><p>Select hotel image folder from dropdown (FileBird).</p><div id="emquest-hotels-wrap">';
        foreach ($meta['hotels'] as $idx => $hotel) $this->hotel_block($idx, $hotel, $folders);
        echo '</div><button type="button" class="emq-btn emq-btn-add" id="emquest-add-hotel">+ Add Hotel Option</button></div>';
        echo '</div>';

        $template = ['title'=>'','location'=>'','price'=>'','details'=>array_fill(0,6,''),'folder_tax'=>'','folder_id'=>''];
        echo '<template id="emquest-hotel-template">';
        $this->hotel_block('__INDEX__', $template, $folders);
        echo '</template>';
    }

    private function input($label, $name, $value, $full = false) { $cls=$full?'emq-full':''; echo '<p class="'.esc_attr($cls).'"><label><strong>'.esc_html($label).'</strong></label><br><input class="emq-input" type="text" name="emquest['.esc_attr($name).']" value="'.esc_attr($value).'"></p>'; }
    private function textarea($label, $name, $value, $full = false, $rows = 4) { $cls=$full?'emq-full':''; echo '<p class="'.esc_attr($cls).'"><label><strong>'.esc_html($label).'</strong></label><br><textarea class="emq-text" rows="'.intval($rows).'" name="emquest['.esc_attr($name).']">'.esc_textarea($value).'</textarea></p>'; }

    private function folder_dropdown($label, $prefix, $selected_tax, $selected_id, $options, $full=false, $idx='') {
        $cls=$full?'emq-full':'';
        $name_tax = $idx === '' ? "emquest[{$prefix}_tax]" : "emquest[hotels][{$idx}][folder_tax]";
        $name_id = $idx === '' ? "emquest[{$prefix}_id]" : "emquest[hotels][{$idx}][folder_id]";
        echo '<p class="'.esc_attr($cls).'"><label><strong>'.esc_html($label).'</strong></label><br>';
        echo '<select class="emq-select" name="'.esc_attr($name_tax).'|'.esc_attr($name_id).'">';
        echo '<option value="">Select folder</option>';
        foreach ($options as $o) {
            $val = $o['taxonomy'] . '|' . $o['id'];
            $sel = ($o['taxonomy'] === $selected_tax && (string)$o['id'] === (string)$selected_id) ? 'selected' : '';
            echo '<option value="'.esc_attr($val).'" '.$sel.'>'.esc_html($o['label']).'</option>';
        }
        echo '</select></p>';
    }

    private function hotel_block($idx, $hotel, $folders) {
        $details = is_array($hotel['details'] ?? null) ? $hotel['details'] : [];
        for ($i=0;$i<6;$i++) if (!isset($details[$i])) $details[$i] = '';
        echo '<div class="emq-hotel-card">';
        echo '<div class="emq-hotel-head"><h4>Hotel Option</h4><button type="button" class="emq-btn emq-btn-danger emquest-remove-hotel">Remove</button></div>';
        echo '<p><label>Hotel Title</label><br><input class="emq-input" type="text" name="emquest[hotels]['.esc_attr($idx).'][title]" value="'.esc_attr($hotel['title'] ?? '').'"></p>';
        echo '<p><label>Hotel Location</label><br><input class="emq-input" type="text" name="emquest[hotels]['.esc_attr($idx).'][location]" value="'.esc_attr($hotel['location'] ?? '').'"></p>';
        echo '<p><label>Price</label><br><input class="emq-input" type="text" name="emquest[hotels]['.esc_attr($idx).'][price]" value="'.esc_attr($hotel['price'] ?? '').'"></p>';
        $this->folder_dropdown('Hotel Gallery Folder', 'hotel_folder', $hotel['folder_tax'] ?? '', $hotel['folder_id'] ?? '', $folders, false, $idx);
        echo '<p><strong>Hotel Details</strong></p>';
        for ($i=0;$i<6;$i++) echo '<p><input class="emq-input" type="text" name="emquest[hotels]['.esc_attr($idx).'][details]['.$i.']" value="'.esc_attr($details[$i]).'" placeholder="Detail '.($i+1).'"></p>';
        echo '</div>';
    }

    private function parse_combined_select($raw) {
        $parts = explode('|', sanitize_text_field($raw));
        return [ $parts[0] ?? '', isset($parts[1]) ? intval($parts[1]) : 0 ];
    }

    public function save_package_meta($post_id) {
        if (!isset($_POST['emquest_package_nonce']) || !wp_verify_nonce($_POST['emquest_package_nonce'], 'emquest_package_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['emquest']) || !is_array($_POST['emquest'])) return;

        $data = wp_unslash($_POST['emquest']);
        foreach (['destination','days_nights','validity','trip_info','included','excluded','book_url','help_url'] as $field) {
            update_post_meta($post_id, '_emquest_'.$field, sanitize_textarea_field($data[$field] ?? ''));
        }

        [$dest_tax, $dest_id] = $this->parse_combined_select($data['destination_folder_tax|emquest[destination_folder_id]'] ?? $data['destination_folder_tax|destination_folder_id'] ?? '');
        if (!$dest_tax && !empty($data['destination_folder_tax']) && !empty($data['destination_folder_id'])) {
            $dest_tax = sanitize_key($data['destination_folder_tax']);
            $dest_id = intval($data['destination_folder_id']);
        }
        update_post_meta($post_id, '_emquest_destination_folder_tax', $dest_tax);
        update_post_meta($post_id, '_emquest_destination_folder_id', $dest_id);

        $clean_hotels=[];
        foreach (($data['hotels'] ?? []) as $hotel) {
            $title = sanitize_text_field($hotel['title'] ?? '');
            if ($title==='') continue;
            $details=[]; foreach (($hotel['details'] ?? []) as $d){$d=sanitize_text_field($d); if($d!=='')$details[]=$d;}
            [$ft,$fid] = $this->parse_combined_select($hotel['folder_tax]|emquest[hotels][x][folder_id]'] ?? $hotel['folder_tax|folder_id'] ?? '');
            if (!$ft) { $ft=sanitize_key($hotel['folder_tax'] ?? ''); $fid=intval($hotel['folder_id'] ?? 0); }
            $clean_hotels[]=['title'=>$title,'location'=>sanitize_text_field($hotel['location'] ?? ''),'price'=>sanitize_text_field($hotel['price'] ?? ''),'folder_tax'=>$ft,'folder_id'=>$fid,'details'=>$details];
        }
        update_post_meta($post_id, '_emquest_hotels', $clean_hotels);
    }

    private function render_folder_gallery($tax, $term_id, $context='default', $limit=24) {
        if (!$tax || !$term_id || !taxonomy_exists($tax)) return '';
        $images = get_posts(['post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>intval($limit),'tax_query'=>[['taxonomy'=>$tax,'field'=>'term_id','terms'=>intval($term_id)]]]);
        if (!$images) return '';
        ob_start(); echo '<div class="emquest-gallery emquest-gallery-'.esc_attr($context).'">';
        foreach($images as $img){$thumb=wp_get_attachment_image_url($img->ID,'medium_large');$full=wp_get_attachment_image_url($img->ID,'full'); if(!$thumb||!$full)continue; echo '<a class="emquest-gallery-item" href="'.esc_url($full).'" target="_blank" rel="noopener"><img src="'.esc_url($thumb).'" alt=""></a>';}
        echo '</div>'; return ob_get_clean();
    }

    public function render_gallery_shortcode($atts) {
        $atts = shortcode_atts(['folder'=>'','context'=>'destination','limit'=>24], $atts, 'emquest_gallery');
        $raw = sanitize_text_field($atts['folder']);
        [$tax,$id] = $this->parse_combined_select($raw);
        return $this->render_folder_gallery($tax,$id,$atts['context'],intval($atts['limit']));
    }

    public function render_package_shortcode($atts) {
        $atts = shortcode_atts(['id'=>get_the_ID()],$atts,'emquest_package');
        $id = intval($atts['id']); if(!$id) return '';
        $hotels = $this->get_meta($id, '_emquest_hotels', []); if(!is_array($hotels)) $hotels=[];
        ob_start(); ?>
        <section class="emquest-package-template">
            <h1><?php echo esc_html(get_the_title($id)); ?></h1>
            <p><strong>📍 <?php echo esc_html($this->get_meta($id, '_emquest_destination')); ?></strong></p>
            <p><?php echo esc_html($this->get_meta($id, '_emquest_days_nights')); ?></p>
            <p><strong>Validity:</strong> <?php echo esc_html($this->get_meta($id, '_emquest_validity')); ?></p>

            <h3>Trip Gallery</h3>
            <?php echo $this->render_folder_gallery($this->get_meta($id, '_emquest_destination_folder_tax'), intval($this->get_meta($id, '_emquest_destination_folder_id')), 'destination'); ?>

            <h3>Trip Info</h3>
            <p><?php echo nl2br(esc_html($this->get_meta($id, '_emquest_trip_info'))); ?></p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                <div><h4>What's Included</h4><ul><?php foreach(array_filter(array_map('trim', explode("\n", $this->get_meta($id, '_emquest_included')))) as $item) echo '<li>'.esc_html($item).'</li>'; ?></ul></div>
                <div><h4>What's Excluded</h4><ul><?php foreach(array_filter(array_map('trim', explode("\n", $this->get_meta($id, '_emquest_excluded')))) as $item) echo '<li>'.esc_html($item).'</li>'; ?></ul></div>
            </div>

            <h3>Hotel Options</h3>
            <?php foreach($hotels as $i=>$hotel): ?>
                <article><h4>Option <?php echo esc_html(str_pad((string)($i+1),2,'0',STR_PAD_LEFT)); ?> — <?php echo esc_html($hotel['title'] ?? ''); ?></h4>
                <p><?php echo esc_html($hotel['location'] ?? ''); ?></p>
                <ul><?php foreach(($hotel['details'] ?? []) as $d) echo '<li>'.esc_html($d).'</li>'; ?></ul>
                <p><strong><?php echo esc_html($hotel['price'] ?? ''); ?></strong></p>
                <details><summary>See Hotel</summary><?php echo $this->render_folder_gallery($hotel['folder_tax'] ?? '', intval($hotel['folder_id'] ?? 0), 'hotel'); ?></details></article>
            <?php endforeach; ?>
            <p><a href="<?php echo esc_url($this->get_meta($id, '_emquest_book_url')); ?>">Book Now</a> | <a href="<?php echo esc_url($this->get_meta($id, '_emquest_help_url')); ?>">Get Help</a></p>
        </section>
        <?php return ob_get_clean();
    }
}
new EmQuestPackageBuilder();
