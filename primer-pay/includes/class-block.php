<?php
/**
 * Primer Pay — Gutenberg block registration.
 *
 * Registers the "Primer Pay Content Gate" block, which serves as a visual
 * separator in the block editor between free teaser content and paid content.
 *
 * On the front-end the block renders as the [primer_pay_x402] shortcode marker,
 * so the existing paywall logic in class-paywall.php handles it seamlessly.
 *
 * All paywall settings (enabled, price, duration, wallet) are stored as block
 * attributes. The meta box is only shown for classic editor users.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Primer_Pay_Block {

    public function __construct() {
        add_action( 'init', array( $this, 'register_block' ) );
    }

    /**
     * Register the Content Gate block.
     */
    public function register_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return; // Gutenberg not available
        }

        wp_register_script(
            'primer-pay-block-editor',
            PRIMER_PAY_PLUGIN_URL . 'assets/block-editor.js',
            array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
            PRIMER_PAY_VERSION,
            true
        );

        wp_register_style(
            'primer-pay-block-editor',
            PRIMER_PAY_PLUGIN_URL . 'assets/block-editor.css',
            array(),
            PRIMER_PAY_VERSION
        );

        wp_set_script_translations( 'primer-pay-block-editor', 'primer-pay' );

        // Pass defaults to the editor script.
        wp_localize_script( 'primer-pay-block-editor', 'primerPayBlock', array(
            'defaultPrice'  => get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE ),
            'defaultWallet' => get_option( 'primer_pay_wallet_address', '' ),
            'durations'     => primer_pay_duration_options(),
        ) );

        register_block_type( 'primer-pay/content-gate', array(
            'editor_script'   => 'primer-pay-block-editor',
            'editor_style'    => 'primer-pay-block-editor',
            'render_callback' => array( $this, 'render_block' ),
            'attributes'      => array(
                'enabled' => array(
                    'type'    => 'boolean',
                    'default' => true,
                ),
                'price' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'accessDuration' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'walletAddress' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
        ) );
    }

    /**
     * Front-end render callback.
     *
     * Outputs the [primer_pay_x402] marker when enabled. If disabled, outputs
     * nothing (content flows through without a paywall). Block attributes for
     * price, duration, and wallet are applied via filters so class-paywall.php
     * picks them up.
     */
    public function render_block( $attributes, $content ) {
        // If the block's enable toggle is off, render nothing — the post
        // won't be treated as paywalled (is_paywalled checks for the block
        // but we return empty so [primer_pay_x402] never appears in content).
        if ( empty( $attributes['enabled'] ) ) {
            return '';
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return '[primer_pay_x402]';
        }

        // Apply block-level overrides via filters.
        if ( ! empty( $attributes['price'] ) && is_numeric( $attributes['price'] ) ) {
            $this->block_price = $attributes['price'];
            add_filter( 'primer_pay_post_price', array( $this, 'filter_price' ), 10, 2 );
        }

        if ( ! empty( $attributes['accessDuration'] ) && is_numeric( $attributes['accessDuration'] ) ) {
            $this->block_duration = $attributes['accessDuration'];
            add_filter( 'primer_pay_post_duration', array( $this, 'filter_duration' ), 10, 2 );
        }

        if ( ! empty( $attributes['walletAddress'] ) && preg_match( '/^0x[a-fA-F0-9]{40}$/', $attributes['walletAddress'] ) ) {
            $this->block_wallet = $attributes['walletAddress'];
            add_filter( 'primer_pay_post_wallet', array( $this, 'filter_wallet' ), 10, 2 );
        }

        return '[primer_pay_x402]';
    }

    /**
     * Filter the per-post price when the block specifies one.
     */
    public function filter_price( $price, $post_id ) {
        if ( isset( $this->block_price ) ) {
            $price = $this->block_price;
            remove_filter( 'primer_pay_post_price', array( $this, 'filter_price' ), 10 );
        }
        return $price;
    }

    /**
     * Filter the per-post duration when the block specifies one.
     */
    public function filter_duration( $duration, $post_id ) {
        if ( isset( $this->block_duration ) ) {
            $duration = $this->block_duration;
            remove_filter( 'primer_pay_post_duration', array( $this, 'filter_duration' ), 10 );
        }
        return $duration;
    }

    /**
     * Filter the per-post wallet when the block specifies one.
     */
    public function filter_wallet( $wallet, $post_id ) {
        if ( isset( $this->block_wallet ) ) {
            $wallet = $this->block_wallet;
            remove_filter( 'primer_pay_post_wallet', array( $this, 'filter_wallet' ), 10 );
        }
        return $wallet;
    }
}
