jQuery( function( $ ) {

    // ── CHECKOUT PAGE: overlay while STK Push sends ──────────────────────────────
    $( document ).on( 'click', '#place_order', function() {
        if ( $( 'input[name="payment_method"]:checked' ).val() !== 'mpesa' ) return;

        setTimeout( function() {
            const hasErrors    = $( '.woocommerce-error, .woocommerce-NoticeGroup-checkout .woocommerce-error' ).length > 0;
            const alreadyShown = $( '#wcmpesa-overlay' ).length > 0;
            if ( hasErrors || alreadyShown ) return;

            $( 'head' ).append( '<style>@keyframes wcmpesa-pulse{0%,100%{opacity:.3}50%{opacity:1}}</style>' );
            $( 'body' ).append(
                '<div id="wcmpesa-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;' +
                'background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;">' +
                '<div style="background:#fff;border-radius:12px;padding:40px;max-width:360px;text-align:center;">' +
                '<div style="font-size:48px;margin-bottom:12px;">📱</div>' +
                '<h3 style="margin:0 0 8px;">Sending M-Pesa Prompt…</h3>' +
                '<p style="color:#555;margin:0 0 16px;">Please wait while we send the STK Push to your phone.</p>' +
                '<div style="display:flex;justify-content:center;gap:6px;">' +
                '<span style="width:10px;height:10px;border-radius:50%;background:#4CAF50;animation:wcmpesa-pulse 1.2s infinite;"></span>' +
                '<span style="width:10px;height:10px;border-radius:50%;background:#4CAF50;animation:wcmpesa-pulse 1.2s infinite .4s;"></span>' +
                '<span style="width:10px;height:10px;border-radius:50%;background:#4CAF50;animation:wcmpesa-pulse 1.2s infinite .8s;"></span>' +
                '</div></div></div>'
            );
            setTimeout( function() {
                $( '#wcmpesa-overlay' ).fadeOut( 400, function() { $( this ).remove(); } );
            }, 15000 );
        }, 800 );
    });

    $( document ).on( 'checkout_error', function() {
        $( '#wcmpesa-overlay' ).fadeOut( 300, function() { $( this ).remove(); } );
    });

    // ── THANK YOU PAGE ───────────────────────────────────────────────────────────
    const $box = $( '#wcmpesa-thankyou-box' );
    if ( ! $box.length ) return;

    const orderId  = $box.data( 'order' );
    const ajaxUrl  = $box.data( 'ajaxurl' )
                  || ( typeof wcmpesa !== 'undefined' ? wcmpesa.ajax_url : '' )
                  || ( globalThis.location.origin + '/wp-admin/admin-ajax.php' );
    let nonce      = $box.data( 'nonce' )
                  || ( typeof wcmpesa !== 'undefined' ? wcmpesa.nonce : '' );

    let pollTimer  = null;
    let pollCount  = 0;
    let confirmed  = false;

    const MAX_POLLS  = 36; // 3 minutes
    const $fetchBtn  = $( '#wcmpesa-fetch-btn' );
    const $completeBtn = $( '#wcmpesa-complete-btn' );
    const $statusMsg = $( '#wcmpesa-status-msg' );
    const $waitMsg   = $( '#wcmpesa-waiting-msg' );

    // ── Auto-poll every 5 seconds ────────────────────────────────────────────────
    startPolling();

    function startPolling() {
        if ( pollTimer ) return;
        pollTimer = setInterval( doPoll, 5000 );
    }

    function stopPolling() {
        clearInterval( pollTimer );
        pollTimer = null;
    }

    function doPoll() {
        if ( confirmed ) {
            stopPolling();
            return;
        }
        pollCount++;

        $.post( ajaxUrl, { action: 'wcmpesa_check_status', order_id: orderId, nonce } )
        .done( function( res ) {
            if ( res?.data?.code === 'nonce_expired' ) {
                nonce = res.data.nonce;
                return;
            }
            if ( res?.success && res?.data?.status === 'paid' ) {
                onPaymentConfirmed( res.data.redirect );
                return;
            }
            if ( pollCount >= MAX_POLLS ) {
                stopPolling();
                $waitMsg
                    .html( 'Auto-detection stopped. Use <strong>Fetch Payment</strong> or <strong>Complete</strong> to check.' )
                    .css({ background: '#92400e' });
            }
        } );
    }

    // ── Shared payment check function (used by both Fetch Payment and Complete) ──
    function checkPayment( $btn, originalLabel ) {
        if ( confirmed ) return;
        $btn.prop( 'disabled', true ).text( '⏳ Checking…' );
        $completeBtn.prop( 'disabled', true );
        $fetchBtn.prop( 'disabled', true );
        $statusMsg.html( '' );

        $.post( ajaxUrl, { action: 'wcmpesa_complete_order', order_id: orderId, nonce } )
        .done( function( res ) {
            if ( res?.success && res?.data?.status === 'paid' ) {
                onPaymentConfirmed( res.data.redirect );
                return;
            }
            const msg = res?.data?.message ?? 'Payment not confirmed yet. Please try again shortly.';
            $statusMsg.html( '<span style="color:#d63638;">❌ ' + msg + '</span>' );
            $btn.prop( 'disabled', false ).text( originalLabel );
            $completeBtn.prop( 'disabled', false );
            $fetchBtn.prop( 'disabled', false );
        } )
        .fail( function() {
            $statusMsg.html( '<span style="color:#d63638;">❌ Could not reach server. Check your connection.</span>' );
            $btn.prop( 'disabled', false ).text( originalLabel );
            $completeBtn.prop( 'disabled', false );
            $fetchBtn.prop( 'disabled', false );
        } );
    }

    // ── FETCH PAYMENT — check if paid via STK, Paybill or reconnect ─────────────
    $fetchBtn.on( 'click', function() {
        checkPayment( $fetchBtn, 'Fetch Payment' );
    } );

    // ── COMPLETE — same check, different label/intent ────────────────────────────
    $completeBtn.on( 'click', function() {
        checkPayment( $completeBtn, 'Complete' );
    } );

    // ── Payment confirmed: show success state then redirect ──────────────────────
    function onPaymentConfirmed( redirect ) {
        if ( confirmed ) return;
        confirmed = true;
        stopPolling();
        $( '#wcmpesa-instructions' ).fadeOut( 300, function() {
            $( '#wcmpesa-confirmed' ).fadeIn( 300 );
        } );
        setTimeout( function() {
            globalThis.location.href = redirect;
        }, 2000 );
    }

} );
