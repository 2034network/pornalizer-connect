<?php
namespace Pornalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: tabbed dashboard with stats, video browser, shortcode builder, sync controls, settings.
 */
class Admin {

    const PAGE_SLUG    = 'pornalizer-connect';
    const HIST_OPTION  = 'pz_sync_history';
    const HIST_MAX     = 20;

    public static function register(): void {
        add_action( 'admin_menu',            [ self::class, 'add_menu' ] );
        add_action( 'admin_init',            [ self::class, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );

        add_action( 'update_option_pz_auto_sync',     [ 'Pornalizer\Sync', 'reschedule' ] );
        add_action( 'update_option_pz_sync_interval', [ 'Pornalizer\Sync', 'reschedule' ] );
        add_action( 'update_option_pz_video_slug',    'flush_rewrite_rules' );

        // Manual sync AJAX
        add_action( 'wp_ajax_pz_sync_incremental',    [ self::class, 'ajax_sync_incremental' ] );
        add_action( 'wp_ajax_pz_sync_full',           [ self::class, 'ajax_sync_full' ] );
        add_action( 'wp_ajax_pz_clear_cache',         [ self::class, 'ajax_clear_cache' ] );
        add_action( 'wp_ajax_pz_test_api',            [ self::class, 'ajax_test_api' ] );

        // Dashboard data
        add_action( 'wp_ajax_pz_dashboard_stats',  [ self::class, 'ajax_dashboard_stats' ] );
        add_action( 'wp_ajax_pz_preview_videos',   [ self::class, 'ajax_preview_videos' ] );
        add_action( 'wp_ajax_pz_get_sync_history', [ self::class, 'ajax_get_sync_history' ] );

        // Scheduler
        add_action( 'wp_ajax_pz_get_scheduled',  [ self::class, 'ajax_get_scheduled' ] );
        add_action( 'wp_ajax_pz_publish_now',    [ self::class, 'ajax_publish_now' ] );
        add_action( 'wp_ajax_pz_save_drip',      [ self::class, 'ajax_save_drip' ] );
        add_action( 'wp_ajax_pz_drip_now',       [ self::class, 'ajax_drip_now' ] );
        add_action( 'wp_ajax_pz_schedule_video', [ self::class, 'ajax_schedule_video' ] );

        // Drip cron
        add_action( 'pz_drip_cron', [ self::class, 'run_drip' ] );
        add_action( 'update_option_pz_drip_mode', [ self::class, 'reschedule_drip' ] );
        add_action( 'update_option_pz_drip_hour', [ self::class, 'reschedule_drip' ] );
    }

    public static function reschedule_drip(): void {
        wp_clear_scheduled_hook( 'pz_drip_cron' );
        if ( get_option( 'pz_drip_mode' ) === '1' ) {
            wp_schedule_event( time(), 'hourly', 'pz_drip_cron' );
        }
    }

    public static function run_drip(): void {
        $per_day  = max( 1, (int) get_option( 'pz_drip_per_day', 10 ) );
        $hour     = (int) get_option( 'pz_drip_hour', 9 );
        $today    = current_time( 'Y-m-d' );
        $last_run = get_option( 'pz_last_drip_date', '' );

        // Only once per day, after configured hour
        if ( $last_run === $today ) return;
        if ( (int) current_time( 'G' ) < $hour ) return;

        $drafts = get_posts( [
            'post_type'      => PostType::CPT,
            'post_status'    => 'draft',
            'posts_per_page' => $per_day,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ] );

        $published = 0;
        foreach ( $drafts as $post_id ) {
            $result = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
            if ( $result && ! is_wp_error( $result ) ) $published++;
        }

        update_option( 'pz_last_drip_date', $today, false );
        update_option( 'pz_last_drip_count', $published, false );
    }

    public static function add_menu(): void {
        add_menu_page(
            'Pornalizer Connect',
            'Pornalizer',
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'render_page' ],
            'dashicons-video-alt3',
            30
        );
    }

    public static function register_settings(): void {
        $settings = [
            'pz_api_url'          => PZ_API_BASE,
            'pz_cache_ttl'        => 60,
            'pz_auto_sync'        => '1',
            'pz_sync_interval'    => 'hourly',
            'pz_full_sync_limit'  => 500,
            'pz_sync_orientation' => 'straight',
            'pz_sync_categories'  => '',
            'pz_sync_sort'        => 'published',
            'pz_video_slug'       => 'videos',
            'pz_import_as'        => 'publish',
            'pz_drip_mode'        => '0',
            'pz_drip_per_day'     => 10,
            'pz_drip_hour'        => 9,
        ];

        foreach ( $settings as $key => $default ) {
            register_setting( 'pz_settings', $key, [
                'sanitize_callback' => [ self::class, 'sanitize_setting_' . $key ],
            ] );
            if ( false === get_option( $key ) ) {
                add_option( $key, $default );
            }
        }
    }

    // Per-option sanitizers -----------------------------------------------

