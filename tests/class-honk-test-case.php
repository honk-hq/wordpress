<?php
/**
 * Base test case: Brain Monkey plus a small in-memory WordPress (options, transients, user meta,
 * WP-Cron, the HTTP API), so whole flows (event → payload → queue → HTTP) run without WordPress.
 *
 * @package Honk
 */

// phpcs:disable

use Brain\Monkey;
use Brain\Monkey\Functions;

abstract class Honk_Test_Case extends \PHPUnit\Framework\TestCase {

	use \Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	protected $options = array();

	/** @var array<string, mixed> */
	protected $transients = array();

	/** @var array<int, array<string, mixed>> */
	protected $user_meta = array();

	/** @var array<int, array{time: int, hook: string, args: array}> */
	protected $cron = array();

	/** @var array<int, array{url: string, args: array}> HTTP requests made. */
	protected $requests = array();

	/** @var array<int, array|WP_Error> Responses returned by wp_remote_post, in order. */
	protected $responses = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options    = array();
		$this->transients = array();
		$this->user_meta  = array();
		$this->cron       = array();
		$this->requests   = array();
		$this->responses  = array();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$self = $this;
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $self ) {
				return array_key_exists( $name, $self->options ) ? $self->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( $self ) {
				$self->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) use ( $self ) {
				if ( array_key_exists( $name, $self->options ) ) {
					return false;
				}
				$self->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( $self ) {
				unset( $self->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) use ( $self ) {
				return array_key_exists( $name, $self->transients ) ? $self->transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) use ( $self ) {
				$self->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			function ( $id, $key ) use ( $self ) {
				return isset( $self->user_meta[ $id ][ $key ] ) ? $self->user_meta[ $id ][ $key ] : '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( $id, $key, $value ) use ( $self ) {
				$self->user_meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $time, $hook, $args = array() ) use ( $self ) {
				$self->cron[] = array(
					'time' => $time,
					'hook' => $hook,
					'args' => $args,
				);
				return true;
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook, $args = array() ) use ( $self ) {
				foreach ( $self->cron as $event ) {
					if ( $event['hook'] === $hook && $event['args'] === $args ) {
						return $event['time'];
					}
				}
				return false;
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( $self ) {
				$self->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $self->responses ? array_shift( $self->responses ) : $self->response( 202, array( 'id' => 'msg_1', 'status' => 'accepted', 'duplicate' => false ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $response ) {
				return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $response ) {
				return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			function ( $response, $header ) {
				return is_array( $response ) && isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			function ( $thing ) {
				return $thing instanceof WP_Error;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.com' );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://shop.example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $value ) {
				return rtrim( (string) $value, '/\\' );
			}
		);
		Functions\when( 'wp_generate_uuid4' )->alias(
			function () {
				return sprintf( '%08x-%04x-4%03x-a%03x-%012x', mt_rand(), mt_rand( 0, 0xffff ), mt_rand( 0, 0xfff ), mt_rand( 0, 0xfff ), mt_rand() * mt_rand() );
			}
		);
		Functions\when( 'wp_rand' )->alias(
			function ( $min = 0, $max = 0 ) {
				return mt_rand( (int) $min, (int) $max );
			}
		);
		Functions\when( 'wp_hash' )->alias(
			function ( $data ) {
				return md5( 'salt' . $data );
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
		Functions\when( 'sanitize_user' )->alias(
			function ( $user ) {
				return trim( (string) $user );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'is_textdomain_loaded' )->justReturn( true );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->justReturn( new WP_User( 1, 'admin', array( 'administrator' ) ) );
		Functions\when( 'wp_date' )->alias(
			function ( $format, $timestamp = null ) {
				return gmdate( $format, null === $timestamp ? time() : $timestamp );
			}
		);
		Functions\when( 'human_time_diff' )->alias(
			function ( $from, $to ) {
				$minutes = (int) round( abs( $to - $from ) / 60 );
				return $minutes . ' ' . ( 1 === $minutes ? 'min' : 'mins' );
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, $decimals );
			}
		);

		Honk_Settings::flush();
		Honk_Dedupe::reset_request();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Saves an ingestion key and a server, so events are sent.
	 *
	 * @param array $extra Other settings.
	 */
	protected function configure( array $extra = array() ) {
		$this->options['honk_settings'] = array_merge(
			array(
				'server_url' => 'https://honk.example.com',
				'api_key'    => 'honk_ab12cd34_secretsecretsecret',
			),
			$extra
		);
		$this->options['honk_site_id']  = 'abcd1234';
		Honk_Settings::flush();
	}

	/**
	 * A wp_remote_* style response.
	 *
	 * @param int   $code    HTTP status.
	 * @param array $body    JSON body.
	 * @param array $headers Headers.
	 * @return array
	 */
	public function response( $code, array $body = array(), array $headers = array() ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => json_encode( $body ),
			'headers'  => $headers,
		);
	}

	/**
	 * Queued jobs (options honk_job_*).
	 *
	 * @return array<string, array>
	 */
	protected function jobs() {
		$jobs = array();
		foreach ( $this->options as $name => $value ) {
			if ( 0 === strpos( $name, 'honk_job_' ) ) {
				$jobs[ substr( $name, 9 ) ] = $value;
			}
		}
		return $jobs;
	}

	/**
	 * The decoded payloads of the queued jobs, in order.
	 *
	 * @return array[]
	 */
	protected function queued_payloads() {
		return array_values(
			array_map(
				function ( $job ) {
					return json_decode( $job['body'], true );
				},
				$this->jobs()
			)
		);
	}
}
