<?php
/**
 * Plugin Name:       TNetPay – USDT (BEP20) Payment Gateway for WooCommerce
 * Plugin URI:        https://tnetpay.com/woocommerce
 * Description:       Accept USDT (BEP20) payments in your WooCommerce store through the TNetPay payment gateway.
 * Version:           1.1.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            TNetPay
 * Author URI:        https://tnetpay.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tnetpay-usdt-gateway
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package TNetPay_USDT_Gateway
 */

/*
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later version.
 *
 * Portions of this plugin are derived from "CoinGate for WooCommerce",
 * Copyright (c) 2015-2016 CoinGate (https://coingate.com), released under the
 * MIT License. See LICENSE.txt for the full MIT notice.
 */

defined( 'ABSPATH' ) || exit;

define( 'TNETPAY_WC_VERSION', '1.1.2' );
define( 'TNETPAY_WC_PLUGIN_FILE', __FILE__ );
define( 'TNETPAY_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TNETPAY_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TNETPAY_WC_PLUGIN_DIR . 'includes/tnetpay-admin-notices.php';

/**
 * Whether the older stand-alone TNetPay plugin (tnetpay-for-woocommerce.php, e.g. folder
 * "tnetpay-woocommerce", versions up to 1.0.11) is active. Both plugins use the same
 * gateway ID and helper names, so this edition stays idle until the old one is deactivated.
 *
 * @return bool
 */
function tnetpay_wc_legacy_plugin_active() {
	if ( defined( 'TNETPAY_FOR_WOOCOMMERCE_VERSION' ) || function_exists( 'tnetpay_wc_api_base' ) || class_exists( 'TNetPay_Gateway', false ) ) {
		return true;
	}
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( $active as $plugin ) {
		if ( 'tnetpay-for-woocommerce.php' === basename( (string) $plugin ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Admin notice shown while the older TNetPay plugin is still active (Plugins screen only).
 */
function tnetpay_wc_legacy_plugin_notice() {
	if ( ! tnetpay_wc_current_user_can_see_admin_notices() || ! tnetpay_wc_is_plugins_admin_screen() ) {
		return;
	}
	if ( tnetpay_wc_is_admin_notice_dismissed( 'legacy_plugin' ) ) {
		return;
	}
	tnetpay_wc_admin_notice_open( 'legacy_plugin', 'warning' );
	echo esc_html__( 'TNetPay USDT Payment Gateway is paused because the older TNetPay for WooCommerce plugin (tnetpay-woocommerce) is still active. Deactivate the old plugin on the Plugins screen; your TNetPay settings are kept and used automatically.', 'tnetpay-usdt-gateway' );
	echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Plugins', 'tnetpay-usdt-gateway' ) . '</a>';
	tnetpay_wc_admin_notice_close();
}

if ( tnetpay_wc_legacy_plugin_active() ) {
	add_action( 'admin_notices', 'tnetpay_wc_legacy_plugin_notice' );
	return;
}

require_once TNETPAY_WC_PLUGIN_DIR . 'includes/tnetpay-api.php';

/**
 * Declare compatibility with WooCommerce HPOS and Cart/Checkout blocks.
 */
function tnetpay_wc_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TNETPAY_WC_PLUGIN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TNETPAY_WC_PLUGIN_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'tnetpay_wc_declare_compatibility' );

/**
 * Load the gateway class and register it with WooCommerce.
 */
function tnetpay_wc_init_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'tnetpay_wc_missing_woocommerce_notice' );
		return;
	}

	require_once TNETPAY_WC_PLUGIN_DIR . 'includes/class-tnetpay-wc-gateway.php';

	add_filter( 'woocommerce_payment_gateways', 'tnetpay_wc_add_gateway' );
}
add_action( 'plugins_loaded', 'tnetpay_wc_init_gateway', 11 );

/**
 * Add the TNetPay gateway to the WooCommerce gateway list.
 *
 * @param array $gateways Registered gateway class names.
 * @return array
 */
function tnetpay_wc_add_gateway( $gateways ) {
	if ( ! in_array( 'TNetPay_WC_Gateway', $gateways, true ) ) {
		$gateways[] = 'TNetPay_WC_Gateway';
	}
	return $gateways;
}

/**
 * Admin notice shown when WooCommerce is not active (Plugins screen only).
 */
function tnetpay_wc_missing_woocommerce_notice() {
	if ( ! tnetpay_wc_current_user_can_see_admin_notices() || ! tnetpay_wc_is_plugins_admin_screen() ) {
		return;
	}
	if ( tnetpay_wc_is_admin_notice_dismissed( 'missing_woocommerce' ) ) {
		return;
	}
	tnetpay_wc_admin_notice_open( 'missing_woocommerce', 'error' );
	echo esc_html__( 'TNetPay USDT Payment Gateway requires WooCommerce to be installed and active.', 'tnetpay-usdt-gateway' );
	tnetpay_wc_admin_notice_close();
}

/**
 * Register the payment method for the block-based checkout.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry Registry.
 */
function tnetpay_wc_register_blocks_payment_method( $payment_method_registry ) {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once TNETPAY_WC_PLUGIN_DIR . 'includes/class-tnetpay-wc-blocks-support.php';
	$payment_method_registry->register( new TNetPay_WC_Blocks_Support() );
}
add_action( 'woocommerce_blocks_payment_method_type_registration', 'tnetpay_wc_register_blocks_payment_method' );

/**
 * Absolute URL of the payment method logo shown at checkout.
 *
 * @return string
 */
function tnetpay_wc_gateway_icon_url() {
	$url = add_query_arg( 'ver', TNETPAY_WC_VERSION, TNETPAY_WC_PLUGIN_URL . 'assets/tnetpay-logo.webp' );
	return (string) apply_filters( 'tnetpay_wc_gateway_icon', $url );
}

/**
 * Add a "Settings" link on the Plugins screen.
 *
 * @param array $links Plugin action links.
 * @return array
 */
function tnetpay_wc_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=tnetpay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tnetpay-usdt-gateway' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'tnetpay_wc_action_links' );
