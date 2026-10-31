<?php

/*
 Plugin Name: Discogs Seller Posts
 Description: Fetches Discogs seller inventory and creates WordPress posts.
 Version: 1.0
 Author: Peter Giammarco
 */

require_once __DIR__ . '/includes/class-tcs-discogs-client.php';
require_once __DIR__ . '/includes/class-tcs-discogs-inventory-synchronizer.php';
require_once __DIR__ . '/includes/class-tcs-discogs-controller.php';

function tcs_discogs_client(): TCS_Discogs_Client {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Discogs_Client();
    }
    return $instance;
}

function tcs_discogs_synchronizer(): TCS_Discogs_Inventory_Synchronizer {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Discogs_Inventory_Synchronizer(tcs_discogs_client());
    }
    return $instance;
}

function tcs_discogs_controller(): TCS_Discogs_Controller {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Discogs_Controller(tcs_discogs_synchronizer());
    }
    return $instance;
}



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
    return tcs_discogs_client()->inventory();
}


// Fetch release data including images, genres, and styles
function fetch_discogs_release($release_id) {
    return tcs_discogs_client()->release($release_id);
}


function create_discogs_posts() {
    return tcs_discogs_synchronizer()->synchronize();
}
 add_action('discogs_update_posts', 'create_discogs_posts');    

 function discogs_add_admin_menu() {
    return tcs_discogs_controller()->register_menu();
}
add_action('admin_menu', 'discogs_add_admin_menu');

function discogs_inventory_page() {
    return tcs_discogs_controller()->render_admin();
}

 // Shortcode to display posts with Buy button
function discogs_posts_shortcode($atts) {
    return tcs_discogs_controller()->render_shortcode($atts);
}
add_shortcode('discogs_posts', 'discogs_posts_shortcode');

// Add basic styling
function discogs_styles() {
    wp_enqueue_style('discogs-styles', plugin_dir_url(__FILE__) . 'discogs-styles.css');
}
add_action('wp_enqueue_scripts', 'discogs_styles');