    public static function sanitize_setting_pz_api_url( $v ): string {
        return esc_url_raw( rtrim( $v, '/' ) ) ?: PZ_API_BASE;
    }
    public static function sanitize_setting_pz_cache_ttl( $v ): int {
        return max( 30, absint( $v ) );
    }
    public static function sanitize_setting_pz_auto_sync( $v ): string {
        return $v === '1' ? '1' : '0';
    }
    public static function sanitize_setting_pz_sync_interval( $v ): string {
        return in_array( $v, [ 'hourly', 'twicedaily', 'daily' ] ) ? $v : 'hourly';
    }
    public static function sanitize_setting_pz_full_sync_limit( $v ): int {
        return max( 1, min( absint( $v ), 10000 ) );
    }
    public static function sanitize_setting_pz_sync_orientation( $v ): string {
        return in_array( $v, [ '', 'straight', 'gay', 'trans', 'bi', 'mixed' ] ) ? $v : 'straight';
    }
    public static function sanitize_setting_pz_sync_categories( $v ): string {
        return sanitize_text_field( $v );
    }
    public static function sanitize_setting_pz_sync_sort( $v ): string {
        return in_array( $v, [ 'published', 'views', 'likes' ] ) ? $v : 'published';
    }
    public static function sanitize_setting_pz_video_slug( $v ): string {
        return sanitize_title( $v ) ?: 'videos';
    }
    public static function sanitize_setting_pz_import_as( $v ): string {
        return in_array( $v, [ 'publish', 'draft', 'schedule' ] ) ? $v : 'publish';
    }
    public static function sanitize_setting_pz_drip_mode( $v ): string {
        return $v === '1' ? '1' : '0';
    }
    public static function sanitize_setting_pz_drip_per_day( $v ): int {
        return max( 1, min( absint( $v ), 500 ) );
    }
    public static function sanitize_setting_pz_drip_hour( $v ): int {
        return max( 0, min( absint( $v ), 23 ) );
    }

    // Sync history --------------------------------------------------------

    public static function record_sync( array $result ): void {
        $hist   = get_option( self::HIST_OPTION, [] );
        $entry  = array_merge( $result, [ 'ts' => time() ] );
        array_unshift( $hist, $entry );
        $hist   = array_slice( $hist, 0, self::HIST_MAX );
        update_option( self::HIST_OPTION, $hist, false );
    }

