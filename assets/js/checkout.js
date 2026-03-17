jQuery( function( $ ) {

    // ── Overlay helpers ──────────────────────────────────────────────────────────
    function showOverlay() {
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
        setTimeout( removeOverlay, 15000 );
    }

    function removeOverlay() {
        $( '#wcmpesa-overlay' ).fadeOut( 400, function() { $( this ).remove(); } );
    }

    function pageHasErrors() {
        return $( '.woocommerce-error, .woocommerce-NoticeGroup-checkout .woocommerce-error' ).length > 0;
    }

    function overlayVisible() {
        return $( '#wcmpesa-overlay' ).length > 0;
    }

    // ── CHECKOUT PAGE: show overlay on Place Order ───────────────────────────────
    $( document ).on( 'click', '#place_order', function() {
        const isMpesa = $( 'input[name="payment_method"]:checked' ).val() === 'mpesa';
        if ( isMpesa ) {
            setTimeout( function() {
                const canShow = ! pageHasErrors() && ! overlayVisible();
                if ( canShow ) {
                    showOverlay();
                }
            }, 800 );
        }
    } );

    $( document ).on( 'checkout_error', removeOverlay );

    // ── THANK YOU PAGE ───────────────────────────────────────────────────────────
    const $box = $( '#wcmpesa-thankyou-box' );
    if ( $box.length === 0 ) return;

    const orderId      = $box.data( 'order' );
    const wcmpesaObj   = typeof wcmpesa !== 'undefined' ? wcmpesa : {};
    const ajaxUrl      = $box.data( 'ajaxurl' ) || wcmpesaObj.ajax_url || ( globalThis.location.origin + '/wp-admin/admin-ajax.php' );
    let nonce          = $box.data( 'nonce' ) || wcmpesaObj.nonce || '';

    let pollTimer      = null;
    let pollCount      = 0;
    let confirmed      = false;

    const MAX_POLLS    = 36;
    const $fetchBtn    = $( '#wcmpesa-fetch-btn' );
    const $completeBtn = $( '#wcmpesa-complete-btn' );
    const $statusMsg   = $( '#wcmpesa-status-msg' );
    const $waitMsg     = $( '#wcmpesa-waiting-msg' );

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
        .done( handlePollResponse );
    }

    function handlePollResponse( res ) {
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
            $waitMsg.html( 'Auto-detection stopped. Use <strong>Fetch Payment</strong> or <strong>Complete</strong> to check.' )
                    .css({ background: '#92400e' });
        }
    }

    // ── Shared payment check (Fetch Payment + Complete) ──────────────────────────
    function checkPayment( $btn, originalLabel ) {
        if ( confirmed ) return;
        $fetchBtn.prop( 'disabled', true );
        $completeBtn.prop( 'disabled', true );
        $btn.text( '⏳ Checking…' );
        $statusMsg.html( '' );

        $.post( ajaxUrl, { action: 'wcmpesa_complete_order', order_id: orderId, nonce } )
        .done( function( res ) { handleCheckResponse( res, $btn, originalLabel ); } )
        .fail( function() { handleCheckFail( $btn, originalLabel ); } );
    }

    function handleCheckResponse( res, $btn, originalLabel ) {
        if ( res?.success && res?.data?.status === 'paid' ) {
            onPaymentConfirmed( res.data.redirect );
            return;
        }
        const msg = res?.data?.message ?? 'Payment not confirmed yet. Please try again shortly.';
        $statusMsg.html( '<span style="color:#d63638;">❌ ' + msg + '</span>' );
        resetButtons( $btn, originalLabel );
    }

    function handleCheckFail( $btn, originalLabel ) {
        $statusMsg.html( '<span style="color:#d63638;">❌ Could not reach server. Check your connection.</span>' );
        resetButtons( $btn, originalLabel );
    }

    function resetButtons( $btn, originalLabel ) {
        $btn.text( originalLabel );
        $fetchBtn.prop( 'disabled', false );
        $completeBtn.prop( 'disabled', false );
    }

    $fetchBtn.on( 'click', function() { checkPayment( $fetchBtn, 'Fetch Payment' ); } );
    $completeBtn.on( 'click', function() { checkPayment( $completeBtn, 'Complete' ); } );

    // ── Payment confirmed ────────────────────────────────────────────────────────
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
