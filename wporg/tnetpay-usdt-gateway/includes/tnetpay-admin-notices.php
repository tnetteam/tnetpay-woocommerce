<?php
/**
 * Admin notice helpers: screen scope, capability, and per-user dismissal.
 *
 * @package TNetPay_USDT_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * User meta key storing dismissed notice IDs.
 */
const TNETPAY_WC_DISMISSED_NOTICES_META = 'tnetpay_wc_dismissed_admin_notices';

/**
 * Whether the current user has dismissed an admin notice.
 *
 * @param string $notice_id Notice identifier.
 * @return bool
 */
function tnetpay_wc_is_admin_notice_dismissed( $notice_id ) {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! is_string( $notice_id ) || '' === $notice_id ) {
		return false;
	}
	$dismissed = get_user_meta( $user_id, TNETPAY_WC_DISMISSED_NOTICES_META, true );
	return is_array( $dismissed ) && in_array( $notice_id, $dismissed, true );
}

/**
 * Whether admin notices for this plugin may run on the current request.
 *
 * @return bool
 */
function tnetpay_wc_current_user_can_see_admin_notices() {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * Whether the request is the Plugins admin screen.
 *
 * @return bool
 */
function tnetpay_wc_is_plugins_admin_screen() {
	if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	return $screen && 'plugins' === $screen->id;
}

/**
 * Whether the request is this gateway's WooCommerce checkout settings section.
 *
 * @return bool
 */
function tnetpay_wc_is_gateway_settings_screen() {
	if ( ! is_admin() ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Screen detection only.
	$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
	return 'wc-settings' === $page && 'checkout' === $tab && 'tnetpay' === $section;
}

/**
 * Enqueue script that persists dismissal of plugin admin notices.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function tnetpay_wc_admin_notice_scripts( $hook_suffix ) {
	if ( 'plugins.php' !== $hook_suffix && ! tnetpay_wc_is_gateway_settings_screen() ) {
		return;
	}
	if ( ! tnetpay_wc_current_user_can_see_admin_notices() ) {
		return;
	}
	wp_enqueue_script(
		'tnetpay-wc-admin-notices',
		TNETPAY_WC_PLUGIN_URL . 'assets/js/tnetpay-admin-notices.js',
		array( 'jquery' ),
		TNETPAY_WC_VERSION,
		true
	);
	wp_localize_script(
		'tnetpay-wc-admin-notices',
		'tnetpayWcAdminNotices',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tnetpay_wc_dismiss_admin_notice' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'tnetpay_wc_admin_notice_scripts' );

/**
 * AJAX handler: store per-user notice dismissal.
 */
function tnetpay_wc_ajax_dismiss_admin_notice() {
	check_ajax_referer( 'tnetpay_wc_dismiss_admin_notice', 'nonce' );
	if ( ! tnetpay_wc_current_user_can_see_admin_notices() ) {
		wp_send_json_error( null, 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
	$notice_id = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : '';
	$allowed   = array( 'legacy_plugin', 'missing_woocommerce' );
	if ( ! in_array( $notice_id, $allowed, true ) ) {
		wp_send_json_error( null, 400 );
	}
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		wp_send_json_error( null, 403 );
	}
	$dismissed = get_user_meta( $user_id, TNETPAY_WC_DISMISSED_NOTICES_META, true );
	if ( ! is_array( $dismissed ) ) {
		$dismissed = array();
	}
	if ( ! in_array( $notice_id, $dismissed, true ) ) {
		$dismissed[] = $notice_id;
		update_user_meta( $user_id, TNETPAY_WC_DISMISSED_NOTICES_META, $dismissed );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_tnetpay_wc_dismiss_admin_notice', 'tnetpay_wc_ajax_dismiss_admin_notice' );

/**
 * Output opening markup for a dismissible admin notice.
 *
 * @param string $notice_id Notice identifier.
 * @param string $type      WordPress notice class suffix (error, warning, info, success).
 */
function tnetpay_wc_admin_notice_open( $notice_id, $type = 'warning' ) {
	$type = in_array( $type, array( 'error', 'warning', 'info', 'success' ), true ) ? $type : 'warning';
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible tnetpay-wc-admin-notice" data-notice-id="' . esc_attr( $notice_id ) . '"><p>';
}

/**
 * Close notice paragraph and container.
 */
function tnetpay_wc_admin_notice_close() {
	echo '</p></div>';
}
