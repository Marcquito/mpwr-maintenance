<?php
/**
 * PM_PageSpeed
 *
 * Fetches PageSpeed Insights scores and full Lighthouse audit data
 * (mobile + desktop) for a given URL using the PageSpeed Insights API v5.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PM_PageSpeed {

    const OPTION_LATEST = 'pm_pagespeed_latest';
    const API_BASE      = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    // ── Public API ───────────────────────────────────────────────────────────

    /**
     * Run PageSpeed tests and return scores only (lightweight).
     * Used for before/after score comparison storage.
     *
     * @param string $url
     * @param string $api_key
     * @return array|WP_Error
     */
    public static function run( $url = '', $api_key = '' ) {
        if ( empty( $url ) ) {
            $url = get_home_url();
        }

        $scores = [];

        foreach ( [ 'mobile', 'desktop' ] as $strategy ) {
            $data = self::fetch( $url, $strategy, $api_key );
            if ( is_wp_error( $data ) ) return $data;

            $categories = $data['lighthouseResult']['categories'] ?? [];
            foreach ( $categories as $cat_id => $cat ) {
                $key = self::cat_key( $cat_id );
                $scores[ $strategy ][ $key ] = isset( $cat['score'] )
                    ? (int) round( $cat['score'] * 100 )
                    : null;
            }
        }

        return [
            'url'       => $url,
            'timestamp' => time(),
            'scores'    => $scores,
        ];
    }

    /**
     * Run PageSpeed tests and return full Lighthouse audit data.
     * Includes scores + classified audits for all categories.
     *
     * @param string $url
     * @param string $api_key
     * @return array|WP_Error
     */
    public static function run_full( $url = '', $api_key = '' ) {
        if ( empty( $url ) ) {
            $url = get_home_url();
        }

        $result = [
            'url'        => $url,
            'timestamp'  => time(),
            'scores'     => [],
            'categories' => [],
        ];

        $cat_titles = [
            'performance'   => 'Performance',
            'accessibility' => 'Accessibility',
            'best-practices'=> 'Best Practices',
            'seo'           => 'SEO',
        ];

        foreach ( [ 'mobile', 'desktop' ] as $strategy ) {
            $data = self::fetch( $url, $strategy, $api_key );
            if ( is_wp_error( $data ) ) return $data;

            $lighthouse = $data['lighthouseResult'] ?? [];
            $all_audits = $lighthouse['audits']     ?? [];
            $categories = $lighthouse['categories'] ?? [];

            foreach ( $categories as $cat_id => $cat ) {
                $cat_key = self::cat_key( $cat_id );

                // Score
                $result['scores'][ $strategy ][ $cat_key ] = isset( $cat['score'] )
                    ? (int) round( $cat['score'] * 100 )
                    : null;

                // Category metadata (first strategy sets title)
                if ( ! isset( $result['categories'][ $cat_key ] ) ) {
                    $result['categories'][ $cat_key ] = [
                        'title'  => $cat_titles[ $cat_id ] ?? ( $cat['title'] ?? $cat_id ),
                        'audits' => [],
                    ];
                }

                $result['categories'][ $cat_key ]['audits'][ $strategy ] =
                    self::parse_audits( $cat['auditRefs'] ?? [], $all_audits );
            }
        }

        return $result;
    }

    /**
     * Retrieve the last stored PageSpeed result (scores + timestamp only).
     *
     * @return array|null
     */
    public static function get_stored() {
        return get_option( self::OPTION_LATEST, null ) ?: null;
    }

    /**
     * Persist summary scores for before/after comparison.
     * Only stores scores + timestamp — NOT full audit data.
     *
     * @param array $data  Full or summary result array.
     */
    public static function store( array $data ) {
        update_option( self::OPTION_LATEST, [
            'url'       => $data['url'],
            'timestamp' => $data['timestamp'],
            'scores'    => $data['scores'],
        ], false );
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    private static function fetch( $url, $strategy, $api_key = '' ) {
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

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    // ── Audit parsing ─────────────────────────────────────────────────────────

    private static function parse_audits( array $audit_refs, array $all_audits ) {
        $buckets = [
            'errors'        => [],
            'opportunities' => [],
            'warnings'      => [],
            'diagnostics'   => [],
            'passed'        => [],
        ];

        foreach ( $audit_refs as $ref ) {
            $id    = $ref['id'];
            $audit = $all_audits[ $id ] ?? null;
            if ( ! $audit ) continue;

            $mode = $audit['scoreDisplayMode'] ?? 'numeric';

            // Skip audits that don't produce actionable results
            if ( in_array( $mode, [ 'notApplicable', 'manual' ], true ) ) {
                continue;
            }

            $raw   = isset( $audit['score'] ) ? (float) $audit['score'] : null;
            $score = $raw !== null ? (int) round( $raw * 100 ) : null;

            $detail_type    = $audit['details']['type']                ?? '';
            $savings_ms     = $audit['details']['overallSavingsMs']    ?? null;
            $savings_bytes  = $audit['details']['overallSavingsBytes'] ?? null;

            $parsed = [
                'id'           => $id,
                'title'        => $audit['title']        ?? '',
                'description'  => self::clean_description( $audit['description'] ?? '' ),
                'score'        => $score,
                'displayValue' => $audit['displayValue'] ?? '',
                'savings_ms'   => $savings_ms    !== null ? (int) round( $savings_ms )   : null,
                'savings_bytes'=> $savings_bytes !== null ? (int) $savings_bytes          : null,
            ];

            if ( $mode === 'informative' || $raw === null ) {
                $buckets['diagnostics'][] = $parsed;
            } elseif ( $detail_type === 'opportunity' && $raw < 0.9 ) {
                $buckets['opportunities'][] = $parsed;
            } elseif ( $raw < 0.5 ) {
                $buckets['errors'][] = $parsed;
            } elseif ( $raw < 0.9 ) {
                $buckets['warnings'][] = $parsed;
            } else {
                $buckets['passed'][] = $parsed;
            }
        }

        // Sort opportunities by potential savings (largest first)
        usort( $buckets['opportunities'], fn( $a, $b ) =>
            ( $b['savings_ms'] ?? 0 ) - ( $a['savings_ms'] ?? 0 )
        );

        return $buckets;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function cat_key( $cat_id ) {
        return str_replace( '-', '_', $cat_id );
    }

    private static function clean_description( $desc ) {
        // Convert markdown links [text](url) → text
        $desc = preg_replace( '/\[([^\]]+)\]\([^\)]+\)/', '$1', $desc );
        // Remove backtick code formatting
        $desc = preg_replace( '/`([^`]+)`/', '$1', $desc );
        return trim( $desc );
    }
}
