<?php
/*
 Plugin Name: Discogs Seller Posts
 Description: Fetches Discogs seller inventory and creates WordPress posts.
 Version: 1.0
 Author: Peter Giammarco
 */

 function discog_load_env_file($file_path) {
    $env_vars = [];
    if (file_exists($file_path)) {
        $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0 || !strpos($line, '=')) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $env_vars[trim($key)] = trim($value);
        }
    }
    return $env_vars;
}
// Load the .env file
$env_file = plugin_dir_path(__FILE__) . 'discog.env';
$env_discog = discog_load_env_file($env_file);

function fetch_discogs_inventory(){
    global $env_discog; 
    $username = $env_discog['DISCOGS_ID'];
    $token = $env_discog['DISCOGS_TOKEN'];
 
   
    $all_items = [];
    $page = 1;
    
    do {
        $url = "https://api.discogs.com/users/{$username}/inventory?per_page=50&page={$page}";

        $args = array(
            'headers' => array(
                'Authorization' => "Discogs token={$token}",
                'User-Agent' => 'DiscogsSellerPosts/1.0'
            )
        );


        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('Discogs API Error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['listings'])) {
            $all_items = array_merge($all_items, $data['listings']);
        }

        $page++;
    } while (!empty($data['pagination']) && $page <= $data['pagination']['pages']);


    error_log(json_encode($data['listings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));


    return ['listings' => $all_items];
 }


// Fetch release data including images, genres, and styles
function fetch_discogs_release($release_id) {

    global $env; 
    $token = $env['DISCOGS_TOKEN'];
    $url = "https://api.discogs.com/releases/{$release_id}";

    
    $args = array(
        'headers' => array(
            'Authorization' => "Discogs token={$token}",
            'User-Agent'    => 'DiscogsSellerPosts/1.0'
        )
    );

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        error_log('Discogs Release API Error: ' . $response->get_error_message());
        return false;
    }
    $body = wp_remote_retrieve_body($response);
    return json_decode($body, true);
}


function create_discogs_posts() {
    $inventory = fetch_discogs_inventory();

    if (!$inventory || empty($inventory['listings'])) {
        return;
    }

    $current_listing_ids = array_column($inventory['listings'], 'id');

    $existing_posts = get_posts(array(
        'meta_key' => 'discogs_listing_id',
        'post_type' => 'post',
        'post_status' => 'any',
        'numberposts' => -1,
    ));

    // Delete posts not in current inventory
    foreach ($existing_posts as $post) {
        $discogs_id = get_post_meta($post->ID, 'discogs_listing_id', true);
        if (!in_array($discogs_id, $current_listing_ids)) {
            // Delete featured image
            $thumb_id = get_post_thumbnail_id($post->ID);
                if ($thumb_id) {
                    wp_delete_attachment($thumb_id, true);
                }

                // Delete all attachments
                $attachments = get_children(array(
                    'post_parent' => $post->ID,
                    'post_type'   => 'attachment'
                ));
                foreach ($attachments as $attachment) {
                    wp_delete_attachment($attachment->ID, true);
                }

            // Delete post and its metadata
            wp_delete_post($post->ID, true);
        }
    }

    // Ensure the category exists
    $category = 'vinyl-records';
    $parent_category = 'collectibles';

        $parent_cat_id = term_exists($parent_category, 'category');

        if (!$parent_cat_id) {
            $parent_cat_id = wp_insert_term($parent_category, 'category');
            $parent_cat_id = $parent_cat_id['term_id'];
        } else {
            $parent_cat_id = $parent_cat_id['term_id'];
        }

        $cat_id = term_exists($category, 'category', $parent_cat_id);

        if (!$cat_id) {
            $cat_id = wp_insert_term($category, 'category', array('parent' => $parent_cat_id));
            $cat_id = $cat_id['term_id'];            
        } else {
            $cat_id = $cat_id['term_id'];
        }

    echo '<h2>Imported Videos:</h2><br><br>';
    foreach ($inventory['listings'] as $item) {

        ?>
        <pre>

            <?php print_r($item); ?>
        
        </pre>
        <?php


        $release_id = $item['release']['id'];
        $title = $item['release']['artist'] . ' - ' . $item['release']['title'];
        echo '<h4>' . $release_id . '</h4>';
        echo '<h5>' . $title . '</h5>';
        echo '-------------------------------------------<br>';
        $price = $item['price']['value'] . ' ' . $item['price']['currency'];
        $release_year = !empty($item['release']['year']) ? $item['release']['year'] : ''; 
        $description = $item['release']['description'] . '<br>' . $item['release']['label'] . '<br>' . $release_year;     
        $url = $item['uri'];
        $release_data = fetch_discogs_release($release_id);
        $image_url = $release_data['images'][0]['uri'];
        $genres = !empty($release_data['genres']) ? $release_data['genres'] : null;
        $styles = !empty($release_data['styles']) ? $release_data['styles'] : null;

        if(is_array($genres)) {
            $genre_name = $genres[0];
        } 

        $genre_id = term_exists($genre_name, 'category', $cat_id);

        if (!$genre_id) {
            $genre_id = wp_insert_term($genre_name, 'category', array('parent' => $cat_id));
            $genre_id = $genre_id['term_id'];
        } else {
            $genre_id = $genre_id['term_id'];
        }

        //Ensure each style exists as a subcategory under the genre
        $style_ids = [];
        foreach ($styles as $style) {
            $style_id = term_exists($style, 'category', $genre_id);
            if (!$style_id) {
                $style_id = wp_insert_term($style, 'category', array('parent' => $genre_id));
                $style_ids[] = $style_id['term_id'];
            } else {
                $style_ids[] = $style_id['term_id'];
            }
        }

        $tracklist_html = '';
        if (!empty($release_data['tracklist'])) {
            $tracklist_html .= '<h3>Track Listing:</h3><ul>';
            foreach ($release_data['tracklist'] as $track) {
                $track_title = $track['title'] ?? '';
                $duration = $track['duration'] ?? '';
                $tracklist_html .= "<li>{$track_title}" . ($duration ? " <em>({$duration})</em>" : "") . "</li>";
            }
            $tracklist_html .= '</ul>';
        }
        ?>

        <?php

        $existing = get_posts(array(
            'meta_key' => 'discogs_listing_id',
            'meta_value' => $item['id'],
            'post_type' => 'post',
            'post_status' => 'any',
            'numberposts' => 1,
        ));
       

        if (empty($existing)) {

            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_content' => '<p>' . ($description ?? 'No description available.') . '</p>' . $tracklist_html,
                'post_status' => 'publish',
                'post_type' => 'post',
                'post_category' => array_unique(array_merge([$parent_cat_id, $cat_id, $genre_id], $style_ids)),
                'meta_input' => array(
                    'discogs_listing_id' => $item['id'],
                    'discogs_url' => $url,
                    'discogs_price' => $price,             
                    'discogs_year' => $release_year,
                    'discogs_image_uri' => $image_url,
                ),
            ));     
            
            
          
            if ($post_id && !is_wp_error($post_id)) {                
                if ($release_data && !empty($release_data['images'])) {
                    require_once(ABSPATH . 'wp-admin/includes/media.php');
                    require_once(ABSPATH . 'wp-admin/includes/file.php');
                    require_once(ABSPATH . 'wp-admin/includes/image.php');

                    $tmp = download_url($release_data['images'][0]['uri']);
                    if (!is_wp_error($tmp)) {
                        $file_array = array(
                            'name' => basename($release_data['images'][0]['uri']),
                            'tmp_name' => $tmp,
                        );
                        $attachment_id = media_handle_sideload($file_array, $post_id);
                        if (!is_wp_error($attachment_id)) {
                            set_post_thumbnail($post_id, $attachment_id);
                        }
                    }
                }
            }
        }
    }
}
 add_action('discogs_update_posts', 'create_discogs_posts');    

 function discogs_add_admin_menu() {
    add_menu_page('Discogs Inventory', 'Discogs Inventory', 'manage_options', 'discogs-inventory', 'discogs_inventory_page','dashicons-album',  6);
}
add_action('admin_menu', 'discogs_add_admin_menu');

function discogs_inventory_page() {
    echo '<div class="wrap"><h1>Discogs Inventory</h1>
    <p>Click the button below to refresh the Discogs inventory.</p>
    <form method="post"><input type="hidden" name="discogs_refresh" value="1">
    <input type="submit" class="button button-primary" value="Refresh Inventory"></form></div>';

    if (isset($_POST['discogs_refresh'])) {
        create_discogs_posts();
        echo '<div class="updated"><p>Inventory has been refreshed.</p></div>';
    }
}

 // Shortcode to display posts with Buy button
function discogs_posts_shortcode($atts) {
    $atts = shortcode_atts(array('number' => 10), $atts);
    $args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'category_name'  => 'vinyl-records',
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
                    $year = get_post_meta(get_the_ID(), 'discogs_year', true);
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
add_shortcode('discogs_posts', 'discogs_posts_shortcode');

// Add basic styling
function discogs_styles() {
    wp_enqueue_style('discogs-styles', plugin_dir_url(__FILE__) . 'discogs-styles.css');
}
add_action('wp_enqueue_scripts', 'discogs_styles');