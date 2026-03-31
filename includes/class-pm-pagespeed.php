<?php
/**
 * PM_PageSpeed
 *
 * Fetches PageSpeed Insights scores (mobile + desktop) for a given URL
 * using the Google PageSpeed Insights API v5 (no API key required).
 *
 * Scores are stored in wp_options so each run can compare against the last.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_PageSpeed {

    const OPTION_LATEST = 'pm_pagespeed_latest';
    const API_BASE      = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Run PageSpeed tests for both mobile and desktop.
     *
     * @param string $url      The URL to test (defaults to home URL).
     * @param string $api_key  Optional Google API key for higher quota.
     * @return array|WP_Error  Result array or WP_Error on failure.
     */
    public static function run( $url = '', $api_key = '' ) {
        if ( empty( $url ) ) {
            $url = get_home_url();
        }

        $scores = [];

        foreach ( [ 'mobile', 'desktop' ] as $strategy ) {
            $api_url = self::API_BASE
                . '?url='      . urlencode( $url )
                . '&strategy=' . $strategy
                . '&category=performance&category=accessibility&category=best-practices&category=seo';

            if ( ! empty( $api_key ) ) {
                $api_url .= '&key=' . urlencode( $api_key );
            }

            $response = wp_remote_get( $api_url, [
                'timeout'    => 90,
                'user-agent' => 'MPWR-Maintenance-Plugin/' . PM_VERSION,
            ] );

            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    'pm_pagespeed_request',
                    "PageSpeed API request failed ({$strategy}): " . $response->get_error_message()
                );
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                $msg  = $body['error']['message'] ?? "HTTP {$code}";
                return new WP_Error( 'pm_pagespeed_api', "PageSpeed API error ({$strategy}): {$msg}" );
            }

            $body       = json_decode( wp_remote_retrieve_body( $response ), true );
            $categories = $body['lighthouseResult']['categories'] ?? [];

            $scores[ $strategy ] = [
                'performance'   => self::score( $categories, 'performance' ),
                'accessibility' => self::score( $categories, 'accessibility' ),
                'best_practices'=> self::score( $categories, 'best-practices' ),
                'seo'           => self::score( $categories, 'seo' ),
            ];
        }

        return [
            'url'       => $url,
            'timestamp' => time(),
            'scores'    => $scores,
        ];
    }

    /**
     * Retrieve the last stored PageSpeed result.
     *
     * @return array|null
     */
    public static function get_stored() {
        return get_option( self::OPTION_LATEST, null ) ?: null;
    }

    /**
     * Persist a PageSpeed result.
     *
     * @param array $data
     */
    public static function store( array $data ) {
        update_option( self::OPTION_LATEST, $data, false );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function score( array $categories, $key ) {
        return isset( $categories[ $key ]['score'] )
            ? (int) round( $categories[ $key ]['score'] * 100 )
            : null;
    }
}
