<?php
/**
 * Plugin Name: WooCommerce M-Pesa Custom
 * Plugin URI:  https://aksantechnologies.co.ke
 * Description: Accept M-Pesa STK Push payments in WooCommerce via Safaricom Daraja API.
 * Version:     2.2.9
 * Author:      Aksan
 * License:     GPL-2.0+
 * Text Domain: wc-mpesa-custom
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCMPESA_VERSION', '2.2.9' );
define( 'WCMPESA_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WCMPESA_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'WCMPESA_LOG_TABLE',       'wcmpesa_transactions' );
define( 'WCMPESA_CALLBACK_BASE',   'wcmpesa/v1/callback/' );
define( 'WCMPESA_ORDER_NOT_FOUND', 'Order not found.' );
define( 'WCMPESA_LOG_WHERE',       ' WHERE checkout_request_id = %s LIMIT 1' );

// ─── Activation: create DB table + generate webhook secret ────────────────────
register_activation_hook( __FILE__, 'wcmpesaActivate' );
function wcmpesaActivate() {
    global $wpdb;
    $table   = $wpdb->prefix . WCMPESA_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id             BIGINT(20) UNSIGNED NOT NULL,
        phone                VARCHAR(20)         NOT NULL,
        amount               DECIMAL(10,2)       NOT NULL,
        mpesa_receipt        VARCHAR(50)         DEFAULT '',
        checkout_request_id  VARCHAR(100)        DEFAULT '',
        status               VARCHAR(20)         NOT NULL DEFAULT 'pending',
        raw_response         LONGTEXT,
        created_at           DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_order_id (order_id),
        KEY idx_checkout_request_id (checkout_request_id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    if ( ! get_option( 'wcmpesa_webhook_secret' ) ) {
        update_option( 'wcmpesa_webhook_secret', wp_generate_password( 32, false ) );
    }
}

// ─── Load the gateway once WooCommerce is ready ────────────────────────────────
add_action( 'plugins_loaded', 'wcmpesaInitGateway' );
function wcmpesaInitGateway() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WooCommerce M-Pesa Custom</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return;
    }

    require_once WCMPESA_PLUGIN_DIR . 'includes/class-mpesa-api.php';
    require_once WCMPESA_PLUGIN_DIR . 'includes/class-mpesa-gateway.php';
    require_once WCMPESA_PLUGIN_DIR . 'includes/class-mpesa-callback.php';
    require_once WCMPESA_PLUGIN_DIR . 'admin/class-mpesa-admin.php';

    add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
        $gateways[] = 'WC_Mpesa_Gateway';
        return $gateways;
    });
}

// ─── Register REST callback endpoint ─────────────────────────────────────────
add_action( 'rest_api_init', function() {
    register_rest_route( 'wcmpesa/v1', '/callback/(?P<token>[A-Za-z0-9_-]{20,})', [
        'methods'             => 'POST',
        'callback'            => [ 'WC_Mpesa_Callback', 'handle' ],
        'permission_callback' => function( WP_REST_Request $request ) {
            $expected = get_option( 'wcmpesa_webhook_secret', '' );
            $provided = $request->get_param( 'token' );
            if ( empty( $expected ) || empty( $provided ) ) {
                return false;
            }
            return hash_equals( $expected, (string) $provided );
        },
        'args' => [
            'token' => [
                'required'          => true,
                'validate_callback' => function( $v ) {
                    return is_string( $v ) && strlen( $v ) >= 20;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ]);
});
