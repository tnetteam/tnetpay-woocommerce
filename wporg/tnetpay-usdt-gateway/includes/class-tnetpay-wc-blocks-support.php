<?php
/**
 * Block-based checkout integration.
 *
 * @package TNetPay_USDT_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * TNetPay payment method for the Cart & Checkout blocks.
 */
final class TNetPay_WC_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method ID (matches the gateway ID).
	 *
	 * @var string
	 */
	protected $name = 'tnetpay';

	/**
	 * Load gateway settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_tnetpay_settings', array() );
	}

	/**
	 * Whether the payment method is enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		return filter_var( $this->get_setting( 'enabled', 'no' ), FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Register the checkout script.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'tnetpay-wc-blocks',
			TNETPAY_WC_PLUGIN_URL . 'assets/js/tnetpay-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			TNETPAY_WC_VERSION,
			true
		);
		return array( 'tnetpay-wc-blocks' );
	}

	/**
	 * Same script in the editor.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}

	/**
	 * Data passed to the checkout script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->get_setting( 'title', __( 'USDT (BEP20) via TNetPay', 'tnetpay-usdt-gateway' ) ),
			'description' => $this->get_setting( 'description', __( 'Pay with USDT (BEP20) via TNetPay.', 'tnetpay-usdt-gateway' ) ),
			'icon'        => tnetpay_wc_gateway_icon_url(),
			'supports'    => array( 'products' ),
		);
	}
}
