# Changelog

All notable changes to the Primer Pay WordPress plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] — 2026-04-10

Initial public release.

### Added
- **Per-post paywall toggle** with per-post price override, configured from a meta box on the post editor.
- **Global settings page** at Settings → Primer Pay: wallet address, default price (USDC), facilitator URL, default access duration.
- **`[x402]` shortcode** to split the free teaser from the paid content. Registered as a WordPress shortcode so the literal `[x402]` marker never appears in rendered output.
- **REST unlock endpoint** `GET /wp-json/primer-pay/v1/unlock/<post_id>` that issues the 402 response, validates X-PAYMENT headers, settles payments via the Primer facilitator, and returns the unlocked content as JSON.
- **HMAC-signed access cookies** so readers aren't charged again on refresh or re-navigation within the configured session duration. Cookies are HttpOnly, SameSite=Lax, and `Secure` on HTTPS.
- **Access duration presets**: 30 minutes / 1 hour (default) / 24 hours / 7 days / 30 days / never expires / always charge. Configurable globally and per-post.
- **Archive-safe teaser rendering**: paywalled posts in blog listings, category pages, search results, and feeds show only the teaser portion, never the paid content.
- **Excerpt filter**: prevents themes that use `get_the_excerpt()` from leaking paid content into archive previews.
- **Admin bypass**: logged-in users who can edit the post always see the full content, so authors can preview their own work.
- **Non-extension visitor fallback**: visitors without the Primer Pay browser extension see the teaser plus a "Get Primer Pay" call-to-action linking to the Chrome Web Store.
- **Declined-payment handling**: if the extension is installed but the payment is declined by the user's spend policy or balance, the banner shows a "Payment declined" message with a retry button instead of the install CTA.

### Requirements
- WordPress 5.8+
- PHP 7.4+
- A Base network wallet address
- Visitors need the Primer Pay browser extension to pay
