/**
 * Primer Pay — unlock.js
 *
 * Runs on paywalled post pages. Auto-fires a fetch to the plugin's REST
 * unlock endpoint; if the Primer Pay browser extension is installed, it
 * intercepts the 402 response, signs a payment, and retries transparently.
 *
 * The key architectural choice: this is an in-page fetch, so the extension
 * uses its content/main.ts fetch interceptor (not the background webRequest
 * navigation handler). The in-page retry preserves credentials, so Set-Cookie
 * headers actually reach the browser's cookie jar. That's what makes the
 * session work across page refreshes.
 *
 * Without the extension: the fetch returns 402, we show the install CTA.
 * With the extension but payment rejected (policy, balance, etc.): we show
 * an error and a retry button.
 *
 * Non-JS visitors see the default banner rendered by PHP, which links to the
 * Chrome Web Store. No JS = no x402 anyway (extension needs JS).
 */
( function () {
  'use strict';

  // ------------------------------------------------------------------
  // Setup
  // ------------------------------------------------------------------

  var container = document.getElementById( 'primer-pay-container' );
  if ( ! container ) {
    return; // Nothing to do — this page isn't paywalled
  }

  var postId     = container.getAttribute( 'data-post-id' );
  var price      = container.getAttribute( 'data-price' );
  var unlockUrl  = container.getAttribute( 'data-unlock-url' );
  var chromeUrl  = container.getAttribute( 'data-chrome-url' );

  if ( ! unlockUrl ) {
    console.warn( '[Primer Pay] No unlock URL on container, aborting' );
    return;
  }

  // Tracks whether we detected the extension's patched fetch during the
  // initial wait phase. Used to decide between "install Primer Pay" and
  // "Primer Pay declined this payment" when the server returns 402 with
  // reason=no_payment_header.
  var extensionDetected = false;

  // ------------------------------------------------------------------
  // State rendering
  // ------------------------------------------------------------------

  /**
   * Replace the container's inner HTML with a state template.
   * Keeps the outer container + data attributes intact so subsequent
   * state transitions still work.
   */
  function render( html ) {
    container.innerHTML = html;
  }

  function renderProcessing() {
    render(
      '<div class="primer-pay-label" style="font-size: 14px; color: #baea2a; font-weight: 600; margin-bottom: 8px; letter-spacing: 0.05em;">PRIMER PAY</div>' +
      '<div class="primer-pay-price" style="font-size: 24px; font-weight: 600; color: #baea2a; margin-bottom: 8px;">$' + escapeHtml( price ) + ' USDC</div>' +
      '<div class="primer-pay-message" style="font-size: 14px; color: rgba(250,250,250,0.7); margin-bottom: 4px;">Processing payment&hellip;</div>' +
      '<div class="primer-pay-spinner" style="margin: 16px auto; width: 24px; height: 24px; border: 2px solid rgba(186,234,42,0.2); border-top-color: #baea2a; border-radius: 50%; animation: primer-pay-spin 0.8s linear infinite;"></div>' +
      '<style>@keyframes primer-pay-spin{to{transform:rotate(360deg)}}</style>'
    );
  }

  function renderInstallCta() {
    render(
      '<div class="primer-pay-label" style="font-size: 14px; color: #baea2a; font-weight: 600; margin-bottom: 8px; letter-spacing: 0.05em;">PRIMER PAY</div>' +
      '<div class="primer-pay-price" style="font-size: 24px; font-weight: 600; color: #baea2a; margin-bottom: 8px;">$' + escapeHtml( price ) + ' USDC</div>' +
      '<div class="primer-pay-message" style="font-size: 14px; color: rgba(250,250,250,0.7); margin-bottom: 24px; line-height: 1.5;">This content is available instantly with the Primer Pay browser extension.<br>No account needed &mdash; just a one-time micropayment.</div>' +
      '<div class="primer-pay-actions"><a href="' + escapeAttr( chromeUrl ) + '" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 32px; background: rgba(186, 234, 42, 0.1); border: 1px solid #baea2a; color: #baea2a; text-decoration: none; font-family: inherit; font-size: 13px; font-weight: 600; letter-spacing: 0.05em;">GET PRIMER PAY</a></div>' +
      '<div class="primer-pay-footer" style="margin-top: 16px; font-size: 11px; color: rgba(250,250,250,0.3);">Powered by <a href="https://primer.systems" style="color: rgba(186,234,42,0.5); text-decoration: none;">x402</a></div>'
    );
  }

  function renderError( message ) {
    render(
      '<div class="primer-pay-label" style="font-size: 14px; color: #B7410E; font-weight: 600; margin-bottom: 8px; letter-spacing: 0.05em;">PAYMENT FAILED</div>' +
      '<div class="primer-pay-message" style="font-size: 14px; color: rgba(250,250,250,0.7); margin-bottom: 24px; line-height: 1.5;">' + escapeHtml( message || 'Something went wrong.' ) + '</div>' +
      '<div class="primer-pay-actions"><button type="button" id="primer-pay-retry" style="display: inline-block; padding: 12px 32px; background: rgba(186, 234, 42, 0.1); border: 1px solid #baea2a; color: #baea2a; text-decoration: none; font-family: inherit; font-size: 13px; font-weight: 600; letter-spacing: 0.05em; cursor: pointer;">TRY AGAIN</button></div>'
    );
    var retryBtn = document.getElementById( 'primer-pay-retry' );
    if ( retryBtn ) {
      retryBtn.addEventListener( 'click', function () {
        attemptUnlock();
      } );
    }
  }

  /**
   * Shown when the extension is installed but declined to sign the
   * payment (policy block, balance too low, rate-limit, etc.). The
   * extension itself shows an overlay modal with the specific reason,
   * so we just acknowledge the block and offer a retry button — no
   * need to duplicate the reason.
   */
  function renderDeclined() {
    render(
      '<div class="primer-pay-label" style="font-size: 14px; color: #baea2a; font-weight: 600; margin-bottom: 8px; letter-spacing: 0.05em;">PRIMER PAY</div>' +
      '<div class="primer-pay-message" style="font-size: 14px; color: rgba(250,250,250,0.7); margin-bottom: 24px; line-height: 1.5;">Payment declined. Check the extension for details.</div>' +
      '<div class="primer-pay-actions"><button type="button" id="primer-pay-retry" style="display: inline-block; padding: 12px 32px; background: rgba(186, 234, 42, 0.1); border: 1px solid #baea2a; color: #baea2a; text-decoration: none; font-family: inherit; font-size: 13px; font-weight: 600; letter-spacing: 0.05em; cursor: pointer;">TRY AGAIN</button></div>'
    );
    var retryBtn = document.getElementById( 'primer-pay-retry' );
    if ( retryBtn ) {
      retryBtn.addEventListener( 'click', function () {
        attemptUnlock();
      } );
    }
  }

  /**
   * Replace the container element (and any content it holds) with the
   * unlocked post content. We need to also re-run any <script> tags that
   * the post content might contain (embeds, WP block scripts, etc.) —
   * innerHTML assignment doesn't execute them automatically.
   */
  function renderUnlockedContent( contentHtml ) {
    // Build a detached element to parse the HTML
    var wrapper = document.createElement( 'div' );
    wrapper.className = 'primer-pay-unlocked';
    wrapper.innerHTML = contentHtml;

    // Replace the container in place
    container.parentNode.replaceChild( wrapper, container );

    // Re-inject any <script> tags so they execute. innerHTML-parsed scripts
    // are inert by design, so we clone them into fresh script elements and
    // append to the DOM.
    var scripts = wrapper.querySelectorAll( 'script' );
    for ( var i = 0; i < scripts.length; i++ ) {
      var oldScript = scripts[ i ];
      var newScript = document.createElement( 'script' );
      for ( var j = 0; j < oldScript.attributes.length; j++ ) {
        var attr = oldScript.attributes[ j ];
        newScript.setAttribute( attr.name, attr.value );
      }
      if ( oldScript.textContent ) {
        newScript.textContent = oldScript.textContent;
      }
      oldScript.parentNode.replaceChild( newScript, oldScript );
    }
  }

  // ------------------------------------------------------------------
  // Minimal HTML escape helpers
  // ------------------------------------------------------------------

  function escapeHtml( str ) {
    if ( str == null ) return '';
    return String( str )
      .replace( /&/g, '&amp;' )
      .replace( /</g, '&lt;' )
      .replace( />/g, '&gt;' )
      .replace( /"/g, '&quot;' )
      .replace( /'/g, '&#039;' );
  }

  function escapeAttr( str ) {
    return escapeHtml( str );
  }

  // ------------------------------------------------------------------
  // The unlock flow
  // ------------------------------------------------------------------

  function attemptUnlock() {
    renderProcessing();

    // same-origin fetch — credentials default to 'same-origin' which is
    // exactly what we want: the extension's patchedFetch will retry with
    // the same credentials setting, so Set-Cookie lands in the cookie jar.
    fetch( unlockUrl, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    } )
      .then( function ( response ) {
        // 200 OK with our payload = payment succeeded (or was skipped via cookie)
        if ( response.ok ) {
          return response.json().then( function ( data ) {
            if ( data && data.success && typeof data.content === 'string' ) {
              renderUnlockedContent( data.content );
            } else {
              renderError( 'Unexpected response from server.' );
            }
          } );
        }

        // 402 has three meanings from our perspective:
        //   - settlement_failed: extension signed, facilitator/server rejected.
        //                        Show the error inline with a retry button.
        //   - no_payment_header, extension NOT detected: user probably doesn't
        //                        have Primer Pay installed. Show install CTA.
        //   - no_payment_header, extension IS detected: the extension saw the
        //                        402 and refused to sign (policy/balance/etc).
        //                        The extension has already shown its own modal
        //                        with the reason — we just show a "declined"
        //                        state with a retry button, NOT the install CTA.
        if ( response.status === 402 ) {
          return response.json().then(
            function ( data ) {
              var reason = data && data.reason;
              if ( reason === 'settlement_failed' ) {
                renderError( data.message || 'Payment could not be processed.' );
              } else if ( extensionDetected ) {
                renderDeclined();
              } else {
                renderInstallCta();
              }
            },
            function () {
              // Body wasn't valid JSON. Fall back based on detection.
              if ( extensionDetected ) {
                renderDeclined();
              } else {
                renderInstallCta();
              }
            }
          );
        }

        // Anything else: surface the message if we can parse it
        return response.json().then(
          function ( data ) {
            renderError(
              ( data && ( data.message || data.error ) ) ||
              ( 'Server returned ' + response.status )
            );
          },
          function () {
            renderError( 'Server returned ' + response.status );
          }
        );
      } )
      .catch( function ( err ) {
        console.error( '[Primer Pay] Unlock fetch failed:', err );
        renderError( 'Network error. Check your connection and try again.' );
      } );
  }

  // ------------------------------------------------------------------
  // Extension-ready detection
  // ------------------------------------------------------------------
  //
  // The Primer Pay browser extension patches window.fetch on DOMContentLoaded
  // to intercept 402 responses. Our unlock.js also runs on DOMContentLoaded,
  // and there's a race: the extension's bridge.ts registers its listener
  // first (at document_start), so its handler runs first and starts
  // asynchronously loading main.ts. But main.ts takes ~20-50ms to load,
  // during which window.fetch is still the native version. If we fire our
  // first fetch immediately, it uses the native fetch and the 402 slips
  // past the interceptor.
  //
  // Fix: check whether window.fetch has been replaced (patched fetches
  // don't contain [native code] in their toString). If still native, poll
  // briefly. If the extension isn't there at all, give up after ~1 second
  // and let the 402 fall through to the install CTA.

  function isFetchPatched() {
    try {
      return ! /\[native code\]/.test( window.fetch.toString() );
    } catch ( e ) {
      return false;
    }
  }

  var MAX_WAIT_MS = 1000;
  var POLL_INTERVAL_MS = 50;

  function waitForExtensionThenUnlock() {
    var waited = 0;
    function poll() {
      if ( isFetchPatched() ) {
        extensionDetected = true;
        attemptUnlock();
        return;
      }
      waited += POLL_INTERVAL_MS;
      if ( waited >= MAX_WAIT_MS ) {
        // Gave up — extension probably not installed. Proceed anyway;
        // we'll get a 402 and show the install CTA.
        attemptUnlock();
        return;
      }
      setTimeout( poll, POLL_INTERVAL_MS );
    }
    poll();
  }

  // Kick off once the DOM is ready. We deliberately DON'T register on
  // DOMContentLoaded if we're already past it — if the page is already
  // loaded, the extension has had time to install its patch.
  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', waitForExtensionThenUnlock );
  } else {
    waitForExtensionThenUnlock();
  }
} )();
