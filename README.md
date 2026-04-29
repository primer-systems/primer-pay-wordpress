# Primer Pay for WordPress

> Monetize WordPress content with x402 micropayments. Readers with the [Primer Pay browser extension](https://chromewebstore.google.com/detail/primer-pay/bckienhfmjoolgkafljofomegfafanmh) unlock posts instantly with a one-time USDC payment. Everyone else sees a teaser with an install prompt.

No accounts. No subscriptions. No payment forms. Just tick a box on any post, set a price, and publish.

[**Download latest release →**](https://github.com/primer-systems/primer-pay-wordpress/releases/latest)
&nbsp;·&nbsp;
[**Full documentation →**](https://docs.primer.systems/primer-pay/wordpress.html)
&nbsp;·&nbsp;
[**Get the browser extension →**](https://chromewebstore.google.com/detail/primer-pay/bckienhfmjoolgkafljofomegfafanmh)

---

## How it works

Primer Pay is an implementation of the [x402 protocol](https://x402.org) — the HTTP-native payment standard built on status code 402 "Payment Required". This plugin turns any WordPress post or page into a paywalled x402 resource:

1. A visitor loads a paywalled post. WordPress serves the teaser + a small unlock container.
2. JavaScript fires a fetch to the plugin's REST endpoint.
3. If the visitor has the [Primer Pay browser extension](https://chromewebstore.google.com/detail/primer-pay/bckienhfmjoolgkafljofomegfafanmh) installed, it intercepts the 402 response, signs a USDC payment, and retries transparently.
4. The plugin validates the signed payment via the Primer facilitator, sets an HMAC-signed access cookie, and returns the unlocked content.
5. Subsequent visits within the session duration are served from the cookie — no re-payment on refresh.

Visitors without the extension see a teaser and a "Get Primer Pay" CTA.

## What you get

- **One-click paywall toggle** per post or page
- **Per-post price override** (defaults to your site-wide price)
- **`[primer_pay_x402]` shortcode** to split the free teaser from the paid content
- **Multi-network support**: accept payments on [Base](https://base.org) (Ethereum L2) and/or [SKALE Base](https://skale.space) (zero-gas EVM) — with configurable priority
- **Configurable access duration**: 30 minutes to "never expires" — prevents double-charging on refresh
- **Works with any theme** that renders `the_content()` normally — no template modifications needed
- **Non-custodial**: payments go directly from the reader's wallet to yours. The plugin never touches funds.

## Requirements

- **WordPress 5.8+**
- **PHP 7.4+**
- **A wallet address** that can receive USDC payments on Base or SKALE Base (e.g. from MetaMask, Coinbase Wallet, or the Primer Pay extension itself — the same address works on all supported networks)
- **HTTPS recommended** (cookies set with the `Secure` flag on HTTPS; HTTP will still work for local dev)

## Installation

### Option 1: Upload via WordPress admin (recommended)

1. Download the latest release: [primer-pay.zip](https://github.com/primer-systems/primer-pay-wordpress/releases/latest/download/primer-pay.zip)
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
3. Select the zip file and click **Install Now**
4. Click **Activate Plugin**

### Option 2: Manual install

1. Download and extract the zip
2. Upload the `primer-pay/` folder to `/wp-content/plugins/` on your site
3. Activate the plugin through **Plugins → Installed Plugins**

## Setup

1. **Go to Settings → Primer Pay** in your WordPress admin.
2. **Enter your wallet address** — the address that should receive USDC payments on Base.
3. Leave **Default Price** at `0.01` (or set whatever you like), pick an **Access Duration**, and choose which **networks** to accept (Base, SKALE Base, or both). Save.
4. **Edit any post**, find the **Primer Pay** box in the sidebar, check **Enable x402 Paywall**, and publish.

### Marking where the teaser ends

Add the `[primer_pay_x402]` shortcode anywhere in your post content. Everything above it is the free teaser; everything below is the paid content:

```
This is the free preview everyone sees.

[primer_pay_x402]

This is the paid content only visitors who pay can read.
```

If you don't add `[primer_pay_x402]`, the plugin falls back to the post's excerpt as the teaser.

## For readers: how to pay

Install the free [Primer Pay browser extension](https://chromewebstore.google.com/detail/primer-pay/bckienhfmjoolgkafljofomegfafanmh), fund it with USDC on Base and browse normally. Paywalled content unlocks automatically — no logins, no forms, no checkout flow.

## Local development

A `docker-compose.yml` is not yet included but the plugin is tested against [Local by Flywheel](https://localwp.com/). Any WordPress environment with the requirements above should work.

Clone this repo, copy or symlink the `primer-pay/` folder into your site's `wp-content/plugins/` directory, then activate it from the admin. Edit and reload.

## Security

- **HMAC-signed cookies**: access cookies are HMAC-SHA256 signed with a site-specific secret stored in `wp_options`. Forgery requires database access.
- **HttpOnly cookies**: cookies cannot be read by JavaScript, preventing XSS exfiltration.
- **Constant-time comparison**: `hash_equals()` is used for cookie verification to prevent timing attacks.
- **No secrets shipped in the plugin**: the secret is generated fresh per install on activation.
- **Non-custodial**: the plugin never handles private keys. All signing happens in the reader's browser extension.

## Planned

- Gutenberg block for inline content gating with editor preview
- Earnings dashboard and payment history export
- WooCommerce integration for digital downloads
- Multi-network support
- SIWX (Sign-In With X) wallet identity for cross-post access
- REST API and media-file gating for headless WordPress and gated downloads

## Contributing

Issues and pull requests are welcome. This is early software; reports of real-world integration quirks, theme incompatibilities, and edge cases are especially appreciated.

For larger features or architectural changes, please open an issue first so we can discuss before you spend time on a PR.

## License

[GPL-2.0-or-later](LICENSE) — matching WordPress core. You're free to use, modify, and redistribute under the terms of the GPL.

## Related

- **[Primer Pay browser extension](https://chromewebstore.google.com/detail/primer-pay/bckienhfmjoolgkafljofomegfafanmh)** — the reader-side component, by the same team
- **[Primer Systems documentation](https://docs.primer.systems)** — full protocol and tooling docs
- **[x402 specification](https://x402.org)** — the underlying payment protocol
- **[Primer x402 facilitator](https://x402.primer.systems)** — settlement service used by this plugin (free, Base network)

---

Built by [Primer Systems](https://primer.systems). Questions: **dev@primer.systems**.
