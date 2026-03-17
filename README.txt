=== WooCommerce M-Pesa Custom ===
Version: 2.3.0
Requires: WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+

== INSTALLATION ==

1. Upload the entire `wc-mpesa-custom` folder to /wp-content/plugins/
2. Go to WordPress Admin → Plugins → Activate "WooCommerce M-Pesa Custom"
3. Go to WooCommerce → Settings → Payments → M-Pesa

== CONFIGURATION ==

Fill in the following fields (from developer.safaricom.co.ke):
- Consumer Key
- Consumer Secret
- Business Shortcode (Pay Bill or Till number)
- Lipa na M-Pesa Passkey

Copy the Callback URL shown in settings and paste it into your Daraja app.

== GOING LIVE ==

1. Switch Environment from "Sandbox" to "Production"
2. Replace sandbox credentials with your live Daraja credentials
3. Ensure your site has a valid SSL certificate (https://)
4. Ensure your Callback URL is publicly accessible

== TRANSACTION LOGS ==

View all M-Pesa payment attempts at:
WooCommerce → M-Pesa Transactions

API debug logs are at:
WooCommerce → Status → Logs → select "wcmpesa"

== FILE STRUCTURE ==

wc-mpesa-custom/
├── wc-mpesa-custom.php          ← Main plugin file (registers everything)
├── includes/
│   ├── class-mpesa-api.php      ← Handles Daraja API calls (token + STK Push)
│   ├── class-mpesa-gateway.php  ← WooCommerce payment gateway (checkout flow)
│   └── class-mpesa-callback.php ← Handles Safaricom's payment confirmation
├── admin/
│   └── class-mpesa-admin.php    ← Admin transactions log page
└── assets/
    ├── css/admin.css            ← Admin page styles
    └── js/checkout.js           ← "Check your phone" overlay on checkout
