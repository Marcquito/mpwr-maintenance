<?php
/**
 * Dashboard page view.
 *
 * Available variables:
 *   $settings    – plugin options array
 *   $pre         – pre-update snapshot array or null
 *   $report_log  – array of recent reports
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function pm_score_label( $score ) {
    if ( $score >= 90 )     return '<span class="pm-score pm-score--good">Good</span>';
    if ( $score >= 50 )     return '<span class="pm-score pm-score--needs-improvement">Needs Improvement</span>';
    return '<span class="pm-score pm-score--poor">Poor</span>';
}

$configured = ! empty( $settings['client_name'] )
           && ! empty( $settings['service_account'] )
           && ! empty( $settings['parent_folder_id'] );

$pre_date      = $pre ? date( 'F j, Y \a\t H:i', $pre['timestamp'] ) : null;
$pagespeed_last = PM_PageSpeed::get_stored();
?>
<div class="wrap pm-wrap">

  <h1 class="pm-page-title">
    <span class="dashicons dashicons-clipboard"></span>
    MPWR Maintenance
  </h1>

  <?php if ( ! $configured ) : ?>
  <div class="notice notice-warning">
    <p>
      <strong>Almost ready!</strong>
      Please <a href="<?php echo esc_url( admin_url( 'admin.php?page=proactive-maintenance-settings' ) ); ?>">complete the Settings</a>
      before running a report.
    </p>
  </div>
  <?php endif; ?>

  <div class="pm-grid">

    <!-- ── Workflow ──────────────────────────────────────────────────────── -->
    <div class="pm-card pm-card--workflow">
      <h2>Maintenance Workflow</h2>
      <p class="pm-muted">Follow these steps in order during each maintenance session.</p>

      <ol class="pm-steps">

        <!-- Step 1: Pre-update snapshot -->
        <li class="pm-step <?php echo $pre ? 'pm-step--done' : ''; ?>">
          <div class="pm-step-header">
            <span class="pm-step-num">1</span>
            <div>
              <strong>Take Pre-Update Snapshot</strong>
              <p class="pm-muted">Records current plugin versions, WP version, PHP, and site health before any changes.</p>
              <?php if ( $pre ) : ?>
                <p class="pm-status pm-status--ok">&#10003; Snapshot taken: <?php echo esc_html( $pre_date ); ?></p>
              <?php endif; ?>
            </div>
          </div>
          <button
            class="button button-primary pm-btn"
            data-action="pm_take_snapshot"
            <?php echo ! $configured ? 'disabled' : ''; ?>
          >
            <?php echo $pre ? 'Retake Snapshot' : 'Take Snapshot'; ?>
          </button>
        </li>

        <!-- Step 2: Create backup -->
        <li class="pm-step">
          <div class="pm-step-header">
            <span class="pm-step-num">2</span>
            <div>
              <strong>Create Database Backup</strong>
              <p class="pm-muted">Exports a full database backup (.sql.gz) and uploads it to the client&rsquo;s Google Drive folder before any changes are made.</p>
            </div>
          </div>
          <button
            class="button pm-btn"
            data-action="pm_create_backup"
            <?php echo ! $configured ? 'disabled' : ''; ?>
          >Create &amp; Upload Backup</button>
        </li>

        <!-- Step 3: Run updates manually -->
        <li class="pm-step">
          <div class="pm-step-header">
            <span class="pm-step-num">3</span>
            <div>
              <strong>Run Updates Manually</strong>
              <p class="pm-muted">Update plugins, themes, and core in WP Admin at your own pace.</p>
            </div>
          </div>
          <a
            href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>"
            class="button"
            target="_blank"
          >Open Updates &rarr;</a>
        </li>

        <!-- Step 4: Generate report -->
        <li class="pm-step">
          <div class="pm-step-header">
            <span class="pm-step-num">4</span>
            <div>
              <strong>Generate &amp; Upload Report</strong>
              <p class="pm-muted">
                Takes a post-update snapshot, compares with pre-update, and publishes a Google Doc to
                <strong><?php echo esc_html( $settings['client_name'] ?? '(no client set)' ); ?></strong>&rsquo;s folder.
              </p>
              <?php if ( ! $pre ) : ?>
                <p class="pm-status pm-status--warn">&#9888; No pre-snapshot found — &ldquo;Before&rdquo; columns will be empty.</p>
              <?php endif; ?>
            </div>
          </div>
          <button
            class="button button-primary pm-btn"
            data-action="pm_generate_report"
            <?php echo ! $configured ? 'disabled' : ''; ?>
          >Generate Report</button>
        </li>

      </ol>

      <!-- Clear snapshots -->
      <?php if ( $pre ) : ?>
      <div class="pm-clear-row">
        <button class="button button-link-delete pm-btn" data-action="pm_clear_snapshot">
          Clear stored snapshots
        </button>
      </div>
      <?php endif; ?>

      <!-- Spinner / result -->
      <div class="pm-result" id="pm-result" style="display:none;"></div>
    </div>

    <!-- ── Report Log ─────────────────────────────────────────────────────── -->
    <div class="pm-card pm-card--log">
      <h2>Recent Reports</h2>

      <?php if ( empty( $report_log ) ) : ?>
        <p class="pm-muted">No reports generated yet.</p>
      <?php else : ?>
        <table class="widefat striped pm-log-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Report</th>
              <th>Pre-Snap</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $report_log as $entry ) : ?>
            <tr>
              <td><?php echo esc_html( $entry['date'] ); ?></td>
              <td>
                <a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank">
                  <?php echo esc_html( $entry['title'] ); ?> &nearr;
                </a>
              </td>
              <td><?php echo $entry['had_pre'] ? '&#10003;' : '&mdash;'; ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- ── Current Snapshot ───────────────────────────────────────────────── -->
    <?php if ( $pre ) : ?>
    <div class="pm-card pm-card--snapshot">
      <h2>Stored Pre-Update Snapshot</h2>
      <table class="widefat">
        <tr><th>WordPress</th><td><?php echo esc_html( $pre['wp_version'] ); ?></td></tr>
        <tr><th>PHP</th><td><?php echo esc_html( $pre['php_version'] ); ?></td></tr>
        <tr><th>Theme</th><td><?php echo esc_html( $pre['active_theme_name'] . ' ' . $pre['active_theme_version'] ); ?></td></tr>
        <tr><th>Active Plugins</th><td><?php echo count( array_filter( $pre['plugins'], fn( $p ) => $p['active'] ) ); ?></td></tr>
        <tr><th>Taken At</th><td><?php echo esc_html( $pre_date ); ?></td></tr>
      </table>
    </div>
    <?php endif; ?>

  <!-- ── PageSpeed Insights ─────────────────────────────────────────────── -->
  <div class="pm-card pm-card--pagespeed">
    <h2>PageSpeed Insights</h2>
    <p class="pm-muted">
      Tests <strong><?php echo esc_html( get_home_url() ); ?></strong> &mdash;
      scores compare against the last time this test was run.
    </p>

    <?php if ( $pagespeed_last ) : ?>
    <p class="pm-muted">Last run: <?php echo esc_html( date( 'F j, Y \a\t H:i', $pagespeed_last['timestamp'] ) ); ?></p>

    <table class="widefat pm-psi-table">
      <thead>
        <tr>
          <th>Category</th>
          <th colspan="2">📱 Mobile</th>
          <th colspan="2">🖥 Desktop</th>
        </tr>
        <tr class="pm-psi-subheader">
          <th></th>
          <th>Score</th><th>Rating</th>
          <th>Score</th><th>Rating</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $cats = [
            'performance'    => 'Performance',
            'accessibility'  => 'Accessibility',
            'best_practices' => 'Best Practices',
            'seo'            => 'SEO',
        ];
        foreach ( $cats as $key => $label ) :
            $m = $pagespeed_last['scores']['mobile'][ $key ]  ?? null;
            $d = $pagespeed_last['scores']['desktop'][ $key ] ?? null;
        ?>
        <tr>
          <td><?php echo esc_html( $label ); ?></td>
          <td><?php echo $m !== null ? $m : '&mdash;'; ?></td>
          <td><?php echo $m !== null ? pm_score_label( $m ) : ''; ?></td>
          <td><?php echo $d !== null ? $d : '&mdash;'; ?></td>
          <td><?php echo $d !== null ? pm_score_label( $d ) : ''; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else : ?>
    <p class="pm-muted">No PageSpeed data yet. Run a test below.</p>
    <?php endif; ?>

    <div style="margin-top: 14px;">
      <button class="button button-primary pm-btn" data-action="pm_run_pagespeed">
        Run PageSpeed Test
      </button>
      <span class="pm-muted" style="margin-left: 10px; font-size: 11px;">Runs mobile &amp; desktop &mdash; takes ~30 seconds</span>
    </div>

    <div class="pm-result pm-result--pagespeed" id="pm-pagespeed-result" style="display:none;"></div>
  </div>

  </div><!-- .pm-grid -->

</div><!-- .pm-wrap -->
