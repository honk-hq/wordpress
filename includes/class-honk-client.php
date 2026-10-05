<?php
/**
 * HTTP calls to the Honk API through the WordPress HTTP API.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends one message (POST /v1/messages) and reads the server's capabilities (GET /v1/config).
 */
final class Honk_Client {

	/**
	 * Seconds per attempt (sdk/README.md: short timeouts).
	 */
	const TIMEOUT = 5;

	/**
	 * Transient prefix of the cached GET /v1/config.
	 */
	const CONFIG_TRANSIENT = 'honk_server_config_';

	/**
	 * The User-Agent of every request.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'honk-wordpress/' . HONK_VERSION . ' (+https://honk-me.app)';
	}

	/**
	 * Sends one message. Never throws; the result says what happened and whether a retry may help.
	 *
	 * @param string $server          Server URL without trailing slash.
	 * @param string $key             Ingestion key.
	 * @param string $body            JSON body.
	 * @param string $idempotency_key Idempotency-Key header.
	 * @param array  $options         Optional: timeout (seconds), blocking (bool).
	 * @return array{ok: bool, status: int, code: string, message: string, id: string, duplicate: bool, retryable: bool, retry_after: int, ms: int}
	 */
	public static function send( $server, $key, $body, $idempotency_key, array $options = array() ) {
		$started  = microtime( true );
		$blocking = ! isset( $options['blocking'] ) || (bool) $options['blocking'];
		$response = wp_remote_post(
			$server . '/v1/messages',
			array(
				'timeout'     => isset( $options['timeout'] ) ? (float) $options['timeout'] : self::TIMEOUT,
				'redirection' => 0,
				'blocking'    => $blocking,
				'user-agent'  => self::user_agent(),
				'headers'     => array(
					'Authorization'   => 'Bearer ' . $key,
					'Content-Type'    => 'application/json',
					'Accept'          => 'application/json',
					'Idempotency-Key' => $idempotency_key,
				),
				'body'        => $body,
				'data_format' => 'body',
			)
		);
		$ms       = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! $blocking ) {
			return self::result( false, 0, 'not_waited', '', $ms );
		}
		return self::interpret( $response, $ms );
	}

	/**
	 * Turns a wp_remote_* response into a result.
	 *
	 * @param array|WP_Error $response Response.
	 * @param int            $ms       Duration in milliseconds.
	 * @return array
	 */
	public static function interpret( $response, $ms = 0 ) {
		if ( is_wp_error( $response ) ) {
			$result              = self::result( false, 0, 'network_error', $response->get_error_message(), $ms );
			$result['retryable'] = true;
			return $result;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		$data   = is_array( $data ) ? $data : array();

		if ( 202 === $status || 200 === $status ) {
			$result              = self::result( true, $status, 'accepted', '', $ms );
			$result['id']        = isset( $data['id'] ) ? (string) $data['id'] : '';
			$result['duplicate'] = ! empty( $data['duplicate'] );
			return $result;
		}

		$error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$code    = isset( $error['code'] ) ? (string) $error['code'] : 'http_' . $status;
		$message = isset( $error['message'] ) ? (string) $error['message'] : '';
		if ( ! empty( $error['fields'] ) && is_array( $error['fields'] ) ) {
			$fields = array();
			foreach ( $error['fields'] as $field ) {
				if ( is_array( $field ) && isset( $field['field'], $field['code'] ) ) {
					$fields[] = $field['field'] . ': ' . $field['code'];
				}
			}
			if ( $fields ) {
				$message .= ' (' . implode( ', ', $fields ) . ')';
			}
		}
		if ( '' === $message && $status >= 300 && $status < 400 ) {
			$code    = 'redirect';
			$message = 'The server replied with a redirect, which isn’t followed. Check the server URL.';
		}

		$result = self::result( false, $status, $code, $message, $ms );

		// A replay with a different body: the event was accepted before, so nothing is lost.
		if ( 409 === $status && 'idempotency_conflict' === $code ) {
			$result['ok']        = true;
			$result['duplicate'] = true;
			return $result;
		}

		if ( 429 === $status || $status >= 500 ) {
			$result['retryable']   = true;
			$result['retry_after'] = self::retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );
		}
		return $result;
	}

	/**
	 * Seconds from a Retry-After header (delta seconds or an HTTP date); 0 if absent.
	 *
	 * @param mixed $header Header value.
	 * @return int
	 */
	public static function retry_after( $header ) {
		if ( is_array( $header ) ) {
			$header = reset( $header );
		}
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return 0;
		}
		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}
		$time = strtotime( $header );
		return false === $time ? 0 : max( 0, $time - time() );
	}

	/**
	 * A result array with every key set.
	 *
	 * @param bool   $ok      Whether the server accepted the message.
	 * @param int    $status  HTTP status (0 for network errors).
	 * @param string $code    Error code or "accepted".
	 * @param string $message Error message.
	 * @param int    $ms      Duration in milliseconds.
	 * @return array
	 */
	private static function result( $ok, $status, $code, $message, $ms ) {
		return array(
			'ok'          => $ok,
			'status'      => $status,
			'code'        => $code,
			'message'     => $message,
			'id'          => '',
			'duplicate'   => false,
			'retryable'   => false,
			'retry_after' => 0,
			'ms'          => $ms,
		);
	}

	/**
	 * The server's public configuration (GET /v1/config), cached for 12 hours.
	 *
	 * @param string $server Server URL without trailing slash.
	 * @param bool   $force  Skip the cache.
	 * @return array Empty when the server cannot be reached.
	 */
	public static function server_config( $server, $force = false ) {
		$transient = self::CONFIG_TRANSIENT . md5( $server );
		if ( ! $force ) {
			$cached = get_transient( $transient );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}
		$response = wp_remote_get(
			$server . '/v1/config',
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => self::user_agent(),
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $transient, array(), 15 * MINUTE_IN_SECONDS );
			return array();
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		set_transient( $transient, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Whether the server reports a capability, e.g. "heartbeats".
	 *
	 * @param string $server     Server URL.
	 * @param string $capability Key in /v1/config.
	 * @param bool   $fetch      Ask the server when nothing is cached. False on page loads, so a
	 *                           visitor's request never waits for Honk.
	 * @return bool
	 */
	public static function server_supports( $server, $capability, $fetch = true ) {
		if ( $fetch ) {
			$config = self::server_config( $server );
		} else {
			$config = get_transient( self::CONFIG_TRANSIENT . md5( $server ) );
		}
		return is_array( $config ) && isset( $config[ $capability ] ) && true === $config[ $capability ];
	}
}
