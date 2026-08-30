<?php

// Run with: wp eval-file wp-content/plugins/mlyn-event/tests/integration-smoke.php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$source_id    = 0;
$duplicate_id = 0;
$admins       = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
$assert( ! empty( $admins ), 'No administrator is available for the integration test.' );
wp_set_current_user( (int) $admins[0] );

try {
	$venue_ids     = get_posts( array( 'post_type' => 'tribe_venue', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	$organizer_ids = get_posts( array( 'post_type' => 'tribe_organizer', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	$tag_ids       = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
	$category_ids  = get_terms( array( 'taxonomy' => 'tribe_events_cat', 'hide_empty' => false, 'number' => 1, 'fields' => 'ids' ) );
	$image_ids     = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	$assert( ! empty( $image_ids ), 'No image attachment is available for the focal-point test.' );
	$venue_id      = (int) ( $venue_ids[0] ?? 0 );
	$organizer_id  = (int) ( $organizer_ids[0] ?? 0 );
	$start         = new DateTimeImmutable( 'first day of next month 09:00:00', wp_timezone() );
	$end           = $start->modify( '+90 minutes' );

	$created = tribe_events()->set_args(
		array(
			'title'              => 'Mlyn Event disposable duplicate source',
			'description'        => '<p>Duplicate source content.</p>',
			'excerpt'            => 'Duplicate source excerpt.',
			'status'             => 'publish',
			'start_date'         => $start->format( 'Y-m-d H:i:s' ),
			'end_date'           => $end->format( 'Y-m-d H:i:s' ),
			'timezone'           => wp_timezone_string(),
			'venue'              => $venue_id,
			'organizer'          => $organizer_id ? array( $organizer_id ) : array(),
			'tag'                => array_map( 'intval', (array) $tag_ids ),
			'category'           => array_map( 'intval', (array) $category_ids ),
			'cost'               => 0,
			'currency_symbol'    => 'Kč',
			'currency_position'  => 'suffix',
			'url'                => 'https://example.test/event',
			'featured'           => true,
			'show_map'           => true,
			'show_map_link'      => true,
		)
	)->create();
	$source_id = $created instanceof WP_Post ? $created->ID : (int) $created;
	$assert( $source_id > 0, 'Could not create the duplicate source event.' );
	set_post_thumbnail( $source_id, (int) $image_ids[0] );
	wp_set_object_terms( $source_id, array_map( 'intval', (array) $tag_ids ), 'post_tag', false );
	wp_set_object_terms( $source_id, array_map( 'intval', (array) $category_ids ), 'tribe_events_cat', false );
	update_post_meta( $source_id, '_EventCurrencyCode', 'CZK' );
	update_post_meta( $source_id, '_edit_lock', '123:1' );
	update_post_meta( $source_id, '_mei_sync_identity', 'must-not-be-copied' );
	$assert( true === mlyn_event_set_occupancy( $source_id, null, 0, 'Pouze pro ZŠ Zenklova' ), 'Could not set source occupancy.' );
	$assert( true === mlyn_event_set_image_focal_point( $source_id, 24, 16 ), 'Could not set the source image focal point.' );

	$duplicated   = Mlyn_Event\Plugin::instance()->duplicate_event( $source_id );
	$duplicate_id = is_wp_error( $duplicated ) ? 0 : (int) $duplicated;
	$assert( ! is_wp_error( $duplicated ) && $duplicate_id > 0, is_wp_error( $duplicated ) ? $duplicated->get_error_message() : 'The duplicate was not created.' );
	$assert( 'draft' === get_post_status( $duplicate_id ), 'The duplicate is not a draft.' );
	$assert( 'Mlyn Event disposable duplicate source – kopie' === get_the_title( $duplicate_id ), 'The duplicate title is wrong.' );
	$assert( get_post_field( 'post_content', $source_id ) === get_post_field( 'post_content', $duplicate_id ), 'The event content was not copied.' );
	$assert( get_post_field( 'post_excerpt', $source_id ) === get_post_field( 'post_excerpt', $duplicate_id ), 'The event excerpt was not copied.' );
	foreach ( array( '_EventStartDate', '_EventEndDate', '_EventTimezone', '_EventVenueID', '_EventCost', '_EventCurrencySymbol', '_EventCurrencyPosition', '_EventCurrencyCode', '_EventURL' ) as $meta_key ) {
		$assert( get_post_meta( $source_id, $meta_key, true ) === get_post_meta( $duplicate_id, $meta_key, true ), 'Event metadata was not copied: ' . $meta_key );
	}
	if ( $organizer_id ) {
		$assert( in_array( $organizer_id, array_map( 'intval', get_post_meta( $duplicate_id, '_EventOrganizerID', false ) ), true ), 'The organizer was not copied.' );
	}
	$assert( wp_get_object_terms( $source_id, 'post_tag', array( 'fields' => 'ids' ) ) === wp_get_object_terms( $duplicate_id, 'post_tag', array( 'fields' => 'ids' ) ), 'Tags were not copied.' );
	$assert( wp_get_object_terms( $source_id, 'tribe_events_cat', array( 'fields' => 'ids' ) ) === wp_get_object_terms( $duplicate_id, 'tribe_events_cat', array( 'fields' => 'ids' ) ), 'Event categories were not copied.' );
	$occupancy = mlyn_event_get_occupancy( $duplicate_id );
	$assert( null === $occupancy['capacity'] && 0 === $occupancy['available_places'] && true === $occupancy['fully_occupied'] && 'Pouze pro ZŠ Zenklova' === $occupancy['note'], 'Occupancy was not copied.' );
	$point = mlyn_event_get_image_focal_point( $duplicate_id );
	$assert( true === $point['specified'] && 24 === $point['x'] && 16 === $point['y'], 'The image focal point was not copied.' );
	$assert( ! metadata_exists( 'post', $duplicate_id, '_edit_lock' ), 'The edit lock was copied.' );
	$assert( ! metadata_exists( 'post', $duplicate_id, '_mei_sync_identity' ), 'An intake synchronization identity was copied.' );

	echo "Mlyn Event integration smoke test passed.\n";
} finally {
	if ( $duplicate_id ) {
		wp_delete_post( $duplicate_id, true );
	}
	if ( $source_id ) {
		wp_delete_post( $source_id, true );
	}
}
