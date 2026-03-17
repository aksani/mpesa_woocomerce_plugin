<?php
/**
 * M-Pesa Daraja API Handler
 *
 * This class talks directly to Safaricom's Daraja API.
 * It handles: getting an access token, and sending the STK Push request.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Mpesa_API {

    private $consumer_key;
    private $consumer_secret;
    private $shortcode;
    private $passkey;
    private $environment; // 'sandbox' or 'production'

    // Daraja API base URLs
    const SANDBOX_URL    = 'https://sandbox.safaricom.co.ke';
    const PRODUCTION_URL = 'https://api.safaricom.co.ke';

    public function __construct( $settings ) {
        $this->consumer_key    = trim( $settings['consumer_key'] );
        $this->consumer_secret = trim( $settings['consumer_secret'] );
        $this->shortcode       = trim( $settings['shortcode'] );
        $this->passkey         = trim( $settings['passkey'] );
        $this->environment     = $settings['environment'];
    }

    /**
     * Returns the correct base URL depending on environment.
     */
    private function base_url() {
        return $this->environment === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    /**
     * Step 1: Get an OAuth access token from Safaricom.
     * This token expires after 1 hour, so we cache it in a transient.
     *
     * @return string|WP_Error  The access token string, or a WP_Error on failure.
     */
    public function get_access_token() {
        $cached = get_transient( 'wcmpesa_access_token' );
        if ( $cached ) return $cached;

        // Base64-encode "ConsumerKey:ConsumerSecret"
        $credentials = base64_encode( $this->consumer_key . ':' . $this->consumer_secret );

        $response = wp_remote_get( $this->base_url() . '/oauth/v1/generate?grant_type=client_credentials', [
            'headers' => [
                // Do NOT send Content-Type here — Safaricom's token endpoint rejects it
                'Authorization' => 'Basic ' . $credentials,
            ],
            'timeout'   => 30,
            'sslverify' => false,
        ]);

        if ( is_wp_error( $response ) ) {
            // Return the real underlying error (e.g. "cURL error 6: could not resolve host")
            return new WP_Error(
                'mpesa_token_error',
                'M-Pesa connection failed: ' . $response->get_error_message() .
                ' — Your server may be blocking outbound connections to Safaricom. Contact your host and ask them to whitelist sandbox.safaricom.co.ke on port 443.'
            );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $detail = $body['error_description'] ?? $body['errorMessage'] ?? wp_remote_retrieve_body( $response );
            return new WP_Error(
                'mpesa_token_error',
                "M-Pesa token error (HTTP $http_code): $detail — Double-check your Consumer Key and Consumer Secret on developer.safaricom.co.ke"
            );
        }

        // Cache the token for 55 minutes (it expires in 60)
        set_transient( 'wcmpesa_access_token', $body['access_token'], 55 * MINUTE_IN_SECONDS );

        return $body['access_token'];
    }

    /**
     * Step 2: Send an STK Push request to the customer's phone.
     *
     * @param string $phone   Customer phone in format 254XXXXXXXXX
     * @param float  $amount  Amount to charge
     * @param int    $order_id WooCommerce order ID (used as account reference)
     * @param string $callback_url  URL Safaricom will POST the result to
     *
     * @return array|WP_Error  Decoded response body, or WP_Error on failure.
     */
    public function stk_push( $phone, $amount, $order_id, $callback_url ) {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) return $token;

        // Build the password: Base64(Shortcode + Passkey + Timestamp)
        $timestamp = date( 'YmdHis' );
        $password  = base64_encode( $this->shortcode . $this->passkey . $timestamp );

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline', // Use 'CustomerBuyGoodsOnline' for Till numbers
            'Amount'            => (int) ceil( $amount ),   // M-Pesa only accepts whole numbers
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callback_url,
            'AccountReference'  => 'Order-' . $order_id,
            'TransactionDesc'   => 'Payment for Order ' . $order_id,
        ];

        $response = wp_remote_post( $this->base_url() . '/mpesa/stkpush/v1/processrequest', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'      => json_encode( $payload ),
            'timeout'   => 30,
            'sslverify' => false, // Needed on many shared cPanel hosts
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // ResponseCode '0' means the STK Push was sent successfully
        if ( isset( $body['ResponseCode'] ) && $body['ResponseCode'] === '0' ) {
            return $body;
        }

        $error_msg = $body['errorMessage'] ?? $body['ResponseDescription'] ?? 'Unknown M-Pesa error.';
        return new WP_Error( 'mpesa_stk_error', $error_msg, $body );
    }
    /**
     * Step 3: Query the status of an STK Push transaction directly from Safaricom.
     * Used as a fallback when the callback hasn't fired.
     *
     * @param string $checkout_request_id  The CheckoutRequestID from the original STK Push
     * @return array|WP_Error
     */
    public function stk_query( $checkout_request_id ) {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) return $token;

        $timestamp = date( 'YmdHis' );
        $password  = base64_encode( $this->shortcode . $this->passkey . $timestamp );

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkout_request_id,
        ];

        $response = wp_remote_post( $this->base_url() . '/mpesa/stkpushquery/v1/query', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'      => json_encode( $payload ),
            'timeout'   => 30,
            'sslverify' => false,
        ]);

        if ( is_wp_error( $response ) ) return $response;

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
