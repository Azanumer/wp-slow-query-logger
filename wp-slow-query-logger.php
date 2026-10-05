<?php
/**
 * Plugin Name:       WP Slow Query Logger
 * Plugin URI:        https://github.com/Azanumer/wp-slow-query-logger
 * Description:       Finds the slow pages on your WordPress site. Logs every page load slower than your
 *                    threshold (default 2 seconds) with load time, SQL query count, query time and memory
 *                    usage, then shows the worst offenders under Tools → Slow Requests.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wp-slow-query-logger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WSQL_VERSION', '1.0.0' );
define( 'WSQL_TABLE', 'wsql_slow_requests' );
// Requests slower than this (milliseconds) get logged. Changeable via the `wsql_threshold_ms` filter.
define( 'WSQL_DEFAULT_THRESHOLD_MS', 2000 );

/**
 * Create the log table on activation.
 */
function wsql_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . WSQL_TABLE;
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta(
        "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            request_url VARCHAR(191) NOT NULL,
            request_method VARCHAR(10) NOT NULL,
            load_ms INT UNSIGNED NOT NULL,
            query_count SMALLINT UNSIGNED NOT NULL,
            query_ms INT UNSIGNED NOT NULL,
            memory_mb DECIMAL(8,2) UNSIGNED NOT NULL,
            client_ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY load_ms (load_ms),
            KEY created_at (created_at)
        ) {$charset};"
    );
    add_option( 'wsql_version', WSQL_VERSION );
}
register_activation_hook( __FILE__, 'wsql_activate' );

/**
 * Drop the log table when the plugin is uninstalled (fresh installs stay clean).
 */
function wsql_uninstall() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . WSQL_TABLE );
    delete_option( 'wsql_version' );
}
register_uninstall_hook( __FILE__, 'wsql_uninstall' );

/**
 * Decide whether the current request should even be measured.
 * Skip: admin-ajax heartbeats, CRON, REST health checks and logged-in admins,
 * since those skew the numbers and are usually not real visitor traffic.
 */
function wsql_should_measure() {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
        return false;
    }
    if ( current_user_can( 'manage_options' ) ) {
        return false;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }
    return true;
}

/**
 * On shutdown, if the page was slow enough, write one row to the log table.
 * Uses shutdown instead of wp_footer so it also captures redirects and
 * short-circuited requests.
 */
function wsql_maybe_log_slow_request() {
    if ( ! wsql_should_measure() ) {
        return;
    }

    global $wpdb;

    $threshold_ms = (int) apply_filters( 'wsql_threshold_ms', WSQL_DEFAULT_THRESHOLD_MS );
    $load_ms      = (int) ( timer_stop( 0, 3 ) * 1000 );

    if ( $load_ms < $threshold_ms ) {
        return; // Fast page — nothing to log.
    }

    $query_count = (int) get_num_queries();
    $query_ms    = 0;
    if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
        // SAVEQUERIES may be off (default) — then per-query timing is unavailable.
        foreach ( $wpdb->queries as $q ) {
            $query_ms += (float) $q[1] * 1000;
        }
    }
    $query_ms = (int) $query_ms;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $wpdb->insert(
        $wpdb->prefix . WSQL_TABLE,
        array(
            'request_url'    => substr( esc_url_raw( home_url( add_query_arg( null, null ) ) ), 0, 191 ),
            'request_method' => substr( sanitize_key( $_SERVER['REQUEST_METHOD'] ?? 'GET' ), 0, 10 ),
            'load_ms'        => $load_ms,
            'query_count'    => $query_count,
            'query_ms'       => $query_ms,
            'memory_mb'      => round( memory_get_peak_usage( true ) / 1048576, 2 ),
            'client_ip'      => substr( sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ),
            'created_at'     => current_time( 'mysql' ),
        ),
        array( '%s', '%s', '%d', '%d', '%d', '%f', '%s', '%s' )
    );
}
add_action( 'shutdown', 'wsql_maybe_log_slow_request', 999 );

/**
 * Register Tools → Slow Requests admin page.
 */
function wsql_admin_menu() {
    add_management_page(
        __( 'Slow Requests', 'wp-slow-query-logger' ),
        __( 'Slow Requests', 'wp-slow-query-logger' ),
        'manage_options',
        'wsql-slow-requests',
        'wsql_render_admin_page'
    );
}
add_action( 'admin_menu', 'wsql_admin_menu' );

/**
 * Render the log viewer: slowest-first table with pagination + a "clear log" button.
 */
function wsql_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-slow-query-logger' ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . WSQL_TABLE;

    // Clear-log action (nonce protected).
    if ( isset( $_POST['wsql_clear'] ) && check_admin_referer( 'wsql_clear_log' ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Slow request log cleared.', 'wp-slow-query-logger' ) . '</p></div>';
    }

    $per_page = 25;
    $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset   = ( $paged - 1 ) * $per_page;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
    $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY load_ms DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ),
        ARRAY_A
    );
    // phpcs:enable

    $threshold_ms = (int) apply_filters( 'wsql_threshold_ms', WSQL_DEFAULT_THRESHOLD_MS );
    $pages        = (int) ceil( $total / $per_page );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Slow Requests', 'wp-slow-query-logger' ); ?></h1>
        <p>
            <?php
            printf(
                /* translators: %d: threshold in milliseconds */
                esc_html__( 'Logging page loads slower than %d ms. Adjust with the %s filter in your theme.', 'wp-slow-query-logger' ),
                esc_html( $threshold_ms ),
                '<code>wsql_threshold_ms</code>'
            );
            ?>
        </p>

        <?php if ( empty( $rows ) ) : ?>
            <div class="notice notice-info inline"><p><?php esc_html_e( 'No slow requests logged yet. Either your site is fast, or no visitor traffic has been measured.', 'wp-slow-query-logger' ); ?></p></div>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'URL', 'wp-slow-query-logger' ); ?></th>
                        <th><?php esc_html_e( 'Load', 'wp-slow-query-logger' ); ?></th>
                        <th><?php esc_html_e( 'SQL queries', 'wp-slow-query-logger' ); ?></th>
                        <th><?php esc_html_e( 'SQL time', 'wp-slow-query-logger' ); ?></th>
                        <th><?php esc_html_e( 'Peak memory', 'wp-slow-query-logger' ); ?></th>
                        <th><?php esc_html_e( 'When', 'wp-slow-query-logger' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $row['request_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $row['request_url'], PHP_URL_PATH ) ?: '/' ); ?></a></td>
                            <td><strong><?php echo esc_html( number_format_i18n( $row['load_ms'] ) ); ?> ms</strong></td>
                            <td><?php echo esc_html( $row['query_count'] ); ?></td>
                            <td><?php echo esc_html( number_format_i18n( $row['query_ms'] ) ); ?> ms</td>
                            <td><?php echo esc_html( $row['memory_mb'] ); ?> MB</td>
                            <td><?php echo esc_html( $row['created_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $paged,
                        'total'     => $pages,
                        'prev_text' => __( '&laquo;' ),
                        'next_text' => __( '&raquo;' ),
                    ) );
                    ?>
                </div></div>
            <?php endif; ?>

            <form method="post" style="margin-top: 1em;">
                <?php wp_nonce_field( 'wsql_clear_log' ); ?>
                <input type="submit" name="wsql_clear" class="button button-secondary"
                       value="<?php esc_attr_e( 'Clear log', 'wp-slow-query-logger' ); ?>"
                       onclick="return confirm('<?php esc_attr_e( 'Delete all logged slow requests?', 'wp-slow-query-logger' ); ?>');">
            </form>
        <?php endif; ?>
    </div>
    <?php
}
