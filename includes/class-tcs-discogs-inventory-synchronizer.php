<?php
defined('ABSPATH') || exit;

/** Owns discogs inventory synchronizer behavior; legacy function entry points delegate here. */
class TCS_Discogs_Inventory_Synchronizer {
    private $client;

    public function __construct(TCS_Discogs_Client $client) {
        $this->client = $client;
    }

    public function synchronize() {
        $inventory = $this->client->inventory();

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
        $category = 'Vinyl Records';
        $parent_category = 'Collectibles';

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

        echo '<h2>Imported Discogs:</h2><br><br>';
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

            $release_year = !empty($item['release']['year'])
                    ? sanitize_text_field(
                    (string) $item['release']['year']
                )
                : 'Unknown';

            $seller_notes = isset($item['comments'])
                && is_scalar($item['comments'])
                    ? sanitize_textarea_field(
                        (string) $item['comments']
                    )
                    : '';

            $description = $item['release']['description'] . '<br>' . $seller_notes .'<br>' . $item['release']['label'] . '<br>' . $release_year;
            $url = $item['uri'];
            $release_data = $this->client->release($release_id);
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

                $listing_id = (string) $item['id'];

                $existing = get_posts([
                    'post_type'      => 'post',
                    'post_status'    => 'any',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => [
                        [
                            'key'     => 'discogs_listing_id',
                            'value'   => $listing_id,
                            'compare' => '=',
                        ],
                    ],
                ]);

                $post_data = [
                    'post_title'   => $title,
                    'post_content' =>
                        '<p>' .
                        ($description ?: 'No description available.') .
                        '</p>' .
                        $tracklist_html,
                    'post_status'  => 'publish',
                    'post_type'    => 'post',
                    'post_category' => array_values(
                        array_unique(
                            array_map(
                                'absint',
                                array_merge(
                                    [$parent_cat_id, $cat_id, $genre_id],
                                    $style_ids
                                )
                            )
                        )
                    ),
                ];

                $is_new_post = empty($existing);

                if ($is_new_post) {
                    /*
                    * Create a post for a listing that has not been imported before.
                    */
                    $post_id = wp_insert_post(
                        $post_data,
                        true
                    );
                } else {
                    /*
                    * Update the existing post whenever the Discogs inventory is refreshed.
                    */
                    $post_id = absint($existing[0]);

                    $post_data['ID'] = $post_id;

                    $post_id = wp_update_post(
                        $post_data,
                        true
                    );
                }

                if (is_wp_error($post_id)) {
                    error_log(
                        sprintf(
                            'Discogs post save failed for listing %s: %s',
                            $listing_id,
                            $post_id->get_error_message()
                        )
                    );

                    continue;
                }

                /*
                * These values must be updated for both new and existing posts.
                */
                update_post_meta(
                    $post_id,
                    'discogs_listing_id',
                    $listing_id
                );

                update_post_meta(
                    $post_id,
                    'discogs_url',
                    esc_url_raw($url)
                );

                update_post_meta(
                    $post_id,
                    'discogs_price',
                    $price
                );

                update_post_meta(
                    $post_id,
                    'discogs_year',
                    $release_year
                );

                update_post_meta(
                    $post_id,
                    'discogs_image_uri',
                    esc_url_raw($image_url)
                );

                error_log(
                    sprintf(
                        'Discogs listing %s: %s post %d with price %s',
                        $listing_id,
                        $is_new_post ? 'created' : 'updated',
                        $post_id,
                        $price
                    )
                );

                /*
                * Download the image for a new post, or when the Discogs image URL changed.
                */
                $stored_image_url = get_post_meta(
                    $post_id,
                    '_discogs_downloaded_image_uri',
                    true
                );

                $should_update_image =
                    !empty($image_url) &&
                    (
                        !has_post_thumbnail($post_id) ||
                        $stored_image_url !== $image_url
                    );

                if ($should_update_image) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    $tmp_file = download_url(
                        $image_url,
                        20
                    );

                    if (is_wp_error($tmp_file)) {
                        error_log(
                            sprintf(
                                'Discogs image download failed for post %d: %s',
                                $post_id,
                                $tmp_file->get_error_message()
                            )
                        );
                    } else {
                        $image_path = wp_parse_url(
                            $image_url,
                            PHP_URL_PATH
                        );

                        $filename = $image_path
                            ? basename($image_path)
                            : 'discogs-' . $release_id . '.jpg';

                        $file_array = [
                            'name'     => sanitize_file_name($filename),
                            'tmp_name' => $tmp_file,
                        ];

                        $attachment_id = media_handle_sideload(
                            $file_array,
                            $post_id,
                            $title
                        );

                        if (is_wp_error($attachment_id)) {
                            @unlink($tmp_file);

                            error_log(
                                sprintf(
                                    'Discogs image attachment failed for post %d: %s',
                                    $post_id,
                                    $attachment_id->get_error_message()
                                )
                            );
                        } else {
                            $old_thumbnail_id = get_post_thumbnail_id(
                                $post_id
                            );

                            set_post_thumbnail(
                                $post_id,
                                $attachment_id
                            );

                            update_post_meta(
                                $post_id,
                                '_discogs_downloaded_image_uri',
                                esc_url_raw($image_url)
                            );

                            /*
                            * Remove the replaced thumbnail after the new image succeeds.
                            */
                            if (
                                $old_thumbnail_id &&
                                $old_thumbnail_id !== $attachment_id
                            ) {
                                wp_delete_attachment(
                                    $old_thumbnail_id,
                                    true
                                );
                            }
                        }
                    }
                }
        }
    }

}
