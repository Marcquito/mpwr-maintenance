<?php
/**
 * PM_Report
 *
 * Builds the HTML report document that gets uploaded to Google Docs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_Report {

    /**
     * Build the full HTML report.
     *
     * @param array      $pre         Pre-update snapshot (may be null if no pre snapshot taken).
     * @param array      $post        Post-update snapshot.
     * @param array|null $backup_info ['filename', 'url', 'size_bytes'] or null.
     * @param string     $client_name
     * @return string  HTML string suitable for uploading as a Google Doc.
     */
    public static function build( $pre, array $post, $backup_info, $client_name, $pagespeed_before = null, $pagespeed_after = null ) {
        $diff        = $pre ? PM_Snapshot::compare( $pre, $post ) : null;
        $date_label  = date( 'F j, Y' );
        $site_url    = $post['site_url'];
        $performed   = get_bloginfo( 'name' );

        $html  = self::styles();
        $html .= "<body>\n";
        $html .= "<h1>Maintenance Report &mdash; " . esc_html( $client_name ) . "</h1>\n";
        $html .= "<p class='meta'>Site: <strong>" . esc_html( $site_url ) . "</strong> &nbsp;|&nbsp; Date: <strong>{$date_label}</strong></p>\n";

        if ( ! $pre ) {
            $html .= "<p class='notice'>&#9888; No pre-update snapshot was found. Version &ldquo;Before&rdquo; columns are unavailable for this report.</p>\n";
        }

        // ── Summary ──────────────────────────────────────────────────────────
        $html .= "<h2>Summary</h2>\n<table>\n";
        $html .= self::tr( 'Site', esc_html( $site_url ) );
        $html .= self::tr( 'Report Date', $date_label );
        $html .= self::tr( 'Report Generated At', date( 'H:i:s' ) . ' (server time)' );
        $html .= self::tr( 'Pre-Snapshot Taken', $pre ? date( 'F j, Y H:i', $pre['timestamp'] ) : '&mdash;' );
        $html .= self::tr( 'Post-Snapshot Taken', date( 'F j, Y H:i', $post['timestamp'] ) );
        $html .= "</table>\n";

        // ── WordPress Core ────────────────────────────────────────────────────
        $html .= "<h2>WordPress Core</h2>\n<table>\n";
        $html .= "<tr><th>Item</th><th>Before</th><th>After</th><th>Status</th></tr>\n";
        $html .= self::diff_row( 'WordPress Version', $diff['wp_core'] ?? null, $post['wp_version'] );
        $html .= self::diff_row( 'Database Version',  $diff['db_version'] ?? null, $post['db_version'] );
        $db_size = self::format_bytes( $post['db_size_bytes'] );
        $html .= self::tr_4( 'Database Size', '&mdash;', $db_size, '' );
        $html .= "</table>\n";

        // ── PHP & Environment ─────────────────────────────────────────────────
        $html .= "<h2>PHP &amp; Server Environment</h2>\n<table>\n";
        $html .= "<tr><th>Setting</th><th>Before</th><th>After</th><th>Status</th></tr>\n";
        $html .= self::diff_row( 'PHP Version', $diff['php'] ?? null, $post['php_version'] );
        $html .= self::tr_4( 'Memory Limit',        '&mdash;', $post['php_ini']['memory_limit'],        '' );
        $html .= self::tr_4( 'Max Execution Time',  '&mdash;', $post['php_ini']['max_execution_time'],  '' );
        $html .= self::tr_4( 'Upload Max Filesize', '&mdash;', $post['php_ini']['upload_max_filesize'], '' );
        $html .= self::tr_4( 'Post Max Size',       '&mdash;', $post['php_ini']['post_max_size'],       '' );
        $html .= self::tr_4( 'Max Input Vars',      '&mdash;', $post['php_ini']['max_input_vars'],      '' );
        $html .= self::tr_4( 'Server Software',     '&mdash;', esc_html( $post['server_software'] ),   '' );
        $html .= "</table>\n";

        // ── Active Theme ──────────────────────────────────────────────────────
        $html .= "<h2>Active Theme</h2>\n<table>\n";
        $html .= "<tr><th>Item</th><th>Before</th><th>After</th><th>Status</th></tr>\n";
        $html .= self::diff_row(
            'Theme Version (' . esc_html( $post['active_theme_name'] ) . ')',
            $diff['active_theme'] ?? null,
            $post['active_theme_version']
        );
        if ( $post['parent_theme_name'] ) {
            $html .= self::tr_4( 'Parent Theme', '&mdash;', esc_html( $post['parent_theme_name'] . ' ' . $post['parent_theme_version'] ), '' );
        }
        $html .= "</table>\n";

        // ── Plugin Updates ────────────────────────────────────────────────────
        $html .= "<h2>Plugins</h2>\n";

        if ( $diff ) {
            $updated  = array_filter( $diff['plugins'], fn( $p ) => $p['version_changed'] );
            $unchanged = array_filter( $diff['plugins'], fn( $p ) => ! $p['version_changed'] );

            if ( $updated ) {
                $html .= "<h3>Changed (" . count( $updated ) . ")</h3>\n";
                $html .= "<table>\n<tr><th>Plugin</th><th>Before</th><th>After</th><th>Status</th></tr>\n";
                foreach ( $updated as $slug => $p ) {
                    $status_label = self::plugin_status_label( $p );
                    $html .= self::tr_4(
                        esc_html( $p['name'] ),
                        esc_html( $p['version_before'] ),
                        esc_html( $p['version_after'] ),
                        $status_label
                    );
                }
                $html .= "</table>\n";
            } else {
                $html .= "<p>No plugin version changes detected.</p>\n";
            }

            if ( $unchanged ) {
                $html .= "<h3>No Changes (" . count( $unchanged ) . ")</h3>\n";
                $html .= "<table>\n<tr><th>Plugin</th><th>Version</th><th>Active</th></tr>\n";
                foreach ( $unchanged as $slug => $p ) {
                    $active = $p['active_after'] ? '&#10003; Active' : 'Inactive';
                    $html .= self::tr_3(
                        esc_html( $p['name'] ),
                        esc_html( $p['version_after'] ),
                        $active
                    );
                }
                $html .= "</table>\n";
            }
        } else {
            // No pre-snapshot — just show current state
            $html .= "<table>\n<tr><th>Plugin</th><th>Version</th><th>Active</th></tr>\n";
            foreach ( $post['plugins'] as $slug => $p ) {
                $html .= self::tr_3(
                    esc_html( $p['name'] ),
                    esc_html( $p['version'] ),
                    $p['active'] ? '&#10003; Active' : 'Inactive'
                );
            }
            $html .= "</table>\n";
        }

        // ── Site Health Signals ───────────────────────────────────────────────
        $html .= "<h2>Site Health &amp; Security</h2>\n<table>\n";
        $html .= "<tr><th>Check</th><th>Status</th><th>Detail</th></tr>\n";

        $health_items = $post['health'] ?? [];
        if ( empty( $health_items ) ) {
            $html .= "<tr><td colspan='3'>No issues detected.</td></tr>\n";
        } else {
            foreach ( $health_items as $item ) {
                $icon = match( $item['type'] ) {
                    'error'   => '&#10060;',
                    'warning' => '&#9888;',
                    'ok'      => '&#10003;',
                    default   => '&#8505;',
                };
                $html .= "<tr><td>" . esc_html( $item['label'] ) . "</td>"
                       . "<td>{$icon} " . ucfirst( $item['type'] ) . "</td>"
                       . "<td>" . esc_html( $item['message'] ) . "</td></tr>\n";
            }
        }

        // Additional environment flags
        $html .= self::flag_row( 'WP_DEBUG',          $post['wp_debug'],          'Enabled', 'Off', 'warning', 'ok' );
        $html .= self::flag_row( 'WP_DEBUG_DISPLAY',  $post['wp_debug_display'],  'Enabled', 'Off', 'warning', 'ok' );
        $html .= self::flag_row( 'WP_DEBUG_LOG',      $post['wp_debug_log'],      'Enabled', 'Off', 'info',    'ok' );
        $html .= self::flag_row( 'DISALLOW_FILE_EDIT',$post['disallow_file_edit'],'Yes &#10003;', 'No', 'ok', 'info' );
        $html .= self::flag_row( 'SSL Active',        $post['is_ssl'],            'Yes &#10003;', 'No &#10060;', 'ok', 'error' );
        $html .= self::tr_3( 'Admin User Count', (string) $post['admin_user_count'], '' );

        $html .= "</table>\n";

        // ── PageSpeed Insights ────────────────────────────────────────────────
        $html .= "<h2>PageSpeed Insights</h2>\n";
        $tested_url = $pagespeed_after['url'] ?? $pagespeed_before['url'] ?? get_home_url();
        $html .= "<p class='meta'>URL tested: <strong>" . esc_html( $tested_url ) . "</strong></p>\n";

        if ( ! $pagespeed_after && ! $pagespeed_before ) {
            $html .= "<p>No PageSpeed data available for this report.</p>\n";
        } else {
            $before_label = $pagespeed_before
                ? 'Previous (' . date( 'M j, Y', $pagespeed_before['timestamp'] ) . ')'
                : 'Previous';
            $after_label = $pagespeed_after
                ? 'Current (' . date( 'M j, Y', $pagespeed_after['timestamp'] ) . ')'
                : 'Current';

            foreach ( [ 'mobile' => '📱 Mobile', 'desktop' => '🖥 Desktop' ] as $strategy => $label ) {
                $html .= "<h3>{$label}</h3>\n";
                $html .= "<table>\n";
                $html .= "<tr><th>Category</th><th>{$before_label}</th><th>{$after_label}</th><th>Change</th></tr>\n";

                $categories = [
                    'performance'    => 'Performance',
                    'accessibility'  => 'Accessibility',
                    'best_practices' => 'Best Practices',
                    'seo'            => 'SEO',
                ];

                foreach ( $categories as $key => $cat_label ) {
                    $before_score = $pagespeed_before['scores'][ $strategy ][ $key ] ?? null;
                    $after_score  = $pagespeed_after['scores'][ $strategy ][ $key ]  ?? null;

                    $before_cell = $before_score !== null ? self::score_badge( $before_score ) : '&mdash;';
                    $after_cell  = $after_score  !== null ? self::score_badge( $after_score )  : '&mdash;';

                    $change_cell = '';
                    if ( $before_score !== null && $after_score !== null ) {
                        $diff = $after_score - $before_score;
                        if ( $diff > 0 )      $change_cell = "<span style='color:#2e7d32'>&#9650; +{$diff}</span>";
                        elseif ( $diff < 0 )  $change_cell = "<span style='color:#c62828'>&#9660; {$diff}</span>";
                        else                  $change_cell = "<span style='color:#777'>&#8212; No change</span>";
                    }

                    $html .= "<tr><td>{$cat_label}</td><td>{$before_cell}</td><td>{$after_cell}</td><td>{$change_cell}</td></tr>\n";
                }

                $html .= "</table>\n";
            }
        }

        // ── Backup ────────────────────────────────────────────────────────────
        $html .= "<h2>Database Backup</h2>\n<table>\n";
        if ( $backup_info ) {
            $html .= self::tr( 'Filename',  esc_html( $backup_info['filename'] ) );
            $html .= self::tr( 'Size',      self::format_bytes( $backup_info['size_bytes'] ) );
            $html .= self::tr( 'Drive Link', isset( $backup_info['url'] )
                ? '<a href="' . esc_url( $backup_info['url'] ) . '">View in Google Drive</a>'
                : 'Stored locally (not yet uploaded)' );
        } else {
            $html .= "<tr><td colspan='2'>No backup was created for this maintenance session.</td></tr>\n";
        }
        $html .= "</table>\n";

        $html .= "<p class='footer'>Generated by Proactive Maintenance plugin &mdash; " . date( 'Y-m-d H:i:s' ) . " UTC</p>\n";
        $html .= "</body>\n</html>";

        return $html;
    }

    // ── HTML helpers ─────────────────────────────────────────────────────────

    private static function styles() {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body  { font-family: Arial, sans-serif; font-size: 11pt; color: #222; }
  h1    { font-size: 18pt; color: #1a3a5c; border-bottom: 2px solid #1a3a5c; padding-bottom: 6px; }
  h2    { font-size: 13pt; color: #1a3a5c; margin-top: 24px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
  h3    { font-size: 11pt; color: #444; margin-top: 14px; }
  table { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
  th    { background: #1a3a5c; color: #fff; text-align: left; padding: 6px 10px; font-size: 10pt; }
  td    { padding: 5px 10px; border-bottom: 1px solid #e0e0e0; font-size: 10pt; vertical-align: top; }
  tr:nth-child(even) td { background: #f7f9fc; }
  .meta    { color: #555; font-size: 10pt; margin-bottom: 16px; }
  .notice  { background: #fff8e1; border-left: 4px solid #f9a825; padding: 8px 12px; color: #5d4037; }
  .footer  { color: #999; font-size: 9pt; margin-top: 32px; border-top: 1px solid #eee; padding-top: 8px; }
  .changed { background: #e8f5e9 !important; }
  .removed { background: #fce4ec !important; }
  .added   { background: #e3f2fd !important; }
</style>
</head>

HTML;
    }

    private static function tr( $label, $value ) {
        return "<tr><td><strong>" . esc_html( $label ) . "</strong></td><td>{$value}</td></tr>\n";
    }

    private static function tr_3( $a, $b, $c ) {
        return "<tr><td>{$a}</td><td>{$b}</td><td>{$c}</td></tr>\n";
    }

    private static function tr_4( $a, $b, $c, $d ) {
        return "<tr><td>{$a}</td><td>{$b}</td><td>{$c}</td><td>{$d}</td></tr>\n";
    }

    private static function diff_row( $label, $diff_entry, $current_value ) {
        if ( ! $diff_entry ) {
            return self::tr_4( esc_html( $label ), '&mdash;', esc_html( $current_value ), '' );
        }
        $changed = $diff_entry['changed'];
        $class   = $changed ? ' class="changed"' : '';
        $status  = $changed ? '&#8593; Updated' : '&#8212; No change';
        return "<tr{$class}><td>" . esc_html( $label ) . "</td>"
             . "<td>" . esc_html( $diff_entry['before'] ) . "</td>"
             . "<td>" . esc_html( $diff_entry['after'] ) . "</td>"
             . "<td>{$status}</td></tr>\n";
    }

    private static function flag_row( $label, $value, $true_label, $false_label, $true_class, $false_class ) {
        $text = $value ? $true_label : $false_label;
        return "<tr><td>" . esc_html( $label ) . "</td><td colspan='2'>{$text}</td></tr>\n";
    }

    private static function plugin_status_label( array $p ) {
        return match( $p['status'] ) {
            'added'   => '&#43; Added',
            'removed' => '&#8722; Removed',
            default   => $p['version_changed'] ? '&#8593; Updated' : '&mdash;',
        };
    }

    private static function score_badge( $score ) {
        if ( $score >= 90 )      $color = '#2e7d32'; // green
        elseif ( $score >= 50 )  $color = '#c87820'; // orange
        else                     $color = '#c62828'; // red

        return "<strong style='color:{$color}'>{$score}</strong>";
    }

    private static function format_bytes( $bytes ) {
        if ( $bytes === null ) return '&mdash;';
        $bytes = (int) $bytes;
        if ( $bytes < 1024 )            return $bytes . ' B';
        if ( $bytes < 1024 * 1024 )     return round( $bytes / 1024, 1 ) . ' KB';
        if ( $bytes < 1024 ** 3 )       return round( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
        return round( $bytes / ( 1024 ** 3 ), 2 ) . ' GB';
    }
}
