/**
 * Primer Pay — Gutenberg block: Content Gate
 *
 * Registers a "Primer Pay Content Gate" block that acts as a visual
 * separator between the free teaser and the paid content. On the
 * front-end it renders as the [primer_pay_x402] shortcode marker.
 *
 * No build step required — uses wp.* globals provided by WordPress.
 */
( function ( blocks, element, blockEditor, components, data ) {
  'use strict';

  var el             = element.createElement;
  var Fragment       = element.Fragment;
  var InspectorControls = blockEditor.InspectorControls;
  var useBlockProps   = blockEditor.useBlockProps;
  var PanelBody      = components.PanelBody;
  var TextControl    = components.TextControl;
  var SelectControl  = components.SelectControl;

  blocks.registerBlockType( 'primer-pay/content-gate', {
    title: 'Primer Pay Content Gate',
    description: 'Split your post into a free teaser (above) and paid content (below). Visitors pay to unlock everything below this block.',
    icon: 'lock',
    category: 'design',
    keywords: [ 'paywall', 'x402', 'micropayment', 'gate', 'paid' ],

    attributes: {
      price: {
        type: 'string',
        default: '',
      },
      accessDuration: {
        type: 'string',
        default: '',
      },
    },

    edit: function ( props ) {
      var attributes = props.attributes;
      var setAttributes = props.setAttributes;

      var blockProps = useBlockProps( {
        className: 'primer-pay-gate-editor',
      } );

      // Build duration options from the localized data (passed from PHP).
      var durationChoices = [ { label: 'Use default', value: '' } ];
      if ( window.primerPayBlock && window.primerPayBlock.durations ) {
        var durations = window.primerPayBlock.durations;
        for ( var key in durations ) {
          if ( durations.hasOwnProperty( key ) ) {
            durationChoices.push( { label: durations[ key ], value: key } );
          }
        }
      }

      var defaultPrice = ( window.primerPayBlock && window.primerPayBlock.defaultPrice ) || '0.01';

      return el( Fragment, null,
        el( InspectorControls, null,
          el( PanelBody, { title: 'Payment Settings', initialOpen: true },
            el( TextControl, {
              label: 'Price (USDC)',
              help: 'Leave blank to use the default price ($' + defaultPrice + ').',
              value: attributes.price,
              onChange: function ( val ) {
                setAttributes( { price: val } );
              },
            } ),
            el( SelectControl, {
              label: 'Access Duration',
              help: 'How long the reader retains access after paying.',
              value: attributes.accessDuration,
              options: durationChoices,
              onChange: function ( val ) {
                setAttributes( { accessDuration: val } );
              },
            } )
          )
        ),
        el( 'div', blockProps,
          el( 'div', { className: 'primer-pay-gate-editor__line' } ),
          el( 'div', { className: 'primer-pay-gate-editor__label' },
            'Primer Pay — Content Gate',
            attributes.price ? ' — $' + attributes.price + ' USDC' : ''
          ),
          el( 'div', { className: 'primer-pay-gate-editor__hint' },
            'Free teaser above \u2022 Paid content below'
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
  window.wp.data
);
