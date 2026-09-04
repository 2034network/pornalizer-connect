<?php
namespace Pornalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the pz_video custom post type and its taxonomies.
 * Videos are stored as WP posts so they get URLs, SEO, and search indexing.
 */
class PostType {

    const CPT      = 'pz_video';
    const TAX_CAT  = 'pz_category';
    const TAX_PERF = 'pz_performer';
    const TAX_TAG  = 'pz_tag';

    public static function register(): void {
        add_action( 'init', [ self::class, 'register_cpt' ] );
        add_action( 'init', [ self::class, 'register_taxonomies' ] );
        add_filter( 'the_content',    [ self::class, 'inject_player' ] );
        add_filter( 'post_thumbnail_html', [ self::class, 'custom_thumbnail' ], 10, 5 );
    }

    public static function register_cpt(): void {
        register_post_type( self::CPT, [
            'labels' => [
                'name'          => 'Pornalizer Videos',
                'singular_name' => 'Pornalizer Video',
                'add_new_item'  => 'Add New Video',
                'edit_item'     => 'Edit Video',
            ],
            'public'       => true,
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => get_option( 'pz_video_slug', 'videos' ) ],
            'supports'     => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
            'show_in_rest' => true,
            'menu_icon'    => 'dashicons-video-alt3',
        ] );
    }

    public static function register_taxonomies(): void {
        register_taxonomy( self::TAX_CAT, self::CPT, [
            'labels'       => [ 'name' => 'Categories', 'singular_name' => 'Category' ],
            'public'       => true,
            'hierarchical' => true,
            'rewrite'      => [ 'slug' => 'video-category' ],
            'show_in_rest' => true,
        ] );

        register_taxonomy( self::TAX_PERF, self::CPT, [
            'labels'       => [ 'name' => 'Performers', 'singular_name' => 'Performer' ],
            'public'       => true,
            'hierarchical' => false,
            'rewrite'      => [ 'slug' => 'performer' ],
            'show_in_rest' => true,
        ] );

        register_taxonomy( self::TAX_TAG, self::CPT, [
            'labels'       => [ 'name' => 'Video Tags', 'singular_name' => 'Video Tag' ],
            'public'       => true,
            'hierarchical' => false,
            'rewrite'      => [ 'slug' => 'video-tag' ],
            'show_in_rest' => true,
        ] );
    }

    /**
     * Auto-inject the embed player at the top of single video pages.
     */
    public static function inject_player( string $content ): string {
        if ( ! is_singular( self::CPT ) ) {
            return $content;
        }

        $post_id    = get_the_ID();
        $video_id   = (int) get_post_meta( $post_id, '_pz_video_id', true );
        $thumbnail  = esc_url( get_post_meta( $post_id, '_pz_thumbnail', true ) );
        $duration   = esc_attr( get_post_meta( $post_id, '_pz_duration_fmt', true ) );

        if ( ! $video_id ) {
            return $content;
        }

        ob_start();
        ?>
        <div class="pz-single-player"
             data-video-id="<?php echo esc_attr( $video_id ); ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'pz_embed_' . $video_id ) ); ?>">
            <div class="pz-player-container">
                <div class="pz-poster" style="background-image:url('<?php echo $thumbnail; ?>')">
                    <button class="pz-play-btn" aria-label="Play video">
                        <svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="40" cy="40" r="38" fill="rgba(0,0,0,.6)" stroke="#fff" stroke-width="2"/>
                            <polygon points="32,24 60,40 32,56" fill="#fff"/>
                        </svg>
                    </button>
                    <?php if ( $duration ) : ?>
                        <span class="pz-duration"><?php echo $duration; ?></span>
                    <?php endif; ?>
                </div>
                <div class="pz-embed-wrap" style="display:none">
                    <div class="pz-loading">Loading player…</div>
                </div>
            </div>
            <?php self::render_meta( $post_id ); ?>
        </div>
        <?php
        $player = ob_get_clean();

        return $player . $content;
    }

    private static function render_meta( int $post_id ): void {
        $views    = number_format( (int) get_post_meta( $post_id, '_pz_views', true ) );
        $duration = esc_html( get_post_meta( $post_id, '_pz_duration_fmt', true ) );
        $cats     = json_decode( get_post_meta( $post_id, '_pz_categories_json', true ) ?: '[]', true );
        $perfs    = json_decode( get_post_meta( $post_id, '_pz_performers_json', true ) ?: '[]', true );

        echo '<div class="pz-video-meta">';
        if ( $views ) {
            echo '<span class="pz-meta-item"><strong>' . $views . '</strong> views</span>';
        }
        if ( $duration ) {
            echo '<span class="pz-meta-item">' . $duration . '</span>';
        }
        if ( ! empty( $perfs ) ) {
            $names = array_column( $perfs, 'name' );
            echo '<span class="pz-meta-item pz-performers">Starring: ' . esc_html( implode( ', ', array_slice( $names, 0, 5 ) ) ) . '</span>';
        }
        echo '</div>';
    }

    /**
     * Replace WP featured image with the Pornalizer thumbnail when none is set.
     */
    public static function custom_thumbnail( string $html, int $post_id, $thumbnail_id, $size, $attr ): string {
        if ( $html || get_post_type( $post_id ) !== self::CPT ) {
            return $html;
        }
        $url = get_post_meta( $post_id, '_pz_thumbnail', true );
        if ( ! $url ) {
            return $html;
        }
        return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '" loading="lazy">';
    }

    /**
     * Create or update a WP post from a Pornalizer video array.
     * Returns the WP post ID.
     */
    public static function upsert( array $video ): int {
        $video_id = (int) $video['id'];

        // Find existing post by Pornalizer video ID
        $existing = get_posts( [
            'post_type'      => self::CPT,
            'meta_key'       => '_pz_video_id',
            'meta_value'     => $video_id,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ] );

        $duration_secs = (int) ( $video['duration_seconds'] ?? 0 );
        $duration_fmt  = $duration_secs ? gmdate( $duration_secs >= 3600 ? 'H:i:s' : 'i:s', $duration_secs ) : '';
        $published     = ! empty( $video['published_at'] ) ? date( 'Y-m-d H:i:s', strtotime( $video['published_at'] ) ) : '';

        $import_as   = get_option( 'pz_import_as', 'publish' );
        $post_status = ( $import_as === 'draft' || $import_as === 'schedule' ) ? 'draft' : 'publish';

        // On update never demote a published post to draft
        if ( ! empty( $existing ) ) {
            $current_status = get_post_status( $existing[0] );
            if ( $current_status === 'publish' ) {
                $post_status = 'publish';
            }
        }

        $post_data = [
            'post_title'   => wp_strip_all_tags( $video['title'] ?? '' ),
            'post_content' => wp_kses_post( $video['description'] ?? '' ),
            'post_type'    => self::CPT,
            'post_status'  => $post_status,
            'post_date'    => $published ?: current_time( 'mysql' ),
        ];

        if ( ! empty( $existing ) ) {
            $post_data['ID'] = $existing[0];
            $post_id = wp_update_post( $post_data );
        } else {
            $post_id = wp_insert_post( $post_data );
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return 0;
        }

        // Store meta
        update_post_meta( $post_id, '_pz_video_id',        $video_id );
        update_post_meta( $post_id, '_pz_thumbnail',       sanitize_url( $video['thumbnail'] ?? '' ) );
        update_post_meta( $post_id, '_pz_duration',        $duration_secs );
        update_post_meta( $post_id, '_pz_duration_fmt',    $duration_fmt );
        update_post_meta( $post_id, '_pz_views',           (int) ( $video['view_count'] ?? 0 ) );
        update_post_meta( $post_id, '_pz_rating',          (float) ( $video['rating'] ?? 0 ) );
        update_post_meta( $post_id, '_pz_categories_json', wp_json_encode( $video['categories'] ?? [] ) );
        update_post_meta( $post_id, '_pz_performers_json', wp_json_encode( $video['performers'] ?? [] ) );
        update_post_meta( $post_id, '_pz_tags_json',       wp_json_encode( $video['tags'] ?? [] ) );
        update_post_meta( $post_id, '_pz_last_synced',     time() );

        // Sync taxonomies
        self::sync_terms( $post_id, $video['categories'] ?? [], self::TAX_CAT );
        self::sync_terms( $post_id, $video['performers'] ?? [], self::TAX_PERF );
        self::sync_terms( $post_id, $video['tags'] ?? [],       self::TAX_TAG );

        return $post_id;
    }

    private static function sync_terms( int $post_id, array $items, string $taxonomy ): void {
        $term_ids = [];
        foreach ( $items as $item ) {
            $name   = sanitize_text_field( $item['name'] ?? '' );
            $slug   = sanitize_title( $item['slug'] ?? $name );
            if ( ! $name ) continue;

            $term = term_exists( $slug, $taxonomy );
            if ( ! $term ) {
                $term = wp_insert_term( $name, $taxonomy, [ 'slug' => $slug ] );
            }
            if ( ! is_wp_error( $term ) ) {
                $term_ids[] = (int) ( $term['term_id'] ?? $term );
            }
        }
        wp_set_object_terms( $post_id, $term_ids, $taxonomy );
    }

    /**
     * Delete a synced video post by Pornalizer video ID.
     */
    public static function delete( int $video_id ): void {
        $posts = get_posts( [
            'post_type'      => self::CPT,
            'meta_key'       => '_pz_video_id',
            'meta_value'     => $video_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );
        foreach ( $posts as $post_id ) {
            wp_delete_post( $post_id, true );
        }
    }
}
