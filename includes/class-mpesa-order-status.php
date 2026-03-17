<?php
/**
 * M-Pesa Order Status Checker
 *
 * Handles fresh order reads and transaction log sync for the polling endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WcMpesaOrderStatus {

    /**
     * Get a fresh order from DB, bypassing WooCommerce object cache.
     */
    public static function getFreshOrder( $order_id ) {
        wc_delete_order_item_transients( $order_id );
        if ( function_exists( 'wc_get_orders' ) ) {
            WC_Cache_Helper::invalidate_cache_group( 'orders' );
        }
        clean_post_cache( $order_id );
        $order = new WC_Order( $order_id );
        return ( $order && $order->get_id() ) ? $order : null;
    }

    /**
     * Check transaction log and sync to WooCommerce if completed.
     */
    public static function maybeSyncFromLog( WC_Order $order ) {
        if ( $order->is_paid() ) {
            return;
        }
        $checkoutRequestId = $order->get_meta( '_mpesa_checkout_request_id' );
        if ( ! $checkoutRequestId ) {
            return;
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, mpesa_receipt FROM {$wpdb->prefix}" . WCMPESA_LOG_TABLE . " WHERE checkout_request_id = %s LIMIT 1",
            $checkoutRequestId
        ) );
        if ( $row && $row->status === 'completed' && ! empty( $row->mpesa_receipt ) ) {
            $receipt = sanitize_text_field( $row->mpesa_receipt );
            $order->payment_complete( $receipt );
            $order->update_meta_data( '_mpesa_receipt', $receipt );
            $order->add_order_note( 'M-Pesa auto-synced from log ✅ Receipt: ' . $receipt );
            $order->save();
        }
    }
}
