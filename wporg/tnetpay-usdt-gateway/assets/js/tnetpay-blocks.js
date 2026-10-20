/**
 * TNetPay - WooCommerce block checkout integration.
 */
( function () {
	'use strict';

	function init() {
		var wc = window.wc;
		if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcBlocksRegistry.registerPaymentMethod ) {
			return;
		}
		if ( ! wc.wcSettings || ! wc.wcSettings.getSetting ) {
			return;
		}

		var settings = wc.wcSettings.getSetting( "tnetpay_data", {} );
		var title = settings.title || 'TNetPay';
		var description = settings.description || '';
		var iconUrl = settings.icon || '';

		var createElement = ( window.wp && window.wp.element && window.wp.element.createElement ) ?
			window.wp.element.createElement : ( window.React && window.React.createElement ) || null;
		var useEffect = ( window.wp && window.wp.element && window.wp.element.useEffect ) ?
			window.wp.element.useEffect : ( window.React && window.React.useEffect ) || null;

		if ( ! createElement || ! useEffect ) {
			return;
		}

		var Content = function ( props ) {
			useEffect( function () {
				var eventRegistration = props.eventRegistration || {};
				var onPaymentSetup = eventRegistration.onPaymentSetup;
				var emitResponse = props.emitResponse || {};
				if ( onPaymentSetup ) {
					var successType = ( emitResponse.responseTypes && emitResponse.responseTypes.SUCCESS ) || 'success';
					var unsubscribe = onPaymentSetup( function () {
						return { type: successType };
					} );
					return function () { if ( typeof unsubscribe === 'function' ) unsubscribe(); };
				}
			}, [] );
			return createElement( 'div', { className: 'tnetpay-description' }, description );
		};

		var label = iconUrl
			? createElement(
				'span',
				{ className: 'tnetpay-blocks-label', style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
				createElement( 'img', {
					src: iconUrl,
					alt: title,
					className: 'tnetpay-blocks-icon',
					style: { maxHeight: '24px', width: 'auto', display: 'block' },
					loading: 'lazy',
					decoding: 'async',
				} ),
				title
			)
			: createElement( 'span', null, title );

		var config = {
			name: 'tnetpay',
			label: label,
			content: createElement( Content ),
			edit: createElement( 'div', { className: 'tnetpay-description' }, description ),
			canMakePayment: function () { return true; },
			ariaLabel: title,
			supports: {
				features: settings.supports || [ 'products' ],
			},
		};

		wc.wcBlocksRegistry.registerPaymentMethod( config );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
