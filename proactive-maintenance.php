<?php
/**
 * Plugin Name:  MPWR Maintenance
 * Description:  Snapshot site state, export database backups, and publish maintenance reports to Google Drive.
 * Version:      1.1.0
 * Author:       MPWR Marketing
 * License:      GPL-2.0+
 * Text Domain:  proactive-maintenance
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PM_VERSION',  '1.1.0' );
define( 'PM_PATH',     plugin_dir_path( __FILE__ ) );
define( 'PM_URL',      plugin_dir_url( __FILE__ ) );
define( 'PM_BASENAME', plugin_basename( __FILE__ ) );

// ── Composer autoloader ──────────────────────────────────────────────────────
$autoload = PM_PATH . 'vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
           . '<strong>Proactive Maintenance:</strong> Composer dependencies are missing. '
           . 'Please run <code>composer install</code> inside the plugin folder.</p></div>';
    } );
    return;
}
require_once $autoload;

// ── Includes ─────────────────────────────────────────────────────────────────
require_once PM_PATH . 'includes/class-pm-snapshot.php';
require_once PM_PATH . 'includes/class-pm-backup.php';
require_once PM_PATH . 'includes/class-pm-google.php';
require_once PM_PATH . 'includes/class-pm-pagespeed.php';
require_once PM_PATH . 'includes/class-pm-pagespeed-report.php';
require_once PM_PATH . 'includes/class-pm-report.php';
require_once PM_PATH . 'admin/class-pm-admin.php';

// ── GitHub auto-update ───────────────────────────────────────────────────────
if ( class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
    $pm_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/marcquito/mpwr-maintenance/',
        __FILE__,
        'proactive-maintenance'
    );
    $pm_update_checker->setBranch( 'main' );
}

// ── Boot ─────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    new PM_Admin();
} );

// ── Activation ───────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, function () {
    $backup_dir = WP_CONTENT_DIR . '/pm-backups';
    if ( ! file_exists( $backup_dir ) ) {
        wp_mkdir_p( $backup_dir );
    }
    // Prevent direct web access to backup files.
    $htaccess = $backup_dir . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        file_put_contents( $htaccess, "Deny from all\n" );
    }
} );

// ── Deactivation ─────────────────────────────────────────────────────────────
register_deactivation_hook( __FILE__, function () {
    // Nothing to clean up on deactivate; snapshots remain in DB.
} );
