<?php
/**
 * PM_Snapshot
 *
 * Captures and stores site state to wp_options.
 * Snapshots are keyed as 'pre' (before updates) and 'post' (after updates).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_Snapshot {

    const OPTION_PRE  = 'pm_snapshot_pre';
    const OPTION_POST = 'pm_snapshot_post';

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Take and persist a snapshot.
     *
     * @param string $label 'pre' | 'post'
     * @return array The snapshot data.
     */
    public static function take( $label ) {
        $data = self::collect();
        update_option( self::option_key( $label ), $data, false );
        return $data;
    }

    /**
     * Retrieve a stored snapshot.
     *
     * @param string $label 'pre' | 'post'
     * @return array|null
     */
    public static function get( $label ) {
        return get_option( self::option_key( $label ), null );
    }

    /**
     * Delete a stored snapshot.
     *
     * @param string $label 'pre' | 'post'
     */
    public static function clear( $label ) {
        delete_option( self::option_key( $label ) );
    }

    /**
     * Compare two snapshots and return a structured diff.
     *
     * @param array $pre
     * @param array $post
     * @return array
     */
    public static function compare( array $pre, array $post ) {
        return [
            'wp_core'     => self::diff_scalar( $pre['wp_version'],  $post['wp_version'] ),
            'php'         => self::diff_scalar( $pre['php_version'],  $post['php_version'] ),
            'db_version'  => self::diff_scalar( $pre['db_version'],   $post['db_version'] ),
            'active_theme'=> self::diff_scalar( $pre['active_theme_version'], $post['active_theme_version'] ),
            'plugins'     => self::diff_plugins( $pre['plugins'],     $post['plugins'] ),
        ];
    }

    // ── Data collection ──────────────────────────────────────────────────────

    /**
     * Collect all site data into a single array.
     *
     * @return array
     */
    public static function collect() {
        global $wpdb;

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins   = get_plugins();
        $active_slugs  = get_option( 'active_plugins', [] );
        $plugins_data  = [];

        foreach ( $all_plugins as $slug => $data ) {
            $plugins_data[ $slug ] = [
                'name'    => $data['Name'],
                'version' => $data['Version'],
                'active'  => in_array( $slug, $active_slugs, true ),
                'author'  => $data['Author'],
            ];
        }

        $active_theme  = wp_get_theme();
        $parent_theme  = $active_theme->parent();

        // ── Environment ──────────────────────────────────────────────────────
        $php_ini = [
            'memory_limit'       => ini_get( 'memory_limit' ),
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'upload_max_filesize'=> ini_get( 'upload_max_filesize' ),
            'post_max_size'      => ini_get( 'post_max_size' ),
            'max_input_vars'     => ini_get( 'max_input_vars' ),
        ];

        // ── DB size ──────────────────────────────────────────────────────────
        $db_size_bytes = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT SUM(data_length + index_length)
                   FROM information_schema.TABLES
                  WHERE table_schema = %s",
                DB_NAME
            )
        );

        // ── Admin users ──────────────────────────────────────────────────────
        $admin_users = count( get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] ) );

        // ── Site health signals ───────────────────────────────────────────────
        $health = self::collect_health_signals();

        return [
            'timestamp'            => time(),
            'site_url'             => get_site_url(),
            'wp_version'           => get_bloginfo( 'version' ),
            'db_version'           => $wpdb->db_version(),
            'db_size_bytes'        => (int) $db_size_bytes,
            'php_version'          => phpversion(),
            'php_ini'              => $php_ini,
            'server_software'      => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'active_theme_name'    => $active_theme->get( 'Name' ),
            'active_theme_version' => $active_theme->get( 'Version' ),
            'parent_theme_name'    => $parent_theme ? $parent_theme->get( 'Name' ) : null,
            'parent_theme_version' => $parent_theme ? $parent_theme->get( 'Version' ) : null,
            'plugins'              => $plugins_data,
            'admin_user_count'     => $admin_users,
            'wp_debug'             => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
            'wp_debug_log'         => ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ),
            'wp_debug_display'     => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ),
            'disallow_file_edit'   => ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ),
            'is_ssl'               => is_ssl(),
            'is_multisite'         => is_multisite(),
            'health'               => $health,
        ];
    }

    // ── Health signals ───────────────────────────────────────────────────────

    private static function collect_health_signals() {
        $signals = [];

        // PHP version recommendation
        $php = phpversion();
        if ( version_compare( $php, '8.0', '<' ) ) {
            $signals[] = [
                'type'    => 'warning',
                'label'   => 'PHP Version',
                'message' => "PHP {$php} is below the recommended 8.0+.",
            ];
        }

        // WP_DEBUG should be off on production
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $signals[] = [
                'type'    => 'warning',
                'label'   => 'WP_DEBUG Enabled',
                'message' => 'WP_DEBUG is enabled. Disable on production sites.',
            ];
        }

        // WP_DEBUG_DISPLAY should be off
        if ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
            $signals[] = [
                'type'    => 'warning',
                'label'   => 'WP_DEBUG_DISPLAY Enabled',
                'message' => 'Debug output is being displayed to visitors.',
            ];
        }

        // File editing disabled (security best practice)
        if ( ! ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) ) {
            $signals[] = [
                'type'    => 'info',
                'label'   => 'File Editor Active',
                'message' => 'Theme/plugin file editor is enabled. Consider setting DISALLOW_FILE_EDIT.',
            ];
        }

        // SSL check
        if ( ! is_ssl() ) {
            $signals[] = [
                'type'    => 'error',
                'label'   => 'SSL Not Active',
                'message' => 'Site is not being served over HTTPS.',
            ];
        }

        // SSL certificate expiry (if OpenSSL available and not localhost)
        $ssl_expiry = self::check_ssl_expiry();
        if ( $ssl_expiry !== null ) {
            if ( $ssl_expiry['days'] <= 30 ) {
                $signals[] = [
                    'type'    => $ssl_expiry['days'] <= 7 ? 'error' : 'warning',
                    'label'   => 'SSL Certificate Expiring',
                    'message' => "Certificate expires in {$ssl_expiry['days']} days ({$ssl_expiry['date']}).",
                ];
            } else {
                $signals[] = [
                    'type'    => 'ok',
                    'label'   => 'SSL Certificate',
                    'message' => "Valid — expires {$ssl_expiry['date']} ({$ssl_expiry['days']} days).",
                ];
            }
        }

        // Inactive plugins installed (security risk)
        if ( function_exists( 'get_plugins' ) ) {
            $all     = get_plugins();
            $active  = get_option( 'active_plugins', [] );
            $inactive_count = count( $all ) - count( array_filter(
                array_keys( $all ),
                fn( $slug ) => in_array( $slug, $active, true )
            ) );
            if ( $inactive_count > 0 ) {
                $signals[] = [
                    'type'    => 'info',
                    'label'   => 'Inactive Plugins',
                    'message' => "{$inactive_count} inactive plugin(s) installed. Consider removing unused plugins.",
                ];
            }
        }

        // Memory limit warning
        $mem_raw   = ini_get( 'memory_limit' );
        $mem_bytes = wp_convert_hr_to_bytes( $mem_raw );
        if ( $mem_bytes < 64 * 1024 * 1024 ) {
            $signals[] = [
                'type'    => 'warning',
                'label'   => 'Low Memory Limit',
                'message' => "PHP memory limit is only {$mem_raw}. 256M or higher is recommended.",
            ];
        }

        return $signals;
    }

    private static function check_ssl_expiry() {
        if ( ! function_exists( 'stream_context_create' ) ) {
            return null;
        }
        $host = parse_url( get_site_url(), PHP_URL_HOST );
        if ( ! $host || in_array( $host, [ 'localhost', '127.0.0.1' ], true ) ) {
            return null;
        }
        $context = stream_context_create( [
            'ssl' => [ 'capture_peer_cert' => true, 'verify_peer' => false ],
        ] );
        $client = @stream_socket_client(
            "ssl://{$host}:443", $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $context
        );
        if ( ! $client ) {
            return null;
        }
        $params  = stream_context_get_params( $client );
        $cert    = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ?? '' );
        fclose( $client );
        if ( empty( $cert['validTo_time_t'] ) ) {
            return null;
        }
        $days = (int) floor( ( $cert['validTo_time_t'] - time() ) / 86400 );
        return [
            'days' => $days,
            'date' => date( 'F j, Y', $cert['validTo_time_t'] ),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function option_key( $label ) {
        return $label === 'pre' ? self::OPTION_PRE : self::OPTION_POST;
    }

    private static function diff_scalar( $before, $after ) {
        return [
            'before'   => $before,
            'after'    => $after,
            'changed'  => $before !== $after,
        ];
    }

    private static function diff_plugins( array $before_map, array $after_map ) {
        $all_slugs = array_unique( array_merge( array_keys( $before_map ), array_keys( $after_map ) ) );
        $results   = [];

        foreach ( $all_slugs as $slug ) {
            $b = $before_map[ $slug ] ?? null;
            $a = $after_map[ $slug ] ?? null;

            $name = $a['name'] ?? $b['name'] ?? $slug;

            if ( $b && $a ) {
                $results[ $slug ] = [
                    'name'            => $name,
                    'version_before'  => $b['version'],
                    'version_after'   => $a['version'],
                    'version_changed' => $b['version'] !== $a['version'],
                    'active_before'   => $b['active'],
                    'active_after'    => $a['active'],
                    'status'          => 'existing',
                ];
            } elseif ( ! $b && $a ) {
                $results[ $slug ] = [
                    'name'            => $name,
                    'version_before'  => '—',
                    'version_after'   => $a['version'],
                    'version_changed' => true,
                    'active_before'   => false,
                    'active_after'    => $a['active'],
                    'status'          => 'added',
                ];
            } else {
                $results[ $slug ] = [
                    'name'            => $name,
                    'version_before'  => $b['version'],
                    'version_after'   => '—',
                    'version_changed' => true,
                    'active_before'   => $b['active'],
                    'active_after'    => false,
                    'status'          => 'removed',
                ];
            }
        }

        uasort( $results, function ( $a, $b ) {
            // Updated first, then alphabetical
            if ( $a['version_changed'] !== $b['version_changed'] ) {
                return $a['version_changed'] ? -1 : 1;
            }
            return strcmp( $a['name'], $b['name'] );
        } );

        return $results;
    }
}
