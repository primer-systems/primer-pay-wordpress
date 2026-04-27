<?php
/**
 * Primer Pay — Gutenberg block registration.
 *
 * Registers the "Primer Pay Content Gate" block, which serves as a visual
 * separator in the block editor between free teaser content and paid content.
 *
 * On the front-end the block renders as the [x402] shortcode marker, so the
 * existing paywall logic in class-paywall.php handles it seamlessly.
 *
 * Block attributes (price, accessDuration) are stored in the block's HTML
 * comment and applied as post-meta overrides when the block is present.
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
            array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ),
            PRIMER_PAY_VERSION,
            true
        );

        wp_register_style(
            'primer-pay-block-editor',
            PRIMER_PAY_PLUGIN_URL . 'assets/block-editor.css',
            array(),
            PRIMER_PAY_VERSION
        );

        // Pass duration presets and default price to the editor script.
        wp_localize_script( 'primer-pay-block-editor', 'primerPayBlock', array(
            'defaultPrice' => get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE ),
            'durations'    => primer_pay_duration_options(),
        ) );

        register_block_type( 'primer-pay/content-gate', array(
            'editor_script'   => 'primer-pay-block-editor',
            'editor_style'    => 'primer-pay-block-editor',
            'render_callback' => array( $this, 'render_block' ),
            'attributes'      => array(
                'price' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
                'accessDuration' => array(
                    'type'    => 'string',
                    'default' => '',
                ),
            ),
        ) );
    }

    /**
     * Front-end render callback.
     *
     * Outputs the [x402] marker. If the block carries a price or duration
     * override, those are applied to the post meta the first time the block
     * is rendered (so class-paywall.php picks them up).
     */
    public function render_block( $attributes, $content ) {
        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return '[x402]';
        }

        // Apply block-level price override to post meta if set.
        // This lets the Gutenberg block act as the single source of truth
        // for per-post pricing when used instead of the meta box.
        if ( ! empty( $attributes['price'] ) && is_numeric( $attributes['price'] ) ) {
            // Only update during the render pass — don't persist to DB here.
            // The paywall class reads the value, which we inject via filter.
            $this->block_price = $attributes['price'];
            add_filter( 'primer_pay_post_price', array( $this, 'filter_price' ), 10, 2 );
        }

        if ( ! empty( $attributes['accessDuration'] ) && is_numeric( $attributes['accessDuration'] ) ) {
            $this->block_duration = $attributes['accessDuration'];
            add_filter( 'primer_pay_post_duration', array( $this, 'filter_duration' ), 10, 2 );
        }

        return '[x402]';
    }

    /**
     * Filter the per-post price when the block specifies one.
     */
    public function filter_price( $price, $post_id ) {
        if ( isset( $this->block_price ) ) {
            $price = $this->block_price;
            // Remove filter after first use to avoid bleeding into other posts.
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
}
