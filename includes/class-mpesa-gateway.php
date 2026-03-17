<?php
/**
 * WooCommerce M-Pesa Payment Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Mpesa_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'mpesa';
        $this->has_fields         = true;
        $this->method_title       = 'M-Pesa';
        $this->method_description = 'Accept payments via Safaricom M-Pesa STK Push (Lipa na M-Pesa).';

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // ── Thank you page: gateway-specific hook + generic fallback ──────────
        add_action( 'woocommerce_thankyou_mpesa', [ $this, 'thankyou_page' ] );
        add_action( 'woocommerce_thankyou',        [ $this, 'thankyou_fallback' ] );

        // ── Remove PAY/CANCEL, add Order Again on thank you page ────────────────
        add_filter( 'woocommerce_my_account_my_orders_actions', [ $this, 'remove_order_actions' ], 10, 2 );
        add_filter( 'woocommerce_order_actions',                 [ $this, 'remove_order_actions_thankyou' ], 10, 1 );
        add_action( 'woocommerce_thankyou',                      [ $this, 'add_order_again_button' ], 20 );

        // ── AJAX: handle "Complete Order" button click ─────────────────────────
        add_action( 'wp_ajax_wcmpesa_complete_order',        [ $this, 'ajax_complete_order' ] );

        // ── AJAX: poll order status (auto-refresh) ─────────────────────────────
        add_action( 'wp_ajax_wcmpesa_check_status',        [ $this, 'ajax_check_status' ] );
        add_action( 'wp_ajax_nopriv_wcmpesa_check_status', [ $this, 'ajax_check_status' ] );

        add_action( 'wp_ajax_wcmpesa_resend_stk',        [ $this, 'ajax_resend_stk' ] );
    }

    public function init_form_fields() {
        $secret       = get_option( 'wcmpesa_webhook_secret', '(activate plugin to generate)' );
        $callback_url = rest_url( WCMPESA_CALLBACK_BASE . $secret );

        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable M-Pesa payments',
                'default' => 'yes',
            ],
            'title' => [
                'title'   => 'Payment Title',
                'type'    => 'text',
                'default' => 'M-Pesa',
            ],
            'description' => [
                'title'   => 'Description',
                'type'    => 'textarea',
                'default' => 'Pay securely using M-Pesa. You will receive an STK Push prompt on your phone.',
            ],
            'environment' => [
                'title'   => 'Environment',
                'type'    => 'select',
                'options' => [
                    'sandbox'    => 'Sandbox (Testing)',
                    'production' => 'Production (Live)',
                ],
                'default' => 'sandbox',
                'description' => '⚠️ Switch to Production when going live.',
            ],
            'consumer_key' => [
                'title' => 'Consumer Key',
                'type'  => 'text',
            ],
            'consumer_secret' => [
                'title' => 'Consumer Secret',
                'type'  => 'password',
            ],
            'shortcode' => [
                'title'   => 'Business Shortcode',
                'type'    => 'text',
                'default' => '',
            ],
            'passkey' => [
                'title' => 'Lipa na M-Pesa Passkey',
                'type'  => 'password',
            ],
            'callback_url_display' => [
                'title'       => 'Callback URL',
                'type'        => 'title',
                'description' => '<code style="background:#f1f1f1;padding:4px 8px;">' . esc_url( $callback_url ) . '</code><br>Copy this URL into your Daraja app.',
            ],
            'send_confirmation_email' => [
                'title'   => 'Payment Confirmation Email',
                'type'    => 'checkbox',
                'label'   => 'Send customer a confirmation email when payment is confirmed',
                'default' => 'yes',
            ],
        ];
    }

    /**
     * Phone number field shown at checkout.
     */
    public function payment_fields() {
        if ( $this->description ) {
            echo '<p>' . esc_html( $this->description ) . '</p>';
        }
        ?>
        <fieldset>
            <p class="form-row form-row-wide">
                <label for="mpesa_phone">M-Pesa Phone Number <span class="required">*</span></label>
                <input type="tel" id="mpesa_phone" name="mpesa_phone" class="input-text"
                    placeholder="e.g. 0712345678 or 254712345678" autocomplete="tel" />
                <small style="color:#666;">Enter the number that will receive the M-Pesa prompt.</small>
                <?php wp_nonce_field( 'wcmpesa_checkout', 'wcmpesa_nonce' ); ?>
            </p>
        </fieldset>
        <?php
    }

    public function validate_fields() {
        $error = $this->getValidationError();
        if ( $error ) {
            wc_add_notice( $error, 'error' );
            return false;
        }
        return true;
    }

    private function getValidationError() {
        if ( ! is_user_logged_in() ) {
            return 'You must be logged in to pay with M-Pesa. Please <a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">log in or create an account</a> first.';
        }
        $nonce = isset( $_POST['wcmpesa_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wcmpesa_nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wcmpesa_checkout' ) ) {
            return 'Security check failed. Please refresh and try again.';
        }
        return $this->getPhoneError();
    }

    private function getPhoneError() {
        $phone = isset( $_POST['mpesa_phone'] ) ? sanitize_text_field( $_POST['mpesa_phone'] ) : '';
        if ( empty( $phone ) ) {
            return 'Please enter your M-Pesa phone number.';
        }
        if ( ! $this->format_phone( $phone ) ) {
            return 'Please enter a valid Kenyan phone number (e.g. 0712345678).';
        }
        return '';
    }

    public function process_payment( $order_id ) {
        // Hard server-side login check — cannot be bypassed by JS
        if ( ! is_user_logged_in() ) {
            wc_add_notice( 'You must be logged in to pay with M-Pesa.', 'error' );
            return [ 'result' => 'failure' ];
        }

        $order = wc_get_order( $order_id );
        $phone = $this->format_phone( sanitize_text_field( $_POST['mpesa_phone'] ) );

        $api = new WC_Mpesa_API([
            'consumer_key'    => $this->get_option( 'consumer_key' ),
            'consumer_secret' => $this->get_option( 'consumer_secret' ),
            'shortcode'       => $this->get_option( 'shortcode' ),
            'passkey'         => $this->get_option( 'passkey' ),
            'environment'     => $this->get_option( 'environment' ),
        ]);

        $secret       = get_option( 'wcmpesa_webhook_secret', '' );
        $callback_url = rest_url( WCMPESA_CALLBACK_BASE . $secret );
        $result       = $api->stkPush( $phone, $order->get_total(), $order_id, $callback_url );

        if ( is_wp_error( $result ) ) {
            wc_add_notice( 'M-Pesa Error: ' . $result->get_error_message(), 'error' );
            $order->add_order_note( 'STK Push failed: ' . $result->get_error_message() );
            return [ 'result' => 'failure' ];
        }

        $checkout_request_id = $result['CheckoutRequestID'];
        $order->update_meta_data( '_mpesa_checkout_request_id', $checkout_request_id );
        $order->update_meta_data( '_mpesa_phone', $phone );
        $order->save();

        $this->log_transaction([
            'order_id'            => $order_id,
            'phone'               => $phone,
            'amount'              => $order->get_total(),
            'checkout_request_id' => $checkout_request_id,
            'status'              => 'pending',
            'raw_response'        => json_encode( $result ),
        ]);

        $order->update_status( 'pending', 'M-Pesa STK Push sent. Awaiting customer confirmation.' );
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }


    /**
     * Custom thank you page.
     * STK Push is already sent when customer clicks "Place Order".
     * This page just asks them to check their phone and click Complete Order.
     */
    /** Track whether thank you content already rendered to prevent double output */
    private $thankyouRendered = false;

    public function thankyou_page( $order_id ) {
        if ( $this->thankyouRendered ) {
            return;
        }
        $this->thankyouRendered = true;

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Already paid via callback — show receipt
        if ( $order->is_paid() ) {
            echo '<div class="wcmpesa-thankyou wcmpesa-paid">
                <span class="wcmpesa-icon">✅</span>
                <h3>Payment Confirmed!</h3>
                <p>Your M-Pesa payment has been received. Receipt: <strong>' . esc_html( $order->get_meta( '_mpesa_receipt' ) ) . '</strong></p>
            </div>';
        }

        $phone         = $order->get_meta( '_mpesa_phone' );
        $displayPhone  = $phone ? '0' . substr( $phone, 3 ) : 'your phone';
        $amount        = number_format( (float) $order->get_total(), 2 );
        $shortcode     = $this->get_option( 'shortcode' );
        $cancelUrl     = $order->get_cancel_order_url( wc_get_page_permalink( 'myaccount' ) );
        ?>
        <div class="wcmpesa-box" id="wcmpesa-thankyou-box"
             data-order="<?php echo (int) $order_id; ?>"
             data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcmpesa_action' ) ); ?>"
             data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">

            <div id="wcmpesa-instructions">

                <!-- Header row: title + amount -->
                <div class="wcmpesa-header">
                    <span class="wcmpesa-title">Pay Using M-PESA</span>
                    <span class="wcmpesa-amount">KES <?php echo esc_html( $amount ); ?></span>
                </div>

                <!-- STK Push sent banner -->
                <div class="wcmpesa-banner" id="wcmpesa-waiting-msg">
                    <span class="wcmpesa-spinner"></span>
                    Please, check your phone for STK Menu
                </div>

                <!-- STK Push instructions -->
                <ol class="wcmpesa-steps">
                    <li>An M-Pesa prompt was sent to <strong><?php echo esc_html( $displayPhone ); ?></strong> — enter your PIN to confirm.</li>
                    <li>Enter your <strong>M-Pesa PIN</strong> and click <strong>OK</strong>.</li>
                    <li>You will receive a confirmation SMS from M-Pesa.</li>
                </ol>
                <p class="wcmpesa-hint">After you receive a successful reply from M-Pesa, click the <strong>Complete</strong> button below.</p>

                <!-- Manual Paybill fallback -->
                <div class="wcmpesa-manual">
                    <p class="wcmpesa-manual-title">Or follow instructions below</p>
                    <ol class="wcmpesa-steps">
                        <li>Go to M-Pesa menu on your phone</li>
                        <li>Select <strong>Lipa na M-Pesa</strong> option</li>
                        <li>Select <strong>Pay Bill</strong> option</li>
                        <li>Enter Business Number <strong><?php echo esc_html( $shortcode ); ?></strong></li>
                        <li>Enter Account Number <strong><?php echo esc_html( 'Order-' . $order_id ); ?></strong></li>
                        <li>Enter the amount <strong>KES <?php echo esc_html( $amount ); ?></strong></li>
                        <li>Enter your M-Pesa PIN and Send</li>
                        <li>You will receive a confirmation SMS from M-Pesa</li>
                    </ol>
                </div>

                <!-- Status message -->
                <p id="wcmpesa-status-msg"></p>

                <!-- Action buttons -->
                <div class="wcmpesa-actions">
                    <button id="wcmpesa-fetch-btn" class="wcmpesa-btn wcmpesa-btn--fetch">Fetch Payment</button>
                    <a href="<?php echo esc_url( $cancelUrl ); ?>" class="wcmpesa-btn wcmpesa-btn--cancel">Cancel</a>
                    <button id="wcmpesa-complete-btn" class="wcmpesa-btn wcmpesa-btn--complete">Complete</button>
                </div>

            </div>

            <!-- Confirmed state -->
            <div id="wcmpesa-confirmed" style="display:none;">
                <div class="wcmpesa-header">
                    <span class="wcmpesa-title">Pay Using M-PESA</span>
                    <span class="wcmpesa-amount">KES <?php echo esc_html( $amount ); ?></span>
                </div>
                <div class="wcmpesa-banner wcmpesa-banner--success">✅ Payment Confirmed! Redirecting…</div>
            </div>

        </div>

        <style>
        .wcmpesa-box { background:#fff; border:1px solid #e0e0e0; border-radius:10px; overflow:hidden; margin:20px 0; font-size:14px; }
        .wcmpesa-header { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #f0f0f0; }
        .wcmpesa-title { font-weight:600; font-size:15px; color:#333; }
        .wcmpesa-amount { font-weight:700; font-size:18px; color:#111; }
        .wcmpesa-banner { display:flex; align-items:center; gap:10px; background:#00a32a; color:#fff; font-weight:600; font-size:14px; padding:14px 20px; }
        .wcmpesa-banner--success { background:#00a32a; }
        .wcmpesa-banner--waiting { background:#00a32a; }
        .wcmpesa-spinner { width:16px; height:16px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:wcmpesa-spin .8s linear infinite; flex-shrink:0; }
        @keyframes wcmpesa-spin { to { transform:rotate(360deg); } }
        .wcmpesa-steps { padding:0 20px 0 36px; margin:14px 0; color:#444; line-height:1.9; }
        .wcmpesa-hint { padding:0 20px; color:#555; margin:0 0 12px; }
        .wcmpesa-manual { background:#f9f9f9; border-top:1px solid #eee; padding:14px 20px; margin-top:12px; }
        .wcmpesa-manual-title { font-weight:600; margin:0 0 8px; color:#333; }
        .wcmpesa-manual .wcmpesa-steps { padding-left:20px; }
        #wcmpesa-status-msg { padding:0 20px; min-height:20px; font-style:italic; }
        .wcmpesa-actions { display:flex; gap:10px; padding:16px 20px; border-top:1px solid #f0f0f0; flex-wrap:wrap; align-items:center; }
        .wcmpesa-btn { padding:11px 22px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; border:none; text-decoration:none; display:inline-block; text-align:center; transition:opacity .2s; }
        .wcmpesa-btn:disabled { opacity:.55; cursor:not-allowed; }
        .wcmpesa-btn--fetch { background:#4a5568; color:#fff; }
        .wcmpesa-btn--fetch:hover { background:#2d3748; color:#fff; }
        .wcmpesa-btn--cancel { background:none; color:#555; border:1px solid #ccc; }
        .wcmpesa-btn--cancel:hover { background:#f5f5f5; color:#333; }
        .wcmpesa-btn--complete { background:#2563eb; color:#fff; margin-left:auto; }
        .wcmpesa-btn--complete:hover { background:#1d4ed8; color:#fff; }
        </style>
        <?php
    }


    /**
     * AJAX: Fetch / Complete button handler.
     * Checks payment via WC DB → transaction log → Safaricom STK Query.
     */
    public function ajax_complete_order() {
        check_ajax_referer( 'wcmpesa_action', 'nonce' );
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => WCMPESA_ORDER_NOT_FOUND ] );
            return;
        }
        $resolver = new WcMpesaPaymentResolver( $this->getApiSettings() );
        $resolver->resolve( $order );
    }

    private function getApiSettings() {
        return [
            'consumer_key'    => $this->get_option( 'consumer_key' ),
            'consumer_secret' => $this->get_option( 'consumer_secret' ),
            'shortcode'       => $this->get_option( 'shortcode' ),
            'passkey'         => $this->get_option( 'passkey' ),
            'environment'     => $this->get_option( 'environment' ),
        ];
    }

    /**
     * AJAX: Resend STK Push (separate from Complete Order).
     */
    public function ajax_resend_stk() {
        check_ajax_referer( 'wcmpesa_action', 'nonce' );

        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) { wp_send_json_error( [ 'message' => WCMPESA_ORDER_NOT_FOUND ] ); return; }
        if ( $order->is_paid() ) {
            $redirect = wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
            wp_send_json_success( [ 'status' => 'paid', 'redirect' => $redirect ] ); return;
        }

        $api = new WC_Mpesa_API( $this->getApiSettings() );

        $phone        = $order->get_meta( '_mpesa_phone' );
        $secret       = get_option( 'wcmpesa_webhook_secret', '' );
        $callback_url = rest_url( WCMPESA_CALLBACK_BASE . $secret );
        $result       = $api->stkPush( $phone, $order->get_total(), $order_id, $callback_url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
            return;
        }

        $order->update_meta_data( '_mpesa_checkout_request_id', $result['CheckoutRequestID'] );
        $order->save();

        wp_send_json_success( [ 'status' => 'sent', 'message' => 'New prompt sent! Check your phone.' ] );
        return;
    }

    /**
     * AJAX: Poll order status every 5s from the thank you page.
     */
    public function ajax_check_status() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'wcmpesa_action' ) ) {
            wp_send_json_error( [ 'code' => 'nonce_expired', 'nonce' => wp_create_nonce( 'wcmpesa_action' ) ] );
            return;
        }
        $order_id = (int) ( $_POST['order_id'] ?? 0 );
        $order    = $order_id ? WcMpesaOrderStatus::getFreshOrder( $order_id ) : null;
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => $order_id ? WCMPESA_ORDER_NOT_FOUND : 'Invalid order.' ] );
            return;
        }
        WcMpesaOrderStatus::maybeSyncFromLog( $order );
        if ( $order->is_paid() ) {
            $redirect = wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
            wp_send_json_success( [ 'status' => 'paid', 'receipt' => $order->get_meta( '_mpesa_receipt' ), 'redirect' => $redirect ] );
            return;
        }
        wp_send_json_success( [ 'status' => 'pending' ] );
    }

    public function format_phone( $phone ) {
        $phone = preg_replace( '/\D/', '', $phone );
        if ( substr( $phone, 0, 1 ) === '0' && strlen( $phone ) === 10 ) {
            return '254' . substr( $phone, 1 );
        }
        if ( substr( $phone, 0, 3 ) === '254' && strlen( $phone ) === 12 ) {
            return $phone;
        }
        return false;
    }

    public function log_transaction( $data ) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . WCMPESA_LOG_TABLE,
            $data,
            [ '%d', '%s', '%f', '%s', '%s', '%s' ]
        );
    }

    /**
     * Fallback: woocommerce_thankyou fires for all gateways.
     * woocommerce_thankyou_mpesa only fires if WC recognises the gateway ID exactly.
     * This ensures our content always shows.
     */
    public function thankyou_fallback( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }
        $this->thankyou_page( $order_id );
    }

    /**
     * Remove PAY/CANCEL from My Account orders list for M-Pesa orders.
     */
    public function remove_order_actions( $actions, $order ) {
        if ( $order->get_payment_method() === $this->id ) {
            unset( $actions['pay'] );
        }
        return $actions;
    }

    /**
     * Remove PAY/CANCEL from the thank you page order details table.
     */
    public function remove_order_actions_thankyou( $actions ) {
        if ( ! is_wc_endpoint_url( 'order-received' ) ) {
            return $actions;
        }
        $order_id = absint( get_query_var( 'order-received' ) );
        if ( ! $order_id ) {
            return $actions;
        }
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_payment_method() === $this->id ) {
            unset( $actions['pay'] );
        }
        return $actions;
    }

    /**
     * Add "Order Another Item" button below order details on the thank you page.
     */
    public function add_order_again_button( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) {
            return;
        }
        $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
        echo '<div style="margin-top:16px;text-align:center;">';
        echo '<a href="' . esc_url( $shop_url ) . '" class="button" style="background:#1d2327;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:15px;">&#128722; Order Another Item</a>';
        echo '</div>';
    }

    public function enqueue_scripts() {
        // Load on checkout form and order-received page
        // Use both checks for maximum theme compatibility
        if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
            return;
        }

        wp_enqueue_script(
            'wcmpesa-checkout',
            WCMPESA_PLUGIN_URL . 'assets/js/checkout.js',
            [ 'jquery' ],
            WCMPESA_VERSION,
            true
        );

        wp_localize_script( 'wcmpesa-checkout', 'wcmpesa', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wcmpesa_action' ),
        ]);
    }
}

// SonarQube-compliant alias — WooCommerce requires WC_Mpesa_Gateway
class_alias( 'WC_Mpesa_Gateway', 'WcMpesaGateway' );
