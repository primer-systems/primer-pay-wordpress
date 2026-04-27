<?php
/**
 * Primer Pay — .well-known/x402 discovery endpoint.
 *
 * Serves a JSON index of all paywalled content on the site at
 * /.well-known/x402. AI agents, crawlers, and other x402-aware clients
 * can hit this URL to discover what content is available for purchase
 * without browsing the site.
 *
 * Uses parse_request to intercept the URL early, avoiding the need to
 * flush rewrite rules on activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Primer_Pay_Discovery {

    public function __construct() {
        add_action( 'parse_request', array( $this, 'handle_well_known' ) );
    }

    /**
     * Intercept requests to /.well-known/x402 and return the discovery JSON.
     */
    public function handle_well_known( $wp ) {
        $request_uri = isset( $_SERVER['REQUEST_URI'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '';

        // Strip query string for matching.
        $path = strtok( $request_uri, '?' );

        // Normalize: remove trailing slash, handle subdirectory installs.
        $home_path = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '';
        $relative  = substr( $path, strlen( $home_path ) );

        if ( '/.well-known/x402' !== $relative ) {
            return;
        }

        $this->serve_discovery_json();
    }

    /**
     * Build and output the discovery JSON, then exit.
     */
    private function serve_discovery_json() {
        $global_wallet = get_option( 'primer_pay_wallet_address', '' );
        $default_price = get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE );

        $all_networks = primer_pay_get_networks();
        $enabled      = get_option( 'primer_pay_enabled_networks', array( 'base' ) );
        $preferred    = get_option( 'primer_pay_preferred_network', 'base' );

        if ( ! is_array( $enabled ) || empty( $enabled ) ) {
            $enabled = array( 'base' );
        }

        // Build network info for the response.
        $networks = array();
        foreach ( $enabled as $key ) {
            if ( isset( $all_networks[ $key ] ) ) {
                $net = $all_networks[ $key ];
                $networks[] = array(
                    'network' => $key,
                    'name'    => $net['name'],
                    'chainId' => $net['chainId'],
                    'asset'   => $net['asset'],
                );
            }
        }

        // Query all paywalled posts.
        $query = new WP_Query( array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'meta_key'       => '_primer_pay_enabled',
            'meta_value'     => '1',
            'posts_per_page' => 200,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ) );

        // Also find posts that use the Content Gate block (without meta flag).
        $block_query = new WP_Query( array(
            'post_type'      => 'any',
            'post_status'    => 'publish',
            's'              => '<!-- wp:primer-pay/content-gate',
            'posts_per_page' => 200,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ) );

        $post_ids = array_unique( array_merge( $query->posts, $block_query->posts ) );

        $items = array();
        foreach ( $post_ids as $pid ) {
            $price = get_post_meta( $pid, '_primer_pay_price', true );
            if ( empty( $price ) ) {
                $price = $default_price;
            }

            $wallet = get_post_meta( $pid, '_primer_pay_wallet_address', true );
            if ( empty( $wallet ) ) {
                $wallet = $global_wallet;
            }

            $items[] = array(
                'url'         => get_permalink( $pid ),
                'title'       => get_the_title( $pid ),
                'price'       => $price,
                'currency'    => 'USDC',
                'payTo'       => $wallet,
                'x402Version' => 1,
            );
        }

        $response = array(
            'x402Version' => 1,
            'description' => get_bloginfo( 'name' ) . ' — paywalled content available via x402 micropayments.',
            'networks'    => $networks,
            'preferred'   => $preferred,
            'items'       => $items,
        );

        status_header( 200 );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: public, max-age=300' ); // 5-minute cache
        echo wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }
}
