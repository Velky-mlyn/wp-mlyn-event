<?php

namespace Mlyn_Event;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Image_Focal_Point {
	public const X             = '_mlyn_event_detail_image_focal_x';
	public const Y             = '_mlyn_event_detail_image_focal_y';
	public const ATTACHMENT_ID = '_mlyn_event_detail_image_focal_attachment_id';

	public static function register_meta(): void {
		foreach ( array( self::X, self::Y, self::ATTACHMENT_ID ) as $meta_key ) {
			register_post_meta(
				'tribe_events',
				$meta_key,
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'absint',
					'auth_callback'     => static function ( $allowed, $key, $post_id ): bool {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
	}

	public static function register_meta_box(): void {
		add_meta_box(
			'mlyn-event-image-focal-point',
			__( 'Výřez obrázku na detailu akce', 'mlyn-event' ),
			array( self::class, 'render_meta_box' ),
			'tribe_events',
			'side',
			'low'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		$point      = self::get( $post->ID );
		$image_id   = get_post_thumbnail_id( $post->ID );
		$image_url  = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';
		$image_url  = $image_url ?: ( $image_id ? wp_get_attachment_url( $image_id ) : '' );
		wp_nonce_field( 'mlyn_event_save_image_focal_point_' . $post->ID, 'mlyn_event_image_focal_point_nonce' );
		?>
		<div id="mlyn-event-image-focal-point-control"
			data-image-id="<?php echo esc_attr( (string) $image_id ); ?>"
			data-image-url="<?php echo esc_url( (string) $image_url ); ?>"
			data-x="<?php echo esc_attr( (string) $point['x'] ); ?>"
			data-y="<?php echo esc_attr( (string) $point['y'] ); ?>">
			<input type="hidden" name="mlyn_event_image_focal_x" value="<?php echo esc_attr( $point['specified'] ? (string) $point['x'] : '' ); ?>">
			<input type="hidden" name="mlyn_event_image_focal_y" value="<?php echo esc_attr( $point['specified'] ? (string) $point['y'] : '' ); ?>">
			<div class="mlyn-event-focal-point-root"></div>
		</div>
		<?php
	}

	public static function save_from_editor( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'tribe_events' !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST['mlyn_event_image_focal_point_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mlyn_event_image_focal_point_nonce'] ) ), 'mlyn_event_save_image_focal_point_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$x = self::parse_coordinate( $_POST['mlyn_event_image_focal_x'] ?? '' );
		$y = self::parse_coordinate( $_POST['mlyn_event_image_focal_y'] ?? '' );
		if ( false === $x || false === $y || ( null === $x ) !== ( null === $y ) ) {
			self::flag_validation_error();
			return;
		}
		$result = self::set( $post_id, $x, $y );
		if ( is_wp_error( $result ) ) {
			self::flag_validation_error();
		}
	}

	public static function get( int $event_id ): array {
		$current_image = get_post_thumbnail_id( $event_id );
		$stored_image  = (int) get_post_meta( $event_id, self::ATTACHMENT_ID, true );
		$specified     = $current_image > 0
			&& $current_image === $stored_image
			&& metadata_exists( 'post', $event_id, self::X )
			&& metadata_exists( 'post', $event_id, self::Y );
		$x             = $specified ? min( 100, max( 0, (int) get_post_meta( $event_id, self::X, true ) ) ) : 50;
		$y             = $specified ? min( 100, max( 0, (int) get_post_meta( $event_id, self::Y, true ) ) ) : 50;

		return array(
			'x'             => $x,
			'y'             => $y,
			'specified'     => $specified,
			'attachment_id' => $current_image,
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function set( int $event_id, ?int $x, ?int $y, bool $notify = true ) {
		if ( 'tribe_events' !== get_post_type( $event_id ) ) {
			return new WP_Error( 'mlyn_event_invalid_event', __( 'Zadaná akce neexistuje.', 'mlyn-event' ) );
		}
		if ( ( null === $x ) !== ( null === $y ) || ( null !== $x && ( $x < 0 || $x > 100 || $y < 0 || $y > 100 ) ) ) {
			return new WP_Error( 'mlyn_event_invalid_focal_point', __( 'Bod výřezu obsahuje neplatné hodnoty.', 'mlyn-event' ) );
		}

		$image_id = get_post_thumbnail_id( $event_id );
		if ( ! $image_id || null === $x ) {
			self::clear( $event_id );
		} else {
			update_post_meta( $event_id, self::X, $x );
			update_post_meta( $event_id, self::Y, $y );
			update_post_meta( $event_id, self::ATTACHMENT_ID, $image_id );
		}

		if ( $notify ) {
			do_action( 'mlyn_event_image_focal_point_updated', $event_id, self::get( $event_id ) );
		}
		return true;
	}

	private static function clear( int $event_id ): void {
		delete_post_meta( $event_id, self::X );
		delete_post_meta( $event_id, self::Y );
		delete_post_meta( $event_id, self::ATTACHMENT_ID );
	}

	/**
	 * @return int|null|false
	 */
	private static function parse_coordinate( $raw ) {
		$value = trim( (string) wp_unslash( $raw ) );
		if ( '' === $value ) {
			return null;
		}
		if ( ! preg_match( '/^\d{1,3}$/', $value ) ) {
			return false;
		}
		$coordinate = (int) $value;
		return $coordinate <= 100 ? $coordinate : false;
	}

	private static function flag_validation_error(): void {
		add_filter(
			'redirect_post_location',
			static function ( string $location ): string {
				return add_query_arg( 'mlyn_event_focal_point_invalid', '1', $location );
			}
		);
	}
}
