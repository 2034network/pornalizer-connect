<?php
namespace Pornalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles background sync of Pornalizer videos into WP posts.
 *
 * Two modes:
 *   - Full sync:        import the first N pages from browse API
 *   - Incremental sync: use /changelog/ to get only new/updated/deleted since last run
 */
class Sync {

    const CRON_HOOK         = 'pz_sync_cron';
    const LAST_SYNC_OPTION  = 'pz_last_sync_time';
    const LAST_FULL_OPTION  = 'pz_last_full_sync';
    const LOG_OPTION        = 'pz_sync_log';

    public static function register_cron(): void {
        add_action( self::CRON_HOOK, [ self::class, 'run_incremental' ] );
    }

    public static function on_activate(): void {
        flush_rewrite_rules();

        if ( get_option( 'pz_auto_sync', '1' ) === '1' ) {
            self::schedule();
        }
    }

    public static function on_deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        flush_rewrite_rules();
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $interval = get_option( 'pz_sync_interval', 'hourly' );
            wp_schedule_event( time(), $interval, self::CRON_HOOK );
        }
    }

    public static function reschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        if ( get_option( 'pz_auto_sync', '1' ) === '1' ) {
            self::schedule();
        }
    }

    /**
     * Incremental sync via changelog — only new/updated/deleted since last run.
     * Called by cron and by the "Sync Now" admin button (AJAX).
     */
    public static function run_incremental(): array {
        $last = get_option( self::LAST_SYNC_OPTION, '' );

        // If no previous sync, fall back to last hour so we don't import everything
        if ( ! $last ) {
            $last = gmdate( 'c', strtotime( '-1 hour' ) );
        }

        $entries = Api::changelog( $last );
        $created = $updated = $deleted = 0;

        foreach ( $entries as $entry ) {
            $change_type = $entry['change_type'] ?? '';
            $video_id    = (int) ( $entry['video_id'] ?? 0 );

            if ( ! $video_id ) continue;

            if ( $change_type === 'deleted' ) {
                PostType::delete( $video_id );
                $deleted++;
                continue;
            }

            // For added/updated — fetch full video data and upsert
            $video = Api::video( $video_id );
            if ( $video ) {
                $existed = (bool) get_posts( [
                    'post_type'      => PostType::CPT,
                    'meta_key'       => '_pz_video_id',
                    'meta_value'     => $video_id,
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                ] );
                PostType::upsert( $video );
                $existed ? $updated++ : $created++;
            }
        }

        update_option( self::LAST_SYNC_OPTION, gmdate( 'c' ) );
        $log = [ 'time' => current_time( 'mysql' ), 'type' => 'incremental', 'created' => $created, 'updated' => $updated, 'deleted' => $deleted ];
        update_option( self::LOG_OPTION, $log );

        return $log;
    }

    /**
     * Full sync — import top N videos from browse API.
     * Respects configured category/orientation filters from settings.
     * Runs page by page until limit or API exhaustion.
     */
    public static function run_full(): array {
        $limit       = (int) get_option( 'pz_full_sync_limit', 500 );
        $page_size   = 100;
        $orientation = sanitize_text_field( get_option( 'pz_sync_orientation', '' ) );
        $categories  = sanitize_text_field( get_option( 'pz_sync_categories', '' ) );
        $sort        = sanitize_text_field( get_option( 'pz_sync_sort', 'published' ) );
        $source_type = sanitize_text_field( get_option( 'pz_sync_source_type', '' ) );

        $created = $updated = $total = 0;
        $page    = 1;

        do {
            $args = array_filter( [
                'page'        => $page,
                'page_size'   => $page_size,
                'sort'        => $sort,
                'orientation' => $orientation,
                'categories'  => $categories,
                'source_type' => $source_type,
            ] );

            $result  = Api::browse( $args );
            $videos  = $result['results'] ?? [];
            $has_more = ! empty( $result['next'] );

            foreach ( $videos as $video ) {
                if ( $total >= $limit ) break 2;

                $existed = (bool) get_posts( [
                    'post_type'      => PostType::CPT,
                    'meta_key'       => '_pz_video_id',
                    'meta_value'     => (int) $video['id'],
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                ] );

                $post_id = PostType::upsert( $video );
                if ( $post_id ) {
                    $existed ? $updated++ : $created++;
                    $total++;
                }
            }

            $page++;

        } while ( $has_more && ! empty( $videos ) && $total < $limit );

        update_option( self::LAST_SYNC_OPTION, gmdate( 'c' ) );
        update_option( self::LAST_FULL_OPTION, current_time( 'mysql' ) );

        $log = [ 'time' => current_time( 'mysql' ), 'type' => 'full', 'created' => $created, 'updated' => $updated, 'total' => $total ];
        update_option( self::LOG_OPTION, $log );

        return $log;
    }
}
