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

if ( ! class_exists( 'WP_Comment' ) ) {
	class WP_Comment {
		public $comment_ID           = 0;
		public $comment_post_ID      = 0;
		public $comment_author       = '';
		public $comment_author_email = '';
		public $comment_content      = '';
		public $comment_approved     = '1';
		public $comment_type         = 'comment';
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $name => $value ) {
				$this->$name = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID                = 0;
		public $post_type         = 'post';
		public $post_author       = 0;
		public $post_title        = '';
		public $post_content      = '';
		public $post_excerpt      = '';
		public $post_status       = 'draft';
		public $post_modified_gmt = '2026-10-05 09:00:00';
		public function __construct( array $fields = array() ) {
			foreach ( $fields as $name => $value ) {
				$this->$name = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Theme' ) ) {
	class WP_Theme {
		private $data;
		public function __construct( array $data = array() ) {
			$this->data = $data;
		}
		public function get( $header ) {
			return isset( $this->data[ $header ] ) ? $this->data[ $header ] : false;
		}
		public function exists() {
			return ! empty( $this->data );
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
