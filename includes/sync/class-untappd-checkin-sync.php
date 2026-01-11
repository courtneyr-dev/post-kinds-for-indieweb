<?php
/**
 * Untappd Checkin Sync
 *
 * Handles bidirectional sync of beer checkins with Untappd.
 *
 * @package Reactions_For_IndieWeb
 * @since 1.0.0
 */

namespace ReactionsForIndieWeb\Sync;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Untappd checkin sync class.
 */
class Untappd_Checkin_Sync extends Checkin_Sync_Base {

    /**
     * Service identifier.
     *
     * @var string
     */
    protected string $service_id = 'untappd';

    /**
     * Service display name.
     *
     * @var string
     */
    protected string $service_name = 'Untappd';

    /**
     * API base URL.
     *
     * @var string
     */
    private const API_BASE = 'https://api.untappd.com/v4';

    /**
     * Register REST API routes.
     *
     * @return void
     */
    public function register_routes(): void {
        // OAuth is handled via admin-post.php, not REST routes.
        // No additional REST routes needed for Untappd.
    }

    /**
     * Check if service is connected.
     *
     * @return bool True if connected.
     */
    public function is_connected(): bool {
        $credentials = $this->get_credentials();
        return ! empty( $credentials['access_token'] );
    }

    /**
     * Get OAuth authorization URL.
     *
     * @return string Authorization URL.
     */
    public function get_auth_url(): string {
        $credentials = $this->get_credentials();
        $redirect_uri = admin_url( 'admin-post.php?action=reactions_untappd_oauth' );

        return add_query_arg( array(
            'client_id'     => $credentials['client_id'] ?? '',
            'response_type' => 'code',
            'redirect_url'  => $redirect_uri,
        ), 'https://untappd.com/oauth/authenticate' );
    }

    /**
     * Handle OAuth callback.
     *
     * @param string $code Authorization code.
     * @return bool True on success.
     */
    public function handle_oauth_callback( string $code ): bool {
        $credentials = $this->get_credentials();
        $redirect_uri = admin_url( 'admin-post.php?action=reactions_untappd_oauth' );

        $response = wp_remote_get( add_query_arg( array(
            'client_id'     => $credentials['client_id'] ?? '',
            'client_secret' => $credentials['client_secret'] ?? '',
            'response_type' => 'code',
            'redirect_url'  => $redirect_uri,
            'code'          => $code,
        ), 'https://untappd.com/oauth/authorize' ), array(
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['response']['access_token'] ) ) {
            return false;
        }

        // Save the token.
        $all_credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        $all_credentials['untappd']['access_token'] = $body['response']['access_token'];

        // Get user info.
        $user_info = $this->api_request( '/user/info', array(), $body['response']['access_token'] );
        if ( ! is_wp_error( $user_info ) && ! empty( $user_info['response']['user']['user_name'] ) ) {
            $all_credentials['untappd']['username'] = $user_info['response']['user']['user_name'];
        }

        update_option( 'reactions_indieweb_api_credentials', $all_credentials );

        return true;
    }

    /**
     * Syndicate a checkin to Untappd (POSSE).
     *
     * @param int                  $post_id      Post ID.
     * @param array<string, mixed> $checkin_data Checkin data.
     * @return string|false External ID on success, false on failure.
     */
    protected function syndicate_checkin( int $post_id, array $checkin_data ) {
        $credentials = $this->get_credentials();

        if ( empty( $credentials['access_token'] ) ) {
            return false;
        }

        // Need beer ID to check in on Untappd.
        $beer_id = get_post_meta( $post_id, '_reactions_untappd_beer_id', true );
        if ( empty( $beer_id ) ) {
            // Try to find beer by name.
            $beer_name = get_post_meta( $post_id, '_reactions_beer_name', true );
            if ( ! empty( $beer_name ) ) {
                $search = $this->api_request( '/search/beer', array(
                    'q'     => $beer_name,
                    'limit' => 1,
                ) );

                if ( ! is_wp_error( $search ) && ! empty( $search['response']['beers']['items'][0]['beer']['bid'] ) ) {
                    $beer_id = $search['response']['beers']['items'][0]['beer']['bid'];
                }
            }
        }

        if ( empty( $beer_id ) ) {
            return false;
        }

        // Get timezone offset.
        $gmt_offset = get_option( 'gmt_offset', 0 );
        $timezone   = wp_timezone_string();

        $params = array(
            'gmt_offset' => $gmt_offset,
            'timezone'   => $timezone,
            'bid'        => $beer_id,
        );

        // Add optional parameters.
        $rating = get_post_meta( $post_id, '_reactions_rating', true );
        if ( ! empty( $rating ) ) {
            // Untappd uses 0-5 scale with 0.25 increments.
            $params['rating'] = min( 5, max( 0, floatval( $rating ) ) );
        }

        $shout = get_the_content( null, false, $post_id );
        if ( ! empty( $shout ) ) {
            $params['shout'] = wp_strip_all_tags( $shout );
        }

        // Venue (Foursquare ID).
        $foursquare_id = get_post_meta( $post_id, '_reactions_foursquare_id', true );
        if ( ! empty( $foursquare_id ) ) {
            $params['foursquare_id'] = $foursquare_id;
        }

        $response = $this->api_request( '/checkin/add', $params, null, 'POST' );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $checkin_id = $response['response']['checkin']['checkin_id'] ?? null;

        if ( $checkin_id ) {
            update_post_meta( $post_id, '_reactions_untappd_checkin_id', $checkin_id );
            return (string) $checkin_id;
        }

        return false;
    }

