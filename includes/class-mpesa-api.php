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
        $args = [ 'headers' => $headers, 'timeout' => 30, 'sslverify' => false ];
        if ( $body !== null ) {
            $args['body'] = $body;
        }
        return $method === 'GET' ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );
    }

    /**
     * Parse the raw HTTP response and return the decoded body or WP_Error.
     */
    private function parseResponse( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return json_decode( wp_remote_retrieve_body( $response ), true );
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
        return $this->fetchNewAccessToken();
    }

    private function fetchNewAccessToken() {
        $credentials = base64_encode( $this->consumerKey . ':' . $this->consumerSecret );
        $response    = $this->makeRequest( 'GET',
            $this->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            [ 'Authorization' => 'Basic ' . $credentials ]
        );
        $body = $this->parseResponse( $response );
        if ( is_wp_error( $body ) ) {
            return new WP_Error( 'mpesa_token_error', 'M-Pesa connection failed: ' . $body->get_error_message() );
        }
        return $this->extractAndCacheToken( $body, $response );
    }

    private function extractAndCacheToken( $body, $response ) {
        if ( ! empty( $body['access_token'] ) ) {
            set_transient( 'wcmpesa_access_token', $body['access_token'], 55 * MINUTE_IN_SECONDS );
            return $body['access_token'];
        }
        $httpCode = wp_remote_retrieve_response_code( $response );
        $detail   = $body['error_description'] ?? $body['errorMessage'] ?? wp_remote_retrieve_body( $response );
        return new WP_Error( 'mpesa_token_error', "M-Pesa token error (HTTP $httpCode): $detail" );
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
        return $this->sendStkPush( $token, $phone, $amount, $orderId, $callbackUrl );
    }

    private function sendStkPush( $token, $phone, $amount, $orderId, $callbackUrl ) {
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
        $body = $this->parseResponse( $this->makeRequest( 'POST',
            $this->baseUrl() . '/mpesa/stkpush/v1/processrequest',
            [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            json_encode( $payload )
        ) );
        return $this->parseStkPushResponse( $body );
    }

    private function parseStkPushResponse( $body ) {
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $success = isset( $body['ResponseCode'] ) && $body['ResponseCode'] === '0';
        if ( $success ) {
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
        return $this->sendStkQuery( $token, $checkoutRequestId );
    }

    private function sendStkQuery( $token, $checkoutRequestId ) {
        $timestamp = date( 'YmdHis' );
        $payload   = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $this->buildPassword( $timestamp ),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];
        return $this->parseResponse( $this->makeRequest( 'POST',
            $this->baseUrl() . '/mpesa/stkpushquery/v1/query',
            [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            json_encode( $payload )
        ) );
    }
}

// Backward-compatible alias
class_alias( 'WcMpesaApi', 'WC_Mpesa_API' );
