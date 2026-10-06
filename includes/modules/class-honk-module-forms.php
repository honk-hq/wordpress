<?php
/**
 * Form submissions: Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Ninja Forms.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Forms module. Each form plugin is detected by its own hook: a hook that never fires costs nothing.
 */
final class Honk_Module_Forms {

	/**
	 * Field types never included in a message.
	 */
	const SKIP_TYPES = array( 'password', 'hidden', 'captcha', 'recaptcha', 'hcaptcha', 'turnstile', 'cloudflare_turnstile', 'honeypot', 'submit', 'html', 'divider', 'pagebreak', 'page-break', 'section', 'step', 'quiz', 'acceptance', 'gdpr', 'gdpr-checkbox', 'gdpr_agreement', 'consent', 'signature', 'spam', 'akismet', 'internal_information', 'custom_html', 'shortcode' );

	/**
	 * Characters per field value in a message.
	 */
	const MAX_VALUE_CHARS = 500;

	/**
	 * Forms listed per plugin on the settings screen (to leave fields out).
	 */
	const MAX_LISTED_FORMS = 20;

	/**
	 * Forms listed in this request, by event id.
	 *
	 * @var array<string, array>
	 */
	private static $listed = array();

	/**
	 * Id of this request, for submissions that have no id of their own.
	 *
	 * @var string
	 */
	private static $request_id = '';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wpcf7_submit', array( __CLASS__, 'on_cf7' ), 20, 2 );
		add_action( 'wpforms_process_complete', array( __CLASS__, 'on_wpforms' ), 20, 4 );
		add_action( 'gform_after_submission', array( __CLASS__, 'on_gravityforms' ), 20, 2 );
		add_action( 'elementor_pro/forms/new_record', array( __CLASS__, 'on_elementor' ), 20, 2 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'on_fluentforms' ), 20, 3 );
		add_action( 'ninja_forms_after_submission', array( __CLASS__, 'on_ninjaforms' ), 20, 1 );
	}

	/**
	 * Contact Form 7 (mail sent, or mail failed: the submission itself was valid).
	 *
	 * @param WPCF7_ContactForm $form   Form.
	 * @param array             $result Result: status.
	 * @return void
	 */
	public static function on_cf7( $form, $result ) {
		$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) || ! is_object( $form ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$types = array();
		if ( method_exists( $form, 'scan_form_tags' ) ) {
			foreach ( $form->scan_form_tags() as $tag ) {
				if ( ! empty( $tag->name ) ) {
					$types[ $tag->name ] = $tag->basetype;
				}
			}
		}
		$fields = array();
		foreach ( (array) $submission->get_posted_data() as $name => $value ) {
			$fields[] = array(
				'key'   => (string) $name,
				'label' => self::humanize( (string) $name ),
				'value' => $value,
				'type'  => isset( $types[ $name ] ) ? $types[ $name ] : '',
			);
		}
		$stamp = method_exists( $submission, 'get_meta' ) ? (string) $submission->get_meta( 'timestamp' ) : '';
		self::report( 'form_cf7', 'cf7', (string) $form->id(), (string) $form->title(), $fields, md5( $stamp . wp_json_encode( $submission->get_posted_data() ) ) );
	}

	/**
	 * WPForms.
	 *
	 * @param array $fields    Fields: id => name, value, type.
	 * @param array $entry     Raw entry.
	 * @param array $form_data Form settings.
	 * @param int   $entry_id  Entry id (0 when entries are not stored).
	 * @return void
	 */
	public static function on_wpforms( $fields, $entry, $form_data, $entry_id = 0 ) {
		unset( $entry );
		$rows = array();
		foreach ( (array) $fields as $id => $field ) {
			if ( is_array( $field ) ) {
				$rows[] = array(
					'key'   => isset( $field['id'] ) ? (string) $field['id'] : (string) $id,
					'label' => isset( $field['name'] ) ? $field['name'] : '',
					'value' => isset( $field['value'] ) ? $field['value'] : '',
					'type'  => isset( $field['type'] ) ? $field['type'] : '',
				);
			}
		}
		$form_id = isset( $form_data['id'] ) ? (string) $form_data['id'] : '0';
		$title   = isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '';
		self::report( 'form_wpforms', 'wpforms', $form_id, $title, $rows, $entry_id ? 'entry-' . $entry_id : '' );
	}

	/**
	 * Gravity Forms.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return void
	 */
	public static function on_gravityforms( $entry, $form ) {
		if ( ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}
		$rows = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $field ) {
			if ( ! is_object( $field ) || ! isset( $field->id ) ) {
				continue;
			}
			$value  = method_exists( $field, 'get_value_export' ) ? $field->get_value_export( $entry, (string) $field->id, true ) : ( isset( $entry[ (string) $field->id ] ) ? $entry[ (string) $field->id ] : '' );
			$rows[] = array(
				'key'   => (string) $field->id,
				'label' => isset( $field->label ) ? (string) $field->label : '',
				'value' => $value,
				'type'  => isset( $field->type ) ? (string) $field->type : '',
			);
		}
		$entry_id = isset( $entry['id'] ) ? 'entry-' . $entry['id'] : '';
		self::report( 'form_gravityforms', 'gravityforms', isset( $form['id'] ) ? (string) $form['id'] : '0', isset( $form['title'] ) ? (string) $form['title'] : '', $rows, $entry_id );
	}

	/**
	 * Elementor Pro Forms.
	 *
	 * @param object $record  Form record.
	 * @param object $handler Ajax handler.
	 * @return void
	 */
	public static function on_elementor( $record, $handler ) {
		unset( $handler );
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$rows = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			if ( is_array( $field ) ) {
				$rows[] = array(
					'key'   => (string) $id,
					'label' => ! empty( $field['title'] ) ? $field['title'] : self::humanize( (string) $id ),
					'value' => isset( $field['value'] ) ? $field['value'] : '',
					'type'  => isset( $field['type'] ) ? $field['type'] : '',
				);
			}
		}
		$form_id = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'id' ) : '0';
		$title   = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		self::report( 'form_elementor', 'elementor', $form_id, $title, $rows, '' );
	}

	/**
	 * Fluent Forms.
	 *
	 * @param int    $entry_id  Entry id.
	 * @param array  $form_data Submitted values: name => value.
	 * @param object $form      Form.
	 * @return void
	 */
	public static function on_fluentforms( $entry_id, $form_data, $form ) {
		$rows = array();
		foreach ( (array) $form_data as $name => $value ) {
			if ( 0 === strpos( (string) $name, '_' ) ) {
				continue; // _wp_http_referer, _fluentform_*_fluentformnonce…
			}
			$rows[] = array(
				'key'   => (string) $name,
				'label' => self::humanize( (string) $name ),
				'value' => $value,
				'type'  => false !== strpos( (string) $name, 'password' ) ? 'password' : '',
			);
		}
		$form_id = is_object( $form ) && isset( $form->id ) ? (string) $form->id : '0';
		$title   = is_object( $form ) && isset( $form->title ) ? (string) $form->title : '';
		self::report( 'form_fluentforms', 'fluentforms', $form_id, $title, $rows, 'entry-' . (int) $entry_id );
	}

	/**
	 * Ninja Forms.
	 *
	 * @param array $form_data Submission: form_id, settings, fields, actions.
	 * @return void
	 */
	public static function on_ninjaforms( $form_data ) {
		if ( ! is_array( $form_data ) ) {
			return;
		}
		$rows = array();
		foreach ( isset( $form_data['fields'] ) ? (array) $form_data['fields'] : array() as $field ) {
			if ( is_array( $field ) ) {
				$rows[] = array(
					'key'   => isset( $field['key'] ) ? (string) $field['key'] : '',
					'label' => isset( $field['label'] ) ? $field['label'] : ( isset( $field['key'] ) ? $field['key'] : '' ),
					'value' => isset( $field['value'] ) ? $field['value'] : '',
					'type'  => isset( $field['type'] ) ? $field['type'] : '',
				);
			}
		}
		$form_id = isset( $form_data['form_id'] ) ? (string) $form_data['form_id'] : '0';
		$title   = isset( $form_data['settings']['title'] ) ? (string) $form_data['settings']['title'] : '';
		$sub_id  = isset( $form_data['actions']['save']['sub_id'] ) ? 'entry-' . $form_data['actions']['save']['sub_id'] : '';
		self::report( 'form_ninjaforms', 'ninjaforms', $form_id, $title, $rows, $sub_id );
	}

	/**
	 * Queues the message of a submission. The form name is the title; field values are included
	 * only when the privacy setting allows personal data, without the fields left out in the
	 * event's details.
	 *
	 * @param string $event_id Event id.
	 * @param string $plugin   Plugin slug for the group key.
	 * @param string $form_id  Form id.
	 * @param string $title    Form name.
	 * @param array  $rows     Fields: key, label, value, type.
	 * @param string $entry    Submission id, or '' to use this request's id.
	 * @return void
	 */
	public static function report( $event_id, $plugin, $form_id, $title, array $rows, $entry ) {
		$form_id = preg_replace( '/[^A-Za-z0-9_-]/', '', $form_id );
		$form_id = '' !== $form_id ? $form_id : '0';
		if ( '' === $entry ) {
			$entry = self::request_id();
		}
		Honk_Notifier::emit(
			$event_id,
			'form-' . $plugin . '-' . $form_id . '-' . $entry,
			function () use ( $event_id, $plugin, $form_id, $title, $rows ) {
				$name  = '' !== trim( $title ) ? Honk_Payload::plain( $title, true ) : __( 'Form', 'honk-me' );
				$lines = Honk_Details::on( $event_id, 'values' ) ? self::field_lines( self::without_skipped( $event_id, $form_id, $rows ) ) : array();
				return Honk_Details::fields(
					$event_id,
					self::form_spec( $name, $lines ),
					array(
						'group_key' => 'wp/forms/' . $plugin . '/' . $form_id,
						'metadata'  => array(
							'form_plugin' => $plugin,
							'form_id'     => $form_id,
						),
					)
				);
			}
		);
	}

	/**
	 * The rows without the fields left out of this form's notifications.
	 *
	 * @param string $event_id Event id.
	 * @param string $form_id  Form id.
	 * @param array  $rows     Fields: key, label, value, type.
	 * @return array
	 */
	public static function without_skipped( $event_id, $form_id, array $rows ) {
		$skip = Honk_Details::skipped_fields( $event_id, Honk_Details::form_key( $form_id ) );
		if ( empty( $skip ) ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $skip ) {
					return ! isset( $row['key'] ) || ! in_array( Honk_Details::field_key( $row['key'] ), $skip, true );
				}
			)
		);
	}

	/**
	 * A form submission's outline (Honk_Details): the same for real entries and for the settings
	 * preview.
	 *
	 * @param string   $name  Form name.
	 * @param string[] $lines field_lines().
	 * @return array
	 */
	public static function form_spec( $name, array $lines ) {
		$spec = array(
			'title'    => array( Honk_Details::part( $name ) ),
			'lines'    => array(),
			/* translators: %s: form name */
			'fallback' => sprintf( __( 'Someone sent the “%s” form.', 'honk-me' ), $name ),
		);
		foreach ( $lines as $line ) {
			$spec['lines'][] = Honk_Details::text( $line, 'values' );
		}
		return $spec;
	}

	/**
	 * The forms of a form plugin and their fields, for leaving fields out on the settings screen.
	 * Contact Form 7, WPForms and Gravity Forms list them; the other plugins return nothing.
	 *
	 * @param string $event_id Event id.
	 * @return array<string, array{title: string, fields: array<string, array{label: string, type: string}>}>
	 */
	public static function forms( $event_id ) {
		if ( ! isset( self::$listed[ $event_id ] ) ) {
			self::$listed[ $event_id ] = self::list_forms( $event_id );
		}
		return self::$listed[ $event_id ];
	}

	/**
	 * Forgets the listed forms (tests).
	 *
	 * @return void
	 */
	public static function flush_forms() {
		self::$listed = array();
	}

	/**
	 * Asks the form plugin for its forms (forms()).
	 *
	 * @param string $event_id Event id.
	 * @return array
	 */
	private static function list_forms( $event_id ) {
		try {
			switch ( $event_id ) {
				case 'form_cf7':
					return self::cf7_forms();
				case 'form_wpforms':
					return self::wpforms_forms();
				case 'form_gravityforms':
					return self::gravityforms_forms();
			}
		} catch ( Throwable $e ) {
			return array(); // A form plugin's API changed: the screen still works, without the list.
		}
		return array();
	}

	/**
	 * Whether a field type is listed (fields that never carry an answer are not).
	 *
	 * @param string $type Field type.
	 * @return bool
	 */
	private static function listed_type( $type ) {
		return ! in_array( strtolower( (string) $type ), array_merge( self::SKIP_TYPES, array( 'page' ) ), true );
	}

	/**
	 * Contact Form 7 forms (WPCF7_ContactForm::find(), form tags).
	 *
	 * @return array
	 */
	private static function cf7_forms() {
		if ( ! class_exists( 'WPCF7_ContactForm' ) || ! method_exists( 'WPCF7_ContactForm', 'find' ) ) {
			return array();
		}
		$out   = array();
		$forms = WPCF7_ContactForm::find(
			array(
				'posts_per_page' => self::MAX_LISTED_FORMS,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( (array) $forms as $form ) {
			if ( ! is_object( $form ) || ! method_exists( $form, 'scan_form_tags' ) ) {
				continue;
			}
			$fields = array();
			foreach ( (array) $form->scan_form_tags() as $tag ) {
				if ( ! is_object( $tag ) || empty( $tag->name ) || ! self::listed_type( $tag->basetype ) ) {
					continue;
				}
				$fields[ (string) $tag->name ] = array(
					'label' => self::humanize( (string) $tag->name ),
					'type'  => (string) $tag->basetype,
				);
			}
			$out[ Honk_Details::form_key( $form->id() ) ] = array(
				'title'  => Honk_Payload::plain( $form->title(), true ),
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * WPForms forms (the form handler's get(), fields in the form's JSON).
	 *
	 * @return array
	 */
	private static function wpforms_forms() {
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}
		$wpforms = wpforms();
		$handler = null;
		if ( is_object( $wpforms ) && method_exists( $wpforms, 'obj' ) ) {
			$handler = $wpforms->obj( 'form' );
		} elseif ( is_object( $wpforms ) && method_exists( $wpforms, 'get' ) ) {
			$handler = $wpforms->get( 'form' );
		}
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'get' ) ) {
			return array();
		}
		$out   = array();
		$forms = $handler->get(
			'',
			array(
				'nopaging'       => false,
				'posts_per_page' => self::MAX_LISTED_FORMS,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( (array) $forms as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$data   = function_exists( 'wpforms_decode' ) ? wpforms_decode( $post->post_content ) : json_decode( $post->post_content, true );
			$fields = array();
			foreach ( isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array() as $id => $field ) {
				if ( ! is_array( $field ) || ! self::listed_type( isset( $field['type'] ) ? $field['type'] : '' ) ) {
					continue;
				}
				$key            = isset( $field['id'] ) ? (string) $field['id'] : (string) $id;
				$label          = isset( $field['label'] ) ? Honk_Payload::plain( $field['label'], true ) : '';
				$fields[ $key ] = array(
					'label' => '' !== $label ? $label : $key,
					'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
				);
			}
			$out[ Honk_Details::form_key( $post->ID ) ] = array(
				'title'  => Honk_Payload::plain( $post->post_title, true ),
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * Gravity Forms forms (GFAPI::get_forms()).
	 *
	 * @return array
	 */
	private static function gravityforms_forms() {
		if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'get_forms' ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( (array) GFAPI::get_forms( true, false, 'id', 'ASC' ), 0, self::MAX_LISTED_FORMS ) as $form ) {
			if ( ! is_array( $form ) || ! isset( $form['id'] ) ) {
				continue;
			}
			$fields = array();
			foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $field ) {
				if ( ! is_object( $field ) || ! isset( $field->id ) || ! self::listed_type( isset( $field->type ) ? $field->type : '' ) ) {
					continue;
				}
				$label                         = isset( $field->label ) ? Honk_Payload::plain( $field->label, true ) : '';
				$fields[ (string) $field->id ] = array(
					'label' => '' !== $label ? $label : (string) $field->id,
					'type'  => isset( $field->type ) ? (string) $field->type : '',
				);
			}
			$out[ Honk_Details::form_key( $form['id'] ) ] = array(
				'title'  => isset( $form['title'] ) ? Honk_Payload::plain( $form['title'], true ) : '',
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * "Label: value" lines for the fields worth showing.
	 *
	 * @param array $rows Fields: label, value, type.
	 * @return string[]
	 */
	public static function field_lines( array $rows ) {
		$lines = array();
		foreach ( $rows as $row ) {
			$type = strtolower( (string) ( isset( $row['type'] ) ? $row['type'] : '' ) );
			if ( in_array( $type, self::SKIP_TYPES, true ) ) {
				continue;
			}
			$value = isset( $row['value'] ) ? $row['value'] : '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_filter( array_map( array( __CLASS__, 'scalar' ), self::flatten( $value ) ), 'strlen' ) );
			}
			$value = Honk_Payload::plain( self::scalar( $value ), false );
			if ( '' === $value ) {
				continue;
			}
			if ( 'file' === $type || 'file-upload' === $type || 'fileupload' === $type || 'upload' === $type ) {
				$value = __( '(file attached)', 'honk-me' );
			}
			$label   = Honk_Payload::plain( isset( $row['label'] ) ? $row['label'] : '', true );
			$value   = Honk_Payload::truncate_chars( $value, self::MAX_VALUE_CHARS );
			$lines[] = ( '' !== $label ? $label . ': ' : '' ) . ( false !== strpos( $value, "\n" ) ? "\n" . $value : $value );
		}
		return $lines;
	}

	/**
	 * Flattens nested arrays (name fields, checkboxes, addresses).
	 *
	 * @param array $values Values.
	 * @return array
	 */
	private static function flatten( array $values ) {
		$out = array();
		array_walk_recursive(
			$values,
			function ( $value ) use ( &$out ) {
				$out[] = $value;
			}
		);
		return $out;
	}

	/**
	 * A scalar as a string; anything else as ''.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function scalar( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '✓' : '';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * "your-email" → "Your email".
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	public static function humanize( $name ) {
		$name = trim( preg_replace( '/[\s_\-\[\]]+/', ' ', $name ) );
		$name = preg_replace( '/^your /i', '', $name );
		return '' === $name ? '' : ucfirst( $name );
	}

	/**
	 * A random id for this request (submissions without an id of their own).
	 *
	 * @return string
	 */
	private static function request_id() {
		if ( '' === self::$request_id ) {
			self::$request_id = 'req-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
		}
		return self::$request_id;
	}
}