    // Enqueue -------------------------------------------------------------

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) return;

        wp_enqueue_style( 'pz-admin', PZ_PLUGIN_URL . 'assets/admin.css', [], PZ_VERSION );
        wp_enqueue_script( 'pz-admin', PZ_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], PZ_VERSION, true );
        wp_localize_script( 'pz-admin', 'pzAdmin', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'pz_admin' ),
            'siteUrl'       => get_site_url(),
            'videoSlug'     => get_option( 'pz_video_slug', 'videos' ),
            'orientations'  => [ 'straight', 'gay', 'trans', 'bi', 'mixed' ],
            'fullSyncLimit' => (int) get_option( 'pz_full_sync_limit', 500 ),
        ] );
    }

    // Main page ----------------------------------------------------------

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap pz-admin-wrap">

            <div class="pz-header">
                <div class="pz-header-brand">
                    <span class="dashicons dashicons-video-alt3 pz-logo-icon"></span>
                    <div>
                        <h1 class="pz-title">Pornalizer Connect</h1>
                        <span class="pz-version">v<?php echo esc_html( PZ_VERSION ); ?></span>
                    </div>
                </div>
                <div class="pz-header-actions">
                    <button id="pz-hdr-incremental" class="pz-btn pz-btn-primary pz-btn-sm">
                        <span class="dashicons dashicons-update"></span> Sync Now
                    </button>
                    <button id="pz-hdr-test-api" class="pz-btn pz-btn-ghost pz-btn-sm">
                        <span class="dashicons dashicons-admin-plugins"></span> Test API
                    </button>
                </div>
            </div>

            <div id="pz-toast" class="pz-toast" style="display:none"></div>

            <!-- Tabs -->
            <nav class="pz-tabs">
                <button class="pz-tab active" data-tab="dashboard">
                    <span class="dashicons dashicons-chart-bar"></span> Dashboard
                </button>
                <button class="pz-tab" data-tab="browse">
                    <span class="dashicons dashicons-grid-view"></span> Browse Videos
                </button>
                <button class="pz-tab" data-tab="sync">
                    <span class="dashicons dashicons-update"></span> Sync
                </button>
                <button class="pz-tab" data-tab="scheduler">
                    <span class="dashicons dashicons-calendar-alt"></span> Scheduler
                </button>
                <button class="pz-tab" data-tab="builder">
                    <span class="dashicons dashicons-shortcode"></span> Shortcode Builder
                </button>
                <button class="pz-tab" data-tab="settings">
                    <span class="dashicons dashicons-admin-settings"></span> Settings
                </button>
                <button class="pz-tab" data-tab="help">
                    <span class="dashicons dashicons-editor-help"></span> Help
                </button>
            </nav>

            <!-- ── TAB: Dashboard ── -->
            <div class="pz-panel active" id="pz-panel-dashboard">
                <div class="pz-kpi-row" id="pz-kpi-row">
                    <div class="pz-kpi pz-kpi-blue">
                        <div class="pz-kpi-value" id="kpi-videos">—</div>
                        <div class="pz-kpi-label">Videos Synced</div>
                        <span class="dashicons dashicons-video-alt3 pz-kpi-icon"></span>
                    </div>
                    <div class="pz-kpi pz-kpi-purple">
                        <div class="pz-kpi-value" id="kpi-categories">—</div>
                        <div class="pz-kpi-label">Categories</div>
                        <span class="dashicons dashicons-tag pz-kpi-icon"></span>
                    </div>
                    <div class="pz-kpi pz-kpi-pink">
                        <div class="pz-kpi-value" id="kpi-performers">—</div>
                        <div class="pz-kpi-label">Performers</div>
                        <span class="dashicons dashicons-groups pz-kpi-icon"></span>
                    </div>
                    <div class="pz-kpi pz-kpi-teal">
                        <div class="pz-kpi-value" id="kpi-api">—</div>
                        <div class="pz-kpi-label">API Videos Available</div>
                        <span class="dashicons dashicons-cloud pz-kpi-icon"></span>
                    </div>
                </div>

                <div class="pz-row">
                    <div class="pz-card pz-card-wide">
                        <div class="pz-card-head">
                            <h3>Sync Status</h3>
                            <span id="dash-api-badge" class="pz-badge pz-badge-loading">Checking…</span>
                        </div>
                        <div class="pz-sync-status">
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Last sync</span>
                                <span class="pz-sync-val" id="dash-last-sync">—</span>
                            </div>
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Next auto-sync</span>
                                <span class="pz-sync-val" id="dash-next-sync">—</span>
                            </div>
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Auto-sync</span>
                                <span class="pz-sync-val" id="dash-auto-sync">—</span>
                            </div>
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Last result</span>
                                <span class="pz-sync-val" id="dash-last-result">—</span>
                            </div>
                        </div>
                        <div class="pz-card-actions">
                            <button class="pz-btn pz-btn-primary pz-btn-sm" id="dash-btn-incremental">
                                <span class="dashicons dashicons-update"></span> Incremental Sync
                            </button>
                            <button class="pz-btn pz-btn-secondary pz-btn-sm" id="dash-btn-full">
                                <span class="dashicons dashicons-image-rotate"></span> Full Sync
                            </button>
                        </div>
                    </div>

                    <div class="pz-card">
                        <div class="pz-card-head">
                            <h3>Sync History</h3>
                            <button class="pz-btn pz-btn-ghost pz-btn-xs" id="dash-refresh-hist">
                                <span class="dashicons dashicons-update"></span>
                            </button>
                        </div>
                        <div id="pz-sync-history" class="pz-history-list">
                            <div class="pz-loading-row">Loading…</div>
                        </div>
                    </div>
                </div>

                <div class="pz-card pz-card-full">
                    <div class="pz-card-head">
                        <h3>Quick Shortcodes</h3>
                    </div>
                    <div class="pz-quickcodes">
                        <div class="pz-quickcode-item">
                            <code id="qc-grid">[pornalizer_videos]</code>
                            <button class="pz-copy-btn" data-target="qc-grid">Copy</button>
                            <span class="pz-qc-desc">Video grid (defaults)</span>
                        </div>
                        <div class="pz-quickcode-item">
                            <code id="qc-search">[pornalizer_search]</code>
                            <button class="pz-copy-btn" data-target="qc-search">Copy</button>
                            <span class="pz-qc-desc">AJAX search bar</span>
                        </div>
                        <div class="pz-quickcode-item">
                            <code id="qc-infinite">[pornalizer_videos infinite="true" count="24"]</code>
                            <button class="pz-copy-btn" data-target="qc-infinite">Copy</button>
                            <span class="pz-qc-desc">Infinite scroll grid</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── TAB: Browse Videos ── -->
            <div class="pz-panel" id="pz-panel-browse">
                <div class="pz-browse-toolbar">
                    <input type="search" id="pz-browse-search" class="pz-input" placeholder="Search videos…">
                    <select id="pz-browse-sort" class="pz-select">
                        <option value="published">Newest first</option>
                        <option value="views">Most viewed</option>
                        <option value="likes">Most liked</option>
                    </select>
                    <select id="pz-browse-orientation" class="pz-select">
                        <option value="">All orientations</option>
                        <option value="straight">Straight</option>
                        <option value="gay">Gay</option>
                        <option value="trans">Trans</option>
                        <option value="bi">Bi</option>
                    </select>
                    <input type="text" id="pz-browse-category" class="pz-input pz-input-sm" placeholder="Category slug…">
                    <button id="pz-browse-go" class="pz-btn pz-btn-primary pz-btn-sm">
                        <span class="dashicons dashicons-search"></span> Search
                    </button>
                </div>

                <div id="pz-browse-info" class="pz-browse-info" style="display:none"></div>

                <div id="pz-browse-grid" class="pz-browse-grid">
                    <div class="pz-empty-state">
                        <span class="dashicons dashicons-video-alt3"></span>
                        <p>Click Search to load videos from the API</p>
                    </div>
                </div>

                <div id="pz-browse-pagination" class="pz-pagination" style="display:none">
                    <button id="pz-browse-prev" class="pz-btn pz-btn-ghost pz-btn-sm" disabled>← Prev</button>
                    <span id="pz-browse-page-info" class="pz-page-info"></span>
                    <button id="pz-browse-next" class="pz-btn pz-btn-ghost pz-btn-sm">Next →</button>
                </div>
            </div>

            <!-- ── TAB: Sync ── -->
            <div class="pz-panel" id="pz-panel-sync">
                <div class="pz-row">
                    <div class="pz-card pz-card-wide">
                        <div class="pz-card-head"><h3>Sync Actions</h3></div>
                        <div class="pz-sync-actions">
                            <div class="pz-sync-action-card">
                                <div class="pz-sa-icon pz-sa-blue"><span class="dashicons dashicons-update"></span></div>
                                <div class="pz-sa-body">
                                    <strong>Incremental Sync</strong>
                                    <p>Fetches the changelog since last sync — fast, processes only new/changed/deleted videos.</p>
                                </div>
                                <button id="sync-btn-incremental" class="pz-btn pz-btn-primary">Run</button>
                            </div>
                            <div class="pz-sync-action-card">
                                <div class="pz-sa-icon pz-sa-purple"><span class="dashicons dashicons-image-rotate"></span></div>
                                <div class="pz-sa-body">
                                    <strong>Full Sync</strong>
                                    <p>Pages through the top N videos and upserts all of them. Use to rebuild or seed your library.</p>
                                </div>
                                <button id="sync-btn-full" class="pz-btn pz-btn-secondary">Run</button>
                            </div>
                            <div class="pz-sync-action-card">
                                <div class="pz-sa-icon pz-sa-orange"><span class="dashicons dashicons-trash"></span></div>
                                <div class="pz-sa-body">
                                    <strong>Clear API Cache</strong>
                                    <p>Deletes all transient cache entries so next API calls fetch fresh data.</p>
                                </div>
                                <button id="sync-btn-clear-cache" class="pz-btn pz-btn-ghost">Clear</button>
                            </div>
                        </div>

                        <div id="sync-progress-wrap" class="pz-progress-wrap" style="display:none">
                            <div class="pz-progress-bar">
                                <div class="pz-progress-fill pz-progress-animated" style="width:100%"></div>
                            </div>
                            <p id="sync-progress-msg" class="pz-progress-msg">Working…</p>
                        </div>
                        <div id="sync-result" class="pz-result" style="display:none"></div>
                    </div>

                    <div class="pz-card">
                        <div class="pz-card-head">
                            <h3>Cron Schedule</h3>
                        </div>
                        <div class="pz-sync-status">
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Auto-sync enabled</span>
                                <span class="pz-sync-val">
                                    <?php echo get_option('pz_auto_sync') === '1'
                                        ? '<span class="pz-badge pz-badge-ok">Yes</span>'
                                        : '<span class="pz-badge pz-badge-off">No</span>'; ?>
                                </span>
                            </div>
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Interval</span>
                                <span class="pz-sync-val"><?php echo esc_html( get_option('pz_sync_interval', 'hourly') ); ?></span>
                            </div>
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Next run</span>
                                <span class="pz-sync-val">
                                    <?php
                                    $next = wp_next_scheduled( Sync::CRON_HOOK );
                                    echo $next ? esc_html( wp_date('Y-m-d H:i:s', $next) ) : '—';
                                    ?>
                                </span>
                            </div>
                            <div class="pz-sync-row">
                                <span class="pz-sync-label">Full sync limit</span>
                                <span class="pz-sync-val"><?php echo esc_html( get_option('pz_full_sync_limit', 500) ); ?> videos</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pz-card pz-card-full">
                    <div class="pz-card-head">
                        <h3>Sync History</h3>
                        <button class="pz-btn pz-btn-ghost pz-btn-xs" id="sync-refresh-hist">
                            <span class="dashicons dashicons-update"></span> Refresh
                        </button>
                    </div>
                    <div id="pz-sync-history-full" class="pz-history-list pz-history-full">
                        <div class="pz-loading-row">Loading…</div>
                    </div>
                </div>
            </div>

            <!-- ── TAB: Scheduler ── -->
            <div class="pz-panel" id="pz-panel-scheduler">
                <div class="pz-row">
                    <div class="pz-card pz-card-wide">
                        <div class="pz-card-head"><h3>Drip Publishing</h3></div>
                        <p class="pz-help-text" style="margin-bottom:16px;">
                            Drip mode automatically publishes a fixed number of drafts each day at a set time.
                            Set <strong>Import as</strong> to <em>Draft</em> so new synced videos come in as drafts,
                            then let the drip scheduler publish them on a cadence.
                        </p>

                        <div class="pz-drip-form">
                            <div class="pz-drip-row">
                                <label>Drip mode</label>
                                <label class="pz-toggle">
                                    <input type="checkbox" id="pz-drip-mode" <?php checked( get_option('pz_drip_mode'), '1' ); ?>>
                                    <span class="pz-toggle-slider"></span>
                                </label>
                            </div>
                            <div class="pz-drip-row">
                                <label>Posts per day</label>
                                <input type="number" id="pz-drip-per-day" class="pz-input pz-input-sm"
                                       value="<?php echo esc_attr( get_option('pz_drip_per_day', 10) ); ?>" min="1" max="500">
                            </div>
                            <div class="pz-drip-row">
                                <label>Publish hour (UTC)</label>
                                <select id="pz-drip-hour" class="pz-select">
                                    <?php for ( $h = 0; $h < 24; $h++ ) : ?>
                                        <option value="<?php echo $h; ?>" <?php selected( (int) get_option('pz_drip_hour', 9), $h ); ?>>
                                            <?php echo sprintf( '%02d:00 UTC', $h ); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="pz-drip-row">
                                <label>Import videos as</label>
                                <select id="pz-import-as" class="pz-select">
                                    <?php foreach ( ['publish'=>'Publish immediately','draft'=>'Draft (drip later)','schedule'=>'Draft (drip later)'] as $v => $l ) : ?>
                                        <option value="<?php echo esc_attr($v); ?>" <?php selected( get_option('pz_import_as','publish'), $v ); ?>><?php echo esc_html($l); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="pz-card-actions">
                            <button id="drip-save"    class="pz-btn pz-btn-primary pz-btn-sm">
                                <span class="dashicons dashicons-saved"></span> Save Drip Settings
                            </button>
                            <button id="drip-trigger" class="pz-btn pz-btn-ghost pz-btn-sm">
                                <span class="dashicons dashicons-controls-play"></span> Run Drip Now
                            </button>
                        </div>

                        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--pz-border)">
                            <span class="pz-help-text">Last drip: <strong><?php echo esc_html( get_option('pz_last_drip_date', 'Never') ); ?></strong>
                            &nbsp;·&nbsp; Published that day: <strong><?php echo esc_html( get_option('pz_last_drip_count', '0') ); ?></strong></span>
                        </div>
                    </div>

                    <div class="pz-card">
                        <div class="pz-card-head">
                            <h3>Draft Queue</h3>
                            <span class="pz-badge pz-badge-loading" id="draft-count-badge">Loading…</span>
                        </div>
                        <p class="pz-help-text" style="margin-bottom:12px;">Videos waiting to be published by the drip scheduler.</p>
                        <div id="pz-sched-list" class="pz-sched-list">
                            <div class="pz-loading-row">Loading…</div>
                        </div>
                    </div>
                </div>

                <div class="pz-card pz-card-full">
                    <div class="pz-card-head"><h3>Schedule a Single Video</h3></div>
                    <p class="pz-help-text" style="margin-bottom:14px;">
                        Browse to any video and click <strong>Schedule</strong> on its card to assign it a specific publish date.
                        Or enter a Pornalizer video ID below to import it directly as a scheduled post.
                    </p>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="number" id="sched-video-id" class="pz-input" placeholder="Video ID (e.g. 12345)" style="width:200px;">
                        <input type="datetime-local" id="sched-video-date" class="pz-input">
                        <button id="sched-video-btn" class="pz-btn pz-btn-primary pz-btn-sm">
                            <span class="dashicons dashicons-calendar-alt"></span> Schedule Video
                        </button>
                    </div>
                    <div id="sched-video-result" class="pz-result" style="display:none;margin-top:12px;"></div>
                </div>
            </div>

            <!-- ── TAB: Shortcode Builder ── -->
            <div class="pz-panel" id="pz-panel-builder">
                <div class="pz-row">
                    <div class="pz-card pz-card-wide">
                        <div class="pz-card-head"><h3>Configure Shortcode</h3></div>

                        <div class="pz-builder-grid">
                            <div class="pz-builder-field">
                                <label>Type</label>
                                <select id="sc-type" class="pz-select pz-select-full">
                                    <option value="grid">Video Grid</option>
                                    <option value="single">Single Video</option>
                                    <option value="search">Search Bar</option>
                                </select>
                            </div>

                            <div class="pz-builder-field" id="sc-field-id" style="display:none">
                                <label>Video ID</label>
                                <input type="number" id="sc-id" class="pz-input pz-input-full" placeholder="12345">
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Count (per page)</label>
                                <input type="number" id="sc-count" class="pz-input pz-input-full" value="12" min="1" max="100">
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Columns</label>
                                <select id="sc-columns" class="pz-select pz-select-full">
                                    <option value="2">2</option>
                                    <option value="3" selected>3</option>
                                    <option value="4">4</option>
                                    <option value="5">5</option>
                                    <option value="6">6</option>
                                </select>
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Sort</label>
                                <select id="sc-sort" class="pz-select pz-select-full">
                                    <option value="published">Newest first</option>
                                    <option value="views">Most viewed</option>
                                    <option value="likes">Most liked</option>
                                </select>
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Orientation</label>
                                <select id="sc-orientation" class="pz-select pz-select-full">
                                    <option value="">All</option>
                                    <option value="straight">Straight</option>
                                    <option value="gay">Gay</option>
                                    <option value="trans">Trans</option>
                                    <option value="bi">Bi</option>
                                </select>
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Category slug</label>
                                <input type="text" id="sc-category" class="pz-input pz-input-full" placeholder="milf">
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Performer slug</label>
                                <input type="text" id="sc-performer" class="pz-input pz-input-full" placeholder="jane-doe">
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Click action</label>
                                <select id="sc-link" class="pz-select pz-select-full">
                                    <option value="lightbox">Lightbox (default)</option>
                                    <option value="page">Open WP page</option>
                                </select>
                            </div>

                            <div class="pz-builder-field sc-grid-field">
                                <label>Infinite scroll</label>
                                <select id="sc-infinite" class="pz-select pz-select-full">
                                    <option value="">No</option>
                                    <option value="true">Yes</option>
                                </select>
                            </div>

                            <div class="pz-builder-field" id="sc-field-autoplay" style="display:none">
                                <label>Autoplay</label>
                                <select id="sc-autoplay" class="pz-select pz-select-full">
                                    <option value="">No (click to play)</option>
                                    <option value="true">Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="pz-card">
                        <div class="pz-card-head"><h3>Generated Shortcode</h3></div>
                        <div class="pz-sc-preview-wrap">
                            <code id="pz-sc-output" class="pz-sc-output">[pornalizer_videos]</code>
                        </div>
                        <button class="pz-btn pz-btn-primary pz-copy-btn-sc">
                            <span class="dashicons dashicons-clipboard"></span> Copy Shortcode
                        </button>
                        <hr class="pz-sep">
                        <p class="pz-help-text">Paste this shortcode into any page, post, or widget.</p>

                        <div class="pz-card-head" style="margin-top:20px"><h3>What it does</h3></div>
                        <p id="pz-sc-description" class="pz-help-text">
                            Renders a responsive video grid fetched live from the Pornalizer API with lightbox playback.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── TAB: Settings ── -->
            <div class="pz-panel" id="pz-panel-settings">
                <form method="post" action="options.php" class="pz-settings-form">
                    <?php settings_fields( 'pz_settings' ); ?>

                    <div class="pz-settings-section">
                        <h3 class="pz-section-head">API Connection</h3>
                        <div class="pz-field-row">
                            <label for="pz_api_url">API Base URL</label>
                            <div class="pz-field-body">
                                <input type="url" id="pz_api_url" name="pz_api_url"
                                    value="<?php echo esc_attr( get_option('pz_api_url') ); ?>" class="pz-input pz-input-wide">
                                <p class="pz-desc">Leave as default unless you have a custom endpoint.</p>
                            </div>
                        </div>
                        <div class="pz-field-row">
                            <label for="pz_cache_ttl">Cache TTL (seconds)</label>
                            <div class="pz-field-body">
                                <input type="number" id="pz_cache_ttl" name="pz_cache_ttl" min="30" max="3600"
                                    value="<?php echo esc_attr( get_option('pz_cache_ttl') ); ?>" class="pz-input pz-input-sm">
                                <p class="pz-desc">How long API responses are cached. Min 30 s. Default: 60 s.</p>
                            </div>
                        </div>
                    </div>

                    <div class="pz-settings-section">
                        <h3 class="pz-section-head">Auto Sync</h3>
                        <div class="pz-field-row">
                            <label for="pz_auto_sync">Enable Auto Sync</label>
                            <div class="pz-field-body">
                                <label class="pz-toggle">
                                    <input type="checkbox" id="pz_auto_sync" name="pz_auto_sync" value="1"
                                        <?php checked( get_option('pz_auto_sync'), '1' ); ?>>
                                    <span class="pz-toggle-slider"></span>
                                </label>
                                <p class="pz-desc">Run incremental sync automatically via WP Cron.</p>
                            </div>
                        </div>
                        <div class="pz-field-row">
                            <label for="pz_sync_interval">Sync Interval</label>
                            <div class="pz-field-body">
                                <select id="pz_sync_interval" name="pz_sync_interval" class="pz-select">
                                    <?php foreach ( ['hourly'=>'Hourly','twicedaily'=>'Twice Daily','daily'=>'Daily'] as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected(get_option('pz_sync_interval'),$val); ?>><?php echo esc_html($lbl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="pz-settings-section">
                        <h3 class="pz-section-head">Content Filter</h3>
                        <div class="pz-field-row">
                            <label for="pz_sync_orientation">Orientation</label>
                            <div class="pz-field-body">
                                <select id="pz_sync_orientation" name="pz_sync_orientation" class="pz-select">
                                    <?php foreach ( [''=>'All','straight'=>'Straight','gay'=>'Gay','trans'=>'Trans','bi'=>'Bi'] as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected(get_option('pz_sync_orientation'),$val); ?>><?php echo esc_html($lbl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="pz-field-row">
                            <label for="pz_sync_categories">Category Filter</label>
                            <div class="pz-field-body">
                                <input type="text" id="pz_sync_categories" name="pz_sync_categories"
                                    value="<?php echo esc_attr( get_option('pz_sync_categories') ); ?>"
                                    class="pz-input pz-input-wide" placeholder="milf,amateur,lesbian">
                                <p class="pz-desc">Comma-separated category slugs to import. Leave blank for all.</p>
                            </div>
                        </div>
                        <div class="pz-field-row">
                            <label for="pz_sync_sort">Full Sync Sort</label>
                            <div class="pz-field-body">
                                <select id="pz_sync_sort" name="pz_sync_sort" class="pz-select">
                                    <?php foreach ( ['published'=>'Newest first','views'=>'Most viewed','likes'=>'Most liked'] as $val => $lbl ) : ?>
                                        <option value="<?php echo esc_attr($val); ?>" <?php selected(get_option('pz_sync_sort'),$val); ?>><?php echo esc_html($lbl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="pz-field-row">
                            <label for="pz_full_sync_limit">Full Sync Limit</label>
                            <div class="pz-field-body">
                                <input type="number" id="pz_full_sync_limit" name="pz_full_sync_limit" min="1" max="10000"
                                    value="<?php echo esc_attr( get_option('pz_full_sync_limit') ); ?>" class="pz-input pz-input-sm">
                                <p class="pz-desc">Max videos to import on a full sync (default: 500).</p>
                            </div>
                        </div>
                    </div>

                    <div class="pz-settings-section">
                        <h3 class="pz-section-head">URLs & Slugs</h3>
                        <div class="pz-field-row">
                            <label for="pz_video_slug">Video URL Slug</label>
                            <div class="pz-field-body">
                                <input type="text" id="pz_video_slug" name="pz_video_slug"
                                    value="<?php echo esc_attr( get_option('pz_video_slug') ); ?>" class="pz-input">
                                <p class="pz-desc">URL prefix for video pages. e.g. <code>videos</code> → /videos/my-title/</p>
                            </div>
                        </div>
                    </div>

                    <div class="pz-settings-footer">
                        <?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>
                        <button type="button" id="settings-test-api" class="pz-btn pz-btn-ghost">
                            <span class="dashicons dashicons-admin-plugins"></span> Test API Connection
                        </button>
                    </div>

                </form>
            </div>

            <!-- ── TAB: Help ── -->
            <div class="pz-panel" id="pz-panel-help">
                <div class="pz-row">
                    <div class="pz-card pz-card-wide">
                        <div class="pz-card-head"><h3>All Shortcodes</h3></div>
                        <table class="pz-help-table">
                            <thead><tr><th>Shortcode</th><th>Description</th></tr></thead>
                            <tbody>
                                <tr>
                                    <td><code>[pornalizer_videos]</code></td>
                                    <td>Live video grid — fetches from API, lightbox on click.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_videos count="24" columns="4" sort="views"]</code></td>
                                    <td>24 videos per page, 4 columns, sorted by most viewed.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_videos category="milf"]</code></td>
                                    <td>Only MILF category videos.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_videos performer="jane-doe" link_to="page"]</code></td>
                                    <td>Performer videos — clicking opens the synced WP post page.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_videos infinite="true"]</code></td>
                                    <td>Infinite scroll — auto-loads more as user scrolls.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_videos orientation="trans" sort="likes"]</code></td>
                                    <td>Trans videos sorted by most liked.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_video id="12345"]</code></td>
                                    <td>Single video embed — click poster to play in lightbox.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_video id="12345" autoplay="true"]</code></td>
                                    <td>Single embed — iframe loads immediately on page load.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_search]</code></td>
                                    <td>AJAX search bar — results update as user types.</td>
                                </tr>
                                <tr>
                                    <td><code>[pornalizer_search columns="4" count="20"]</code></td>
                                    <td>Search with 4-column results, 20 per page.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="pz-card">
                        <div class="pz-card-head"><h3>Shortcode Parameters</h3></div>
                        <table class="pz-help-table">
                            <thead><tr><th>Param</th><th>Values</th></tr></thead>
                            <tbody>
                                <tr><td><code>count</code></td><td>Any integer (default: 12)</td></tr>
                                <tr><td><code>columns</code></td><td>2–6 (default: 3)</td></tr>
                                <tr><td><code>sort</code></td><td>published, views, likes</td></tr>
                                <tr><td><code>category</code></td><td>Category slug</td></tr>
                                <tr><td><code>performer</code></td><td>Performer slug</td></tr>
                                <tr><td><code>orientation</code></td><td>straight, gay, trans, bi, mixed</td></tr>
                                <tr><td><code>link_to</code></td><td>lightbox (default), page</td></tr>
                                <tr><td><code>infinite</code></td><td>true / false (default)</td></tr>
                                <tr><td><code>autoplay</code></td><td>true / false (default)</td></tr>
                                <tr><td><code>id</code></td><td>Video ID (single video only)</td></tr>
                            </tbody>
                        </table>

                        <div class="pz-card-head" style="margin-top:20px"><h3>Embed Security</h3></div>
                        <p class="pz-help-text">All embed links are signed with HMAC-SHA256 tokens that expire after 2 hours and are bound to the referring domain. This prevents hotlinking and share-link abuse while keeping playback seamless for your visitors.</p>
                    </div>
                </div>

                <div class="pz-card pz-card-full">
                    <div class="pz-card-head"><h3>Plugin Info</h3></div>
                    <div class="pz-info-grid">
                        <div class="pz-info-item">
                            <span class="pz-info-label">Version</span>
                            <span class="pz-info-val"><?php echo esc_html( PZ_VERSION ); ?></span>
                        </div>
                        <div class="pz-info-item">
                            <span class="pz-info-label">API Base</span>
                            <span class="pz-info-val"><?php echo esc_html( get_option('pz_api_url', PZ_API_BASE) ); ?></span>
                        </div>
                        <div class="pz-info-item">
                            <span class="pz-info-label">Video CPT</span>
                            <span class="pz-info-val">pz_video</span>
                        </div>
                        <div class="pz-info-item">
                            <span class="pz-info-label">Plugin dir</span>
                            <span class="pz-info-val"><?php echo esc_html( PZ_PLUGIN_DIR ); ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- .pz-admin-wrap -->
        <?php
    }

    // AJAX handlers -------------------------------------------------------

    public static function ajax_sync_incremental(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $result = Sync::run_incremental();
        self::record_sync( $result );
        wp_send_json_success( $result );
    }

    public static function ajax_sync_full(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $result = Sync::run_full();
        self::record_sync( $result );
        wp_send_json_success( $result );
    }

    public static function ajax_clear_cache(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pz_%' OR option_name LIKE '_transient_timeout_pz_%'" );
        wp_send_json_success( [ 'message' => 'API cache cleared.' ] );
    }

    public static function ajax_test_api(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $result = Api::get( '/database/browse/', [ 'page_size' => 1 ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $count = $result['count'] ?? '?';
        wp_send_json_success( [ 'message' => "Connected! {$count} videos available in the API." ] );
    }

    public static function ajax_dashboard_stats(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $video_count = (int) ( wp_count_posts( PostType::CPT )->publish ?? 0 );
        $cat_count   = (int) wp_count_terms( [ 'taxonomy' => PostType::TAX_CAT ] );
        $perf_count  = (int) wp_count_terms( [ 'taxonomy' => PostType::TAX_PERF ] );
        $tag_count   = (int) wp_count_terms( [ 'taxonomy' => PostType::TAX_TAG ] );

        $log  = get_option( Sync::LOG_OPTION, null );
        $last = get_option( Sync::LAST_SYNC_OPTION, '' );
        $next = wp_next_scheduled( Sync::CRON_HOOK );

        // Quick API check (use cached result if available)
        $api_ok    = false;
        $api_count = 0;
        $api_result = Api::get( '/database/browse/', [ 'page_size' => 1 ] );
        if ( ! is_wp_error( $api_result ) ) {
            $api_ok    = true;
            $api_count = $api_result['count'] ?? 0;
        }

        wp_send_json_success( [
            'videos'     => $video_count,
            'categories' => $cat_count,
            'performers' => $perf_count,
            'tags'       => $tag_count,
            'api_ok'     => $api_ok,
            'api_count'  => $api_count,
            'last_sync'  => $last ? wp_date( 'M j, Y H:i', strtotime( $last ) ) : 'Never',
            'next_sync'  => $next ? wp_date( 'M j, Y H:i', $next ) : '—',
            'auto_sync'  => get_option( 'pz_auto_sync' ) === '1',
            'last_log'   => $log,
        ] );
    }

    public static function ajax_preview_videos(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $page        = max( 1, absint( $_POST['page'] ?? 1 ) );
        $search      = sanitize_text_field( $_POST['search'] ?? '' );
        $sort        = sanitize_text_field( $_POST['sort'] ?? 'published' );
        $orientation = sanitize_text_field( $_POST['orientation'] ?? '' );
        $category    = sanitize_text_field( $_POST['category'] ?? '' );

        $args = [
            'page'      => $page,
            'page_size' => 24,
            'sort'      => in_array( $sort, [ 'published', 'views', 'likes' ] ) ? $sort : 'published',
        ];
        if ( $search )      $args['q']           = $search;
        if ( $orientation ) $args['orientation']  = $orientation;
        if ( $category )    $args['category']     = $category;

        $result = Api::browse( $args );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $videos = $result['results'] ?? [];
        $count  = $result['count']   ?? 0;
        $pages  = ceil( $count / 24 );

        $cards = [];
        foreach ( $videos as $v ) {
            $cards[] = [
                'id'        => $v['id'],
                'title'     => $v['title'] ?? '',
                'thumb'     => $v['thumbnail'] ?? '',
                'duration'  => $v['duration'] ?? 0,
                'views'     => $v['views'] ?? 0,
                'shortcode' => '[pornalizer_video id="' . absint($v['id']) . '"]',
            ];
        }

        wp_send_json_success( [
            'cards' => $cards,
            'count' => $count,
            'page'  => $page,
            'pages' => $pages,
        ] );
    }

    public static function ajax_get_sync_history(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $hist = get_option( self::HIST_OPTION, [] );

        foreach ( $hist as &$entry ) {
            $entry['ts_fmt'] = isset( $entry['ts'] ) ? wp_date( 'M j H:i', $entry['ts'] ) : '—';
        }
        unset( $entry );

        wp_send_json_success( $hist );
    }

    // Scheduler AJAX -------------------------------------------------------

    public static function ajax_get_scheduled(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $drafts = get_posts( [
            'post_type'      => PostType::CPT,
            'post_status'    => [ 'draft', 'future' ],
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ] );

        $list = [];
        foreach ( $drafts as $p ) {
            $list[] = [
                'id'     => $p->ID,
                'title'  => $p->post_title,
                'status' => $p->post_status,
                'date'   => wp_date( 'M j, Y H:i', strtotime( $p->post_date ) ),
            ];
        }

        wp_send_json_success( $list );
    }

    public static function ajax_publish_now(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || get_post_type( $post_id ) !== PostType::CPT ) {
            wp_send_json_error( 'Invalid post.' );
        }

        $result = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( [ 'message' => 'Published.' ] );
    }

    public static function ajax_save_drip(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        update_option( 'pz_drip_mode',    $_POST['drip_mode'] === '1' ? '1' : '0' );
        update_option( 'pz_drip_per_day', max( 1, min( 500, absint( $_POST['drip_per_day'] ?? 10 ) ) ) );
        update_option( 'pz_drip_hour',    max( 0, min( 23, absint( $_POST['drip_hour'] ?? 9 ) ) ) );
        update_option( 'pz_import_as',    in_array( $_POST['import_as'] ?? '', [ 'publish', 'draft', 'schedule' ] ) ? $_POST['import_as'] : 'publish' );

        self::reschedule_drip();
        wp_send_json_success( [ 'message' => 'Drip settings saved.' ] );
    }

    public static function ajax_drip_now(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        // Reset last-run date so drip will execute immediately
        delete_option( 'pz_last_drip_date' );
        self::run_drip();

        $count = (int) get_option( 'pz_last_drip_count', 0 );
        wp_send_json_success( [ 'message' => "Drip complete — published {$count} videos." ] );
    }

    public static function ajax_schedule_video(): void {
        check_ajax_referer( 'pz_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized', 403 );

        $video_id   = absint( $_POST['video_id'] ?? 0 );
        $publish_at = sanitize_text_field( $_POST['publish_at'] ?? '' );

        if ( ! $video_id || ! $publish_at ) {
            wp_send_json_error( 'Missing video_id or publish_at.' );
        }

        // Fetch from API
        $data = Api::video( $video_id );
        if ( is_wp_error( $data ) ) {
            wp_send_json_error( $data->get_error_message() );
        }

        // Import as draft first
        $data['id'] = $video_id;
        $post_id = PostType::upsert( $data );
        if ( ! $post_id ) {
            wp_send_json_error( 'Failed to import video.' );
        }

        // Set scheduled date
        $ts = strtotime( $publish_at );
        if ( ! $ts ) {
            wp_send_json_error( 'Invalid date.' );
        }

        $date_gmt  = gmdate( 'Y-m-d H:i:s', $ts );
        $date_local = wp_date( 'Y-m-d H:i:s', $ts );

        $update = [
            'ID'            => $post_id,
            'post_status'   => $ts > time() ? 'future' : 'publish',
            'post_date'     => $date_local,
            'post_date_gmt' => $date_gmt,
        ];

        $result = wp_update_post( $update );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $fmt = wp_date( 'M j, Y \a\t H:i', $ts );
        wp_send_json_success( [ 'message' => "Scheduled for {$fmt}." ] );
    }
}
