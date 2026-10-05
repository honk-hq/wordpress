<?php
/**
 * Minimal WordPress classes the plugin type-checks against.
 *
 * @package Honk
 */

// phpcs:disable

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public $ID              = 0;
		public $user_login      = '';
		public $display_name    = '';
		public $user_email      = '';
		public $user_registered = '2020-01-01 00:00:00';
		public $roles           = array();
		public function __construct( $id = 0, $login = '', $roles = array() ) {
			$this->ID         = $id;
			$this->user_login = $login;
			$this->roles      = $roles;
		}
		public function exists() {
			return $this->ID > 0;
		}
	}
}
