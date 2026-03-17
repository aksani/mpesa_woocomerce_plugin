<?php
/**
 * M-Pesa Callback Handler
 *
 * Safaricom POSTs to: https://yoursite.com/wp-json/wcmpesa/v1/callback/<secret>
 * The secret token is validated in the REST route's permission_callback (main plugin file)
 * BEFORE this handler runs — so by the time we're here, the request is authenticated.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Mpesa_Callback {

    public static function handle( WP_REST_Request $request ) {
        $raw  = $request->get_body();
        $body = $request->get_json_params();
        self::write_log( 'Callback received. Raw body: ' . $raw );

        // [SECURITY] Strict schema validation — reject anything malformed
        if ( ! isset(
            $body['Body']['stkCallback']['ResultCode'],
            $body['Body']['stkCallback']['CheckoutRequestID']
        ) ) {
            self::write_log( 'Rejected: invalid payload structure.' );
            return new WP_REST_Response( [ 'ResultCode' => 1, 'ResultDesc' => 'Invalid payload' ], 400 );
        }

        $stk_callback        = $body['Body']['stkCallback'];
        $result_code         = (int) $stk_callback['ResultCode'];
        $checkout_request_id = sanitize_text_field( $stk_callback['CheckoutRequestID'] );

        // Find the matching WooCommerce order
        self::write_log( 'Looking up order for CheckoutRequestID: ' . $checkout_request_id );
        $order = self::find_order_by_checkout_request_id( $checkout_request_id );

        if ( ! $order ) {
            self::write_log( 'FAILED: No order found for CheckoutRequestID: ' . $checkout_request_id );
            // Still return 200 so Safaricom doesn't keep retrying
            return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
        }

        // [SECURITY] Prevent replaying a callback on an already-paid order
        if ( $order->is_paid() ) {
            self::write_log( 'Skipped: Order #' . $order->get_id() . ' is already paid.' );
            return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
        }

        if ( $result_code === 0 ) {
            // ── Payment successful ─────────────────────────────────────────────
            $metadata         = $stk_callback['CallbackMetadata']['Item'] ?? [];
            $mpesa_receipt    = sanitize_text_field( (string) self::get_metadata_value( $metadata, 'MpesaReceiptNumber' ) );
            $amount_paid      = (float) self::get_metadata_value( $metadata, 'Amount' );
            $transaction_date = sanitize_text_field( (string) self::get_metadata_value( $metadata, 'TransactionDate' ) );
            $phone            = sanitize_text_field( (string) self::get_metadata_value( $metadata, 'PhoneNumber' ) );

            // [SECURITY] Verify the amount paid matches the order total
            // Prevents underpayment attacks where someone pays KES 1 for a KES 5000 order
            $order_total = (float) $order->get_total();
            if ( $amount_paid < $order_total ) {
                self::write_log( sprintf(
                    'AMOUNT MISMATCH for Order #%d — Expected KES %.2f, got KES %.2f. Order NOT marked paid.',
                    $order->get_id(), $order_total, $amount_paid
                ) );
                $order->update_status( 'on-hold', sprintf(
                    'M-Pesa amount mismatch ⚠️ Expected KES %.2f but received KES %.2f. Receipt: %s. Manual review required.',
                    $order_total, $amount_paid, $mpesa_receipt
                ) );
                self::update_transaction_log( $checkout_request_id, 'mismatch', $mpesa_receipt, wp_json_encode( $body ) );
                return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
            }

            // All checks passed — mark order as paid
            $order->payment_complete( $mpesa_receipt );
            $order->add_order_note( sprintf(
                "M-Pesa payment confirmed ✅\nReceipt: %s\nAmount: KES %.2f\nPhone: %s\nDate: %s",
                $mpesa_receipt, $amount_paid, $phone, $transaction_date
            ) );
            $order->update_meta_data( '_mpesa_receipt', $mpesa_receipt );
            $order->save();

            self::update_transaction_log( $checkout_request_id, 'completed', $mpesa_receipt, wp_json_encode( $body ) );
            self::maybe_send_confirmation_email( $order, $mpesa_receipt );
            self::write_log( 'Payment completed for Order #' . $order->get_id() . ' — Receipt: ' . $mpesa_receipt );

        } else {
            // ── Payment failed or cancelled ────────────────────────────────────
            $result_desc = sanitize_text_field( $stk_callback['ResultDesc'] ?? 'Payment was not completed.' );
            $order->update_status( 'failed', 'M-Pesa payment failed: ' . $result_desc );
            self::update_transaction_log( $checkout_request_id, 'failed', '', wp_json_encode( $body ) );
            self::write_log( 'Payment failed for Order #' . $order->get_id() . ': ' . $result_desc );
        }

        return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
    }

    private static function find_order_by_checkout_request_id( $checkout_request_id ) {
        // Method 1: wc_get_orders (works with both HPOS and legacy storage)
        $orders = wc_get_orders([
            'meta_key'   => '_mpesa_checkout_request_id',
            'meta_value' => $checkout_request_id,
            'limit'      => 1,
            'status'     => 'any',
        ]);

        if ( ! empty( $orders ) ) {
            self::write_log( 'Order found via wc_get_orders for: ' . $checkout_request_id );
            return $orders[0];
        }

        // Method 2: Fallback — direct DB query (handles edge cases with HPOS)
        global $wpdb;
        $order_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
             WHERE meta_key = '_mpesa_checkout_request_id' AND meta_value = %s
             LIMIT 1",
            $checkout_request_id
        ));

        if ( ! $order_id ) {
            // Also check legacy postmeta table
            $order_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}postmeta
                 WHERE meta_key = '_mpesa_checkout_request_id' AND meta_value = %s
                 LIMIT 1",
                $checkout_request_id
            ));
        }

        if ( $order_id ) {
            self::write_log( 'Order found via DB fallback for: ' . $checkout_request_id . ' → Order #' . $order_id );
            return wc_get_order( (int) $order_id );
        }

        self::write_log( 'No order found for CheckoutRequestID: ' . $checkout_request_id );
        return false;
    }

    private static function get_metadata_value( $items, $key ) {
        foreach ( $items as $item ) {
            if ( isset( $item['Name'] ) && $item['Name'] === $key ) {
                return $item['Value'] ?? '';
            }
        }
        return '';
    }

    private static function update_transaction_log( $checkout_request_id, $status, $receipt, $raw ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . WCMPESA_LOG_TABLE,
            [
                'status'        => $status,
                'mpesa_receipt' => $receipt,
                'raw_response'  => $raw,
            ],
            [ 'checkout_request_id' => $checkout_request_id ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );
    }

    private static function maybe_send_confirmation_email( WC_Order $order, $receipt ) {
        $settings = get_option( 'woocommerce_mpesa_settings', [] );
        if ( ( $settings['send_confirmation_email'] ?? 'yes' ) !== 'yes' ) return;

        $to      = $order->get_billing_email();
        $subject = 'Payment Confirmed — Order #' . $order->get_order_number();
        $message = sprintf(
            "Hi %s,\n\nYour M-Pesa payment for Order #%s has been confirmed.\n\nM-Pesa Receipt: %s\nAmount: KES %s\n\nThank you for your purchase!\n\n%s",
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $receipt,
            $order->get_total(),
            get_bloginfo( 'name' )
        );

        wp_mail( $to, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );
    }

    private static function write_log( $message ) {
        wc_get_logger()->info( $message, [ 'source' => 'wcmpesa' ] );
    }
}
