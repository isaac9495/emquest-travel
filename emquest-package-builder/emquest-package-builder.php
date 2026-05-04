<?php
/**
 * Plugin Name: EmQuest Package Builder
 * Description: Dashboard-friendly package builder for EmQuest travel packages.
 * Version: 0.2.0
 * Author: EmQuest
 */

if (!defined('ABSPATH')) {
    exit;
}

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
            'labels' => [
                'name' => 'EmQuest Packages',
                'singular_name' => 'EmQuest Package',
                'add_new_item' => 'Add New Package',
                'edit_item' => 'Edit Package',
            ],
            'public' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-palmtree',
            'supports' => ['title', 'editor', 'thumbnail'],
        ]);
    }

    public function register_meta_box() {
        add_meta_box(
            'emquest_package_fields',
            'Package Details',
            [$this, 'render_meta_box'],
            'emquest_package',
            'normal',
            'high'
        );
    }

    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'emquest_package') {
            return;
        }

        wp_enqueue_script(
            'emquest-package-builder-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery'],
            '0.2.0',
            true
        );
    }

    private function get_meta($post_id, $key, $default = '') {
        $value = get_post_meta($post_id, $key, true);
        return $value === '' ? $default : $value;
    }

    public function render_meta_box($post) {
        wp_nonce_field('emquest_package_save', 'emquest_package_nonce');

        $meta = [
            'destination' => $this->get_meta($post->ID, '_emquest_destination'),
            'days_nights' => $this->get_meta($post->ID, '_emquest_days_nights'),
            'validity' => $this->get_meta($post->ID, '_emquest_validity'),
            'trip_info' => $this->get_meta($post->ID, '_emquest_trip_info'),
            'included' => $this->get_meta($post->ID, '_emquest_included'),
            'excluded' => $this->get_meta($post->ID, '_emquest_excluded'),
            'destination_gallery_shortcode' => $this->get_meta($post->ID, '_emquest_destination_gallery_shortcode'),
            'book_url' => $this->get_meta($post->ID, '_emquest_book_url'),
            'help_url' => $this->get_meta($post->ID, '_emquest_help_url'),
            'hotels' => $this->get_meta($post->ID, '_emquest_hotels', []),
        ];

        if (!is_array($meta['hotels'])) {
            $meta['hotels'] = [];
        }

        echo '<style>.emq-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.emq-full{grid-column:1/-1}.emq-input,.emq-text{width:100%}.emq-hotel-card{border:1px solid #d7d7d7;padding:12px;margin:10px 0;background:#fff}.emq-hotel-head{display:flex;justify-content:space-between;align-items:center}.emq-btn{padding:6px 10px;cursor:pointer}.emq-btn-danger{background:#b42318;color:#fff;border:0}.emq-btn-add{background:#0f766e;color:#fff;border:0}</style>';

        echo '<div class="emq-grid">';
        $this->input('Main Destination', 'destination', $meta['destination']);
        $this->input('Days & Nights (e.g. 5 Days · 4 Nights)', 'days_nights', $meta['days_nights']);
        $this->input('Validity', 'validity', $meta['validity']);
        $this->input('Trip Gallery Shortcode', 'destination_gallery_shortcode', $meta['destination_gallery_shortcode'], true, '[emquest_gallery folder="malindi" context="destination"]');
        $this->textarea('Trip Info', 'trip_info', $meta['trip_info'], true, 6);
        $this->textarea("What's Included (one item per line)", 'included', $meta['included'], false, 6);
        $this->textarea("What's Excluded (one item per line)", 'excluded', $meta['excluded'], false, 6);
        $this->input('Book Now URL', 'book_url', $meta['book_url']);
        $this->input('Get Help URL', 'help_url', $meta['help_url']);

        echo '<div class="emq-full"><h3>Hotel Options</h3><p>Add each hotel option here. Option labels are automatic (Option 01, Option 02...).</p>';
        echo '<div id="emquest-hotels-wrap">';
        foreach ($meta['hotels'] as $idx => $hotel) {
            $this->hotel_block($idx, $hotel);
        }
        echo '</div>';
        echo '<button type="button" class="emq-btn emq-btn-add" id="emquest-add-hotel">+ Add Hotel Option</button>';
        echo '</div>';
        echo '</div>';

        $template = ['title' => '', 'location' => '', 'price' => '', 'details' => array_fill(0, 6, ''), 'gallery_shortcode' => ''];
        echo '<template id="emquest-hotel-template">';
        $this->hotel_block('__INDEX__', $template);
        echo '</template>';
    }

    private function input($label, $name, $value, $full = false, $placeholder = '') {
        $cls = $full ? 'emq-full' : '';
        echo '<p class="' . esc_attr($cls) . '"><label><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<input class="emq-input" type="text" name="emquest[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"></p>';
    }

    private function textarea($label, $name, $value, $full = false, $rows = 4) {
        $cls = $full ? 'emq-full' : '';
        echo '<p class="' . esc_attr($cls) . '"><label><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<textarea class="emq-text" rows="' . intval($rows) . '" name="emquest[' . esc_attr($name) . ']">' . esc_textarea($value) . '</textarea></p>';
    }

    private function hotel_block($idx, $hotel) {
        $title = $hotel['title'] ?? '';
        $location = $hotel['location'] ?? '';
        $price = $hotel['price'] ?? '';
        $gallery_shortcode = $hotel['gallery_shortcode'] ?? '';
        $details = $hotel['details'] ?? [];
        for ($i = 0; $i < 6; $i++) {
            if (!isset($details[$i])) $details[$i] = '';
        }

        echo '<div class="emq-hotel-card" data-hotel-index="' . esc_attr($idx) . '">';
        echo '<div class="emq-hotel-head"><h4>Hotel Option</h4><button type="button" class="emq-btn emq-btn-danger emquest-remove-hotel">Remove</button></div>';
        echo '<p><label>Hotel Title</label><br><input class="emq-input" type="text" name="emquest[hotels][' . esc_attr($idx) . '][title]" value="' . esc_attr($title) . '"></p>';
        echo '<p><label>Hotel Location</label><br><input class="emq-input" type="text" name="emquest[hotels][' . esc_attr($idx) . '][location]" value="' . esc_attr($location) . '"></p>';
        echo '<p><label>Price</label><br><input class="emq-input" type="text" name="emquest[hotels][' . esc_attr($idx) . '][price]" value="' . esc_attr($price) . '"></p>';
        echo '<p><label>Hotel Gallery Shortcode</label><br><input class="emq-input" type="text" name="emquest[hotels][' . esc_attr($idx) . '][gallery_shortcode]" value="' . esc_attr($gallery_shortcode) . '" placeholder="[emquest_gallery folder=&quot;diamonds&quot; context=&quot;hotel&quot;]"></p>';
        echo '<p><strong>Hotel Details (6 lines)</strong></p>';
        for ($i = 0; $i < 6; $i++) {
            echo '<p><input class="emq-input" type="text" name="emquest[hotels][' . esc_attr($idx) . '][details][' . $i . ']" value="' . esc_attr($details[$i]) . '" placeholder="Detail ' . ($i + 1) . '"></p>';
        }
        echo '<p><small>If you need more than 6 details, use the post content editor or duplicate details in line 6 for now (we can extend this in next pass).</small></p>';
        echo '</div>';
    }

    public function save_package_meta($post_id) {
        if (!isset($_POST['emquest_package_nonce']) || !wp_verify_nonce($_POST['emquest_package_nonce'], 'emquest_package_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['emquest']) || !is_array($_POST['emquest'])) return;

        $data = wp_unslash($_POST['emquest']);
        $fields = ['destination', 'days_nights', 'validity', 'trip_info', 'included', 'excluded', 'destination_gallery_shortcode', 'book_url', 'help_url'];

        foreach ($fields as $field) {
            update_post_meta($post_id, '_emquest_' . $field, sanitize_textarea_field($data[$field] ?? ''));
        }

        $hotels = $data['hotels'] ?? [];
        $clean_hotels = [];

        if (is_array($hotels)) {
            foreach ($hotels as $hotel) {
                $title = sanitize_text_field($hotel['title'] ?? '');
                if ($title === '') continue;

                $details = [];
                if (!empty($hotel['details']) && is_array($hotel['details'])) {
                    foreach ($hotel['details'] as $detail) {
                        $clean = sanitize_text_field($detail);
                        if ($clean !== '') $details[] = $clean;
                    }
                }

                $clean_hotels[] = [
                    'title' => $title,
                    'location' => sanitize_text_field($hotel['location'] ?? ''),
                    'price' => sanitize_text_field($hotel['price'] ?? ''),
                    'gallery_shortcode' => sanitize_text_field($hotel['gallery_shortcode'] ?? ''),
                    'details' => $details,
                ];
            }
        }

        update_post_meta($post_id, '_emquest_hotels', $clean_hotels);
    }

    public function render_gallery_shortcode($atts) {
        $atts = shortcode_atts([
            'folder' => '',
            'context' => 'destination',
            'limit' => 24,
        ], $atts, 'emquest_gallery');

        $folder = sanitize_text_field($atts['folder']);
        if ($folder === '') return '';

        $images = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => intval($atts['limit']),
            's' => $folder,
        ]);

        if (!$images) return '';

        ob_start();
        echo '<div class="emquest-gallery emquest-gallery-' . esc_attr($atts['context']) . '">';
        foreach ($images as $image) {
            $thumb = wp_get_attachment_image_url($image->ID, 'medium_large');
            $full = wp_get_attachment_image_url($image->ID, 'full');
            if (!$thumb || !$full) continue;
            echo '<a class="emquest-gallery-item" href="' . esc_url($full) . '" target="_blank" rel="noopener">';
            echo '<img src="' . esc_url($thumb) . '" alt="">';
            echo '</a>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    public function render_package_shortcode($atts) {
        $atts = shortcode_atts(['id' => get_the_ID()], $atts, 'emquest_package');
        $post_id = intval($atts['id']);
        if (!$post_id) return '';

        $title = get_the_title($post_id);
        $destination = $this->get_meta($post_id, '_emquest_destination');
        $days_nights = $this->get_meta($post_id, '_emquest_days_nights');
        $validity = $this->get_meta($post_id, '_emquest_validity');
        $trip_info = $this->get_meta($post_id, '_emquest_trip_info');
        $included = array_filter(array_map('trim', explode("\n", $this->get_meta($post_id, '_emquest_included'))));
        $excluded = array_filter(array_map('trim', explode("\n", $this->get_meta($post_id, '_emquest_excluded'))));
        $destination_gallery_shortcode = $this->get_meta($post_id, '_emquest_destination_gallery_shortcode');
        $book_url = $this->get_meta($post_id, '_emquest_book_url');
        $help_url = $this->get_meta($post_id, '_emquest_help_url');
        $hotels = $this->get_meta($post_id, '_emquest_hotels', []);
        if (!is_array($hotels)) $hotels = [];

        ob_start();
        ?>
        <section class="emquest-package-template">
            <h1><?php echo esc_html($title); ?></h1>
            <p><strong>📍 <?php echo esc_html($destination); ?></strong></p>
            <p><?php echo esc_html($days_nights); ?></p>
            <p><strong>Validity:</strong> <?php echo esc_html($validity); ?></p>

            <h3>Trip Gallery</h3>
            <?php echo do_shortcode($destination_gallery_shortcode); ?>

            <h3>Trip Info</h3>
            <p><?php echo nl2br(esc_html($trip_info)); ?></p>

            <div class="emquest-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                <div>
                    <h4>What's Included</h4>
                    <ul><?php foreach ($included as $item) { echo '<li>' . esc_html($item) . '</li>'; } ?></ul>
                </div>
                <div>
                    <h4>What's Excluded</h4>
                    <ul><?php foreach ($excluded as $item) { echo '<li>' . esc_html($item) . '</li>'; } ?></ul>
                </div>
            </div>

            <h3>Hotel Options</h3>
            <?php foreach ($hotels as $i => $hotel) : ?>
                <article class="emquest-hotel">
                    <h4>Option <?php echo esc_html(str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)); ?> — <?php echo esc_html($hotel['title'] ?? ''); ?></h4>
                    <p><?php echo esc_html($hotel['location'] ?? ''); ?></p>
                    <ul>
                        <?php foreach (($hotel['details'] ?? []) as $detail) { echo '<li>' . esc_html($detail) . '</li>'; } ?>
                    </ul>
                    <p><strong><?php echo esc_html($hotel['price'] ?? ''); ?></strong></p>
                    <details>
                        <summary>See Hotel</summary>
                        <?php echo do_shortcode($hotel['gallery_shortcode'] ?? ''); ?>
                    </details>
                </article>
            <?php endforeach; ?>

            <p>
                <a href="<?php echo esc_url($book_url); ?>">Book Now</a>
                |
                <a href="<?php echo esc_url($help_url); ?>">Get Help</a>
            </p>
        </section>
        <?php
        return ob_get_clean();
    }
}

new EmQuestPackageBuilder();
