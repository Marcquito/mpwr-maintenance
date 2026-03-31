<?php
/**
 * Settings page view.
 *
 * Available variables:
 *   $settings – current plugin options array
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$client_name        = $settings['client_name']        ?? '';
$parent_folder_id   = $settings['parent_folder_id']   ?? '';
$service_account    = $settings['service_account']    ?? '';
$pagespeed_api_key  = $settings['pagespeed_api_key']  ?? '';
?>
<div class="wrap pm-wrap">

  <h1 class="pm-page-title">
    <span class="dashicons dashicons-admin-settings"></span>
    MPWR Maintenance Settings
  </h1>

  <!-- ── Google Cloud setup guide ──────────────────────────────────────────── -->
  <div class="pm-card pm-card--setup">
    <h2>Google Drive Setup (one-time)</h2>
    <ol class="pm-setup-steps">
      <li>Go to <strong>Google Cloud Console</strong> and create a project (or select an existing one).</li>
      <li>Enable the <strong>Google Drive API</strong> and <strong>Google Docs API</strong>.</li>
      <li>Under <em>IAM &amp; Admin &rarr; Service Accounts</em>, create a service account and download the <strong>JSON key</strong>.</li>
      <li>In your <strong>Google Drive</strong>, create a folder named <strong>Proactive Maintenance</strong>.</li>
      <li>Share that folder with the service account&rsquo;s email (looks like <code>name@project.iam.gserviceaccount.com</code>) with <strong>Editor</strong> access.</li>
      <li>Open the folder in Drive and copy the folder ID from the URL — it&rsquo;s the string after <code>/folders/</code>.</li>
      <li>Paste the JSON key contents and folder ID below, then save.</li>
    </ol>
  </div>

  <form method="post" action="options.php">
    <?php settings_fields( 'pm_settings_group' ); ?>

    <div class="pm-card">
      <h2>Site &amp; Client</h2>

      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="pm_client_name">Client Name</label>
          </th>
          <td>
            <input
              type="text"
              id="pm_client_name"
              name="pm_settings[client_name]"
              value="<?php echo esc_attr( $client_name ); ?>"
              class="regular-text"
              placeholder="e.g. Acme Corp"
            >
            <p class="description">
              Used as the subfolder name inside your Proactive Maintenance Drive folder.
              Reports will be saved to: <code>Proactive Maintenance / [Client Name] / YYYY-MM-DD Maintenance Report</code>
            </p>
          </td>
        </tr>
      </table>
    </div>

    <div class="pm-card">
      <h2>Google Drive Credentials</h2>

      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="pm_parent_folder_id">Proactive Maintenance Folder ID</label>
          </th>
          <td>
            <input
              type="text"
              id="pm_parent_folder_id"
              name="pm_settings[parent_folder_id]"
              value="<?php echo esc_attr( $parent_folder_id ); ?>"
              class="regular-text"
              placeholder="e.g. 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs"
            >
            <p class="description">
              The ID of your <strong>Proactive Maintenance</strong> folder in Google Drive.
              Find it in the URL when you open the folder: <code>drive.google.com/drive/folders/<strong>[folder-id]</strong></code>
            </p>
          </td>
        </tr>

        <tr>
          <th scope="row">
            <label for="pm_service_account">Service Account JSON</label>
          </th>
          <td>
            <textarea
              id="pm_service_account"
              name="pm_settings[service_account]"
              rows="10"
              class="large-text code"
              placeholder='Paste the full contents of your service account JSON key file here...'
              spellcheck="false"
            ><?php echo esc_textarea( $service_account ); ?></textarea>
            <p class="description">
              Paste the entire JSON file contents from your Google Cloud service account key.
              This is stored in the WordPress database.
            </p>
          </td>
        </tr>
      </table>

    </div>

    <div class="pm-card">
      <h2>PageSpeed Insights</h2>

      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="pm_pagespeed_api_key">PageSpeed API Key</label>
          </th>
          <td>
            <input
              type="text"
              id="pm_pagespeed_api_key"
              name="pm_settings[pagespeed_api_key]"
              value="<?php echo esc_attr( $pagespeed_api_key ); ?>"
              class="regular-text"
              placeholder="AIza..."
            >
            <p class="description">
              Free API key from Google Cloud Console. Enables higher quota (25,000 req/day) for PageSpeed tests.
              In Cloud Console: <strong>APIs &amp; Services &rarr; Library</strong> &rarr; enable <strong>PageSpeed Insights API</strong>,
              then <strong>Credentials &rarr; Create API Key</strong>.
            </p>
          </td>
        </tr>
      </table>
    </div>

    <div class="pm-card">
      <h2>Test Connection</h2>

      <?php if ( $service_account && $parent_folder_id ) : ?>
      <div class="pm-test-row">
        <button type="button" class="button pm-btn" data-action="pm_test_connection" id="pm-test-btn">
          Test Google Drive Connection
        </button>
        <span id="pm-test-result" class="pm-test-result"></span>
      </div>
      <?php endif; ?>
    </div>

    <?php submit_button( 'Save Settings' ); ?>
  </form>

  <div class="pm-result" id="pm-result" style="display:none;"></div>

</div><!-- .pm-wrap -->
