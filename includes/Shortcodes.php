<?php
namespace Pornalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes:
 *
 *   [pornalizer_videos]              — live grid from API (with pagination + AJAX lightbox)
 *   [pornalizer_video id="12345"]    — single video embed (immediate or click-to-load)
 *   [pornalizer_search]              — AJAX search bar
 *
 * Shortcode attributes for [pornalizer_videos]:
 *   category      — comma-separated category slugs
 *   performer     — comma-separated performer slugs
 *   tag           — comma-separated tag slugs
 *   search        — keyword search
 *   sort          — published|views|likes  (default: published)
 *   orientation   — straight|gay|trans|bi  (default: straight)
 *   count         — videos per page        (default: 12, max 100)
 *   columns       — grid columns           (default: 3)
 *   show_title    — true|false             (default: true)
 *   show_duration — true|false             (default: true)
 *   show_views    — true|false             (default: false)
 *   link_to       — lightbox|page|none     (default: lightbox)
 *   autoplay      — true|false             (default: false)
 */
class Shortcodes {

    public static function register(): void {
        add_shortcode( 'pornalizer_videos', [ self::class, 'videos_grid' ] );
        add_shortcode( 'pornalizer_video',  [ self::class, 'single_video' ] );
        add_shortcode( 'pornalizer_search', [ self::class, 'search_bar' ] );

        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'wp_head',            [ self::class, 'schema_for_video_page' ] );

        // AJAX: get embed URL (public — works for logged-out users)
        add_action( 'wp_ajax_pz_get_embed',        [ self::class, 'ajax_get_embed' ] );
        add_action( 'wp_ajax_nopriv_pz_get_embed', [ self::class, 'ajax_get_embed' ] );

        // AJAX: paginated browse + infinite scroll
        add_action( 'wp_ajax_pz_browse',        [ self::class, 'ajax_browse' ] );
        add_action( 'wp_ajax_nopriv_pz_browse', [ self::class, 'ajax_browse' ] );

