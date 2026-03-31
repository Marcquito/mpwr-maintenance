<?php
/**
 * PM_Google
 *
 * Wraps Google Drive + Docs API calls.
 *
 * Usage:
 *   $g = new PM_Google( $service_account_json_string );
 *   $folder_id = $g->get_or_create_client_folder( $client_name, $parent_folder_id );
 *   $doc = $g->upload_as_doc( $html, 'Report Title', $folder_id );
 *   $file = $g->upload_file( '/path/to/file.sql.gz', 'backup.sql.gz', $folder_id );
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_Google {

    /** @var Google\Client */
    private $client;

    /** @var Google\Service\Drive */
    private $drive;

    /**
     * @param string $service_account_json  Raw JSON string from the service account key file.
     * @throws Exception If credentials are invalid or the API client is unavailable.
     */
    public function __construct( $service_account_json ) {
        if ( ! class_exists( 'Google\Client' ) ) {
            throw new Exception( 'Google API client library is not loaded. Run composer install.' );
        }

        $credentials = json_decode( $service_account_json, true );
        if ( empty( $credentials ) || ! isset( $credentials['type'] ) ) {
            throw new Exception( 'Invalid service account JSON.' );
        }

        $this->client = new Google\Client();
        $this->client->setAuthConfig( $credentials );
        $this->client->addScope( Google\Service\Drive::DRIVE );
        $this->client->setApplicationName( 'Proactive Maintenance WP Plugin' );

        $this->drive = new Google\Service\Drive( $this->client );
    }

    // ── Folder management ────────────────────────────────────────────────────

    /**
     * Find or create a subfolder with $name inside $parent_folder_id.
     *
     * @param string $name
     * @param string $parent_folder_id  Google Drive folder ID.
     * @return string  The folder ID.
     */
    public function get_or_create_folder( $name, $parent_folder_id ) {
        // Search for existing folder
        $q = sprintf(
            "name = '%s' and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents and trashed = false",
            addslashes( $name ),
            $parent_folder_id
        );

        $result = $this->drive->files->listFiles( [
            'q'                         => $q,
            'fields'                    => 'files(id, name)',
            'supportsAllDrives'         => true,
            'includeItemsFromAllDrives' => true,
        ] );

        if ( ! empty( $result->getFiles() ) ) {
            return $result->getFiles()[0]->getId();
        }

        // Create it
        $folder_meta = new Google\Service\Drive\DriveFile( [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [ $parent_folder_id ],
        ] );

        $folder = $this->drive->files->create( $folder_meta, [
            'fields'            => 'id',
            'supportsAllDrives' => true,
        ] );
        return $folder->getId();
    }

    /**
     * Get or create a fully dated folder hierarchy for a maintenance session:
     *   Parent → Client Name → YYYY-MM → YYYY-MM-DD
     *
     * Multiple reports in the same month share the YYYY-MM folder but each
     * get their own YYYY-MM-DD day folder. A new month automatically creates
     * a new YYYY-MM folder.
     *
     * @param string $client_name
     * @param string $parent_folder_id
     * @return string  Folder ID for today's session folder.
     */
    public function get_or_create_dated_folder( $client_name, $parent_folder_id ) {
        $client_folder = $this->get_or_create_folder( $client_name, $parent_folder_id );
        $month_folder  = $this->get_or_create_folder( date( 'Y-m' ), $client_folder );
        $day_folder    = $this->get_or_create_folder( date( 'Y-m-d' ), $month_folder );
        return $day_folder;
    }

    // ── File uploads ─────────────────────────────────────────────────────────

    /**
     * Upload an HTML string to Google Drive and convert it to a Google Doc.
     *
     * @param string $html_content
     * @param string $doc_title
     * @param string $folder_id
     * @return array{id: string, url: string}
     */
    public function upload_as_doc( $html_content, $doc_title, $folder_id ) {
        $file_meta = new Google\Service\Drive\DriveFile( [
            'name'     => $doc_title,
            'mimeType' => 'application/vnd.google-apps.document',
            'parents'  => [ $folder_id ],
        ] );

        $result = $this->drive->files->create(
            $file_meta,
            [
                'data'              => $html_content,
                'mimeType'          => 'text/html',
                'uploadType'        => 'multipart',
                'fields'            => 'id',
                'supportsAllDrives' => true,
            ]
        );

        $id = $result->getId();
        return [
            'id'  => $id,
            'url' => "https://docs.google.com/document/d/{$id}/edit",
        ];
    }

    /**
     * Upload a local file to Google Drive.
     *
     * @param string $file_path   Absolute path to the file.
     * @param string $filename    Name to use in Drive.
     * @param string $folder_id
     * @param string $mime_type
     * @return array{id: string, url: string}
     */
    public function upload_file( $file_path, $filename, $folder_id, $mime_type = 'application/octet-stream' ) {
        $file_meta = new Google\Service\Drive\DriveFile( [
            'name'    => $filename,
            'parents' => [ $folder_id ],
        ] );

        $result = $this->drive->files->create(
            $file_meta,
            [
                'data'              => file_get_contents( $file_path ),
                'mimeType'          => $mime_type,
                'uploadType'        => 'multipart',
                'fields'            => 'id',
                'supportsAllDrives' => true,
            ]
        );

        $id = $result->getId();
        return [
            'id'  => $id,
            'url' => "https://drive.google.com/file/d/{$id}/view",
        ];
    }

    // ── Connection test ──────────────────────────────────────────────────────

    /**
     * Verify credentials and that the specified parent folder is accessible.
     *
     * @param string $parent_folder_id
     * @return true|WP_Error
     */
    public function test_connection( $parent_folder_id ) {
        try {
            $file = $this->drive->files->get( $parent_folder_id, [
                'fields'            => 'id, name',
                'supportsAllDrives' => true,
            ] );
            return true;
        } catch ( Exception $e ) {
            return new WP_Error( 'pm_google_test', $e->getMessage() );
        }
    }
}
