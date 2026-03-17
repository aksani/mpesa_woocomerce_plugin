jQuery( function( $ ) {

    // ── CHECKOUT PAGE: overlay while STK Push sends ──────────────────────────────
    $( document ).on( 'click', '#place_order', function() {
        if ( $( 'input[name="payment_method"]:checked' ).val() !== 'mpesa' ) return;

        // Wait for WooCommerce to process validation (errors appear within ~300ms)
        setTimeout( function() {
            // If any errors exist on page — do NOT show overlay
            if ( $( '.woocommerce-error, .woocommerce-NoticeGroup-checkout .woocommerce-error' ).length > 0 ) return;
            // If page redirected away already (e.g. to login) — do not show
            if ( $( '#wcmpesa-overlay' ).length ) return;

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

            // Auto-remove overlay if page hasn't redirected after 15 seconds (something went wrong)
            setTimeout( function() {
                $( '#wcmpesa-overlay' ).fadeOut( 400, function() { $( this ).remove(); } );
            }, 15000 );

        }, 800 ); // 800ms gives WooCommerce time to show validation errors via AJAX
    });

    // Also remove overlay immediately if WooCommerce reports an error after AJAX checkout
    $( document ).on( 'checkout_error', function() {
        $( '#wcmpesa-overlay' ).fadeOut( 300, function() { $( this ).remove(); } );
    });

    // ── THANK YOU PAGE ───────────────────────────────────────────────────────────
    var $box = $( '#wcmpesa-thankyou-box' );
    if ( ! $box.length ) return;

    var orderId  = $box.data( 'order' );
    // Read ajaxUrl from data-ajaxurl (always correct) — fallback chain for safety
    var ajaxUrl  = $box.data( 'ajaxurl' )
                || ( typeof wcmpesa !== 'undefined' ? wcmpesa.ajax_url : '' )
                || window.location.origin + '/wp-admin/admin-ajax.php';
    // Nonce from HTML data attribute is always freshest
    var nonce    = $box.data( 'nonce' )
                || ( typeof wcmpesa !== 'undefined' ? wcmpesa.nonce : '' );

    var pollTimer  = null;
    var pollCount  = 0;
    var MAX_POLLS  = 36; // 36 × 5s = 3 minutes
    var confirmed  = false;

    var $fetchBtn   = $( '#wcmpesa-complete-btn' );
    var $resendBtn  = $( '#wcmpesa-resend-btn' );
    var $statusMsg  = $( '#wcmpesa-status-msg' );
    var $waitingMsg = $( '#wcmpesa-waiting-msg' );

    // ── Start polling immediately on page load ───────────────────────────────────
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
        if ( confirmed ) { stopPolling(); return; }
        pollCount++;

        $.post( ajaxUrl, {
            action:   'wcmpesa_check_status',
            order_id: orderId,
            nonce:    nonce,
        })
        .done( function( res ) {
            // Handle nonce expiry — server sends back a fresh nonce
            if ( res && ! res.success && res.data && res.data.code === 'nonce_expired' ) {
                nonce = res.data.nonce; // refresh nonce and carry on polling
                return;
            }

            if ( res && res.success && res.data && res.data.status === 'paid' ) {
                onPaymentConfirmed( res.data.redirect );
                return;
            }

            if ( pollCount >= MAX_POLLS ) {
                stopPolling();
                $waitingMsg
                    .html( '⌛ Auto-detection stopped. If you have paid, click <strong>Fetch Payment</strong>.' )
                    .css({ background:'#fefce8', 'border-color':'#fde68a', color:'#854d0e' });
            }
        })
        .fail( function() {
            // Network blip — keep polling silently
        });
    }

    // ── FETCH PAYMENT: query Safaricom directly ──────────────────────────────────
    $fetchBtn.on( 'click', function() {
        if ( confirmed ) return;
        $fetchBtn.prop( 'disabled', true ).text( '⏳ Checking…' );
        $statusMsg.html( '' );

        $.post( ajaxUrl, {
            action:   'wcmpesa_complete_order',
            order_id: orderId,
            nonce:    nonce,
        })
        .done( function( res ) {
            if ( res && res.success && res.data && res.data.status === 'paid' ) {
                onPaymentConfirmed( res.data.redirect );
            } else {
                var msg = ( res && res.data && res.data.message )
                    ? res.data.message
                    : 'Payment not confirmed yet. Please wait a moment and try again.';
                $statusMsg.html( '<span style="color:#d63638;">❌ ' + msg + '</span>' );
                $fetchBtn.prop( 'disabled', false ).text( '🔍 Fetch Payment' );
            }
        })
        .fail( function() {
            $statusMsg.html( '<span style="color:#d63638;">❌ Could not reach server. Check your connection.</span>' );
            $fetchBtn.prop( 'disabled', false ).text( '🔍 Fetch Payment' );
        });
    });

    // ── RESEND PROMPT ────────────────────────────────────────────────────────────
    $resendBtn.on( 'click', function() {
        if ( confirmed ) return;
        $resendBtn.prop( 'disabled', true ).text( '⏳ Sending…' );
        $statusMsg.html( '' );

        $.post( ajaxUrl, {
            action:   'wcmpesa_resend_stk',
            order_id: orderId,
            nonce:    nonce,
        })
        .done( function( res ) {
            if ( res && res.success && res.data && res.data.status === 'sent' ) {
                $statusMsg.html( '<span style="color:#00a32a;">✅ Prompt sent! Enter your M-Pesa PIN.</span>' );
                $waitingMsg
                    .html( '⏳ Waiting for payment confirmation…' )
                    .css({ background:'#f0fdf4', 'border-color':'#bbf7d0', color:'#166534' });
                stopPolling();
                pollCount = 0;
                pollTimer = null;
                startPolling();
            } else if ( res && res.success && res.data && res.data.status === 'paid' ) {
                onPaymentConfirmed( res.data.redirect );
            } else {
                var msg = ( res && res.data && res.data.message ) ? res.data.message : 'Could not resend. Try again.';
                $statusMsg.html( '<span style="color:#d63638;">❌ ' + msg + '</span>' );
            }
            $resendBtn.prop( 'disabled', false ).text( '📱 Resend Prompt' );
        })
        .fail( function() {
            $statusMsg.html( '<span style="color:#d63638;">❌ Network error. Please refresh.</span>' );
            $resendBtn.prop( 'disabled', false ).text( '📱 Resend Prompt' );
        });
    });

    // ── Payment confirmed ────────────────────────────────────────────────────────
    function onPaymentConfirmed( redirect ) {
        if ( confirmed ) return;
        confirmed = true;
        stopPolling();
        $( '#wcmpesa-instructions' ).fadeOut( 300, function() {
            $( '#wcmpesa-confirmed' ).fadeIn( 300 );
        });
        setTimeout( function() {
            window.location.href = redirect;
        }, 2000 );
    }

});
