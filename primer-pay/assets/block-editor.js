/**
 * Primer Pay — Gutenberg block: Content Gate
 *
 * Registers a "Primer Pay Content Gate" block that acts as a visual
 * separator between the free teaser and the paid content. On the
 * front-end it renders as the [primer_pay_x402] shortcode marker.
 *
 * No build step required — uses wp.* globals provided by WordPress.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
  'use strict';

  var el             = element.createElement;
  var Fragment       = element.Fragment;
  var __             = i18n.__;
  var InspectorControls = blockEditor.InspectorControls;
  var useBlockProps   = blockEditor.useBlockProps;
  var PanelBody      = components.PanelBody;
  var TextControl    = components.TextControl;
  var SelectControl  = components.SelectControl;
  var ToggleControl  = components.ToggleControl;

  blocks.registerBlockType( 'primer-pay/content-gate', {
    title: __( 'Primer Pay Content Gate', 'primer-pay' ),
    description: __( 'Split your post into a free teaser (above) and paid content (below). Visitors pay to unlock everything below this block.', 'primer-pay' ),
    icon: 'lock',
    category: 'design',
    keywords: [ 'paywall', 'x402', 'micropayment', 'gate', 'paid' ],

    attributes: {
      enabled: {
        type: 'boolean',
        default: true,
      },
      price: {
        type: 'string',
        default: '',
      },
      accessDuration: {
        type: 'string',
        default: '',
      },
      walletAddress: {
        type: 'string',
        default: '',
      },
    },

    edit: function ( props ) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;

      var blockProps = useBlockProps( {
        className: 'primer-pay-gate-editor' + ( attributes.enabled ? '' : ' primer-pay-gate-editor--disabled' ),
      } );

      // Build duration options from the localized data (passed from PHP).
      var durationChoices = [ { label: __( 'Use default', 'primer-pay' ), value: '' } ];
      if ( window.primerPayBlock && window.primerPayBlock.durations ) {
        var durations = window.primerPayBlock.durations;
        for ( var key in durations ) {
          if ( durations.hasOwnProperty( key ) ) {
            durationChoices.push( { label: durations[ key ], value: key } );
          }
        }
      }

      var defaultPrice  = ( window.primerPayBlock && window.primerPayBlock.defaultPrice ) || '0.01';
      var defaultWallet = ( window.primerPayBlock && window.primerPayBlock.defaultWallet ) || '';

      return el( Fragment, null,
        el( InspectorControls, null,
          el( PanelBody, { title: __( 'Paywall Settings', 'primer-pay' ), initialOpen: true },
            el( ToggleControl, {
              label: __( 'Enable Paywall', 'primer-pay' ),
              help: attributes.enabled
                ? __( 'Content below this block is paywalled.', 'primer-pay' )
                : __( 'Paywall is disabled. All content is visible.', 'primer-pay' ),
              checked: attributes.enabled,
              onChange: function ( val ) {
                setAttributes( { enabled: val } );
              },
            } ),
            el( TextControl, {
              label: __( 'Price (USDC)', 'primer-pay' ),
              help: __( 'Leave blank to use the default price.', 'primer-pay' ) + ( defaultPrice ? ' ($' + defaultPrice + ')' : '' ),
              value: attributes.price,
              onChange: function ( val ) {
                setAttributes( { price: val } );
              },
            } ),
            el( SelectControl, {
              label: __( 'Access Duration', 'primer-pay' ),
              help: __( 'How long the reader retains access after paying.', 'primer-pay' ),
              value: attributes.accessDuration,
              options: durationChoices,
              onChange: function ( val ) {
                setAttributes( { accessDuration: val } );
              },
            } ),
            el( TextControl, {
              label: __( 'Payment Wallet (optional)', 'primer-pay' ),
              help: __( 'Leave blank to use the default wallet.', 'primer-pay' ),
              value: attributes.walletAddress,
              placeholder: defaultWallet ? defaultWallet : '0x...',
              onChange: function ( val ) {
                setAttributes( { walletAddress: val } );
              },
            } )
          )
        ),
        el( 'div', blockProps,
          el( 'div', { className: 'primer-pay-gate-editor__line' } ),
          el( 'div', { className: 'primer-pay-gate-editor__label' },
            attributes.enabled
              ? __( 'Primer Pay — Content Gate', 'primer-pay' ) + ( attributes.price ? ' — $' + attributes.price + ' USDC' : '' )
              : __( 'Primer Pay — Content Gate (disabled)', 'primer-pay' )
          ),
          el( 'div', { className: 'primer-pay-gate-editor__hint' },
            __( 'Free teaser above', 'primer-pay' ) + ' \u2022 ' + __( 'Paid content below', 'primer-pay' )
          ),
          el( 'div', { className: 'primer-pay-gate-editor__line' } )
        )
      );
    },

    save: function () {
      // Dynamic block — rendered by PHP on the front-end.
      return null;
    },
  } );
} )(
  window.wp.blocks,
  window.wp.element,
  window.wp.blockEditor,
  window.wp.components,
  window.wp.i18n
);
