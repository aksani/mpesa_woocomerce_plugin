<?php
/**
 * M-Pesa Callback Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Mpesa_Callback {

    public static function handle( WP_REST_Request $request ) {
        $raw  = $request->get_body();
        $body = $request->get_json_params();
        self::writeLog( 'Callback received. Raw body: ' . $raw );

        if ( ! self::isValidPayload( $body ) ) {
            self::writeLog( 'Rejected: invalid payload structure.' );
            return new WP_REST_Response( [ 'ResultCode' => 1, 'ResultDesc' => 'Invalid payload' ], 400 );
        }

        $stkCallback       = $body['Body']['stkCallback'];
        $resultCode        = (int) $stkCallback['ResultCode'];
        $checkoutRequestId = sanitize_text_field( $stkCallback['CheckoutRequestID'] );

        self::writeLog( 'Looking up order for CheckoutRequestID: ' . $checkoutRequestId );
        $order = self::findOrderByCheckoutRequestId( $checkoutRequestId );

        if ( ! $order ) {
            self::writeLog( 'FAILED: No order found for CheckoutRequestID: ' . $checkoutRequestId );
            return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
        }

        if ( $order->is_paid() ) {
            self::writeLog( 'Skipped: Order #' . $order->get_id() . ' is already paid.' );
            return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
        }

        if ( $resultCode === 0 ) {
            self::processSuccessfulPayment( $order, $stkCallback, $checkoutRequestId, $body );
        } else {
            self::processFailedPayment( $order, $stkCallback, $checkoutRequestId, $body );
        }

        return new WP_REST_Response( [ 'ResultCode' => 0, 'ResultDesc' => 'Accepted' ], 200 );
    }

    private static function isValidPayload( $body ) {
        return isset(
            $body['Body']['stkCallback']['ResultCode'],
            $body['Body']['stkCallback']['CheckoutRequestID']
        );
    }

    private static function processSuccessfulPayment( $order, $stkCallback, $checkoutRequestId, $body ) {
        $metadata         = $stkCallback['CallbackMetadata']['Item'] ?? [];
        $mpesaReceipt     = sanitize_text_field( (string) self::getMetadataValue( $metadata, 'MpesaReceiptNumber' ) );
        $amountPaid       = (float) self::getMetadataValue( $metadata, 'Amount' );
        $transactionDate  = sanitize_text_field( (string) self::getMetadataValue( $metadata, 'TransactionDate' ) );
        $phone            = sanitize_text_field( (string) self::getMetadataValue( $metadata, 'PhoneNumber' ) );
        $orderTotal       = (float) $order->get_total();

        if ( $amountPaid < $orderTotal ) {
            self::handleAmountMismatch( $order, $orderTotal, $amountPaid, $mpesaReceipt, $checkoutRequestId, $body );
            return;
        }

        $order->payment_complete( $mpesaReceipt );
        $order->add_order_note( sprintf(
            "M-Pesa payment confirmed ✅\nReceipt: %s\nAmount: KES %.2f\nPhone: %s\nDate: %s",
            $mpesaReceipt, $amountPaid, $phone, $transactionDate
        ) );
        $order->update_meta_data( '_mpesa_receipt', $mpesaReceipt );
        $order->save();

        self::updateTransactionLog( $checkoutRequestId, 'completed', $mpesaReceipt, wp_json_encode( $body ) );
        self::maybeSendConfirmationEmail( $order, $mpesaReceipt );
        self::writeLog( 'Payment completed for Order #' . $order->get_id() . ' — Receipt: ' . $mpesaReceipt );
    }

    private static function processFailedPayment( $order, $stkCallback, $checkoutRequestId, $body ) {
        $resultDesc = sanitize_text_field( $stkCallback['ResultDesc'] ?? 'Payment was not completed.' );
        $order->update_status( 'failed', 'M-Pesa payment failed: ' . $resultDesc );
        self::updateTransactionLog( $checkoutRequestId, 'failed', '', wp_json_encode( $body ) );
        self::writeLog( 'Payment failed for Order #' . $order->get_id() . ': ' . $resultDesc );
    }

    private static function handleAmountMismatch( $order, $orderTotal, $amountPaid, $receipt, $checkoutRequestId, $body ) {
        self::writeLog( sprintf(
            'AMOUNT MISMATCH for Order #%d — Expected KES %.2f, got KES %.2f.',
            $order->get_id(), $orderTotal, $amountPaid
        ) );
        $order->update_status( 'on-hold', sprintf(
            'M-Pesa amount mismatch ⚠️ Expected KES %.2f but received KES %.2f. Receipt: %s. Manual review required.',
            $orderTotal, $amountPaid, $receipt
        ) );
        self::updateTransactionLog( $checkoutRequestId, 'mismatch', $receipt, wp_json_encode( $body ) );
    }

    private static function findOrderByCheckoutRequestId( $checkoutRequestId ) {
        $orders = wc_get_orders([
            'meta_key'   => '_mpesa_checkout_request_id',
            'meta_value' => $checkoutRequestId,
            'limit'      => 1,
            'status'     => 'any',
        ]);

        if ( ! empty( $orders ) ) {
            return $orders[0];
        }

        return self::findOrderInDatabase( $checkoutRequestId );
    }

    private static function findOrderInDatabase( $checkoutRequestId ) {
        global $wpdb;

        $orderId = $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
             WHERE meta_key = '_mpesa_checkout_request_id' AND meta_value = %s LIMIT 1",
            $checkoutRequestId
        ) );

        if ( ! $orderId ) {
            $orderId = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->prefix}postmeta
                 WHERE meta_key = '_mpesa_checkout_request_id' AND meta_value = %s LIMIT 1",
                $checkoutRequestId
            ) );
        }

        if ( $orderId ) {
            return wc_get_order( (int) $orderId );
        }

        self::writeLog( 'No order found for CheckoutRequestID: ' . $checkoutRequestId );
        return false;
    }

    private static function getMetadataValue( $items, $key ) {
        foreach ( $items as $item ) {
            if ( isset( $item['Name'] ) && $item['Name'] === $key ) {
                return $item['Value'] ?? '';
            }
        }
        return '';
    }

    private static function updateTransactionLog( $checkoutRequestId, $status, $receipt, $raw ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . WCMPESA_LOG_TABLE,
            [ 'status' => $status, 'mpesa_receipt' => $receipt, 'raw_response' => $raw ],
            [ 'checkout_request_id' => $checkoutRequestId ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );
    }

    private static function maybeSendConfirmationEmail( WC_Order $order, $receipt ) {
        $settings = get_option( 'woocommerce_mpesa_settings', [] );
        if ( ( $settings['send_confirmation_email'] ?? 'yes' ) !== 'yes' ) {
            return;
        }
        $to      = $order->get_billing_email();
        $subject = 'Payment Confirmed — Order #' . $order->get_order_number();
        $message = sprintf(
            "Hi %s,\n\nYour M-Pesa payment for Order #%s has been confirmed.\n\nM-Pesa Receipt: %s\nAmount: KES %s\n\nThank you!\n\n%s",
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $receipt,
            $order->get_total(),
            get_bloginfo( 'name' )
        );
        wp_mail( $to, $subject, $message, [ 'Content-Type: text/plain; charset=UTF-8' ] );
    }

    private static function writeLog( $message ) {
        wc_get_logger()->info( $message, [ 'source' => 'wcmpesa' ] );
    }
}
