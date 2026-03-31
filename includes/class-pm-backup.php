<?php
/**
 * PM_Backup
 *
 * Exports the WordPress database to a compressed .sql.gz file
 * stored in wp-content/pm-backups/.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_Backup {

    const BACKUP_DIR_NAME = 'pm-backups';

    /**
     * Create a full database backup.
     *
     * @return array{path: string, filename: string, size_bytes: int}|WP_Error
     */
    public static function create() {
        global $wpdb;

        $dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
        }

        $filename  = sanitize_file_name(
            get_bloginfo( 'name' ) . '_db_' . date( 'Y-m-d_His' ) . '.sql'
        );
        $filepath  = $dir . '/' . $filename;

        // ── Collect SQL ───────────────────────────────────────────────────────
        $sql = self::build_sql( $wpdb );

        // ── Write file (gzip if available) ────────────────────────────────────
        if ( function_exists( 'gzopen' ) ) {
            $gz = gzopen( $filepath . '.gz', 'w9' );
            if ( ! $gz ) {
                return new WP_Error( 'pm_backup_write', 'Could not open file for writing.' );
            }
            gzwrite( $gz, $sql );
            gzclose( $gz );
            $final_path     = $filepath . '.gz';
            $final_filename = $filename . '.gz';
        } else {
            if ( file_put_contents( $filepath, $sql ) === false ) {
                return new WP_Error( 'pm_backup_write', 'Could not write backup file.' );
            }
            $final_path     = $filepath;
            $final_filename = $filename;
        }

        return [
            'path'       => $final_path,
            'filename'   => $final_filename,
            'size_bytes' => filesize( $final_path ),
        ];
    }

    /**
     * Delete backup files older than $days days.
     *
     * @param int $days
     */
    public static function prune( $days = 30 ) {
        $dir   = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
        $files = glob( $dir . '/*.sql*' );
        if ( ! $files ) {
            return;
        }
        $cutoff = time() - ( $days * DAY_IN_SECONDS );
        foreach ( $files as $file ) {
            if ( filemtime( $file ) < $cutoff ) {
                @unlink( $file );
            }
        }
    }

    // ── SQL builder ──────────────────────────────────────────────────────────

    private static function build_sql( $wpdb ) {
        $output  = "-- Proactive Maintenance Database Backup\n";
        $output .= '-- Site: ' . get_site_url() . "\n";
        $output .= '-- Date: ' . date( 'Y-m-d H:i:s' ) . " UTC\n";
        $output .= '-- WordPress: ' . get_bloginfo( 'version' ) . "\n";
        $output .= "-- -------------------------------------------------------\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $output .= "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
        $output .= "SET NAMES utf8mb4;\n\n";

        $tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );
        foreach ( $tables as $row ) {
            $table   = $row[0];
            $output .= self::dump_table( $wpdb, $table );
        }

        $output .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
        return $output;
    }

    private static function dump_table( $wpdb, $table ) {
        $output = "\n-- Table: `{$table}`\n";
        $output .= "DROP TABLE IF EXISTS `{$table}`;\n";

        // CREATE TABLE statement
        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( $create ) {
            $output .= $create[1] . ";\n\n";
        }

        // Rows — batch in groups of 500 to avoid memory exhaustion on large tables
        $offset     = 0;
        $batch_size = 500;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch_size, $offset ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            $columns = '`' . implode( '`, `', array_keys( $rows[0] ) ) . '`';

            foreach ( $rows as $row ) {
                $values = array_map( function ( $val ) use ( $wpdb ) {
                    if ( $val === null ) {
                        return 'NULL';
                    }
                    return "'" . $wpdb->_real_escape( $val ) . "'";
                }, array_values( $row ) );

                $output .= "INSERT INTO `{$table}` ({$columns}) VALUES ("
                         . implode( ', ', $values ) . ");\n";
            }

            $offset += $batch_size;
        } while ( count( $rows ) === $batch_size );

        return $output . "\n";
    }
}
