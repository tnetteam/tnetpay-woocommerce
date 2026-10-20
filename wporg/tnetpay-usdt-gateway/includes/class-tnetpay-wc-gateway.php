<?php
/**
 * TNetPay payment gateway for WooCommerce.
 *
 * @package TNetPay_USDT_Gateway
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'TNetPay_WC_Gateway', false ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

/**
 * TNetPay USDT (BEP20) payment gateway.
 */
class TNetPay_WC_Gateway extends WC_Payment_Gateway {

	const INVOICE_ID_META_KEY = 'tnetpay_invoice_id';

	const SETTINGS_KEY = 'woocommerce_tnetpay_settings';

	/**
	 * Merchant API key.
	 *
	 * @var string
	 */
	public $api_key;

	/**
	 * Order status mapping.
	 *
	 * @var array
	 */
	public $order_statuses;

	/**
	 * Test mode flag.
	 *
	 * @var bool
	 */
	public $test = false;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	public $api_base_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'tnetpay';
		$this->has_fields         = false;
		$this->method_title       = 'TNetPay';
		$this->method_description = __( 'Accept USDT (BEP20) payments via the TNetPay payment gateway.', 'tnetpay-usdt-gateway' );
		$this->icon               = tnetpay_wc_gateway_icon_url();
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->enabled        = $this->get_option( 'enabled', 'no' );
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->api_key        = $this->get_option( 'api_key' );
		$order_statuses       = $this->get_option( 'order_statuses' );
		$this->order_statuses = is_array( $order_statuses ) ? $order_statuses : array();
		$this->test           = ( 'yes' === $this->get_option( 'test', 'no' ) );
		$this->api_base_url   = tnetpay_wc_api_base();

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_order_statuses' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou' ) );
		add_action( 'woocommerce_api_wc_gateway_tnetpay', array( $this, 'payment_callback' ) );
	}

	/**
	 * Multipliers that convert a store currency into Toman.
	 *
	 * The TNetPay API expects the invoice amount in Toman (it converts Toman to
	 * USDT at the live rate), so only Iranian currencies can be converted here.
	 *
	 * @return array<string, float>
	 */
	public static function toman_multipliers() {
		return (array) apply_filters(
			'tnetpay_wc_toman_multipliers',
			array(
				'IRT'  => 1,
				'IRR'  => 0.1,
				'IRHT' => 1000,
				'IRHR' => 100,
			)
		);
	}

	/**
	 * Whether the store currency is supported.
	 *
	 * @return bool
	 */
	public function valid_for_use() {
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$allowed  = (array) apply_filters( 'tnetpay_wc_supported_currencies', array_keys( self::toman_multipliers() ) );
		return '' !== $currency && in_array( $currency, $allowed, true );
	}

	/**
	 * Convert an order total into the Toman amount the TNetPay API expects.
	 *
	 * @param WC_Order $order Order.
	 * @return float|null Toman amount, or null when the currency cannot be converted.
	 */
	public function get_order_amount_toman( $order ) {
		$currency    = $order->get_currency();
		$multipliers = self::toman_multipliers();
		$amount      = null;
		if ( isset( $multipliers[ $currency ] ) ) {
			$amount = round( (float) $order->get_total() * (float) $multipliers[ $currency ] );
		}
		/**
		 * Filter the Toman amount sent to TNetPay. Return null to reject the order.
		 *
		 * @param float|null $amount   Toman amount.
		 * @param WC_Order   $order    Order.
		 * @param string     $currency Order currency.
		 */
		$amount = apply_filters( 'tnetpay_wc_order_amount_toman', $amount, $order, $currency );
		return ( null === $amount || (float) $amount <= 0 ) ? null : (float) $amount;
	}

	/**
	 * Whether the gateway can be used at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( empty( $this->api_key ) || empty( $this->api_base_url ) ) {
			return false;
		}
		return $this->valid_for_use();
	}

	/**
	 * Output the gateway settings screen.
	 */
	public function admin_options() {
		$api_key = $this->get_option( 'api_key' );
		if ( $api_key ) {
			$remote = tnetpay_wc_fetch_remote_settlement( $api_key );
			if ( $remote ) {
				$this->settings['settlement_type']           = $remote['settlementType'];
				$this->settings['settlement_wallet_address'] = $remote['settlementWalletAddress'];
			}
		}

		wp_enqueue_script(
			'tnetpay-wc-admin-settings',
			TNETPAY_WC_PLUGIN_URL . 'assets/js/tnetpay-admin-settings.js',
			array(),
			TNETPAY_WC_VERSION,
			true
		);
		?>
		<h3><?php echo esc_html( 'TNetPay' ); ?></h3>
		<?php if ( ! $this->valid_for_use() ) : ?>
			<div class="inline error"><p>
				<?php
				printf(
					/* translators: %s: store currency code */
					esc_html__( 'TNetPay is not available at checkout: your store currency (%s) is not supported. TNetPay invoices are priced in Iranian Toman, so set the store currency to Iranian Toman (IRT) or Iranian Rial (IRR).', 'tnetpay-usdt-gateway' ),
					esc_html( get_woocommerce_currency() )
				);
				?>
			</p></div>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'The settlement type is synced with your TNetPay account. A wallet address is required for Trust mode (direct USDT settlement).', 'tnetpay-usdt-gateway' ); ?>
		</p>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	/**
	 * Save settings and sync the settlement settings to TNetPay.
	 */
	public function process_admin_options() {
		parent::process_admin_options();

		$api_key = $this->get_option( 'api_key' );
		if ( ! $api_key ) {
			return;
		}

		$type   = $this->get_option( 'settlement_type', 'rial' );
		$wallet = (string) $this->get_option( 'settlement_wallet_address', '' );
		if ( ! tnetpay_wc_push_settlement_settings( $api_key, $type, $wallet ) ) {
			WC_Admin_Settings::add_error(
				esc_html__( 'Settlement settings could not be saved on the TNetPay server. Please check your API key.', 'tnetpay-usdt-gateway' )
			);
		}
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'                   => array(
				'title'       => __( 'Enable/Disable', 'tnetpay-usdt-gateway' ),
				'label'       => __( 'Enable TNetPay', 'tnetpay-usdt-gateway' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable USDT (BEP20) payments via TNetPay.', 'tnetpay-usdt-gateway' ),
				'default'     => 'no',
			),
			'description'               => array(
				'title'       => __( 'Description', 'tnetpay-usdt-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'The payment method description which a user sees at the checkout of your store.', 'tnetpay-usdt-gateway' ),
				'default'     => __( 'Pay with USDT (BEP20) via TNetPay.', 'tnetpay-usdt-gateway' ),
			),
			'title'                     => array(
				'title'       => __( 'Title', 'tnetpay-usdt-gateway' ),
				'type'        => 'text',
				'description' => __( 'The payment method title which a customer sees at the checkout of your store.', 'tnetpay-usdt-gateway' ),
				'default'     => __( 'USDT (BEP20) via TNetPay', 'tnetpay-usdt-gateway' ),
			),
			'api_key'                   => array(
				'title'       => __( 'API Key', 'tnetpay-usdt-gateway' ),
				'type'        => 'password',
				'description' => __( 'Your TNetPay API Key. You can get it from your TNetPay dashboard.', 'tnetpay-usdt-gateway' ),
				'default'     => '',
			),
			'settlement_type'           => array(
				'title'       => __( 'Settlement type', 'tnetpay-usdt-gateway' ),
				'type'        => 'select',
				'description' => __( 'Rial settlement: the balance stays in your TNetPay panel and you request a settlement. Trust: the fee is deducted and USDT is sent to your wallet.', 'tnetpay-usdt-gateway' ),
				'default'     => 'rial',
				'options'     => array(
					'rial'        => __( 'Rial settlement (TNetPay panel)', 'tnetpay-usdt-gateway' ),
					'usdt_direct' => __( 'Direct USDT to your wallet (Trust)', 'tnetpay-usdt-gateway' ),
				),
			),
			'settlement_wallet_address' => array(
				'title'       => __( 'USDT (BEP20) wallet address (Trust)', 'tnetpay-usdt-gateway' ),
				'type'        => 'text',
				'description' => __( 'Trust mode only — a BEP20 wallet address (0x...) is required.', 'tnetpay-usdt-gateway' ),
				'default'     => '',
				'placeholder' => '0x...',
			),
			'order_statuses'            => array(
				'type' => 'order_statuses',
			),
			'test'                      => array(
				'title'       => __( 'Test Mode', 'tnetpay-usdt-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable Test Mode', 'tnetpay-usdt-gateway' ),
				'default'     => 'no',
				'description' => __( 'Enable test mode for development and testing purposes.', 'tnetpay-usdt-gateway' ),
			),
		);
	}

	/**
	 * Thank-you page output.
	 */
	public function thankyou() {
		$description = $this->get_description();
		if ( $description ) {
			echo '<p>' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Validate the API key field by calling TNetPay.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_api_key_field( $key, $value ) {
		$value = sanitize_text_field( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		$check = tnetpay_wc_verify_api_key( $value );
		if ( ! empty( $check['ok'] ) ) {
			return $value;
		}

		if ( isset( $check['message'] ) && 'invalid_key' === $check['message'] ) {
			WC_Admin_Settings::add_error( esc_html__( 'API Key is invalid. Your changes have not been saved.', 'tnetpay-usdt-gateway' ) );
		} else {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: error detail */
					esc_html__( 'Could not reach TNetPay API (%s). Please try again later or contact support.', 'tnetpay-usdt-gateway' ),
					esc_html( isset( $check['message'] ) ? (string) $check['message'] : '' )
				)
			);
		}

		return '';
	}

	/**
	 * Read a posted settings value (nonce is verified by WooCommerce before saving).
	 *
	 * @param string $field Field key without prefix.
	 * @return string|null
	 */
	private function get_posted_setting( $field ) {
		$post = $this->get_post_data();
		$name = $this->get_field_key( $field );
		return isset( $post[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $post[ $name ] ) ) : null;
	}

	/**
	 * Validate the settlement type field.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_settlement_type_field( $key, $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( 'usdt_direct' !== $value ) {
			return in_array( $value, array( 'rial', 'usdt_direct' ), true ) ? $value : 'rial';
		}

		$wallet = (string) $this->get_posted_setting( 'settlement_wallet_address' );
		if ( ! tnetpay_wc_is_valid_evm_address( $wallet ) ) {
			WC_Admin_Settings::add_error( esc_html__( 'For Trust mode you must enter a valid USDT (BEP20) wallet address (0x + 40 characters).', 'tnetpay-usdt-gateway' ) );
			return 'rial';
		}

		return 'usdt_direct';
	}

	/**
	 * Validate the wallet address field.
	 *
	 * @param string $key   Field key.
	 * @param string $value Field value.
	 * @return string
	 */
	public function validate_settlement_wallet_address_field( $key, $value ) {
		$value = sanitize_text_field( (string) $value );
		$type  = $this->get_posted_setting( 'settlement_type' );
		if ( null === $type ) {
			$type = $this->get_option( 'settlement_type', 'rial' );
		}

		if ( 'usdt_direct' !== $type ) {
			return $value;
		}

		if ( ! tnetpay_wc_is_valid_evm_address( $value ) ) {
			WC_Admin_Settings::add_error( esc_html__( 'The wallet address for Trust mode is invalid.', 'tnetpay-usdt-gateway' ) );
			return '';
		}

		return $value;
	}

	/**
	 * Create a TNetPay invoice and redirect the customer to the payment page.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'fail' );
		}

		$amount = $this->get_order_amount_toman( $order );
		if ( null === $amount ) {
			tnetpay_wc_log( sprintf( 'Order #%d: currency %s cannot be converted to Toman.', $order->get_id(), $order->get_currency() ) );
			wc_add_notice( __( 'Payment error: this payment method is not available for the store currency.', 'tnetpay-usdt-gateway' ), 'error' );
			return array( 'result' => 'fail' );
		}

		$description = array();
		foreach ( $order->get_items() as $item ) {
			$description[] = $item->get_quantity() . ' × ' . $item->get_name();
		}

		$payment_data = array(
			// The API takes the amount in Toman and converts it to USDT; "USDT" is the only accepted currency value.
			'amount'      => $amount,
			'currency'    => 'USDT',
			'description' => implode( ', ', $description ),
			'webhookUrl'  => trailingslashit( get_bloginfo( 'wpurl' ) ) . '?wc-api=wc_gateway_tnetpay',
			'orderId'     => $order->get_id(),
			'successUrl'  => $this->get_return_url( $order ),
			'cancelUrl'   => $order->get_cancel_order_url_raw(),
			'source'      => 'api_gateway',
		);

		$result  = tnetpay_wc_api_request( $this->api_key, 'POST', 'payments/gateway', $payment_data );
		$body    = ( ! empty( $result['ok'] ) && isset( $result['data'] ) && is_array( $result['data'] ) ) ? $result['data'] : array();
		$invoice = self::extract_invoice( $body );

		if ( $invoice ) {
			$order->update_meta_data( self::INVOICE_ID_META_KEY, sanitize_text_field( (string) $invoice['invoiceId'] ) );
			$order->save();
			return array(
				'result'   => 'success',
				'redirect' => esc_url_raw( (string) $invoice['paymentUrl'] ),
			);
		}

		if ( empty( $result['ok'] ) ) {
			$message = isset( $result['message'] ) ? (string) $result['message'] : 'connection_failed';
		} elseif ( isset( $body['message'] ) && is_scalar( $body['message'] ) ) {
			$message = (string) $body['message'];
		} else {
			$message = 'invalid_api_response';
		}
		tnetpay_wc_log( sprintf( 'Failed to create payment for order #%d: %s', $order->get_id(), $message ) );
		wc_add_notice(
			sprintf(
				/* translators: %s: error message returned by TNetPay */
				__( 'Payment error: Failed to create payment: %s', 'tnetpay-usdt-gateway' ),
				esc_html( $message )
			),
			'error'
		);

		return array( 'result' => 'fail' );
	}

	/**
	 * Extract the invoice from a "create payment" response.
	 *
	 * Accepts both the wrapped shape {success: true, data: {invoiceId, paymentUrl}}
	 * and the flat shape {invoiceId, paymentUrl}.
	 *
	 * @param array $body Decoded response body.
	 * @return array{invoiceId:string, paymentUrl:string}|null
	 */
	private static function extract_invoice( array $body ) {
		$data = null;
		if ( ! empty( $body['success'] ) && isset( $body['data'] ) && is_array( $body['data'] ) ) {
			$data = $body['data'];
		} elseif ( isset( $body['invoiceId'] ) ) {
			$data = $body;
		}
		if ( ! $data || empty( $data['invoiceId'] ) || empty( $data['paymentUrl'] ) || ! is_scalar( $data['invoiceId'] ) || ! is_scalar( $data['paymentUrl'] ) ) {
			return null;
		}
		return array(
			'invoiceId'  => (string) $data['invoiceId'],
			'paymentUrl' => (string) $data['paymentUrl'],
		);
	}

	/**
	 * Get an invoice status from TNetPay.
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return array|false
	 */
	private function get_payment_status( $invoice_id ) {
		$result = tnetpay_wc_api_request( $this->api_key, 'GET', 'payments/invoice/' . rawurlencode( $invoice_id ) );
		if ( empty( $result['ok'] ) || empty( $result['data'] ) || ! is_array( $result['data'] ) ) {
			tnetpay_wc_log( 'Invoice status request failed: ' . ( isset( $result['message'] ) ? (string) $result['message'] : 'unknown' ) );
			return false;
		}
		return $result['data'];
	}

	/**
	 * Webhook handler (?wc-api=wc_gateway_tnetpay).
	 *
	 * The payload is never trusted: only the invoice ID is read from it, and the
	 * real status is fetched from the TNetPay API with the merchant's API key.
	 */
	public function payment_callback() {
		$request_data = json_decode( (string) file_get_contents( 'php://input' ), true );

		// phpcs:disable WordPress.Security.NonceVerification -- Server-to-server webhook; status is verified against the TNetPay API.
		if ( empty( $request_data ) && ! empty( $_POST ) ) {
			$request_data = array(
				'invoiceId' => isset( $_POST['invoiceId'] ) ? sanitize_text_field( wp_unslash( $_POST['invoiceId'] ) ) : '',
			);
		} elseif ( empty( $request_data ) ) {
			status_header( 400 );
			exit;
		}

		$invoice_id = ( is_array( $request_data ) && isset( $request_data['invoiceId'] ) ) ? sanitize_text_field( (string) $request_data['invoiceId'] ) : '';
		if ( '' === $invoice_id && isset( $_GET['invoiceId'] ) ) {
			$invoice_id = sanitize_text_field( wp_unslash( $_GET['invoiceId'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( '' === $invoice_id ) {
			status_header( 400 );
			exit;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery -- Lookup of a single order by gateway invoice ID.
				'meta_key'   => self::INVOICE_ID_META_KEY,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $invoice_id,
			)
		);

		if ( empty( $orders ) ) {
			status_header( 404 );
			exit;
		}

		$order = $orders[0];
		if ( $order->get_payment_method() !== $this->id ) {
			status_header( 400 );
			exit;
		}

		$payment_data = $this->get_payment_status( $invoice_id );
		if ( ! $payment_data ) {
			status_header( 500 );
			exit;
		}

		$payment_status  = isset( $payment_data['status'] ) ? sanitize_text_field( (string) $payment_data['status'] ) : 'pending';
		$order_statuses  = is_array( $this->get_option( 'order_statuses' ) ) ? $this->get_option( 'order_statuses' ) : array();
		$wc_order_status = isset( $order_statuses[ $payment_status ] ) ? $order_statuses[ $payment_status ] : null;

		if ( ! $wc_order_status ) {
			status_header( 200 );
			exit;
		}

		switch ( $payment_status ) {
			case 'paid':
				$status_was = 'wc-' . $order->get_status();

				$this->handle_order_status( $order, $wc_order_status );
				$order->add_order_note( __( 'Payment is confirmed on the blockchain, and has been credited to the merchant. Purchased goods/services can be securely delivered to the buyer.', 'tnetpay-usdt-gateway' ) );
				$order->payment_complete();

				$wc_expired_status = isset( $order_statuses['expired'] ) ? $order_statuses['expired'] : null;
				$emails            = WC()->mailer()->emails;

				if ( 'processing' === $order->get_status() && ( $status_was === $wc_expired_status ) ) {
					$emails['WC_Email_Customer_Processing_Order']->trigger( $order->get_id() );
				}
				if ( ( 'processing' === $order->get_status() || 'completed' === $order->get_status() ) && ( $status_was === $wc_expired_status ) ) {
					$emails['WC_Email_New_Order']->trigger( $order->get_id() );
				}
				break;
			case 'expired':
				$this->handle_order_status( $order, $wc_order_status );
				$order->add_order_note( __( 'Buyer did not pay within the required time and the invoice expired.', 'tnetpay-usdt-gateway' ) );
				break;
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Render the order-status mapping field.
	 *
	 * @return string
	 */
	public function generate_order_statuses_html() {
		ob_start();

		$wc_statuses      = array_merge( array( 'ignore' => __( 'Do nothing', 'tnetpay-usdt-gateway' ) ), wc_get_order_statuses() );
		$default_statuses = array(
			'paid'    => 'wc-processing',
			'pending' => 'ignore',
			'expired' => 'ignore',
		);
		$settings         = get_option( self::SETTINGS_KEY );
		$order_statuses   = ( is_array( $settings ) && isset( $settings['order_statuses'] ) && is_array( $settings['order_statuses'] ) ) ? $settings['order_statuses'] : array();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Order Statuses:', 'tnetpay-usdt-gateway' ); ?></th>
			<td class="forminp" id="tnetpay_order_statuses">
				<table cellspacing="0">
					<?php foreach ( $this->tnetpay_order_statuses() as $tnetpay_status_name => $tnetpay_status_title ) : ?>
						<?php
						$current_status = ! empty( $order_statuses[ $tnetpay_status_name ] ) ? $order_statuses[ $tnetpay_status_name ] : $default_statuses[ $tnetpay_status_name ];
						?>
						<tr>
							<th><?php echo esc_html( $tnetpay_status_title ); ?></th>
							<td>
								<select name="woocommerce_tnetpay_order_statuses[<?php echo esc_attr( $tnetpay_status_name ); ?>]">
									<?php foreach ( $wc_statuses as $wc_status_name => $wc_status_title ) : ?>
										<option value="<?php echo esc_attr( $wc_status_name ); ?>" <?php selected( $current_status, $wc_status_name ); ?>><?php echo esc_html( $wc_status_title ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Validate the order-status mapping field.
	 *
	 * @return array
	 */
	public function validate_order_statuses_field() {
		$post = $this->get_post_data();
		$name = $this->plugin_id . $this->id . '_order_statuses';
		if ( isset( $post[ $name ] ) && is_array( $post[ $name ] ) ) {
			return array_map( 'sanitize_text_field', wp_unslash( $post[ $name ] ) );
		}
		$current = $this->get_option( 'order_statuses' );
		return is_array( $current ) ? $current : array();
	}

	/**
	 * Save the order-status mapping (only known WooCommerce statuses are stored).
	 */
	public function save_order_statuses() {
		$post = $this->get_post_data();
		if ( ! isset( $post['woocommerce_tnetpay_order_statuses'] ) || ! is_array( $post['woocommerce_tnetpay_order_statuses'] ) ) {
			return;
		}

		$posted         = wp_unslash( $post['woocommerce_tnetpay_order_statuses'] );
		$wc_statuses    = wc_get_order_statuses();
		$settings       = get_option( self::SETTINGS_KEY );
		$settings       = is_array( $settings ) ? $settings : array();
		$order_statuses = ( isset( $settings['order_statuses'] ) && is_array( $settings['order_statuses'] ) ) ? $settings['order_statuses'] : array();

		foreach ( array_keys( $this->tnetpay_order_statuses() ) as $tnetpay_status_name ) {
			if ( ! isset( $posted[ $tnetpay_status_name ] ) ) {
				continue;
			}
			$wc_status_name = sanitize_text_field( (string) $posted[ $tnetpay_status_name ] );
			if ( array_key_exists( $wc_status_name, $wc_statuses ) ) {
				$order_statuses[ $tnetpay_status_name ] = $wc_status_name;
			}
		}

		$settings['order_statuses'] = $order_statuses;
		update_option( self::SETTINGS_KEY, $settings );
	}

	/**
	 * Apply a mapped order status.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Status.
	 */
	protected function handle_order_status( $order, $status ) {
		if ( 'ignore' !== $status ) {
			$order->update_status( $status );
		}
	}

	/**
	 * TNetPay invoice statuses.
	 *
	 * @return string[]
	 */
	private function tnetpay_order_statuses() {
		return array(
			'paid'    => __( 'Paid', 'tnetpay-usdt-gateway' ),
			'pending' => __( 'Pending', 'tnetpay-usdt-gateway' ),
			'expired' => __( 'Expired', 'tnetpay-usdt-gateway' ),
		);
	}
}
