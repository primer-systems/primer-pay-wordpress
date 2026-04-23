=== Primer Pay ===
Contributors: primersystems
Tags: paywall, micropayments, x402, usdc, monetization
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monetize your WordPress content with x402 micropayments. No accounts, no subscriptions — just instant pay-per-view with USDC.

== Description ==

Primer Pay lets you put any post or page behind a micropayment wall using the x402 protocol. Visitors with the Primer Pay browser extension pay seamlessly and see your content instantly. No user accounts, no subscriptions, no payment forms.

**How it works:**

1. You enter your wallet address in Settings > Primer Pay.
2. You check "Enable x402 Paywall" on any post and set a price (e.g., $0.01 USDC).
3. Visitors with the Primer Pay Chrome extension pay automatically and see your content.
4. Visitors without the extension see a teaser and a prompt to install Primer Pay.
5. Payments settle on Base (Ethereum L2) via USDC — low fees, instant finality.

**Features:**

* One-click paywall toggle per post/page
* Customizable price per post (or use a global default)
* Multi-network: accept payments on Base, SKALE Base, or both — with configurable priority
* `[x402]` shortcode to split free teaser from paid content
* Styled paywall banner for visitors without the extension
* No user accounts or login required
* Non-custodial — payments go directly to your wallet
* Works with the standard x402 protocol

**Requirements:**

* A wallet address on Base or SKALE Base (e.g., from MetaMask, Coinbase Wallet, or Primer Pay itself — same address works on all supported networks)
* HTTPS recommended (cookies are marked Secure on HTTPS; HTTP still works for local dev)

== Installation ==

1. Upload the `primer-pay` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to Settings > Primer Pay and enter your wallet address.
4. Edit any post, check "Enable x402 Paywall" in the Primer Pay sidebar box, and publish.

== Frequently Asked Questions ==

= What is x402? =

x402 is a protocol for web payments using HTTP status code 402 (Payment Required). When a server returns 402, the Primer Pay browser extension automatically handles the payment and retries the request.

= Do my visitors need crypto? =

Visitors need the Primer Pay browser extension with a small USDC balance. The extension manages a simple wallet — no MetaMask or crypto experience required.

= What are the fees? =

Primer does not charge fees. Base network gas fees are typically less than $0.001 per transaction.

= What happens if a visitor doesn't have the extension? =

They see a free teaser of your content plus a styled banner explaining the price and linking to the Primer Pay extension.

= Is this custodial? =

No. Payments go directly from the visitor's extension wallet to your wallet address. Primer never holds funds.

== External services ==

This plugin relies on the Primer x402 facilitator service to verify and settle payments. When a visitor attempts to pay for content, the plugin sends the signed payment authorization to the facilitator, which validates the signature and executes the on-chain USDC transfer on the Base network.

* Service: Primer x402 Facilitator
* Endpoint: https://x402.primer.systems/settle
* When: Whenever a visitor submits a valid X-PAYMENT header to the plugin's unlock endpoint
* Data sent: The base64-encoded signed payment authorization (EIP-712 typed data) from the visitor, plus the payment requirements (amount, asset address, recipient wallet address, network). No personally identifiable information about the visitor is sent — the only identifier is the wallet address they signed with.
* Terms of service: https://primer.systems
* Privacy policy: https://primer.systems

This is the standard x402 protocol flow. If you prefer to run your own facilitator, you can configure a custom facilitator URL in the plugin settings.

== Changelog ==

= 0.1.0 =
* Initial release
* Global settings: wallet address, default price, facilitator URL, access duration
* Per-post paywall toggle with price and access-duration overrides
* [x402] shortcode for teaser/content splitting (registered so the marker never appears in output)
* REST unlock endpoint at /wp-json/primer-pay/v1/unlock/&lt;post_id&gt;
* HMAC-signed session cookies so refreshing doesn't re-charge readers
* Archive-safe teaser rendering (no content leaks on blog index, categories, feeds, excerpts)
* Non-extension visitor fallback with install CTA
* Declined-payment handling with retry button
