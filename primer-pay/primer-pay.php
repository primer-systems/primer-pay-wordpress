<?php
/**
 * Plugin Name: Primer Pay
 * Plugin URI:  https://github.com/primer-systems/primer-pay-wordpress
 * Description: Monetize WordPress content with x402 micropayments. Visitors with the Primer Pay browser extension pay seamlessly; everyone else sees a teaser.
 * Version:     0.1.0
 * Author:      Primer Systems
 * Author URI:  https://primer.systems
 * License:     GPL-2.0-or-later
 * Text Domain: primer-pay
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PRIMER_PAY_VERSION', '0.1.0' );
define( 'PRIMER_PAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRIMER_PAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// USDC on Base mainnet
define( 'PRIMER_PAY_DEFAULT_ASSET', '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913' );
define( 'PRIMER_PAY_DEFAULT_NETWORK', 'base' );
define( 'PRIMER_PAY_DEFAULT_FACILITATOR', 'https://x402.primer.systems' );
define( 'PRIMER_PAY_DEFAULT_PRICE', '0.01' );
define( 'PRIMER_PAY_USDC_DECIMALS', 6 );

// Token metadata for EIP-712 domain (USDC on Base)
define( 'PRIMER_PAY_TOKEN_NAME', 'USD Coin' );
define( 'PRIMER_PAY_TOKEN_VERSION', '2' );

// Access duration presets, in seconds. Key = stored value, label = UI text.
// 0           = no cookie, always charge on refresh
// 315360000   = 10 years ("never expires" — browsers will cap at ~400 days)
if ( ! function_exists( 'primer_pay_duration_options' ) ) {
    function primer_pay_duration_options() {
        return array(
            0         => 'Always charge (no session)',
            1800      => '30 minutes',
            3600      => '1 hour',
            86400     => '24 hours',
            604800    => '7 days',
            2592000   => '30 days',
            315360000 => 'Never expires (one-time payment)',
        );
    }
}
define( 'PRIMER_PAY_DEFAULT_ACCESS_DURATION', 3600 ); // 1 hour

/**
 * Get (or lazily generate) the HMAC secret used to sign access cookies.
 * Stored as a non-autoloaded option so it never ships in WP memory by default.
 * 64 hex chars = 256 bits of entropy.
 */
function primer_pay_get_secret() {
    $secret = get_option( 'primer_pay_hmac_secret', '' );
    if ( empty( $secret ) ) {
        $secret = bin2hex( random_bytes( 32 ) );
        update_option( 'primer_pay_hmac_secret', $secret, false );
    }
    return $secret;
}

require_once PRIMER_PAY_PLUGIN_DIR . 'includes/class-paywall.php';
require_once PRIMER_PAY_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Boot the plugin.
 */
function primer_pay_init() {
    $admin   = new Primer_Pay_Admin();
    $paywall = new Primer_Pay_Paywall();
}
add_action( 'plugins_loaded', 'primer_pay_init' );

/**
 * Set default options on activation.
 */
function primer_pay_activate() {
    add_option( 'primer_pay_wallet_address', '' );
    add_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE );
    add_option( 'primer_pay_network', PRIMER_PAY_DEFAULT_NETWORK );
    add_option( 'primer_pay_facilitator_url', PRIMER_PAY_DEFAULT_FACILITATOR );
    add_option( 'primer_pay_asset_address', PRIMER_PAY_DEFAULT_ASSET );
    add_option( 'primer_pay_access_duration', PRIMER_PAY_DEFAULT_ACCESS_DURATION );
    // Seed the HMAC secret so it exists immediately after activation.
    primer_pay_get_secret();
}
register_activation_hook( __FILE__, 'primer_pay_activate' );
