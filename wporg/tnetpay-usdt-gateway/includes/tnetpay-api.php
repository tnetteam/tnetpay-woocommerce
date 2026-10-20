<?php
/**
 * TNetPay API client helpers and settlement-settings sync.
 *
 * @package TNetPay_USDT_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Primary TNetPay API base URL (Iran relay operated by TNetPay).
 *
 * @return string
 */
function tnetpay_wc_api_base() {
	return rtrim( (string) apply_filters( 'tnetpay_wc_api_base', 'https://api-relay.mytnet.ir' ), '/' );
}

/**
 * Accept only HTTPS API base URLs (the API key is sent in a header, so plain HTTP or raw IPs are never used).
 *
 * @param string $url Candidate URL.
 * @return string Clean URL, or an empty string when rejected.
 */
function tnetpay_wc_sanitize_api_candidate( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
		return '';
	}
	return rtrim( $url, '/' );
}

/**
 * API base URLs tried in order when the primary endpoint is unreachable (HTTPS only).
 *
 * @return string[]
 */
function tnetpay_wc_api_base_candidates() {
	$list = array(
		tnetpay_wc_api_base(),
		'https://tnetpay.com/api',
	);
	$list = (array) apply_filters( 'tnetpay_wc_api_base_candidates', $list );
	$out  = array();
	foreach ( $list as $item ) {
		$clean = tnetpay_wc_sanitize_api_candidate( is_scalar( $item ) ? (string) $item : '' );
		if ( '' !== $clean ) {
			$out[] = $clean;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Write an error to the WooCommerce log (WooCommerce > Status > Logs, source "tnetpay").
 *
 * @param string $message Message. Never pass API keys or other secrets.
 */
function tnetpay_wc_log( $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->error( $message, array( 'source' => 'tnetpay' ) );
	}
}

/**
 * Normalize known API base URLs.
 *
 * @param string $api_base API base URL.
 * @return string
 */
function tnetpay_wc_normalize_api_base( $api_base ) {
	$api_base = trim( (string) $api_base );
	if ( '' === $api_base ) {
		return tnetpay_wc_api_base();
	}

	$api_base = rtrim( $api_base, '/' );
	$host     = (string) wp_parse_url( $api_base, PHP_URL_HOST );
	$path     = (string) wp_parse_url( $api_base, PHP_URL_PATH );

	if ( in_array( $host, array( 'tnetpay.com', 'www.tnetpay.com' ), true ) ) {
		if ( '' === $path || '/' === $path || 0 !== strpos( $path, '/api' ) ) {
			$api_base .= '/api';
		}
	}

	return rtrim( $api_base, '/' );
}

/**
 * Whether a failed request should be retried on the next HTTPS API base (relay, then tnetpay.com/api).
 *
 * Authentication and validation errors (401, 403, 422) are final; server errors,
 * timeouts, rate limits, other 4xx responses and connection failures fail over.
 *
 * @param array $result Request result.
 * @return bool
 */
function tnetpay_wc_should_failover_api( array $result ) {
	if ( isset( $result['http_code'] ) ) {
		$code = (int) $result['http_code'];
		if ( in_array( $code, array( 401, 403, 422 ), true ) ) {
			return false;
		}
		if ( $code >= 400 || 0 === $code ) {
			return true;
		}
	}

	$msg = strtolower( (string) ( isset( $result['message'] ) ? $result['message'] : '' ) );
	if ( '' === $msg ) {
		return true;
	}
	$needles = array(
		'redirect',
		'timed out',
		'could not resolve',
		'connection refused',
		'failed to connect',
		'ssl',
		'http 5',
		'http 0',
		'connection_failed',
	);
	foreach ( $needles as $needle ) {
		if ( false !== strpos( $msg, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Perform one request against one API base.
 *
 * @param string     $api_base API base.
 * @param string     $api_key  Merchant API key.
 * @param string     $method   HTTP method.
 * @param string     $path     Endpoint path.
 * @param array|null $body     JSON body.
 * @return array{ok:bool, data?:array, message?:string, http_code?:int}
 */
function tnetpay_wc_api_request_single( $api_base, $api_key, $method, $path, $body = null ) {
	$api_base = tnetpay_wc_normalize_api_base( $api_base );
	$url      = trailingslashit( $api_base ) . ltrim( $path, '/' );
	$args     = array(
		'method'      => $method,
		'timeout'     => 20,
		'redirection' => 2,
		'headers'     => array(
			'Authorization' => 'Bearer ' . $api_key,
			'X-API-Key'     => $api_key,
			'Accept'        => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return array(
			'ok'      => false,
			'message' => $response->get_error_message(),
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( $code >= 200 && $code < 300 ) {
		return array(
			'ok'        => true,
			'data'      => is_array( $data ) ? $data : array(),
			'http_code' => $code,
		);
	}

	return array(
		'ok'        => false,
		'message'   => ( is_array( $data ) && ! empty( $data['message'] ) ) ? (string) $data['message'] : ( 'HTTP ' . $code ),
		'http_code' => $code,
	);
}

/**
 * Perform an API request, failing over between API bases on connection errors.
 *
 * @param string     $api_key Merchant API key.
 * @param string     $method  HTTP method.
 * @param string     $path    Endpoint path.
 * @param array|null $body    JSON body.
 * @return array{ok:bool, data?:array, message?:string, http_code?:int, api_base_used?:string}
 */
function tnetpay_wc_api_request( $api_key, $method, $path, $body = null ) {
	$last = array(
		'ok'      => false,
		'message' => 'connection_failed',
	);

	foreach ( tnetpay_wc_api_base_candidates() as $base ) {
		$last                  = tnetpay_wc_api_request_single( $base, $api_key, $method, $path, $body );
		$last['api_base_used'] = $base;

		if ( ! empty( $last['ok'] ) ) {
			return $last;
		}
		if ( isset( $last['http_code'] ) && 401 === (int) $last['http_code'] ) {
			return $last;
		}
		if ( ! tnetpay_wc_should_failover_api( $last ) ) {
			return $last;
		}
	}

	return $last;
}

/**
 * Verify an API key against TNetPay.
 *
 * @param string $api_key API key.
 * @return array{ok:bool, message?:string}
 */
function tnetpay_wc_verify_api_key( $api_key ) {
	if ( '' === trim( (string) $api_key ) ) {
		return array(
			'ok'      => false,
			'message' => 'empty_key',
		);
	}

	$result = tnetpay_wc_api_request( $api_key, 'GET', 'merchants/integration/settlement-settings' );

	if ( $result['ok'] ) {
		return array( 'ok' => true );
	}
	if ( isset( $result['http_code'] ) && 401 === (int) $result['http_code'] ) {
		return array(
			'ok'      => false,
			'message' => 'invalid_key',
		);
	}
	return array(
		'ok'      => false,
		'message' => isset( $result['message'] ) ? $result['message'] : 'connection_failed',
	);
}

/**
 * Fetch the merchant's settlement settings from TNetPay.
 *
 * @param string $api_key API key.
 * @return array{settlementType:string, settlementWalletAddress:string}|null
 */
function tnetpay_wc_fetch_remote_settlement( $api_key ) {
	if ( ! $api_key ) {
		return null;
	}
	$result = tnetpay_wc_api_request( $api_key, 'GET', 'merchants/integration/settlement-settings' );
	if ( ! $result['ok'] || empty( $result['data'] ) ) {
		return null;
	}
	$data = $result['data'];
	return array(
		'settlementType'          => (string) ( isset( $data['settlementType'] ) ? $data['settlementType'] : 'rial' ),
		'settlementWalletAddress' => (string) ( isset( $data['settlementWalletAddress'] ) ? $data['settlementWalletAddress'] : '' ),
	);
}

/**
 * Whether a string is a valid EVM (BEP20) wallet address.
 *
 * @param string $address Address.
 * @return bool
 */
function tnetpay_wc_is_valid_evm_address( $address ) {
	return (bool) preg_match( '/^0x[a-fA-F0-9]{40}$/', trim( (string) $address ) );
}

/**
 * Push settlement settings to TNetPay after the WooCommerce settings are saved.
 *
 * @param string $api_key         API key.
 * @param string $settlement_type "rial" or "usdt_direct".
 * @param string $wallet_address  BEP20 wallet address for "usdt_direct".
 * @return bool
 */
function tnetpay_wc_push_settlement_settings( $api_key, $settlement_type, $wallet_address ) {
	$payload = array( 'settlementType' => $settlement_type );
	if ( 'usdt_direct' === $settlement_type ) {
		$payload['settlementWalletAddress'] = trim( $wallet_address );
	}

	$result = tnetpay_wc_api_request( $api_key, 'PATCH', 'merchants/integration/settlement-settings', $payload );

	return ! empty( $result['ok'] );
}
