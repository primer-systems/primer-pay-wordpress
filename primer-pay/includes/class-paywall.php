<?php
/**
 * Core paywall logic.
 *
 * Architecture:
 *  - template_redirect: serves paywalled posts as normal 200 OK pages with
 *    a teaser + unlock container. No top-level 402.
 *  - REST endpoint /primer-pay/v1/unlock/<post_id>: issues the actual 402,
 *    settles payments, sets the access cookie. Called from unlock.js via
 *    in-page fetch — extension intercepts and pays transparently.
 *  - Valid cookie fast-path: subsequent visits skip the whole unlock dance.
 *
 * Why AJAX and not top-level 402 navigation? Chrome's content-script CSP
 * blocks inline scripts in document.write()'d content, and extension
 * background fetches use credentials:'omit' which discards Set-Cookie
 * headers. The AJAX path lives in the regular page context so both of
 * those constraints go away.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Primer_Pay_Paywall {

    public function __construct() {
        add_action( 'template_redirect', array( $this, 'maybe_intercept' ) );
        add_filter( 'the_content', array( $this, 'filter_content' ) );
        // Also filter excerpts, so themes that use get_the_excerpt() on
        // archive pages don't leak the paid portion of the content.
        add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 10, 2 );
        // Register [x402] as a proper shortcode that renders to nothing.
        // We use it internally (in get_teaser) as a split marker, but as a
        // shortcode it ensures the literal "[x402]" text never appears in
        // rendered output.
        add_shortcode( 'x402', '__return_empty_string' );
        // REST endpoint for the AJAX unlock flow. Called from unlock.js via
        // in-page fetch, which preserves credentials so Set-Cookie works.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        // Enqueue the unlock script on paywalled post pages only.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_unlock_script' ) );
    }

    /**
     * On each page load, decide whether to serve paid content directly
     * (valid cookie from a previous payment) or let WordPress render the
     * normal teaser + unlock container.
     *
     * This no longer issues a 402 — that happens only in the REST endpoint.
     * Page loads of paywalled posts always return 200 OK with the theme
     * fully rendered. The unlock dance happens via fetch after the page
     * has loaded.
     */
    public function maybe_intercept() {
        if ( ! is_singular() ) {
            return;
        }

        $post_id = get_queried_object_id();
        if ( ! $post_id || ! $this->is_paywalled( $post_id ) ) {
            return;
        }

        // Admins see everything, always
        if ( current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $wallet = get_option( 'primer_pay_wallet_address', '' );
        if ( empty( $wallet ) ) {
            // Plugin not configured — serve content normally
            return;
        }

        // Fast path: valid access cookie from a previous payment. Unlocks
        // the full content for this request without a fetch round-trip.
        // The cookie is set by the REST endpoint after successful settlement.
        if ( $this->has_valid_access_cookie( $post_id ) ) {
            $GLOBALS['primer_pay_settled'] = true;
            return;
        }

        // Otherwise fall through — WordPress renders the page normally
        // with filter_content serving teaser + unlock container. unlock.js
        // takes over from there.
    }

    /**
     * Filter post content to show teaser or full content.
     */
    public function filter_content( $content ) {
        $post_id = get_the_ID();
        if ( ! $post_id || ! $this->is_paywalled( $post_id ) ) {
            return $content;
        }

        // Admins see everything
        if ( current_user_can( 'edit_post', $post_id ) ) {
            return $content;
        }

        // If payment was settled this request, show full content
        if ( ! empty( $GLOBALS['primer_pay_settled'] ) ) {
            return $content;
        }

        $teaser = $this->get_teaser( $content );

        // On archive / home / feed pages, just show the teaser. Don't show
        // the paywall banner there — it would spam the banner onto every
        // paywalled post in the listing. The banner only appears on the
        // single-post view, where the visitor is actually being asked to pay.
        if ( ! is_singular() ) {
            return $teaser;
        }

        // Single-post view: teaser + paywall banner (container for unlock.js)
        $price  = $this->get_price( $post_id );
        $banner = $this->get_paywall_banner( $price, $post_id );

        return $teaser . $banner;
    }

    /**
     * Filter the excerpt for paywalled posts on archive / search / feed views.
     * Ensures themes using get_the_excerpt() don't leak the paid portion of the
     * content by falling back to WordPress's default excerpt generator, which
     * runs on the full (unfiltered) content.
     *
     * @param string  $excerpt The generated or manual excerpt.
     * @param WP_Post $post    The post object (may be omitted by callers).
     */
    public function filter_excerpt( $excerpt, $post = null ) {
        $post_id = $post && isset( $post->ID ) ? $post->ID : get_the_ID();
        if ( ! $post_id || ! $this->is_paywalled( $post_id ) ) {
            return $excerpt;
        }

        // Admins see the full excerpt
        if ( current_user_can( 'edit_post', $post_id ) ) {
            return $excerpt;
        }

        // Return only the teaser portion, stripped of HTML for excerpt use.
        $raw_content = $post ? $post->post_content : get_post_field( 'post_content', $post_id );
        $teaser_html = $this->get_teaser( $raw_content );
        return wp_strip_all_tags( $teaser_html );
    }

    /**
     * Check if a post has the paywall enabled.
     */
    private function is_paywalled( $post_id ) {
        return (bool) get_post_meta( $post_id, '_primer_pay_enabled', true );
    }

    /**
     * Get the price for a post (per-post override or global default).
     */
    private function get_price( $post_id ) {
        $price = get_post_meta( $post_id, '_primer_pay_price', true );
        if ( empty( $price ) ) {
            $price = get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE );
        }
        return $price;
    }

    /**
     * Get the access duration (in seconds) for a post.
     * Per-post override > global default. Empty per-post override means "use default".
     *
     * @return int Duration in seconds. 0 means "always charge, no cookie".
     */
    private function get_access_duration( $post_id ) {
        $override = get_post_meta( $post_id, '_primer_pay_access_duration', true );
        // Empty string / null = "use default". Zero is a valid override.
        if ( '' === $override || null === $override ) {
            $duration = get_option( 'primer_pay_access_duration', PRIMER_PAY_DEFAULT_ACCESS_DURATION );
        } else {
            $duration = $override;
        }
        $duration = (int) $duration;
        // Validate against the known preset set — reject anything weird.
        $allowed = array_keys( primer_pay_duration_options() );
        if ( ! in_array( $duration, $allowed, true ) ) {
            $duration = PRIMER_PAY_DEFAULT_ACCESS_DURATION;
        }
        return $duration;
    }

    /**
     * Cookie name for a given post. One cookie per post so mixed durations
     * (e.g. short session for news post, long session for premium post) work
     * independently and browsers handle expiry automatically.
     */
    private function access_cookie_name( $post_id ) {
        return 'primer_pay_access_' . (int) $post_id;
    }

    /**
     * Check whether the incoming request carries a valid access cookie for this post.
     * Format: "{expiry}.{hmac}" where hmac = hmac_sha256(secret, "{post_id}.{expiry}").
     * Returns true only if signature matches AND expiry is in the future.
     */
    private function has_valid_access_cookie( $post_id ) {
        $name = $this->access_cookie_name( $post_id );
        if ( empty( $_COOKIE[ $name ] ) ) {
            return false;
        }
        $raw = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
        $parts = explode( '.', $raw );
        if ( count( $parts ) !== 2 ) {
            return false;
        }
        list( $expiry, $provided_hmac ) = $parts;
        if ( ! ctype_digit( $expiry ) ) {
            return false;
        }
        $expiry = (int) $expiry;
        if ( $expiry <= time() ) {
            return false;
        }
        $secret = primer_pay_get_secret();
        $expected_hmac = hash_hmac( 'sha256', $post_id . '.' . $expiry, $secret );
        // hash_equals is constant-time to prevent timing attacks.
        return hash_equals( $expected_hmac, $provided_hmac );
    }

    /**
     * Issue a signed access cookie for this post, valid for the configured
     * duration. No-op if duration is 0 ("always charge" mode).
     *
     * Must be called BEFORE any output is sent to the client. In the REST
     * endpoint path, this means calling it before returning the WP_REST_Response.
     *
     * The AJAX unlock flow (in-page fetch with credentials preserved) means
     * Set-Cookie headers make it to the browser's cookie jar normally.
     */
    private function set_access_cookie( $post_id ) {
        $duration = $this->get_access_duration( $post_id );
        if ( $duration <= 0 ) {
            return; // "always charge" — no cookie
        }
        $expiry = time() + $duration;
        $secret = primer_pay_get_secret();
        $hmac   = hash_hmac( 'sha256', $post_id . '.' . $expiry, $secret );
        $value  = $expiry . '.' . $hmac;

        setcookie(
            $this->access_cookie_name( $post_id ),
            $value,
            array(
                'expires'  => $expiry,
                'path'     => '/',
                'domain'   => '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }

    // ============================================================
    // REST endpoint — the actual 402/settle flow
    // ============================================================

    /**
     * Register the /primer-pay/v1/unlock/<post_id> route.
     * Called from the rest_api_init action.
     */
    public function register_rest_routes() {
        register_rest_route(
            'primer-pay/v1',
            '/unlock/(?P<post_id>\d+)',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_unlock_request' ),
                'permission_callback' => '__return_true', // public
                'args'                => array(
                    'post_id' => array(
                        'validate_callback' => function ( $value ) {
                            return is_numeric( $value ) && (int) $value > 0;
                        },
                    ),
                ),
            )
        );
    }

    /**
     * Handle an unlock request. This is where the real x402 dance happens:
     *
     *  1. No X-PAYMENT header → return 402 with payment-required
     *  2. X-PAYMENT header present → settle via facilitator
     *  3. On success → set access cookie + return post content as JSON
     *  4. On failure → return 402 with error
     *
     * Called via in-page fetch from unlock.js. The extension's fetch
     * interceptor sees the 402, signs, and retries with credentials
     * preserved — so Set-Cookie works on the response.
     */
    public function handle_unlock_request( WP_REST_Request $request ) {
        $post_id = (int) $request->get_param( 'post_id' );
        $post    = get_post( $post_id );

        if ( ! $post || 'publish' !== $post->post_status ) {
            return new WP_Error(
                'primer_pay_not_found',
                'Post not found.',
                array( 'status' => 404 )
            );
        }

        if ( ! $this->is_paywalled( $post_id ) ) {
            return new WP_Error(
                'primer_pay_not_paywalled',
                'This post is not paywalled.',
                array( 'status' => 400 )
            );
        }

        $wallet = get_option( 'primer_pay_wallet_address', '' );
        if ( empty( $wallet ) ) {
            return new WP_Error(
                'primer_pay_not_configured',
                'Paywall is not configured.',
                array( 'status' => 503 )
            );
        }

        // If the caller already has a valid cookie, just return the content.
        // Happens if unlock.js runs before we realised the page-load fast
        // path already unlocked things.
        if ( $this->has_valid_access_cookie( $post_id ) ) {
            return $this->build_success_response( $post, null );
        }

        $payment_header = $request->get_header( 'x-payment' );

        if ( ! $payment_header ) {
            return $this->build_402_response( $post_id );
        }

        // Settle the payment
        $result = $this->settle_payment( $payment_header, $post_id );

        if ( ! $result['success'] ) {
            return $this->build_402_response( $post_id, $result['error'] );
        }

        // Success — plant the cookie and return content
        $this->set_access_cookie( $post_id );

        return $this->build_success_response( $post, $result['data'] );
    }

    /**
     * Build the 402 WP_REST_Response with the payment-required header.
     * Reused for both "no payment" and "settlement failed" paths.
     *
     * The `reason` field in the body tells unlock.js whether to show the
     * "install Primer Pay" CTA (no_payment_header) or a settlement error
     * with a retry button (settlement_failed). From the client's view,
     * no_payment_header typically means the extension isn't installed.
     */
    private function build_402_response( $post_id, $error_message = null ) {
        $requirements = $this->build_payment_requirements( $post_id );
        if ( $error_message ) {
            $requirements['error'] = $error_message;
        }
        $encoded = base64_encode( wp_json_encode( $requirements ) );

        $reason = $error_message ? 'settlement_failed' : 'no_payment_header';

        $response = new WP_REST_Response(
            array(
                'error'   => 'payment_required',
                'reason'  => $reason,
                'message' => $error_message ?: 'Payment required to access this content.',
            ),
            402
        );
        $response->header( 'payment-required', $encoded );
        // Expose the custom header so JS can read it cross-origin if needed.
        $response->header( 'Access-Control-Expose-Headers', 'payment-required, X-PAYMENT-RESPONSE' );
        return $response;
    }

    /**
     * Build the success response containing the unlocked post content.
     *
     * Content is run through the `the_content` filter so shortcodes, blocks,
     * and embeds are all rendered — matching what a normal page view would
     * look like.
     *
     * We temporarily swap the global $post so filters that rely on
     * get_the_ID() / get_post() inside the filter chain (including our own
     * filter_content) see the correct post context. Otherwise the REST
     * request has no post global set and filters may misbehave.
     */
    private function build_success_response( $post_to_render, $settlement_data ) {
        global $post;
        $saved_post = $post;

        // Swap in the post we're rendering
        $post = $post_to_render; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        setup_postdata( $post_to_render );

        // Signal to filter_content that this request is unlocked so it
        // returns the full content instead of the teaser.
        $GLOBALS['primer_pay_settled'] = true;

        // Return only the paid portion (content after [x402]). The visitor
        // is already looking at the teaser on their current page — we just
        // need to swap the banner for the rest of the article, not
        // re-render the whole thing.
        $paid_raw = $this->get_paid_portion( $post_to_render->post_content );
        $content  = apply_filters( 'the_content', $paid_raw );

        unset( $GLOBALS['primer_pay_settled'] );

        // Restore the original post context
        $post = $saved_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        if ( $saved_post instanceof WP_Post ) {
            setup_postdata( $saved_post );
        }

        $response_data = array(
            'success' => true,
            'content' => $content,
            'title'   => get_the_title( $post_to_render->ID ),
        );

        if ( $settlement_data ) {
            $response_data['txHash'] = isset( $settlement_data['transaction'] )
                ? $settlement_data['transaction']
                : ( isset( $settlement_data['transactionHash'] ) ? $settlement_data['transactionHash'] : null );
        }

        $response = new WP_REST_Response( $response_data, 200 );

        if ( $settlement_data ) {
            $response->header(
                'X-PAYMENT-RESPONSE',
                base64_encode( wp_json_encode( $settlement_data ) )
            );
            $response->header( 'Access-Control-Expose-Headers', 'X-PAYMENT-RESPONSE' );
        }

        return $response;
    }

    /**
     * Build the x402 payment requirements object for a post. Shared between
     * the 402 response path (above) and any other future caller.
     */
    private function build_payment_requirements( $post_id ) {
        $wallet  = get_option( 'primer_pay_wallet_address', '' );
        $price   = $this->get_price( $post_id );
        $asset   = get_option( 'primer_pay_asset_address', PRIMER_PAY_DEFAULT_ASSET );
        $network = get_option( 'primer_pay_network', PRIMER_PAY_DEFAULT_NETWORK );
        if ( empty( $asset ) )   { $asset   = PRIMER_PAY_DEFAULT_ASSET; }
        if ( empty( $network ) ) { $network = PRIMER_PAY_DEFAULT_NETWORK; }

        $permalink = get_permalink( $post_id );
        $path      = wp_parse_url( $permalink, PHP_URL_PATH ) ?: '/';
        $title     = get_the_title( $post_id );

        return array(
            'x402Version' => 1,
            'accepts'     => array(
                array(
                    'scheme'            => 'exact',
                    'network'           => $network,
                    'maxAmountRequired' => $this->to_atomic_units( $price ),
                    'resource'          => $path,
                    'description'       => 'Access to: ' . $title,
                    'mimeType'          => 'text/html',
                    'payTo'             => $wallet,
                    'maxTimeoutSeconds' => 3600,
                    'asset'             => $asset,
                    'extra'             => array(
                        'name'    => PRIMER_PAY_TOKEN_NAME,
                        'version' => PRIMER_PAY_TOKEN_VERSION,
                    ),
                ),
            ),
        );
    }

    // ============================================================
    // Script enqueueing
    // ============================================================

    /**
     * Enqueue unlock.js on paywalled post pages only. Skip if the current
     * user can edit the post (admins see full content via filter_content)
     * or if the cookie fast-path already unlocked this request.
     */
    public function enqueue_unlock_script() {
        if ( ! is_singular() ) {
            return;
        }
        $post_id = get_queried_object_id();
        if ( ! $post_id || ! $this->is_paywalled( $post_id ) ) {
            return;
        }
        if ( current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( ! empty( $GLOBALS['primer_pay_settled'] ) ) {
            // Already unlocked via cookie — no JS needed
            return;
        }
        $wallet = get_option( 'primer_pay_wallet_address', '' );
        if ( empty( $wallet ) ) {
            return;
        }

        wp_enqueue_script(
            'primer-pay-unlock',
            PRIMER_PAY_PLUGIN_URL . 'assets/unlock.js',
            array(),
            PRIMER_PAY_VERSION,
            true // load in footer
        );
    }

    /**
     * Settle a payment via the facilitator.
     */
    private function settle_payment( $payment_header, $post_id ) {
        // Decode and validate the payment header
        $decoded = base64_decode( $payment_header, true );
        if ( false === $decoded ) {
            return array( 'success' => false, 'error' => 'Invalid base64' );
        }

        $payment = json_decode( $decoded, true );
        if ( ! $payment || empty( $payment['x402Version'] ) || empty( $payment['payload'] ) ) {
            return array( 'success' => false, 'error' => 'Invalid payment structure' );
        }

        $wallet  = get_option( 'primer_pay_wallet_address', '' );
        $price   = $this->get_price( $post_id );
        $asset   = get_option( 'primer_pay_asset_address', PRIMER_PAY_DEFAULT_ASSET );
        $network = get_option( 'primer_pay_network', PRIMER_PAY_DEFAULT_NETWORK );
        if ( empty( $asset ) )   { $asset   = PRIMER_PAY_DEFAULT_ASSET; }
        if ( empty( $network ) ) { $network = PRIMER_PAY_DEFAULT_NETWORK; }

        $atomic_amount = $this->to_atomic_units( $price );

        $facilitator_url = get_option( 'primer_pay_facilitator_url', PRIMER_PAY_DEFAULT_FACILITATOR );

        $payload = array(
            'x402Version'         => 1,
            'paymentPayload'      => $payment,
            'paymentRequirements' => array(
                'scheme'            => 'exact',
                'network'           => ! empty( $payment['network'] ) ? $payment['network'] : $network,
                'maxAmountRequired' => $atomic_amount,
                'asset'             => $asset,
                'payTo'             => $wallet,
            ),
        );

        $response = wp_remote_post(
            $facilitator_url . '/settle',
            array(
                'body'    => wp_json_encode( $payload ),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'success' => false, 'error' => $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 === $status ) {
            return array( 'success' => true, 'data' => $body );
        }

        $error = ! empty( $body['error'] ) ? $body['error'] : 'Settlement failed (HTTP ' . $status . ')';
        return array( 'success' => false, 'error' => $error );
    }

    /**
     * Convert a human-readable USDC amount to atomic units (6 decimals).
     *
     * We avoid floating-point math by splitting on the decimal point and
     * padding/truncating to exactly PRIMER_PAY_USDC_DECIMALS digits.
     */
    private function to_atomic_units( $amount ) {
        $decimals = PRIMER_PAY_USDC_DECIMALS;
        $parts    = explode( '.', (string) $amount );
        $whole    = $parts[0];
        $frac     = isset( $parts[1] ) ? $parts[1] : '';

        // Pad or truncate fractional part to exactly $decimals digits
        $frac = str_pad( substr( $frac, 0, $decimals ), $decimals, '0' );

        // Remove leading zeros from the combined string, but keep at least '0'
        $result = ltrim( $whole . $frac, '0' );
        return '' === $result ? '0' : $result;
    }

    /**
     * Extract the teaser portion of post content.
     * If [x402] shortcode exists, everything above it is the teaser.
     * Otherwise, use the excerpt or first paragraph.
     */
    private function get_teaser( $content ) {
        // Check for [x402] shortcode marker
        $marker_pos = strpos( $content, '[x402]' );
        if ( false !== $marker_pos ) {
            return wp_kses_post( substr( $content, 0, $marker_pos ) );
        }

        // Fallback: first paragraph or excerpt
        $excerpt = get_the_excerpt();
        if ( $excerpt ) {
            return '<p>' . esc_html( $excerpt ) . '</p>';
        }

        // Last resort: first 200 characters
        $stripped = wp_strip_all_tags( $content );
        return '<p>' . esc_html( mb_substr( $stripped, 0, 200 ) ) . '&hellip;</p>';
    }

    /**
     * Extract the paid portion of post content (everything after [x402]).
     * If no marker exists, the entire content is considered paid.
     *
     * Used by the REST unlock endpoint: the visitor is already looking at
     * the teaser (rendered server-side during page load), so returning the
     * full content would duplicate the teaser. We only need to ship the
     * paid portion so the JS can replace the banner placeholder.
     */
    private function get_paid_portion( $content ) {
        $marker_pos = strpos( $content, '[x402]' );
        if ( false !== $marker_pos ) {
            // Skip past the marker itself (6 chars: "[x402]")
            return substr( $content, $marker_pos + 6 );
        }
        // No marker = everything is paid. Return it all.
        return $content;
    }

    /**
     * Render the paywall banner / unlock container.
     *
     * This is the element unlock.js targets. It carries all the data needed
     * for the unlock flow (post ID, price, REST endpoint URL, Chrome Web
     * Store link) as data attributes, and contains a default "fallback"
     * state that's visible if JS is disabled or the extension isn't
     * installed.
     *
     * The structure is intentionally framework-free (no classes that
     * conflict with theme styles, all inline styles) so it drops into any
     * WordPress theme without surprise.
     */
    private function get_paywall_banner( $price, $post_id ) {
        $chrome_url = 'https://chromewebstore.google.com/detail/primer-pay/bckienhfmjoolgkafljofomegfafanmh';
        $unlock_url = rest_url( 'primer-pay/v1/unlock/' . (int) $post_id );

        ob_start();
        ?>
        <div id="primer-pay-container"
             class="primer-pay-container primer-pay-state-initial"
             data-post-id="<?php echo (int) $post_id; ?>"
             data-price="<?php echo esc_attr( $price ); ?>"
             data-unlock-url="<?php echo esc_url( $unlock_url ); ?>"
             data-chrome-url="<?php echo esc_url( $chrome_url ); ?>"
             style="
                margin: 32px 0;
                padding: 32px;
                border: 2px solid #baea2a;
                background: #09090b;
                color: #fafafa;
                font-family: 'JetBrains Mono', ui-monospace, 'Fira Code', monospace;
                text-align: center;
             ">
            <div class="primer-pay-label" style="font-size: 14px; color: #baea2a; font-weight: 600; margin-bottom: 8px; letter-spacing: 0.05em;">
                PRIMER PAY
            </div>
            <div class="primer-pay-price" style="font-size: 24px; font-weight: 600; color: #baea2a; margin-bottom: 8px;">
                $<?php echo esc_html( $price ); ?> USDC
            </div>
            <div class="primer-pay-message" style="font-size: 14px; color: rgba(250,250,250,0.7); margin-bottom: 24px; line-height: 1.5;">
                This content is available instantly with the Primer Pay browser extension.
                <br>No account needed &mdash; just a one-time micropayment.
            </div>
            <div class="primer-pay-actions">
                <a class="primer-pay-cta"
                   href="<?php echo esc_url( $chrome_url ); ?>"
                   target="_blank"
                   rel="noopener"
                   style="
                        display: inline-block;
                        padding: 12px 32px;
                        background: rgba(186, 234, 42, 0.1);
                        border: 1px solid #baea2a;
                        color: #baea2a;
                        text-decoration: none;
                        font-family: inherit;
                        font-size: 13px;
                        font-weight: 600;
                        letter-spacing: 0.05em;
                   ">
                    GET PRIMER PAY
                </a>
            </div>
            <div class="primer-pay-footer" style="margin-top: 16px; font-size: 11px; color: rgba(250,250,250,0.3);">
                Powered by <a href="https://primer.systems" style="color: rgba(186,234,42,0.5); text-decoration: none;">x402</a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
