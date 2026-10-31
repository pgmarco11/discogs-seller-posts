<?php
defined('ABSPATH') || exit;

/** Owns discogs client behavior; legacy function entry points delegate here. */
class TCS_Discogs_Client {
    public function inventory(){
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

    public function release($release_id) {

        global $env_discog;

        $token = $env_discog['DISCOGS_TOKEN'] ?? '';

        if (empty($token)) {
            error_log('Discogs Release API: Missing DISCOGS_TOKEN.');
            return false;
        }

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

}
