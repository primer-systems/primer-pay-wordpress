<?php
/**
 * Admin UI: settings page and post meta box for paywall configuration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Primer_Pay_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_meta_box' ) );
    }

    // -------------------------------------------------------------------------
    // Settings Page
    // -------------------------------------------------------------------------

    public function add_settings_page() {
        add_options_page(
            'Primer Pay',
            'Primer Pay',
            'manage_options',
            'primer-pay',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        // Section
        add_settings_section(
            'primer_pay_main',
            'x402 Payment Settings',
            array( $this, 'render_section_intro' ),
            'primer-pay'
        );

        // Wallet address
        register_setting( 'primer-pay', 'primer_pay_wallet_address', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_wallet_address' ),
        ) );
        add_settings_field(
            'primer_pay_wallet_address',
            'Wallet Address',
            array( $this, 'render_wallet_field' ),
            'primer-pay',
            'primer_pay_main'
        );

        // Default price
        register_setting( 'primer-pay', 'primer_pay_default_price', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_price' ),
        ) );
        add_settings_field(
            'primer_pay_default_price',
            'Default Price (USDC)',
            array( $this, 'render_price_field' ),
            'primer-pay',
            'primer_pay_main'
        );

        // Facilitator URL
        register_setting( 'primer-pay', 'primer_pay_facilitator_url', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ) );
        add_settings_field(
            'primer_pay_facilitator_url',
            'Facilitator URL',
            array( $this, 'render_facilitator_field' ),
            'primer-pay',
            'primer_pay_main'
        );

        // Access duration after payment (session cookie lifetime)
        register_setting( 'primer-pay', 'primer_pay_access_duration', array(
            'type'              => 'integer',
            'sanitize_callback' => array( $this, 'sanitize_access_duration' ),
        ) );
        add_settings_field(
            'primer_pay_access_duration',
            'Access Duration',
            array( $this, 'render_access_duration_field' ),
            'primer-pay',
            'primer_pay_main'
        );

        // Asset address (advanced)
        register_setting( 'primer-pay', 'primer_pay_asset_address', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_wallet_address' ),
        ) );

        // Network (advanced)
        register_setting( 'primer-pay', 'primer_pay_network', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ) );
    }

    public function render_section_intro() {
        echo '<p>Configure your x402 payment settings. You need a wallet address that will receive USDC payments on Base.</p>';
    }

    public function render_wallet_field() {
        $value = get_option( 'primer_pay_wallet_address', '' );
        printf(
            '<input type="text" name="primer_pay_wallet_address" value="%s" class="regular-text" placeholder="0x..." style="font-family: monospace;" />',
            esc_attr( $value )
        );
        echo '<p class="description">Your Ethereum/Base wallet address. Payments will be sent here as USDC.</p>';

        if ( empty( $value ) ) {
            echo '<p class="description" style="color: #d63638; font-weight: 600;">&#9888; No wallet configured. Paywalls are inactive until you set this.</p>';
        }
    }

    public function render_price_field() {
        $value = get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE );
        printf(
            '<input type="text" name="primer_pay_default_price" value="%s" class="small-text" placeholder="0.01" /> USDC',
            esc_attr( $value )
        );
        echo '<p class="description">Default price per post. You can override this on individual posts.</p>';
    }

    public function render_facilitator_field() {
        $value = get_option( 'primer_pay_facilitator_url', PRIMER_PAY_DEFAULT_FACILITATOR );
        printf(
            '<input type="url" name="primer_pay_facilitator_url" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">The x402 facilitator that verifies and settles payments. The default Primer facilitator supports Base network.</p>';
    }

    public function render_access_duration_field() {
        $value   = (int) get_option( 'primer_pay_access_duration', PRIMER_PAY_DEFAULT_ACCESS_DURATION );
        $options = primer_pay_duration_options();
        echo '<select name="primer_pay_access_duration">';
        foreach ( $options as $seconds => $label ) {
            printf(
                '<option value="%d"%s>%s</option>',
                (int) $seconds,
                selected( $value, (int) $seconds, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">After a successful payment, readers get free access to the same post for this duration before being asked to pay again. Prevents double-charging on refresh or re-navigation. Can be overridden per post.</p>';
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Primer Pay Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'primer-pay' );
                do_settings_sections( 'primer-pay' );
                submit_button();
                ?>
            </form>

            <hr />
            <h2>Quick Start</h2>
            <ol>
                <li>Enter your wallet address above and save.</li>
                <li>Edit any post and check <strong>"Enable x402 Paywall"</strong> in the Primer Pay box.</li>
                <li>Optionally add <code>[x402]</code> in your post content to mark where the teaser ends and paid content begins.</li>
                <li>Visitors with the <a href="https://primer.systems" target="_blank">Primer Pay extension</a> will pay automatically. Others see a teaser with an install prompt.</li>
            </ol>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Sanitization
    // -------------------------------------------------------------------------

    public function sanitize_wallet_address( $value ) {
        $value = sanitize_text_field( $value );
        if ( ! empty( $value ) && ! preg_match( '/^0x[a-fA-F0-9]{40}$/', $value ) ) {
            add_settings_error(
                'primer_pay_wallet_address',
                'invalid_address',
                'Invalid wallet address. Must be a 0x-prefixed Ethereum address (42 characters).'
            );
            return get_option( 'primer_pay_wallet_address', '' );
        }
        return $value;
    }

    public function sanitize_price( $value ) {
        $value = sanitize_text_field( $value );
        if ( ! is_numeric( $value ) || (float) $value < 0 ) {
            add_settings_error(
                'primer_pay_default_price',
                'invalid_price',
                'Price must be a positive number.'
            );
            return get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE );
        }
        return $value;
    }

    /**
     * Validate access duration against the known preset set. Anything else
     * (missing field, arbitrary integer, negative) falls back to the default
     * so users can't accidentally set a bogus value by editing the form.
     */
    public function sanitize_access_duration( $value ) {
        $value   = (int) $value;
        $allowed = array_keys( primer_pay_duration_options() );
        if ( ! in_array( $value, $allowed, true ) ) {
            return PRIMER_PAY_DEFAULT_ACCESS_DURATION;
        }
        return $value;
    }

    // -------------------------------------------------------------------------
    // Post Meta Box
    // -------------------------------------------------------------------------

    public function add_meta_box() {
        add_meta_box(
            'primer_pay_meta_box',
            'Primer Pay',
            array( $this, 'render_meta_box' ),
            array( 'post', 'page' ),
            'side',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'primer_pay_meta', 'primer_pay_nonce' );

        $enabled          = get_post_meta( $post->ID, '_primer_pay_enabled', true );
        $price            = get_post_meta( $post->ID, '_primer_pay_price', true );
        $access_override  = get_post_meta( $post->ID, '_primer_pay_access_duration', true );
        $default          = get_option( 'primer_pay_default_price', PRIMER_PAY_DEFAULT_PRICE );
        $default_duration = (int) get_option( 'primer_pay_access_duration', PRIMER_PAY_DEFAULT_ACCESS_DURATION );
        $wallet           = get_option( 'primer_pay_wallet_address', '' );

        if ( empty( $wallet ) ) {
            echo '<p style="color: #d63638;">&#9888; Configure your wallet in <a href="' . esc_url( admin_url( 'options-general.php?page=primer-pay' ) ) . '">Settings &rarr; Primer Pay</a> first.</p>';
            return;
        }

        $duration_options = primer_pay_duration_options();
        $default_label    = isset( $duration_options[ $default_duration ] )
            ? $duration_options[ $default_duration ]
            : 'default';
        ?>
        <p>
            <label>
                <input type="checkbox"
                       name="primer_pay_enabled"
                       value="1"
                       <?php checked( $enabled ); ?> />
                Enable x402 Paywall
            </label>
        </p>
        <p>
            <label for="primer_pay_price">Price (USDC):</label><br />
            <input type="text"
                   id="primer_pay_price"
                   name="primer_pay_price"
                   value="<?php echo esc_attr( $price ); ?>"
                   placeholder="<?php echo esc_attr( $default ); ?> (default)"
                   style="width: 100%; font-family: monospace;" />
        </p>
        <p>
            <label for="primer_pay_access_duration">Access Duration:</label><br />
            <select id="primer_pay_access_duration"
                    name="primer_pay_access_duration"
                    style="width: 100%;">
                <option value="" <?php selected( '', $access_override ); ?>>
                    Use default (<?php echo esc_html( $default_label ); ?>)
                </option>
                <?php foreach ( $duration_options as $seconds => $label ) : ?>
                    <option value="<?php echo (int) $seconds; ?>"
                        <?php selected( (string) $access_override, (string) $seconds ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description">
            Leave price blank to use the default ($<?php echo esc_html( $default ); ?>).
            Add <code>[x402]</code> in your content to mark where the free teaser ends.
        </p>
        <?php
    }

    public function save_meta_box( $post_id ) {
        if ( ! isset( $_POST['primer_pay_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['primer_pay_nonce'] ) ), 'primer_pay_meta' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Enabled checkbox
        $enabled = ! empty( $_POST['primer_pay_enabled'] ) ? '1' : '';
        update_post_meta( $post_id, '_primer_pay_enabled', $enabled );

        // Price override
        $price = isset( $_POST['primer_pay_price'] ) ? sanitize_text_field( wp_unslash( $_POST['primer_pay_price'] ) ) : '';
        if ( ! empty( $price ) && ( ! is_numeric( $price ) || (float) $price < 0 ) ) {
            $price = '';
        }
        update_post_meta( $post_id, '_primer_pay_price', $price );

        // Access duration override. Empty string = "use default" (no override
        // stored). Any other value must match one of the known duration presets.
        $duration_override = isset( $_POST['primer_pay_access_duration'] )
            ? sanitize_text_field( wp_unslash( $_POST['primer_pay_access_duration'] ) )
            : '';
        if ( '' === $duration_override ) {
            update_post_meta( $post_id, '_primer_pay_access_duration', '' );
        } else {
            $duration_int = (int) $duration_override;
            $allowed      = array_keys( primer_pay_duration_options() );
            if ( in_array( $duration_int, $allowed, true ) ) {
                update_post_meta( $post_id, '_primer_pay_access_duration', $duration_int );
            } else {
                // Invalid override — reset to "use default"
                update_post_meta( $post_id, '_primer_pay_access_duration', '' );
            }
        }
    }
}
