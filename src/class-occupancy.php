<?php

namespace Mlyn_Event;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Occupancy {
	public const CAPACITY         = '_mlyn_event_capacity';
	public const AVAILABLE_PLACES = '_mlyn_event_available_places';
	public const NOTE             = '_mlyn_event_occupancy_note';

	public static function register_meta(): void {
		foreach ( array( self::CAPACITY, self::AVAILABLE_PLACES ) as $meta_key ) {
			register_post_meta(
				'tribe_events',
				$meta_key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => static function ( $value ): int {
						return max( 0, (int) $value );
					},
					'auth_callback'     => static function ( $allowed, $key, $post_id ): bool {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}

		register_post_meta(
			'tribe_events',
			self::NOTE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => static function ( $allowed, $key, $post_id ): bool {
					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}

	public static function register_meta_box(): void {
		add_meta_box(
			'mlyn-event-occupancy',
			__( 'Obsazenost', 'mlyn-event' ),
			array( self::class, 'render_meta_box' ),
			'tribe_events',
			'side',
			'low'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		$occupancy = self::get( $post->ID );
		wp_nonce_field( 'mlyn_event_save_occupancy_' . $post->ID, 'mlyn_event_occupancy_nonce' );
		?>
		<p><label for="mlyn-event-capacity"><strong><?php esc_html_e( 'Kapacita', 'mlyn-event' ); ?></strong></label><br><input id="mlyn-event-capacity" class="widefat" type="number" min="0" step="1" name="mlyn_event_capacity" value="<?php echo esc_attr( null === $occupancy['capacity'] ? '' : (string) $occupancy['capacity'] ); ?>"></p>
		<p><label for="mlyn-event-available"><strong><?php esc_html_e( 'Volná místa', 'mlyn-event' ); ?></strong></label><br><input id="mlyn-event-available" class="widefat" type="number" min="0" step="1" name="mlyn_event_available_places" value="<?php echo esc_attr( null === $occupancy['available_places'] ? '' : (string) $occupancy['available_places'] ); ?>"></p>
		<p><label for="mlyn-event-occupancy-note"><strong><?php esc_html_e( 'Poznámka k obsazenosti', 'mlyn-event' ); ?></strong></label><br><textarea id="mlyn-event-occupancy-note" class="widefat" rows="3" maxlength="500" name="mlyn_event_occupancy_note"><?php echo esc_textarea( $occupancy['note'] ); ?></textarea></p>
		<p class="description"><?php esc_html_e( 'Pole mohou zůstat prázdná. Nula volných míst označí akci jako obsazenou i bez uvedené kapacity.', 'mlyn-event' ); ?></p>
		<?php
	}

	public static function save_from_editor( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'tribe_events' !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['mlyn_event_occupancy_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mlyn_event_occupancy_nonce'] ) ), 'mlyn_event_save_occupancy_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$capacity_valid  = true;
		$available_valid = true;
		$capacity        = self::parse_nullable_count( $_POST['mlyn_event_capacity'] ?? '', $capacity_valid );
		$available       = self::parse_nullable_count( $_POST['mlyn_event_available_places'] ?? '', $available_valid );
		$note            = mb_substr( sanitize_textarea_field( (string) wp_unslash( $_POST['mlyn_event_occupancy_note'] ?? '' ) ), 0, 500 );
		if ( ! $capacity_valid || ! $available_valid ) {
			self::flag_validation_error();
			return;
		}

		$result = self::set( $post_id, $capacity, $available, $note );
		if ( is_wp_error( $result ) ) {
			self::flag_validation_error();
		}
	}

	public static function get( int $event_id ): array {
		$capacity  = metadata_exists( 'post', $event_id, self::CAPACITY ) ? max( 0, (int) get_post_meta( $event_id, self::CAPACITY, true ) ) : null;
		$available = metadata_exists( 'post', $event_id, self::AVAILABLE_PLACES ) ? max( 0, (int) get_post_meta( $event_id, self::AVAILABLE_PLACES, true ) ) : null;
		$note      = metadata_exists( 'post', $event_id, self::NOTE ) ? (string) get_post_meta( $event_id, self::NOTE, true ) : '';

		return array(
			'capacity'         => $capacity,
			'available_places' => $available,
			'note'             => $note,
			'fully_occupied'   => null !== $available && 0 === $available,
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function set( int $event_id, ?int $capacity, ?int $available, string $note, bool $notify = true ) {
		if ( 'tribe_events' !== get_post_type( $event_id ) ) {
			return new WP_Error( 'mlyn_event_invalid_event', __( 'Zadaná akce neexistuje.', 'mlyn-event' ) );
		}
		if ( ( null !== $capacity && $capacity < 0 ) || ( null !== $available && $available < 0 ) || ( null !== $capacity && null !== $available && $available > $capacity ) ) {
			return new WP_Error( 'mlyn_event_invalid_occupancy', __( 'Obsazenost obsahuje neplatné hodnoty.', 'mlyn-event' ) );
		}

		$note = mb_substr( sanitize_textarea_field( $note ), 0, 500 );
		self::set_nullable_meta( $event_id, self::CAPACITY, $capacity );
		self::set_nullable_meta( $event_id, self::AVAILABLE_PLACES, $available );
		self::set_nullable_meta( $event_id, self::NOTE, '' === $note ? null : $note );

		if ( $notify ) {
			do_action( 'mlyn_event_occupancy_updated', $event_id, self::get( $event_id ) );
		}
		return true;
	}

	private static function parse_nullable_count( $raw, bool &$valid ): ?int {
		$value = trim( (string) wp_unslash( $raw ) );
		$valid = true;
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d+$/', $value ) ) {
			$valid = false;
			return null;
		}
		$count = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		if ( false === $count ) {
			$valid = false;
			return null;
		}
		return (int) $count;
	}

	private static function set_nullable_meta( int $event_id, string $meta_key, $value ): void {
		if ( null === $value ) {
			delete_post_meta( $event_id, $meta_key );
			return;
		}
		update_post_meta( $event_id, $meta_key, $value );
	}

	private static function flag_validation_error(): void {
		add_filter(
			'redirect_post_location',
			static function ( string $location ): string {
				return add_query_arg( 'mlyn_event_occupancy_invalid', '1', $location );
			}
		);
	}
}
