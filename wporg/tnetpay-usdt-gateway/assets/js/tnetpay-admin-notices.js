( function ( $ ) {
	$( document ).on(
		'click',
		'.tnetpay-wc-admin-notice .notice-dismiss',
		function () {
			var $notice = $( this ).closest( '.tnetpay-wc-admin-notice' );
			var noticeId = $notice.data( 'notice-id' );
			if ( ! noticeId || typeof tnetpayWcAdminNotices === 'undefined' ) {
				return;
			}
			$.post( tnetpayWcAdminNotices.ajaxUrl, {
				action: 'tnetpay_wc_dismiss_admin_notice',
				nonce: tnetpayWcAdminNotices.nonce,
				notice: noticeId,
			} );
		}
	);
}( jQuery ) );
