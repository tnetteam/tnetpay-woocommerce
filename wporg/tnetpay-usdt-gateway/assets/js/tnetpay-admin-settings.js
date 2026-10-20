/**
 * TNetPay settings screen: show the wallet field only for Trust (direct USDT) settlement.
 */
( function () {
	'use strict';

	function toggleWalletRow() {
		var type = document.getElementById( 'woocommerce_tnetpay_settlement_type' );
		var input = document.getElementById( 'woocommerce_tnetpay_settlement_wallet_address' );
		var row = input ? input.closest( 'tr' ) : null;
		if ( ! type || ! row ) {
			return;
		}
		row.style.display = ( 'usdt_direct' === type.value ) ? '' : 'none';
	}

	function init() {
		var type = document.getElementById( 'woocommerce_tnetpay_settlement_type' );
		if ( type ) {
			type.addEventListener( 'change', toggleWalletRow );
			toggleWalletRow();
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
