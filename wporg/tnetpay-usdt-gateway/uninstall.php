<?php
/**
 * Uninstall cleanup for TNetPay USDT Payment Gateway.
 *
 * Removes the gateway settings. Order meta (TNetPay invoice IDs) is kept so
 * that historical orders remain traceable.
 *
 * @package TNetPay_USDT_Gateway
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_tnetpay_settings' );
delete_transient( 'tnetpay_wc_update_info' );
