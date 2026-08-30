<?php

namespace Mlyn_Event;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Event_Duplicator {
	/**
	 * @return int|WP_Error
	 */
	public function duplicate( int $event_id ) {
		$source = get_post( $event_id );
		if ( ! $source instanceof WP_Post || 'tribe_events' !== $source->post_type ) {
			return new WP_Error( 'mlyn_event_invalid_source', __( 'Zdrojová akce neexistuje.', 'mlyn-event' ) );
		}
		if ( ! function_exists( 'tribe_events' ) ) {
			return new WP_Error( 'mlyn_event_tec_unavailable', __( 'Plugin The Events Calendar není aktivní.', 'mlyn-event' ) );
		}

		$start = (string) get_post_meta( $event_id, '_EventStartDate', true );
		$end   = (string) get_post_meta( $event_id, '_EventEndDate', true );
		if ( '' === $start || '' === $end ) {
			return new WP_Error( 'mlyn_event_missing_dates', __( 'Zdrojové akci chybí datum začátku nebo konce.', 'mlyn-event' ) );
		}

		$args = array(
			'title'                => sprintf( __( '%s – kopie', 'mlyn-event' ), $source->post_title ),
			'description'          => $source->post_content,
			'excerpt'              => $source->post_excerpt,
			'author'               => (int) $source->post_author,
			'status'               => 'draft',
			'start_date'           => $start,
			'end_date'             => $end,
			'all_day'              => tribe_event_is_all_day( $event_id ),
			'timezone'             => (string) get_post_meta( $event_id, '_EventTimezone', true ),
			'venue'                => (int) get_post_meta( $event_id, '_EventVenueID', true ),
			'organizer'            => $this->get_organizer_ids( $event_id ),
			'url'                  => (string) get_post_meta( $event_id, '_EventURL', true ),
			'image'                => get_post_thumbnail_id( $event_id ),
			'tag'                  => wp_get_object_terms( $event_id, 'post_tag', array( 'fields' => 'ids' ) ),
			'category'             => wp_get_object_terms( $event_id, 'tribe_events_cat', array( 'fields' => 'ids' ) ),
			'currency_symbol'      => (string) get_post_meta( $event_id, '_EventCurrencySymbol', true ),
			'currency_position'    => (string) get_post_meta( $event_id, '_EventCurrencyPosition', true ),
			'hide_from_upcoming'   => tribe_is_truthy( get_post_meta( $event_id, '_EventHideFromUpcoming', true ) ),
			'featured'             => tribe_is_truthy( get_post_meta( $event_id, '_tribe_featured', true ) ),
			'sticky'               => (int) $source->menu_order < 0,
			'show_map'             => tribe_is_truthy( get_post_meta( $event_id, '_EventShowMap', true ) ),
			'show_map_link'        => tribe_is_truthy( get_post_meta( $event_id, '_EventShowMapLink', true ) ),
		);
		if ( metadata_exists( 'post', $event_id, '_EventCost' ) ) {
			$args['cost'] = get_post_meta( $event_id, '_EventCost', true );
		}

		$created   = tribe_events()->set_args( $args )->create();
		$duplicate = $created instanceof WP_Post ? $created : get_post( (int) $created );
		if ( ! $duplicate instanceof WP_Post ) {
			return new WP_Error( 'mlyn_event_duplicate_failed', __( 'Akci se nepodařilo duplikovat.', 'mlyn-event' ) );
		}

		$this->copy_supported_extras( $event_id, $duplicate->ID );
		$occupancy = Occupancy::get( $event_id );
		$result    = Occupancy::set( $duplicate->ID, $occupancy['capacity'], $occupancy['available_places'], $occupancy['note'] );
		if ( is_wp_error( $result ) ) {
			wp_delete_post( $duplicate->ID, true );
			return $result;
		}
		$focal_point = Image_Focal_Point::get( $event_id );
		$result      = Image_Focal_Point::set(
			$duplicate->ID,
			$focal_point['specified'] ? $focal_point['x'] : null,
			$focal_point['specified'] ? $focal_point['y'] : null
		);
		if ( is_wp_error( $result ) ) {
			wp_delete_post( $duplicate->ID, true );
			return $result;
		}

		do_action( 'mlyn_event_duplicated', $duplicate->ID, $event_id );
		return $duplicate->ID;
	}

	private function get_organizer_ids( int $event_id ): array {
		$ids = array();
		array_walk_recursive(
			get_post_meta( $event_id, '_EventOrganizerID', false ),
			static function ( $value ) use ( &$ids ): void {
				if ( absint( $value ) ) {
					$ids[] = absint( $value );
				}
			}
		);
		return array_values( array_unique( $ids ) );
	}

	private function copy_supported_extras( int $source_id, int $duplicate_id ): void {
		wp_set_object_terms( $duplicate_id, wp_get_object_terms( $source_id, 'post_tag', array( 'fields' => 'ids' ) ), 'post_tag', false );
		wp_set_object_terms( $duplicate_id, wp_get_object_terms( $source_id, 'tribe_events_cat', array( 'fields' => 'ids' ) ), 'tribe_events_cat', false );

		foreach ( array( '_EventCurrencyCode', '_tribe_events_status', '_tribe_events_status_reason' ) as $meta_key ) {
			if ( metadata_exists( 'post', $source_id, $meta_key ) ) {
				update_post_meta( $duplicate_id, $meta_key, get_post_meta( $source_id, $meta_key, true ) );
			} else {
				delete_post_meta( $duplicate_id, $meta_key );
			}
		}

		$thumbnail_id = get_post_thumbnail_id( $source_id );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $duplicate_id, $thumbnail_id );
		} else {
			delete_post_thumbnail( $duplicate_id );
		}
	}
}
