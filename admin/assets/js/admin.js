/* Proactive Maintenance — Admin JS */
( function ( $ ) {
    'use strict';

    // ── Button click handler ──────────────────────────────────────────────────

    $( document ).on( 'click', '.pm-btn[data-action]', function () {
        var $btn    = $( this );
        var action  = $btn.data( 'action' );
        var $result = $( '#pm-result' );

        if ( $btn.hasClass( 'loading' ) ) return;

        // Special handling for test connection (different result element)
        if ( action === 'pm_test_connection' ) {
            handleTestConnection( $btn );
            return;
        }

        // Special handling for PageSpeed (different result element)
        if ( action === 'pm_run_pagespeed' ) {
            handlePageSpeed( $btn );
            return;
        }

        // Confirm destructive actions
        if ( action === 'pm_clear_snapshot' ) {
            if ( ! confirm( 'Clear all stored snapshots? This cannot be undone.' ) ) return;
        }

        $btn.addClass( 'loading' ).text( labelFor( action, 'loading' ) );
        $result.hide().removeClass( 'pm-result--ok pm-result--error pm-result--info' );

        $.post( PM.ajax_url, {
            action : action,
            nonce  : PM.nonce
        } )
        .done( function ( res ) {
            if ( res.success ) {
                showResult( $result, 'ok', buildSuccessHTML( action, res.data ) );
                handlePostSuccess( action, res.data );
            } else {
                showResult( $result, 'error', '&#10060; ' + ( res.data || 'An unknown error occurred.' ) );
            }
        } )
        .fail( function () {
            showResult( $result, 'error', '&#10060; Request failed. Check your connection and try again.' );
        } )
        .always( function () {
            $btn.removeClass( 'loading' ).text( labelFor( action, 'default' ) );
        } );
    } );

    // ── PageSpeed ─────────────────────────────────────────────────────────────

    function handlePageSpeed( $btn ) {
        var $result = $( '#pm-pagespeed-result' );
        $btn.addClass( 'loading' ).text( 'Running tests… (~30 sec)' );
        $result.hide().removeClass( 'pm-result--ok pm-result--error pm-result--info' );

        $.post( PM.ajax_url, { action: 'pm_run_pagespeed', nonce: PM.nonce } )
        .done( function ( res ) {
            if ( res.success ) {
                showResult( $result, 'ok', buildPageSpeedHTML( res.data ) );
                // Reload after a moment to update the scores table
                setTimeout( function () { location.reload(); }, 3000 );
            } else {
                showResult( $result, 'error', '&#10060; ' + ( res.data || 'PageSpeed test failed.' ) );
            }
        } )
        .fail( function () {
            showResult( $result, 'error', '&#10060; Request failed. The test may have timed out — try again.' );
        } )
        .always( function () {
            $btn.removeClass( 'loading' ).text( 'Run PageSpeed Test' );
        } );
    }

    function buildPageSpeedHTML( data ) {
        var cats = {
            performance:    'Performance',
            accessibility:  'Accessibility',
            best_practices: 'Best Practices',
            seo:            'SEO'
        };
        var prev    = data.previous;
        var current = data.current;
        var html    = '<strong>&#10003; PageSpeed test complete!</strong><br><br>';

        [ ['mobile', '📱 Mobile'], ['desktop', '🖥 Desktop'] ].forEach( function( pair ) {
            var strategy = pair[0], label = pair[1];
            html += '<strong>' + label + '</strong><br>';
            html += '<table style="width:100%;border-collapse:collapse;margin-bottom:10px;font-size:12px;">';
            html += '<tr><th style="text-align:left;padding:3px 8px;">Category</th>';
            if ( prev ) html += '<th style="padding:3px 8px;">Previous</th>';
            html += '<th style="padding:3px 8px;">Current</th>';
            if ( prev ) html += '<th style="padding:3px 8px;">Change</th>';
            html += '</tr>';

            Object.keys( cats ).forEach( function( key ) {
                var cur  = current.scores[ strategy ][ key ];
                var old  = prev ? prev.scores[ strategy ][ key ] : null;
                var diff = ( old !== null && old !== undefined ) ? cur - old : null;

                var changeCell = '';
                if ( diff !== null ) {
                    if ( diff > 0 )      changeCell = '<span style="color:#2e7d32">▲ +' + diff + '</span>';
                    else if ( diff < 0 ) changeCell = '<span style="color:#c62828">▼ ' + diff + '</span>';
                    else                 changeCell = '<span style="color:#777">— same</span>';
                }

                html += '<tr>';
                html += '<td style="padding:3px 8px;">' + cats[ key ] + '</td>';
                if ( prev ) html += '<td style="text-align:center;padding:3px 8px;">' + ( old !== null ? old : '—' ) + '</td>';
                html += '<td style="text-align:center;padding:3px 8px;font-weight:600;color:' + scoreColor( cur ) + '">' + cur + '</td>';
                if ( prev ) html += '<td style="text-align:center;padding:3px 8px;">' + changeCell + '</td>';
                html += '</tr>';
            } );

            html += '</table>';
        } );

        return html;
    }

    function scoreColor( score ) {
        if ( score >= 90 ) return '#2e7d32';
        if ( score >= 50 ) return '#c87820';
        return '#c62828';
    }

    // ── Test connection ───────────────────────────────────────────────────────

    function handleTestConnection( $btn ) {
        var $result = $( '#pm-test-result' );
        $btn.addClass( 'loading' ).text( 'Testing…' );
        $result.text( '' ).removeClass( 'ok error' );

        $.post( PM.ajax_url, { action: 'pm_test_connection', nonce: PM.nonce } )
        .done( function ( res ) {
            if ( res.success ) {
                $result.addClass( 'ok' ).text( '✓ ' + res.data.message );
            } else {
                $result.addClass( 'error' ).text( '✗ ' + ( res.data || 'Connection failed.' ) );
            }
        } )
        .fail( function () {
            $result.addClass( 'error' ).text( '✗ Request failed.' );
        } )
        .always( function () {
            $btn.removeClass( 'loading' ).text( 'Test Google Drive Connection' );
        } );
    }

    // ── Post-success side-effects ─────────────────────────────────────────────

    function handlePostSuccess( action, data ) {
        if ( action === 'pm_take_snapshot' ) {
            // Reload so the snapshot status card updates
            setTimeout( function () { location.reload(); }, 1200 );
        }
        if ( action === 'pm_clear_snapshot' ) {
            setTimeout( function () { location.reload(); }, 800 );
        }
        if ( action === 'pm_generate_report' ) {
            // Reload to update the report log
            setTimeout( function () { location.reload(); }, 2500 );
        }
    }

    // ── Result display ────────────────────────────────────────────────────────

    function showResult( $el, type, html ) {
        $el.addClass( 'pm-result--' + type ).html( html ).show();
        $el[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }

    function buildSuccessHTML( action, data ) {
        var msg = '&#10003; ' + ( data.message || 'Done.' );

        if ( action === 'pm_generate_report' && data.doc_url ) {
            msg += ' &nbsp;<a href="' + data.doc_url + '" target="_blank">Open Report &rarr;</a>';
        }
        if ( action === 'pm_create_backup' && data.backup_url ) {
            msg += ' &nbsp;<a href="' + data.backup_url + '" target="_blank">View in Drive &rarr;</a>';
        }
        if ( data.warning ) {
            msg += '<br><em>&#9888; ' + data.warning + '</em>';
        }
        return msg;
    }

    // ── Button labels ─────────────────────────────────────────────────────────

    var labels = {
        pm_take_snapshot   : { default: 'Take Snapshot',               loading: 'Taking snapshot…'     },
        pm_create_backup   : { default: 'Create & Upload Backup',       loading: 'Creating backup…'     },
        pm_generate_report : { default: 'Generate Report',              loading: 'Generating report…'   },
        pm_clear_snapshot  : { default: 'Clear stored snapshots',       loading: 'Clearing…'            },
        pm_run_pagespeed   : { default: 'Run PageSpeed Test',           loading: 'Running tests… (~30 sec)' },
    };

    function labelFor( action, state ) {
        return ( labels[ action ] && labels[ action ][ state ] ) ? labels[ action ][ state ] : ( state === 'loading' ? 'Please wait…' : action );
    }

} )( jQuery );
