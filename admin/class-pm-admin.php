<?php
/**
 * PM_Admin
 *
 * Registers admin menus, settings, and AJAX handlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_Admin {

    const MENU_SLUG          = 'proactive-maintenance';
    const SETTINGS_SLUG      = 'proactive-maintenance-settings';
    const OPTION_SETTINGS    = 'pm_settings';
    const OPTION_REPORT_LOG  = 'pm_report_log';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers (logged-in admin only)
        $actions = [
            'pm_take_snapshot',
            'pm_create_backup',
            'pm_generate_report',
            'pm_clear_snapshot',
            'pm_test_connection',
            'pm_run_pagespeed',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, 'handle_ajax' ] );
        }
    }

    // ── Menu ─────────────────────────────────────────────────────────────────

    public function register_menu() {
        add_menu_page(
            'MPWR Maintenance',
            'MPWR Maintenance',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_dashboard' ],
            'dashicons-clipboard',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Dashboard',
            'Dashboard',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Settings',
            'Settings',
            'manage_options',
            self::SETTINGS_SLUG,
            [ $this, 'render_settings' ]
        );
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function register_settings() {
        register_setting( 'pm_settings_group', self::OPTION_SETTINGS, [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ) {
        $clean = [];
        $clean['client_name']       = sanitize_text_field( $input['client_name'] ?? '' );
        $clean['parent_folder_id']  = sanitize_text_field( $input['parent_folder_id'] ?? '' );
        $clean['service_account']   = sanitize_textarea_field( $input['service_account'] ?? '' );
        $clean['pagespeed_api_key'] = sanitize_text_field( $input['pagespeed_api_key'] ?? '' );
        return $clean;
    }

    // ── Asset enqueueing ─────────────────────────────────────────────────────

    public function enqueue_assets( $hook ) {
        if ( ! in_array( $hook, [
            'toplevel_page_' . self::MENU_SLUG,
            'maintenance_page_' . self::SETTINGS_SLUG,
        ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'pm-admin',
            PM_URL . 'admin/assets/css/admin.css',
            [],
            PM_VERSION
        );

        wp_enqueue_script(
            'pm-admin',
            PM_URL . 'admin/assets/js/admin.js',
            [ 'jquery' ],
            PM_VERSION,
            true
        );

        wp_localize_script( 'pm-admin', 'PM', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'pm_nonce' ),
        ] );
    }

    // ── Views ─────────────────────────────────────────────────────────────────

    public function render_dashboard() {
        $settings   = get_option( self::OPTION_SETTINGS, [] );
        $pre        = PM_Snapshot::get( 'pre' );
        $report_log = get_option( self::OPTION_REPORT_LOG, [] );
        require_once PM_PATH . 'admin/views/page-dashboard.php';
    }

    public function render_settings() {
        $settings = get_option( self::OPTION_SETTINGS, [] );
        require_once PM_PATH . 'admin/views/page-settings.php';
    }

    // ── AJAX dispatcher ───────────────────────────────────────────────────────

    public function handle_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.', 403 );
        }

        check_ajax_referer( 'pm_nonce', 'nonce' );

        $action = sanitize_key( $_POST['action'] ?? '' );

        switch ( $action ) {
            case 'pm_take_snapshot':   $this->ajax_take_snapshot();  break;
            case 'pm_create_backup':   $this->ajax_create_backup();  break;
            case 'pm_generate_report': $this->ajax_generate_report(); break;
            case 'pm_clear_snapshot':  $this->ajax_clear_snapshot(); break;
            case 'pm_test_connection': $this->ajax_test_connection(); break;
            case 'pm_run_pagespeed':   $this->ajax_run_pagespeed();  break;
            default:
                wp_send_json_error( 'Unknown action.' );
        }
    }

    // ── AJAX handlers ─────────────────────────────────────────────────────────

    private function ajax_take_snapshot() {
        $data = PM_Snapshot::take( 'pre' );
        wp_send_json_success( [
            'message'   => 'Pre-update snapshot taken successfully.',
            'timestamp' => date( 'F j, Y H:i:s', $data['timestamp'] ),
            'wp_version'=> $data['wp_version'],
            'php_version'=> $data['php_version'],
            'plugin_count' => count( $data['plugins'] ),
        ] );
    }

    private function ajax_clear_snapshot() {
        PM_Snapshot::clear( 'pre' );
        PM_Snapshot::clear( 'post' );
        wp_send_json_success( [ 'message' => 'Snapshots cleared.' ] );
    }

    private function ajax_create_backup() {
        $settings = get_option( self::OPTION_SETTINGS, [] );

        $result = PM_Backup::create();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $response = [
            'message'  => 'Database backup created: ' . $result['filename'],
            'filename' => $result['filename'],
            'size'     => size_format( $result['size_bytes'] ),
        ];

        // Upload to Drive if configured
        $drive_result = $this->upload_backup_to_drive( $result, $settings );
        if ( is_wp_error( $drive_result ) ) {
            $response['warning'] = 'Backup created locally but Drive upload failed: ' . $drive_result->get_error_message();
        } elseif ( $drive_result ) {
            $response['backup_url']  = $drive_result['url'];
            $response['message']    .= ' — Uploaded to Google Drive.';

            // Persist backup info for the report
            update_option( 'pm_last_backup', [
                'filename'   => $result['filename'],
                'size_bytes' => $result['size_bytes'],
                'url'        => $drive_result['url'],
                'timestamp'  => time(),
            ], false );

            // Clean up local file after successful upload
            @unlink( $result['path'] );
        }

        wp_send_json_success( $response );
    }

    private function ajax_generate_report() {
        $settings = get_option( self::OPTION_SETTINGS, [] );

        if ( empty( $settings['service_account'] ) || empty( $settings['parent_folder_id'] ) ) {
            wp_send_json_error( 'Google Drive is not configured. Please complete the Settings page.' );
        }
        if ( empty( $settings['client_name'] ) ) {
            wp_send_json_error( 'Client name is not set. Please complete the Settings page.' );
        }

        // Post-update snapshot
        $post = PM_Snapshot::take( 'post' );
        $pre  = PM_Snapshot::get( 'pre' );

        // Backup info (if a backup was created this session)
        $backup_info = get_option( 'pm_last_backup', null );
        if ( $backup_info && ( time() - $backup_info['timestamp'] ) > DAY_IN_SECONDS ) {
            $backup_info = null; // stale — don't include
        }

        // PageSpeed — use stored scores as "before", run fresh full data as "after"
        $api_key          = $settings['pagespeed_api_key'] ?? '';
        $pagespeed_before = PM_PageSpeed::get_stored();
        $pagespeed_full   = PM_PageSpeed::run_full( '', $api_key );
        $pagespeed_after  = null;
        if ( ! is_wp_error( $pagespeed_full ) ) {
            PM_PageSpeed::store( $pagespeed_full );
            $pagespeed_after = $pagespeed_full; // scores are included in full data
        }

        // Build main report HTML
        $html = PM_Report::build( $pre, $post, $backup_info, $settings['client_name'], $pagespeed_before, $pagespeed_after );

        // Upload to Drive
        try {
            $google        = new PM_Google( $settings['service_account'] );
            $client_folder = $google->get_or_create_dated_folder(
                $settings['client_name'],
                $settings['parent_folder_id']
            );

            $doc_title = date( 'Y-m-d' ) . ' Maintenance Report';
            $doc       = $google->upload_as_doc( $html, $doc_title, $client_folder );

            // Upload separate PageSpeed report doc if we have full data
            if ( $pagespeed_full && ! is_wp_error( $pagespeed_full ) ) {
                $psi_html  = PM_PageSpeedReport::build( $pagespeed_full, $settings['client_name'] );
                $psi_title = date( 'Y-m-d' ) . ' PageSpeed Report';
                $google->upload_as_doc( $psi_html, $psi_title, $client_folder );
            }

        } catch ( Exception $e ) {
            wp_send_json_error( 'Google Drive error: ' . $e->getMessage() );
        }

        // Log the report
        $log   = get_option( self::OPTION_REPORT_LOG, [] );
        array_unshift( $log, [
            'date'        => date( 'Y-m-d H:i' ),
            'title'       => $doc_title,
            'url'         => $doc['url'],
            'client'      => $settings['client_name'],
            'had_pre'     => ! empty( $pre ),
        ] );
        $log = array_slice( $log, 0, 25 ); // keep last 25
        update_option( self::OPTION_REPORT_LOG, $log, false );

        // Clear snapshots and last backup ref
        PM_Snapshot::clear( 'pre' );
        PM_Snapshot::clear( 'post' );
        delete_option( 'pm_last_backup' );

        wp_send_json_success( [
            'message' => 'Report generated and uploaded to Google Drive.',
            'doc_url' => $doc['url'],
            'title'   => $doc_title,
        ] );
    }

    private function ajax_run_pagespeed() {
        $settings = get_option( self::OPTION_SETTINGS, [] );
        $api_key  = $settings['pagespeed_api_key'] ?? '';
        $previous = PM_PageSpeed::get_stored();

        $full = PM_PageSpeed::run_full( '', $api_key );
        if ( is_wp_error( $full ) ) {
            wp_send_json_error( $full->get_error_message() );
        }

        // Store summary scores only for future before/after comparison
        PM_PageSpeed::store( $full );

        // Upload standalone PageSpeed Google Doc to Drive if configured
        $doc_url = '';
        if ( ! empty( $settings['service_account'] ) && ! empty( $settings['parent_folder_id'] ) && ! empty( $settings['client_name'] ) ) {
            try {
                $google    = new PM_Google( $settings['service_account'] );
                $folder_id = $google->get_or_create_dated_folder( $settings['client_name'], $settings['parent_folder_id'] );
                $doc_title = date( 'Y-m-d' ) . ' PageSpeed Report';
                $html      = PM_PageSpeedReport::build( $full, $settings['client_name'] );
                $doc       = $google->upload_as_doc( $html, $doc_title, $folder_id );
                $doc_url   = $doc['url'];
            } catch ( Exception $e ) {
                // Don't fail the test over a Drive upload error
            }
        }

        $dashboard_html = PM_PageSpeedReport::build_dashboard_html( $full, $previous, $doc_url );

        wp_send_json_success( [
            'message' => 'PageSpeed test complete.',
            'html'    => $dashboard_html,
        ] );
    }

    private function ajax_test_connection() {
        $settings = get_option( self::OPTION_SETTINGS, [] );
        if ( empty( $settings['service_account'] ) ) {
            wp_send_json_error( 'No service account JSON saved yet.' );
        }
        if ( empty( $settings['parent_folder_id'] ) ) {
            wp_send_json_error( 'No parent folder ID saved yet.' );
        }
        try {
            $google = new PM_Google( $settings['service_account'] );
            $result = $google->test_connection( $settings['parent_folder_id'] );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( $result->get_error_message() );
            }
            wp_send_json_success( [ 'message' => 'Connection successful! Google Drive folder is accessible.' ] );
        } catch ( Exception $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function upload_backup_to_drive( array $backup_result, array $settings ) {
        if ( empty( $settings['service_account'] ) || empty( $settings['parent_folder_id'] ) ) {
            return null; // Drive not configured
        }
        try {
            $google        = new PM_Google( $settings['service_account'] );
            $client_folder = $google->get_or_create_dated_folder(
                $settings['client_name'] ?? 'Unnamed Client',
                $settings['parent_folder_id']
            );
            return $google->upload_file(
                $backup_result['path'],
                $backup_result['filename'],
                $client_folder,
                'application/gzip'
            );
        } catch ( Exception $e ) {
            return new WP_Error( 'pm_drive_backup', $e->getMessage() );
        }
    }
}
