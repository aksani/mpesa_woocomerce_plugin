<?php
/**
 * Admin Transactions Log
 *
 * Adds a "M-Pesa Transactions" page under the WooCommerce admin menu,
 * showing a searchable table of all STK Push attempts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Mpesa_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
    }

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            'M-Pesa Transactions',
            'M-Pesa Transactions',
            'manage_woocommerce',
            'wcmpesa-transactions',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        // [SECURITY FIX 1] Verify the user has permission to be here
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'You do not have permission to view this page.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . WCMPESA_LOG_TABLE;

        // [SECURITY FIX 2] Verify nonce on search form submission
        $search = '';
        if ( isset( $_GET['s'] ) ) {
            // Nonce check — only validate when search is actually submitted
            if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wcmpesa_search' ) ) {
                $search = sanitize_text_field( wp_unslash( $_GET['s'] ) );
            }
        }

        $where = $search
            ? $wpdb->prepare(
                "WHERE phone LIKE %s OR mpesa_receipt LIKE %s OR order_id = %d",
                '%' . $wpdb->esc_like( $search ) . '%',
                '%' . $wpdb->esc_like( $search ) . '%',
                (int) $search
              )
            : '';

        // [SECURITY FIX 3] Cast pagination to int to prevent injection
        $per_page     = 20;
        $current_page = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset       = ( $current_page - 1 ) * $per_page;

        // [SECURITY FIX 4] Use $wpdb->prepare() for LIMIT/OFFSET
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        ?>
        <div class="wrap wcmpesa-admin">
            <h1 class="wp-heading-inline">M-Pesa Transactions</h1>

            <!-- [SECURITY FIX 2] Add nonce to search form -->
            <form method="get" style="margin: 15px 0;">
                <input type="hidden" name="page" value="wcmpesa-transactions">
                <?php wp_nonce_field( 'wcmpesa_search', '_wpnonce' ); ?>
                <input
                    type="search"
                    name="s"
                    value="<?php echo esc_attr( $search ); ?>"
                    placeholder="Search by phone, receipt or order ID…"
                    style="width:280px;"
                />
                <?php submit_button( 'Search', 'secondary', '', false ); ?>
                <?php if ( $search ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcmpesa-transactions' ) ); ?>" class="button">Clear</a>
                <?php endif; ?>
            </form>

            <?php
            $stats = $wpdb->get_row( "
                SELECT
                    COUNT(*) AS total,
                    SUM( status = 'completed' ) AS completed,
                    SUM( status = 'pending' )   AS pending,
                    SUM( status = 'failed' )    AS failed,
                    SUM( CASE WHEN status = 'completed' THEN amount ELSE 0 END ) AS total_amount
                FROM $table
            " );
            ?>
            <div class="wcmpesa-stats">
                <div class="stat-box">
                    <span class="stat-number"><?php echo (int) $stats->total; ?></span>
                    <span class="stat-label">Total</span>
                </div>
                <div class="stat-box success">
                    <span class="stat-number"><?php echo (int) $stats->completed; ?></span>
                    <span class="stat-label">Completed</span>
                </div>
                <div class="stat-box pending">
                    <span class="stat-number"><?php echo (int) $stats->pending; ?></span>
                    <span class="stat-label">Pending</span>
                </div>
                <div class="stat-box failed">
                    <span class="stat-number"><?php echo (int) $stats->failed; ?></span>
                    <span class="stat-label">Failed</span>
                </div>
                <div class="stat-box revenue">
                    <span class="stat-number">KES <?php echo number_format( (float) $stats->total_amount, 2 ); ?></span>
                    <span class="stat-label">Collected</span>
                </div>
            </div>

            <table class="wp-list-table widefat fixed striped wcmpesa-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Order</th>
                        <th>Phone</th>
                        <th>Amount (KES)</th>
                        <th>M-Pesa Receipt</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7" style="text-align:center;padding:30px;">No transactions found.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) :
                        $order_url = admin_url( 'post.php?post=' . (int) $row->order_id . '&action=edit' );
                    ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td><a href="<?php echo esc_url( $order_url ); ?>">#<?php echo (int) $row->order_id; ?></a></td>
                        <td><?php echo esc_html( $row->phone ); ?></td>
                        <td><?php echo number_format( (float) $row->amount, 2 ); ?></td>
                        <td><?php echo esc_html( $row->mpesa_receipt ?: '—' ); ?></td>
                        <td>
                            <span class="wcmpesa-badge wcmpesa-badge--<?php echo esc_attr( $row->status ); ?>">
                                <?php echo esc_html( ucfirst( $row->status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $row->created_at ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $total > $per_page ) :
                $total_pages = ceil( $total / $per_page );
                echo '<div class="tablenav bottom"><div class="tablenav-pages">';
                echo paginate_links([
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo; Prev',
                    'next_text' => 'Next &raquo;',
                ]);
                echo '</div></div>';
            endif; ?>
        </div>
        <?php
    }

    public function enqueue_admin_styles( $hook ) {
        if ( strpos( $hook, 'wcmpesa' ) === false ) return;
        wp_enqueue_style( 'wcmpesa-admin', WCMPESA_PLUGIN_URL . 'assets/css/admin.css', [], WCMPESA_VERSION );
    }
}

new WC_Mpesa_Admin();
