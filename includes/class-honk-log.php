<?php
/**
 * The delivery log: the last 50 sends, for debugging.
 *
 * @package Honk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores one row per queued message, updated as it is delivered.
 */
final class Honk_Log {

	const OPTION = 'honk_log';

	const MAX = 50;

	/**
	 * Adds or updates the row of a job.
	 *
	 * @param string $job_id Job id.
	 * @param array  $fields Fields to set: time, event, title, status, http, code, detail,
	 *                       attempts, message_id, pii.
	 * @return void
	 */
	public static function record( $job_id, array $fields ) {
		$rows  = self::all();
		$found = false;
		foreach ( $rows as $i => $row ) {
			if ( isset( $row['job'] ) && $row['job'] === $job_id ) {
				$rows[ $i ] = array_merge( $row, $fields, array( 'updated' => time() ) );
				$found      = true;
				break;
			}
		}
		if ( ! $found ) {
			array_unshift(
				$rows,
				array_merge(
					array(
						'job'        => $job_id,
						'time'       => time(),
						'updated'    => time(),
						'event'      => '',
						'title'      => '',
						'status'     => 'queued',
						'http'       => 0,
						'code'       => '',
						'detail'     => '',
						'attempts'   => 0,
						'message_id' => '',
						'pii'        => false,
					),
					$fields
				)
			);
		}
		update_option( self::OPTION, array_slice( $rows, 0, self::MAX ), false );
	}

	/**
	 * All rows, newest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Empties the log.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Removes titles that may contain personal data (used when the privacy switch is turned off).
	 *
	 * @return void
	 */
	public static function redact() {
		$rows    = self::all();
		$changed = false;
		foreach ( $rows as $i => $row ) {
			if ( ! empty( $row['pii'] ) ) {
				$rows[ $i ]['title'] = '';
				$rows[ $i ]['pii']   = false;
				$changed             = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $rows, false );
		}
	}
}
