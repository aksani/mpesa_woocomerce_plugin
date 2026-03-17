<?php
/**
 * M-Pesa Daraja API Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WcMpesaApi {

    private $consumerKey;
    private $consumerSecret;
    private $shortcode;
    private $passkey;
    private $environment;

    const SANDBOX_URL    = 'https://sandbox.safaricom.co.ke';
    const PRODUCTION_URL = 'https://api.safaricom.co.ke';

    public function __construct( $settings ) {
        $this->consumerKey    = trim( $settings['consumer_key'] );
        $this->consumerSecret = trim( $settings['consumer_secret'] );
        $this->shortcode      = trim( $settings['shortcode'] );
        $this->passkey        = trim( $settings['passkey'] );
        $this->environment    = $settings['environment'];
    }

    private function baseUrl() {
        return $this->environment === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    private function buildPassword( $timestamp ) {
        return base64_encode( $this->shortcode . $this->passkey . $timestamp );
    }

    private function makeRequest( $method, $url, $headers, $body = null ) {
        $args = [
            'headers'   => $headers,
            'timeout'   => 30,
            'sslverify' => false,
        ];
        if ( $body !== null ) {
            $args['body'] = $body;
        }
        return $method === 'GET'
            ? wp_remote_get( $url, $args )
            : wp_remote_post( $url, $args );
    }

    /**
     * Get an OAuth access token from Safaricom (cached 55 min).
     *
     * @return string|WP_Error
     */
    public function getAccessToken() {
        $cached = get_transient( 'wcmpesa_access_token' );
        if ( $cached ) {
            return $cached;
        }

        $credentials = base64_encode( $this->consumerKey . ':' . $this->consumerSecret );
        $response    = $this->makeRequest( 'GET',
            $this->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            [ 'Authorization' => 'Basic ' . $credentials ]
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'mpesa_token_error',
                'M-Pesa connection failed: ' . $response->get_error_message()
            );
        }

        $httpCode = wp_remote_retrieve_response_code( $response );
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $detail = $body['error_description'] ?? $body['errorMessage'] ?? wp_remote_retrieve_body( $response );
            return new WP_Error( 'mpesa_token_error',
                "M-Pesa token error (HTTP $httpCode): $detail"
            );
        }

        set_transient( 'wcmpesa_access_token', $body['access_token'], 55 * MINUTE_IN_SECONDS );
        return $body['access_token'];
    }

    /**
     * Send an STK Push to the customer's phone.
     *
     * @param string $phone
     * @param float  $amount
     * @param int    $orderId
     * @param string $callbackUrl
     * @return array|WP_Error
     */
    public function stkPush( $phone, $amount, $orderId, $callbackUrl ) {
        $token = $this->getAccessToken();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $timestamp = date( 'YmdHis' );
        $payload   = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $this->buildPassword( $timestamp ),
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int) ceil( $amount ),
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => 'Order-' . $orderId,
            'TransactionDesc'   => 'Payment for Order ' . $orderId,
        ];

        $response = $this->makeRequest( 'POST',
            $this->baseUrl() . '/mpesa/stkpush/v1/processrequest',
            [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            json_encode( $payload )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['ResponseCode'] ) && $body['ResponseCode'] === '0' ) {
            return $body;
        }

        $errorMsg = $body['errorMessage'] ?? $body['ResponseDescription'] ?? 'Unknown M-Pesa error.';
        return new WP_Error( 'mpesa_stk_error', $errorMsg, $body );
    }

    /**
     * Query the status of an STK Push transaction.
     *
     * @param string $checkoutRequestId
     * @return array|WP_Error
     */
    public function stkQuery( $checkoutRequestId ) {
        $token = $this->getAccessToken();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $timestamp = date( 'YmdHis' );
        $payload   = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $this->buildPassword( $timestamp ),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $response = $this->makeRequest( 'POST',
            $this->baseUrl() . '/mpesa/stkpushquery/v1/query',
            [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            json_encode( $payload )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}

// Backward-compatible alias — existing code that references WC_Mpesa_API still works
class_alias( 'WcMpesaApi', 'WC_Mpesa_API' );