    /**
     * Import a checkin from Untappd (PESOS).
     *
     * @param array<string, mixed> $external_checkin External checkin data.
     * @return int|false Post ID on success, false on failure.
     */
    protected function import_checkin( array $external_checkin ) {
        $checkin_id = $external_checkin['checkin_id'] ?? '';

        if ( empty( $checkin_id ) ) {
            return false;
        }

        // Check for duplicate.
        if ( $this->find_existing_post( $checkin_id ) ) {
            return false;
        }

        $settings = get_option( 'reactions_indieweb_settings', array() );
        $post_status = $settings['checkin_import_status'] ?? 'publish';

        // Build post content.
        $content = '';
        if ( ! empty( $external_checkin['checkin_comment'] ) ) {
            $content = sanitize_text_field( $external_checkin['checkin_comment'] );
        }

        // Create post title.
        $beer_name = $external_checkin['beer']['beer_name'] ?? 'Unknown Beer';
        $brewery_name = $external_checkin['brewery']['brewery_name'] ?? '';
        $title = $beer_name;
        if ( $brewery_name ) {
            $title .= ' by ' . $brewery_name;
        }

        $post_data = array(
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => $content,
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( $external_checkin['created_at'] ?? 'now' ) ),
        );

        $post_id = wp_insert_post( $post_data );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return false;
        }

        // Set post kind.
        wp_set_object_terms( $post_id, 'checkin', 'kind' );

        // Save meta.
        update_post_meta( $post_id, '_reactions_kind', 'checkin' );
        update_post_meta( $post_id, '_reactions_untappd_checkin_id', $checkin_id );
        update_post_meta( $post_id, '_reactions_imported_from', 'untappd' );

        // Beer info.
        if ( ! empty( $external_checkin['beer'] ) ) {
            update_post_meta( $post_id, '_reactions_beer_name', $external_checkin['beer']['beer_name'] ?? '' );
            update_post_meta( $post_id, '_reactions_untappd_beer_id', $external_checkin['beer']['bid'] ?? '' );
            update_post_meta( $post_id, '_reactions_beer_style', $external_checkin['beer']['beer_style'] ?? '' );
            update_post_meta( $post_id, '_reactions_beer_abv', $external_checkin['beer']['beer_abv'] ?? '' );

            if ( ! empty( $external_checkin['beer']['beer_label'] ) ) {
                update_post_meta( $post_id, '_reactions_beer_image', $external_checkin['beer']['beer_label'] );
            }
        }

        // Brewery info.
        if ( ! empty( $external_checkin['brewery'] ) ) {
            update_post_meta( $post_id, '_reactions_brewery_name', $external_checkin['brewery']['brewery_name'] ?? '' );
            update_post_meta( $post_id, '_reactions_brewery_id', $external_checkin['brewery']['brewery_id'] ?? '' );
            update_post_meta( $post_id, '_reactions_brewery_country', $external_checkin['brewery']['country_name'] ?? '' );
        }

        // Rating.
        if ( ! empty( $external_checkin['rating_score'] ) ) {
            update_post_meta( $post_id, '_reactions_rating', $external_checkin['rating_score'] );
        }

        // Venue info.
        if ( ! empty( $external_checkin['venue'] ) ) {
            $venue = $external_checkin['venue'];
            update_post_meta( $post_id, '_reactions_checkin_name', $venue['venue_name'] ?? '' );

            if ( ! empty( $venue['location'] ) ) {
                update_post_meta( $post_id, '_reactions_geo_latitude', $venue['location']['lat'] ?? '' );
                update_post_meta( $post_id, '_reactions_geo_longitude', $venue['location']['lng'] ?? '' );
                update_post_meta( $post_id, '_reactions_checkin_locality', $venue['location']['venue_city'] ?? '' );
                update_post_meta( $post_id, '_reactions_checkin_region', $venue['location']['venue_state'] ?? '' );
                update_post_meta( $post_id, '_reactions_checkin_country', $venue['location']['venue_country'] ?? '' );
            }

            if ( ! empty( $venue['foursquare']['foursquare_id'] ) ) {
                update_post_meta( $post_id, '_reactions_foursquare_id', $venue['foursquare']['foursquare_id'] );
            }
        }

        return $post_id;
    }

    /**
     * Fetch recent checkins from Untappd.
     *
     * @param int $limit Maximum number of checkins.
     * @return array<int, array<string, mixed>> Checkins array (empty if not connected or on error).
     */
    public function fetch_recent_checkins( int $limit = 50 ): array {
        $credentials = $this->get_credentials();

        if ( empty( $credentials['access_token'] ) ) {
            return array();
        }

        $username = $credentials['username'] ?? '';
        if ( empty( $username ) ) {
            // Get from API.
            $user_info = $this->api_request( '/user/info' );
            if ( ! is_wp_error( $user_info ) ) {
                $username = $user_info['response']['user']['user_name'] ?? '';
            }
        }

        if ( empty( $username ) ) {
            return array();
        }

        $checkins = array();
        $max_id   = null;

        // Untappd returns max 50 per request.
        while ( count( $checkins ) < $limit ) {
            $params = array(
                'limit' => min( 50, $limit - count( $checkins ) ),
            );

            if ( $max_id ) {
                $params['max_id'] = $max_id;
            }

            $response = $this->api_request( "/user/checkins/{$username}", $params );

            if ( is_wp_error( $response ) ) {
                break;
            }

            $items = $response['response']['checkins']['items'] ?? array();

            if ( empty( $items ) ) {
                break;
            }

            foreach ( $items as $item ) {
                $checkins[] = $this->normalize_checkin( $item );
                $max_id = $item['checkin_id'];
            }

            // If we got fewer than requested, we're at the end.
            if ( count( $items ) < 50 ) {
                break;
            }
        }

        return $checkins;
    }

    /**
     * Normalize a checkin from Untappd format.
     *
     * @param array<string, mixed> $checkin Raw checkin data.
     * @return array<string, mixed> Normalized data.
     */
    private function normalize_checkin( array $checkin ): array {
        $normalized = array(
            'checkin_id'      => $checkin['checkin_id'] ?? '',
            'checkin_comment' => $checkin['checkin_comment'] ?? '',
            'rating_score'    => $checkin['rating_score'] ?? 0,
            'created_at'      => $checkin['created_at'] ?? '',
            'timestamp'       => strtotime( $checkin['created_at'] ?? 'now' ),
        );

        // Beer info.
        if ( ! empty( $checkin['beer'] ) ) {
            $normalized['beer'] = $checkin['beer'];
            $normalized['beer_name'] = $checkin['beer']['beer_name'] ?? '';
        }

        // Brewery info.
        if ( ! empty( $checkin['brewery'] ) ) {
            $normalized['brewery'] = $checkin['brewery'];
            $normalized['brewery_name'] = $checkin['brewery']['brewery_name'] ?? '';
        }

        // Venue info.
        if ( ! empty( $checkin['venue'] ) ) {
            $normalized['venue'] = $checkin['venue'];
            $normalized['venue_name'] = $checkin['venue']['venue_name'] ?? '';
        }

        return $normalized;
    }

    /**
     * Make an API request to Untappd.
     *
     * @param string               $endpoint     API endpoint.
     * @param array<string, mixed> $params       Request parameters.
     * @param string|null          $access_token Override access token.
     * @param string               $method       HTTP method.
     * @return array<string, mixed>|\WP_Error Response or error.
     */
    private function api_request( string $endpoint, array $params = array(), ?string $access_token = null, string $method = 'GET' ) {
        $credentials = $this->get_credentials();
        $token = $access_token ?? ( $credentials['access_token'] ?? '' );

        if ( empty( $token ) ) {
            return new \WP_Error( 'no_token', __( 'No access token available.', 'reactions-indieweb' ) );
        }

        $params['access_token'] = $token;

        $url = self::API_BASE . $endpoint;

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'Reactions for IndieWeb WordPress Plugin',
            ),
        );

        if ( 'GET' === $method ) {
            $url = add_query_arg( $params, $url );
        } else {
            $args['method'] = $method;
            $args['body']   = $params;
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $error_message = $body['meta']['error_detail'] ?? __( 'API request failed.', 'reactions-indieweb' );
            return new \WP_Error( 'api_error', $error_message );
        }

        return $body;
    }

    /**
     * Get API credentials.
     *
     * @return array<string, mixed> Credentials.
     */
    private function get_credentials(): array {
        $all_credentials = get_option( 'reactions_indieweb_api_credentials', array() );
        return $all_credentials['untappd'] ?? array();
    }
}
