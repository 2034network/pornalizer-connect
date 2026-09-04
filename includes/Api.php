<?php
namespace Pornalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Pornalizer REST API.
 * All public endpoints — no API key required.
 * Responses cached with WP transients (default 60 s, configurable).
 */
class Api {

    private static function base(): string {
        return rtrim( get_option( 'pz_api_url', PZ_API_BASE ), '/' );
    }

    private static function cache_ttl(): int {
        return max( 30, (int) get_option( 'pz_cache_ttl', 60 ) );
    }

    /**
     * GET request with transient cache.
     *
     * @param  string $path   e.g. '/database/browse/'
     * @param  array  $params Query params
     * @return array|WP_Error Decoded JSON body
     */
    public static function get( string $path, array $params = [] ) {
        $url      = self::base() . $path;
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }

        $cache_key = 'pz_' . md5( $url );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get( $url, [
            'timeout'    => 10,
            'user-agent' => 'PornalizerConnect/' . PZ_VERSION . '; ' . get_bloginfo( 'url' ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new \WP_Error( 'pz_api', "API returned HTTP {$code}", [ 'url' => $url ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'pz_api_parse', 'Invalid JSON from API' );
        }

        set_transient( $cache_key, $body, self::cache_ttl() );
        return $body;
    }

    /**
     * POST request (no caching — used for embed URL retrieval and tracking).
     */
    public static function post( string $path, array $body = [] ) {
        $url = self::base() . $path;

        $response = wp_remote_post( $url, [
            'timeout'    => 10,
            'headers'    => [ 'Content-Type' => 'application/json' ],
            'body'       => wp_json_encode( $body ),
            'user-agent' => 'PornalizerConnect/' . PZ_VERSION . '; ' . get_bloginfo( 'url' ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $msg = isset( $data['error'] ) ? $data['error'] : "HTTP {$code}";
            return new \WP_Error( 'pz_api', $msg );
        }

        return is_array( $data ) ? $data : [];
    }

    /**
     * Fetch a page of videos from the browse endpoint.
     */
    public static function browse( array $args = [] ): array {
        $allowed = [
            'categories', 'tags', 'performers', 'search',
            'sort', 'page', 'page_size', 'orientation',
            'content_type', 'source_type',
        ];
        $params = array_intersect_key( $args, array_flip( $allowed ) );
        $params['page_size'] = min( (int) ( $params['page_size'] ?? 12 ), 100 );

        $result = self::get( '/database/browse/', $params );
        if ( is_wp_error( $result ) ) {
            return [ 'results' => [], 'count' => 0, 'next' => null, 'previous' => null ];
        }
        return $result;
    }

    /**
     * Fetch a single video's metadata.
     */
    public static function video( int $video_id ): ?array {
        $result = self::get( "/videos/{$video_id}/" );
        return is_wp_error( $result ) ? null : $result;
    }

    /**
     * Get the embed player URL for a video.
     * Returns [ 'player_url' => '...', 'embed_code' => '...', 'title' => '...' ]
     */
    public static function embed( int $video_id ): ?array {
        $result = self::post( "/videos/{$video_id}/get-embed/" );
        return is_wp_error( $result ) ? null : $result;
    }

    /**
     * Fetch metadata (categories / tags / performers lists).
     */
    public static function metadata( string $type = 'all', int $limit = 2000 ): array {
        $result = self::get( '/database/metadata/', [ 'type' => $type, 'limit' => $limit ] );
        return is_wp_error( $result ) ? [] : $result;
    }

    /**
     * Fetch changelog entries since a given ISO-8601 timestamp.
     */
    public static function changelog( string $since ): array {
        $result = self::get( '/changelog/', [ 'since' => $since ] );
        return is_wp_error( $result ) ? [] : ( $result['results'] ?? [] );
    }
}
