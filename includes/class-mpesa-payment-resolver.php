<?php
/**
 * M-Pesa Payment Resolver
 *
 * Handles the multi-step payment verification logic:
 * WooCommerce DB → Transaction log → Safaricom STK Query
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WcMpesaPaymentResolver {

    private $apiSettings;

    public function __construct( array $apiSettings ) {
        $this->apiSettings = $apiSettings;
    }

    /**
     * Resolve payment for an order — checks all sources and sends JSON response.
     */
    public function resolve( WC_Order $order ) {
        if ( $order->is_paid() ) {
            $this->sendPaidResponse( $order, $order->get_meta( '_mpesa_receipt' ) );
            return;
        }
        $this->resolveByCheckoutId( $order );
    }

    private function resolveByCheckoutId( WC_Order $order ) {
        $checkoutRequestId = $order->get_meta( '_mpesa_checkout_request_id' );
        $syncedFromLog     = $checkoutRequestId && $this->syncFromLog( $order, $checkoutRequestId );
        if ( $syncedFromLog ) {
            return;
        }
        if ( $checkoutRequestId ) {
            $this->queryAndResolve( $order, $checkoutRequestId );
            return;
        }
        wp_send_json_error( [ 'message' => 'Payment not confirmed yet. Please wait a moment or try again.' ] );
    }

    private function syncFromLog( WC_Order $order, $checkoutRequestId ) {
        global $wpdb;
        $log = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . WCMPESA_LOG_TABLE . " WHERE checkout_request_id = %s LIMIT 1",
            $checkoutRequestId
        ) );
        wc_get_logger()->info( 'Fetch Payment — log: ' . wp_json_encode( $log ), [ 'source' => 'wcmpesa' ] );
        if ( ! $log || $log->status !== 'completed' || empty( $log->mpesa_receipt ) ) {
            return false;
        }
        $receipt = sanitize_text_field( $log->mpesa_receipt );
        $order->payment_complete( $receipt );
        $order->update_meta_data( '_mpesa_receipt', $receipt );
        $order->add_order_note( 'M-Pesa payment synced from transaction log ✅ Receipt: ' . $receipt );
        $order->save();
        $this->sendPaidResponse( $order, $receipt );
        return true;
    }

    private function queryAndResolve( WC_Order $order, $checkoutRequestId ) {
        $api   = new WC_Mpesa_API( $this->apiSettings );
        $query = $api->stkQuery( $checkoutRequestId );
        wc_get_logger()->info( 'Fetch Payment — STK Query: ' . wp_json_encode( $query ), [ 'source' => 'wcmpesa' ] );
        if ( is_wp_error( $query ) || ! isset( $query['ResultCode'] ) ) {
            wp_send_json_error( [ 'message' => 'Payment not confirmed yet. Please wait a moment or try again.' ] );
            return;
        }
        $this->handleQueryResult( $order, $query, $checkoutRequestId );
    }

    private function handleQueryResult( WC_Order $order, $query, $checkoutRequestId ) {
        $resultCode = (int) $query['ResultCode'];
        if ( $resultCode !== 0 ) {
            $messages = [ 1032 => 'You cancelled the M-Pesa prompt.', 1037 => 'M-Pesa prompt timed out.' ];
            $msg = $messages[ $resultCode ] ?? sanitize_text_field( $query['ResultDesc'] ?? 'Payment not completed.' );
            wp_send_json_error( [ 'message' => $msg ] );
            return;
        }
        $receipt = $this->extractReceiptFromQuery( $query, $checkoutRequestId );
        $order->payment_complete( $receipt );
        $order->update_meta_data( '_mpesa_receipt', $receipt );
        $order->add_order_note( 'M-Pesa confirmed via STK Query ✅ Receipt: ' . $receipt );
        $order->save();
        global $wpdb;
        $wpdb->update( $wpdb->prefix . WCMPESA_LOG_TABLE,
            [ 'status' => 'completed', 'mpesa_receipt' => $receipt ],
            [ 'checkout_request_id' => $checkoutRequestId ],
            [ '%s', '%s' ], [ '%s' ]
        );
        $this->sendPaidResponse( $order, $receipt );
    }

    private function extractReceiptFromQuery( $query, $fallback ) {
        foreach ( $query['CallbackMetadata']['Item'] ?? [] as $item ) {
            if ( ( $item['Name'] ?? '' ) === 'MpesaReceiptNumber' && ! empty( $item['Value'] ) ) {
                return sanitize_text_field( (string) $item['Value'] );
            }
        }
        return sanitize_text_field( $fallback );
    }

    public function sendPaidResponse( WC_Order $order, $receipt ) {
        $redirect = wc_get_endpoint_url( 'view-order', $order->get_id(), wc_get_page_permalink( 'myaccount' ) );
        wp_send_json_success( [ 'status' => 'paid', 'receipt' => $receipt, 'redirect' => $redirect ] );
    }
}
