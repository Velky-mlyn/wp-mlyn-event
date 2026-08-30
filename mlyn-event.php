<?php
/**
 * Plugin Name:       Mlýn Event
 * Description:       Event occupancy and administrative tools for The Events Calendar.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  the-events-calendar
 * Author:            Velký mlýn
 * License:           GPL-2.0-or-later
 * Text Domain:       mlyn-event
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MLYN_EVENT_VERSION', '1.1.0' );
define( 'MLYN_EVENT_FILE', __FILE__ );
define( 'MLYN_EVENT_DIR', plugin_dir_path( __FILE__ ) );

require_once MLYN_EVENT_DIR . 'src/class-occupancy.php';
require_once MLYN_EVENT_DIR . 'src/class-image-focal-point.php';
require_once MLYN_EVENT_DIR . 'src/class-event-duplicator.php';
require_once MLYN_EVENT_DIR . 'src/class-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		Mlyn_Event\Plugin::instance();
	}
);

/**
 * Return the normalized occupancy values for an event.
 */
function mlyn_event_get_occupancy( int $event_id ): array {
	return Mlyn_Event\Occupancy::get( $event_id );
}

/**
 * Persist event occupancy and optionally notify integrations.
 *
 * @return true|WP_Error
 */
function mlyn_event_set_occupancy( int $event_id, ?int $capacity, ?int $available_places, string $note, bool $notify = true ) {
	return Mlyn_Event\Occupancy::set( $event_id, $capacity, $available_places, $note, $notify );
}

/**
 * Return the detail-banner focal point for an event.
 */
function mlyn_event_get_image_focal_point( int $event_id ): array {
	return Mlyn_Event\Image_Focal_Point::get( $event_id );
}

/**
 * Persist or clear the detail-banner focal point.
 *
 * @return true|WP_Error
 */
function mlyn_event_set_image_focal_point( int $event_id, ?int $x, ?int $y, bool $notify = true ) {
	return Mlyn_Event\Image_Focal_Point::set( $event_id, $x, $y, $notify );
}