        // AJAX: view tracking postback → Pornalizer /api/track/view/
        add_action( 'wp_ajax_pz_track_view',        [ self::class, 'ajax_track_view' ] );
        add_action( 'wp_ajax_nopriv_pz_track_view', [ self::class, 'ajax_track_view' ] );
    }

    /**
     * Inject VideoObject Schema.org JSON-LD on single pz_video pages.
     * Google requires this for video rich results.
     */
    public static function schema_for_video_page(): void {
        if ( ! is_singular( PostType::CPT ) ) return;

        $post_id   = get_the_ID();
        $video_id  = (int) get_post_meta( $post_id, '_pz_video_id', true );
        if ( ! $video_id ) return;

        $title     = get_the_title( $post_id );
        $thumb     = get_post_meta( $post_id, '_pz_thumbnail', true );
        $dur_secs  = (int) get_post_meta( $post_id, '_pz_duration', true );
        $published = get_post_field( 'post_date', $post_id );
        $views     = (int) get_post_meta( $post_id, '_pz_views', true );

        // Description: use raw post_content (never run through content filters),
        // fall back to constructing one from taxonomy meta stored at sync time.
        $desc = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
        if ( strlen( $desc ) < 30 ) {
            $cats  = json_decode( get_post_meta( $post_id, '_pz_categories_json', true ) ?: '[]', true );
            $perfs = json_decode( get_post_meta( $post_id, '_pz_performers_json', true ) ?: '[]', true );
            $cat_names  = array_slice( array_column( $cats,  'name' ), 0, 3 );
            $perf_names = array_slice( array_column( $perfs, 'name' ), 0, 2 );
            $site = get_bloginfo( 'name' );
            if ( $perf_names && $cat_names ) {
                $desc = implode( ' and ', $perf_names ) . ' in a ' . implode( ', ', $cat_names ) . ' scene on ' . $site . '.';
            } elseif ( $perf_names ) {
                $desc = implode( ' and ', $perf_names ) . ' in a hot scene on ' . $site . '.';
            } elseif ( $cat_names ) {
                $desc = 'Watch this ' . implode( ', ', $cat_names ) . ' scene on ' . $site . '.';
            } else {
                $desc = 'Watch ' . $title . ' on ' . $site . '. Free HD porn video.';
            }
        }
        $desc = mb_substr( $desc, 0, 320 );

        // embedUrl: strip /api suffix properly — rtrim strips characters, not strings.
        $api_root  = preg_replace( '#/api/?$#', '', rtrim( get_option( 'pz_api_url', PZ_API_BASE ), '/' ) );
        $embed_url = $api_root . '/player/embed/' . $video_id . '/';

        // ISO 8601 duration: PT#M#S
        $iso_dur = '';
        if ( $dur_secs ) {
            $h = intdiv( $dur_secs, 3600 );
            $m = intdiv( $dur_secs % 3600, 60 );
            $s = $dur_secs % 60;
            $iso_dur = 'PT' . ( $h ? $h . 'H' : '' ) . ( $m ? $m . 'M' : '' ) . $s . 'S';
        }

        $schema = [
            '@context'     => 'https://schema.org',
            '@type'        => 'VideoObject',
            '@id'          => get_permalink( $post_id ) . '#videoobject',
            'name'         => $title,
            'description'  => $desc,
            'thumbnailUrl' => [ $thumb ],
            'uploadDate'   => date( 'c', strtotime( $published ) ),
            'contentUrl'   => get_permalink( $post_id ),
            'embedUrl'     => esc_url( $embed_url ),
        ];
        if ( $iso_dur ) $schema['duration']      = $iso_dur;
        if ( $views )   $schema['interactionStatistic'] = [
            '@type'            => 'InteractionCounter',
            'interactionType'  => 'https://schema.org/WatchAction',
            'userInteractionCount' => $views,
        ];

        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    public static function enqueue(): void {
        wp_enqueue_style(
            'pz-frontend',
            PZ_PLUGIN_URL . 'assets/frontend.css',
            [],
            PZ_VERSION
        );
        wp_enqueue_script(
            'pz-frontend',
            PZ_PLUGIN_URL . 'assets/frontend.js',
            [ 'jquery' ],
            PZ_VERSION,
            true
        );
        wp_localize_script( 'pz-frontend', 'pzConfig', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'pz_public' ),
            'playerUrl' => rtrim( get_option( 'pz_api_url', PZ_API_BASE ), '/api' ),
        ] );
    }

    // ── [pornalizer_videos] ────────────────────────────────────────────────

    public static function videos_grid( $atts ): string {
        $atts = shortcode_atts( [
            'category'      => '',
            'performer'     => '',
            'tag'           => '',
            'search'        => '',
            'sort'          => 'published',
            'orientation'   => 'straight',
            'count'         => 12,
            'columns'       => 3,
            'show_title'    => 'true',
            'show_duration' => 'true',
            'show_views'    => 'false',
            'link_to'       => 'lightbox',
            'infinite'      => 'false',
            'page'          => 1,
        ], $atts, 'pornalizer_videos' );

        // Sanitize
        $args = [
            'categories'  => sanitize_text_field( $atts['category'] ),
            'performers'  => sanitize_text_field( $atts['performer'] ),
            'tags'        => sanitize_text_field( $atts['tag'] ),
            'search'      => sanitize_text_field( $atts['search'] ),
            'sort'        => in_array( $atts['sort'], [ 'published', 'views', 'likes', 'trending' ] ) ? $atts['sort'] : 'published',
            'orientation' => in_array( $atts['orientation'], [ 'straight', 'gay', 'trans', 'bi', 'mixed' ] ) ? $atts['orientation'] : 'straight',
            'page_size'   => min( absint( $atts['count'] ), 100 ),
            'page'        => max( 1, absint( $atts['page'] ) ),
        ];
        $args = array_filter( $args );

        $columns      = min( max( absint( $atts['columns'] ), 1 ), 6 );
        $show_title   = $atts['show_title']    !== 'false';
        $show_dur     = $atts['show_duration'] !== 'false';
        $show_views   = $atts['show_views']    === 'true';
        $link_to      = in_array( $atts['link_to'], [ 'lightbox', 'page', 'none' ] ) ? $atts['link_to'] : 'lightbox';

        $result = Api::browse( $args );
        $videos = $result['results'] ?? [];
        $total  = $result['count']   ?? 0;
        $pages  = $total > 0 ? (int) ceil( $total / $args['page_size'] ) : 0;

        if ( empty( $videos ) ) {
            return '<p class="pz-no-videos">No videos found.</p>';
        }

        // Encode args for AJAX pagination
        $encoded_args = esc_attr( wp_json_encode( array_merge( $args, [
            'columns'    => $columns,
            'show_title' => $show_title,
            'show_dur'   => $show_dur,
            'show_views' => $show_views,
            'link_to'    => $link_to,
        ] ) ) );

        ob_start();
        $infinite = $atts['infinite'] === 'true';
        echo '<div class="pz-grid-wrap" data-args="' . $encoded_args . '"' . ( $infinite ? ' data-infinite="1"' : '' ) . '>';
        echo '<div class="pz-grid pz-cols-' . $columns . '">';

        foreach ( $videos as $video ) {
            self::render_card( $video, $link_to, $show_title, $show_dur, $show_views );
        }

        echo '</div>';

        // Pagination
        if ( $pages > 1 ) {
            $current_page = (int) $args['page'];
            echo '<div class="pz-pagination" data-total-pages="' . esc_attr( $pages ) . '" data-current="' . esc_attr( $current_page ) . '">';
            if ( $current_page > 1 ) {
                echo '<button class="pz-page-btn pz-prev" data-page="' . esc_attr( $current_page - 1 ) . '">&laquo; Previous</button>';
            }
            echo '<span class="pz-page-info">Page ' . esc_html( $current_page ) . ' of ' . esc_html( $pages ) . '</span>';
            if ( $current_page < $pages ) {
                echo '<button class="pz-page-btn pz-next" data-page="' . esc_attr( $current_page + 1 ) . '">Next &raquo;</button>';
            }
            echo '</div>';
        }

        echo '</div>';

        // Lightbox markup (hidden, filled by JS)
        echo '<div class="pz-lightbox" role="dialog" aria-modal="true" style="display:none">
            <div class="pz-lightbox-overlay"></div>
            <div class="pz-lightbox-box">
                <button class="pz-lightbox-close" aria-label="Close">&times;</button>
                <div class="pz-lightbox-title"></div>
                <div class="pz-lightbox-player"></div>
            </div>
        </div>';

        return ob_get_clean();
    }

    private static function render_card( array $video, string $link_to, bool $show_title, bool $show_dur, bool $show_views ): void {
        $video_id  = (int) $video['id'];
        $title     = esc_html( $video['title'] ?? '' );
        $thumb     = esc_url( $video['thumbnail'] ?? '' );
        $dur_secs  = (int) ( $video['duration_seconds'] ?? 0 );
        $dur_fmt   = $dur_secs ? gmdate( $dur_secs >= 3600 ? 'H:i:s' : 'i:s', $dur_secs ) : '';
        $views     = number_format( (int) ( $video['view_count'] ?? 0 ) );

        // Find WP post for this video (for page link mode)
        $wp_url = '';
        if ( $link_to === 'page' ) {
            $posts = get_posts( [
                'post_type'      => PostType::CPT,
                'meta_key'       => '_pz_video_id',
                'meta_value'     => $video_id,
                'posts_per_page' => 1,
                'fields'         => 'ids',
            ] );
            $wp_url = ! empty( $posts ) ? esc_url( get_permalink( $posts[0] ) ) : '';
        }

        echo '<div class="pz-card" data-video-id="' . esc_attr( $video_id ) . '">';

        // Thumbnail / link wrapper
        if ( $link_to === 'lightbox' ) {
            echo '<button class="pz-card-thumb-btn" data-video-id="' . esc_attr( $video_id ) . '" data-title="' . esc_attr( $video['title'] ?? '' ) . '" aria-label="Play ' . esc_attr( $video['title'] ?? '' ) . '">';
        } elseif ( $link_to === 'page' && $wp_url ) {
            echo '<a class="pz-card-thumb-link" href="' . $wp_url . '">';
        } else {
            echo '<div class="pz-card-thumb-wrap">';
        }

        $source_type = $video['primary_source_type'] ?? 'embed';
        $is_direct   = in_array( $source_type, [ 'direct', 'hls' ], true );

        echo '<div class="pz-card-thumb' . ( $is_direct ? ' pz-card-thumb--premium' : '' ) . '" style="background-image:url(\'' . $thumb . '\')">';
        echo '<span class="pz-play-icon" aria-hidden="true">&#9654;</span>';
        if ( $is_direct ) {
            echo '<span class="pz-badge pz-badge--hd">HD</span>';
        }
        if ( $show_dur && $dur_fmt ) {
            echo '<span class="pz-card-duration">' . esc_html( $dur_fmt ) . '</span>';
        }
        echo '</div>';

        if ( $link_to === 'lightbox' ) {
            echo '</button>';
        } elseif ( $link_to === 'page' && $wp_url ) {
            echo '</a>';
        } else {
            echo '</div>';
        }

        if ( $show_title || $show_views ) {
            echo '<div class="pz-card-info">';
            if ( $show_title ) {
                echo '<p class="pz-card-title">' . $title . '</p>';
            }
            if ( $show_views ) {
                echo '<p class="pz-card-views">' . esc_html( $views ) . ' views</p>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    // ── [pornalizer_video id="..."] ────────────────────────────────────────

    public static function single_video( $atts ): string {
        $atts     = shortcode_atts( [ 'id' => 0, 'autoplay' => 'false' ], $atts, 'pornalizer_video' );
        $video_id = absint( $atts['id'] );

        if ( ! $video_id ) {
            return '<p class="pz-error">pornalizer_video: missing id attribute.</p>';
        }

        $video = Api::video( $video_id );
        if ( ! $video ) {
            return '<p class="pz-error">Video not found.</p>';
        }

        $title    = esc_html( $video['title'] ?? '' );
        $thumb    = esc_url( $video['thumbnail'] ?? '' );
        $dur_secs = (int) ( $video['duration_seconds'] ?? 0 );
        $dur_fmt  = $dur_secs ? gmdate( $dur_secs >= 3600 ? 'H:i:s' : 'i:s', $dur_secs ) : '';
        $autoplay = $atts['autoplay'] === 'true';

        ob_start();
        echo '<div class="pz-single-embed" data-video-id="' . esc_attr( $video_id ) . '">';

        if ( $autoplay ) {
            // Fetch embed immediately, pass autoplay=1 so player fires without poster click
            $embed = Api::embed( $video_id );
            if ( $embed && ! empty( $embed['player_url'] ) ) {
                $auto_url = esc_url( add_query_arg( 'autoplay', '1', $embed['player_url'] ) );
                echo '<div class="pz-iframe-wrap">';
                echo '<iframe src="' . $auto_url . '" frameborder="0" allowfullscreen scrolling="no" allow="autoplay; encrypted-media; picture-in-picture" loading="eager"></iframe>';
                echo '</div>';
            }
        } else {
            echo '<div class="pz-poster-single" style="background-image:url(\'' . $thumb . '\')">';
            echo '<button class="pz-play-btn-single" data-video-id="' . esc_attr( $video_id ) . '" aria-label="Play ' . esc_attr( $video['title'] ?? '' ) . '">';
            echo '<svg viewBox="0 0 80 80" xmlns="http://www.w3.org/2000/svg"><circle cx="40" cy="40" r="38" fill="rgba(0,0,0,.55)" stroke="#fff" stroke-width="2"/><polygon points="32,24 60,40 32,56" fill="#fff"/></svg>';
            echo '</button>';
            if ( $dur_fmt ) {
                echo '<span class="pz-duration">' . esc_html( $dur_fmt ) . '</span>';
            }
            echo '</div>';
            echo '<div class="pz-inline-player" style="display:none"></div>';
        }

        echo '<p class="pz-embed-title">' . $title . '</p>';
        echo '</div>';

        return ob_get_clean();
    }

    // ── [pornalizer_search] ────────────────────────────────────────────────

    public static function search_bar( $atts ): string {
        $atts = shortcode_atts( [
            'placeholder' => 'Search videos…',
            'columns'     => 3,
            'count'       => 12,
            'orientation' => 'straight',
        ], $atts, 'pornalizer_search' );

        ob_start();
        ?>
        <div class="pz-search-widget"
             data-columns="<?php echo esc_attr( absint( $atts['columns'] ) ); ?>"
             data-count="<?php echo esc_attr( min( absint( $atts['count'] ), 100 ) ); ?>"
             data-orientation="<?php echo esc_attr( sanitize_text_field( $atts['orientation'] ) ); ?>">
            <input class="pz-search-input" type="search"
                   placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
                   aria-label="Search videos">
            <div class="pz-search-results pz-grid pz-cols-<?php echo esc_attr( absint( $atts['columns'] ) ); ?>"></div>
            <div class="pz-search-status"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── AJAX handlers ──────────────────────────────────────────────────────

    /**
     * AJAX: return embed iframe HTML for a video ID.
     * Called when user clicks a card in lightbox mode.
     */
    public static function ajax_get_embed(): void {
        check_ajax_referer( 'pz_public', 'nonce' );

        $video_id = absint( $_POST['video_id'] ?? 0 );
        if ( ! $video_id ) {
            wp_send_json_error( 'Missing video_id', 400 );
        }

        $embed = Api::embed( $video_id );
        if ( ! $embed || empty( $embed['player_url'] ) ) {
            wp_send_json_error( 'Embed unavailable', 503 );
        }

        // Always pass ?autoplay=1 — the Pornalizer player fires loadSource() immediately,
        // so the user's single click on the card IS the only click before video plays.
        $player_url = esc_url( add_query_arg( 'autoplay', '1', $embed['player_url'] ) );
        $iframe = '<div class="pz-iframe-wrap"><iframe src="' . $player_url . '" frameborder="0" allowfullscreen scrolling="no" allow="autoplay; encrypted-media; picture-in-picture" loading="eager"></iframe></div>';

        wp_send_json_success( [
            'html'  => $iframe,
            'title' => esc_html( $embed['title'] ?? '' ),
        ] );
    }

    /**
     * AJAX: fire a view postback to Pornalizer so view counts stay accurate.
     * Uses fire-and-forget — result is irrelevant to the user.
     */
    public static function ajax_track_view(): void {
        check_ajax_referer( 'pz_public', 'nonce' );
        $video_id = absint( $_POST['video_id'] ?? 0 );
        if ( $video_id ) {
            Api::post( '/track/view/', [ 'video_id' => $video_id, 'referrer' => get_bloginfo( 'url' ) ] );
        }
        wp_send_json_success();
    }

    /**
     * AJAX: return rendered card HTML for a new page of browse results.
     */
    public static function ajax_browse(): void {
        check_ajax_referer( 'pz_public', 'nonce' );

        $raw = json_decode( stripslashes( $_POST['args'] ?? '{}' ), true );
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( 'Bad args', 400 );
        }

        // Sanitize everything from raw args
        $page     = max( 1, absint( $raw['page'] ?? 1 ) );
        $columns  = min( max( absint( $raw['columns'] ?? 3 ), 1 ), 6 );
        $link_to  = in_array( $raw['link_to'] ?? '', [ 'lightbox', 'page', 'none' ] ) ? $raw['link_to'] : 'lightbox';
        $show_title = ! isset( $raw['show_title'] ) || $raw['show_title'];
        $show_dur   = ! isset( $raw['show_dur'] )   || $raw['show_dur'];
        $show_views = ! empty( $raw['show_views'] );

        $api_args = array_filter( [
            'page'        => $page,
            'page_size'   => min( absint( $raw['page_size'] ?? 12 ), 100 ),
            'sort'        => sanitize_text_field( $raw['sort'] ?? 'published' ),
            'orientation' => sanitize_text_field( $raw['orientation'] ?? 'straight' ),
            'categories'  => sanitize_text_field( $raw['categories'] ?? '' ),
            'performers'  => sanitize_text_field( $raw['performers'] ?? '' ),
            'tags'        => sanitize_text_field( $raw['tags'] ?? '' ),
            'search'      => sanitize_text_field( $raw['search'] ?? '' ),
        ] );

        $result = Api::browse( $api_args );
        $videos = $result['results'] ?? [];

        if ( empty( $videos ) ) {
            wp_send_json_success( [ 'html' => '<p class="pz-no-videos">No more videos.</p>', 'total_pages' => 0 ] );
        }

        ob_start();
        foreach ( $videos as $video ) {
            self::render_card( $video, $link_to, $show_title, $show_dur, $show_views );
        }
        $html = ob_get_clean();

        $total      = (int) ( $result['count'] ?? 0 );
        $page_size  = (int) ( $api_args['page_size'] ?? 12 );
        $total_pages = $total > 0 ? (int) ceil( $total / $page_size ) : 0;

        wp_send_json_success( [ 'html' => $html, 'total_pages' => $total_pages, 'current_page' => $page ] );
    }
}
