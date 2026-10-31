<?php
defined('ABSPATH') || exit;

/** Owns discogs controller behavior; legacy function entry points delegate here. */
class TCS_Discogs_Controller {
    private $synchronizer;

    public function __construct(TCS_Discogs_Inventory_Synchronizer $synchronizer) {
        $this->synchronizer = $synchronizer;
    }

    public function register_menu() {
        add_menu_page('Discogs Inventory', 'Discogs Inventory', 'manage_options', 'discogs-inventory', 'discogs_inventory_page','dashicons-album',  6);
    }

    public function render_admin() {
        if (
            isset($_POST['discogs_refresh']) &&
            current_user_can('manage_options')
        ) {
            check_admin_referer(
                'discogs_refresh_inventory',
                'discogs_refresh_nonce'
            );

            $this->synchronizer->synchronize();

            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>Discogs inventory and existing posts have been updated.</p>';
            echo '</div>';
        }
        ?>
    <div class="wrap">
        <h1>Discogs Inventory</h1>

        <p>
            Refresh the Discogs inventory and update all corresponding
            WordPress posts.
        </p>

        <form method="post">
            <?php
            wp_nonce_field(
                    'discogs_refresh_inventory',
                    'discogs_refresh_nonce'
                );
                ?>

            <input
                type="hidden"
                name="discogs_refresh"
                value="1"
            >

            <?php
            submit_button(
                    'Refresh Inventory',
                    'primary',
                    'submit',
                    false
                );
                ?>
        </form>
    </div>
    <?php
}

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array('number' => 10), $atts);
        $args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'category_name'  => 'Vinyl Records',
            'posts_per_page' => $atts['number'],
        );
        $query = new WP_Query($args);
        ob_start();

        if ($query->have_posts()) {
           ?>
        <div class="discogs-grid">
            <?php
                while ($query->have_posts()) {
                        $query->the_post();
                        $url = get_post_meta(get_the_ID(), 'discogs_url', true);
                        $year = get_post_meta(get_the_ID(), 'discogs_year', true) ?: 'Unknown';
                        $image_url = get_post_meta(get_the_ID(), 'discogs_image_uri', true);
                        $categories = get_the_category();

                        // Find the "Vinyl Records" category
                        $vinyl_cat = get_category_by_slug('vinyl-records');
                        $genre = '';
                        $style = '';

                        if ($vinyl_cat) {
                            foreach ($categories as $cat) {
                                if ($cat->parent == $vinyl_cat->term_id) {
                                    $genre = $cat->name;

                                    // Check for a child category of this genre
                                    foreach ($categories as $subcat) {
                                        if ($subcat->parent == $cat->term_id) {
                                            $style = $subcat->name;
                                            break;
                                        }
                                    }
                                    break;
                                }
                            }

                        }
                        ?>

                    <article class="discogs-post">
                        <?php if (has_post_thumbnail()) : ?>
                            <div class="discogs-thumbnail"><a href="<?php echo the_permalink(); ?>"><?php the_post_thumbnail('medium'); ?></a></div>
                        <?php endif; ?>
                        <h4><a href="<?php echo the_permalink();  ?>"><?php the_title(); ?></a></h4>
                        <?php
                            if ($year) {
                                    echo '<div class="discogs-year"><strong>Release Year:</strong> ' . esc_html($year) . '</div>';
                                }
                                if ($genre && $style) {
                                    echo '<div class="discogs-genre"><strong>Genre / Style: </strong>' . esc_html($genre) . ' / ' . esc_html($style) . '</div>';
                                } elseif ($genre) {
                                    echo '<div class="discogs-genre"><strong>Genre: </strong>' . esc_html($genre) . '</div>';
                                }
                            ?>
                        <p><a href="<?php echo esc_url($url); ?>" target="_blank" class="discogs-buy-button">Buy Now</a></p>
                    </article>
                <?php
                }
                ?>
        </div>
        <?php
            wp_reset_postdata();
        }
        return ob_get_clean();
    }

}
