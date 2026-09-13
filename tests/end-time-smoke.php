<?php
// Run with wp eval-file; all fixture events are removed in finally.
$assert = static function ( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
$ids = [];
wp_set_current_user( get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ] )[0] );
try {
	$event = tribe_events()->set_args( [ 'title' => 'Disposable unknown end test', 'status' => 'publish', 'start_date' => '2027-03-28 10:00:00', 'end_date' => '2027-03-28 11:00:00', 'timezone' => 'Europe/Prague' ] )->create();
	$id = $event->ID; $ids[] = $id;
	$assert( ! mlyn_event_end_time_unknown( $id ), 'New event incorrectly unknown' );
	mlyn_event_set_end_time_unknown( $id, true );
	$assert( get_post_meta( $id, '_EventEndDate', true ) === '2027-03-28 23:59:59', 'Internal expiry wrong' );
	$assert( get_post_meta( $id, '_EventEndDateUTC', true ) === '2027-03-28 21:59:59', 'DST UTC expiry wrong' );
	$assert( strpos( tribe_events_event_schedule_details( $id ), '23:59' ) === false, 'Schedule leaks expiry' );
	$assert( strpos( velkymlyn_get_event_schedule_html( $id ), '23:59' ) === false, 'Theme leaks expiry' );

	$template = new Tribe__Template();
	$template->set_template_origin( Tribe__Events__Main::instance() );
	$template->set_template_folder( 'src/views/v2' );
	$template->set_template_folder_lookup( true );
	$template->set_template_context_extract( true );
	$name = 'month/calendar-body/day/calendar-events/calendar-event/date';
	$html = $template->template( $name, [ 'event' => tribe_get_event( $id ), 'date_formats' => (object) [ 'time_range_separator' => ' – ' ] ], false );
	$assert( strpos( $html, '23:59' ) === false && strpos( $html, '10:00' ) !== false, 'Month view leaks expiry or loses start.' );
	$known = tribe_events()->set_args( [ 'title' => 'Disposable known end', 'status' => 'draft', 'start_date' => '2027-03-28 10:00:00', 'end_date' => '2027-03-28 13:00:00', 'timezone' => 'Europe/Prague' ] )->create();
	$ids[] = $known->ID;
	$html = $template->template( $name, [ 'event' => tribe_get_event( $known->ID ), 'date_formats' => (object) [ 'time_range_separator' => ' – ' ] ], false );
	$assert( strpos( $html, '13:00' ) !== false, 'Unknown event suppressed following known event end.' );
	$data = apply_filters( 'tribe_json_ld_event_object', (object) [ 'endDate' => 'test' ], [], get_post( $id ) );
	$assert( ! isset( $data->endDate ), 'JSON LD leaks expiry' );
	$data = tribe( 'tec.rest-v1.repository' )->get_event_data( $id );
	$assert( $data['end_time_unknown'] && $data['end_date'] === null && $data['utc_end_date'] === null, 'REST leaks expiry' );
	$item = apply_filters( 'tribe_ical_feed_item', [ 'DTEND' => 'test', 'DURATION' => 'test' ], get_post( $id ) );
	$assert( ! isset( $item['DTEND'], $item['DURATION'] ), 'ICS leaks expiry' );
	$copy = Mlyn_Event\Plugin::instance()->duplicate_event( $id ); $ids[] = $copy;
	$assert( mlyn_event_end_time_unknown( $copy ), 'Duplication loses flag' );
	$_POST = [ 'post_ID' => $id, 'mlyn_event_end_time_nonce' => wp_create_nonce( 'mlyn_event_end_time' ), 'mlyn_end_time_unknown' => '1', 'EventStartDate' => '2027-03-29', 'EventEndDate' => '2027-03-30', 'EventStartTime' => '12:00:00' ];
	do_action( 'admin_init' );
	$assert( $_POST['EventEndTime'] === '23:59:59', 'Missing disabled end input fallback' );
	Tribe__Events__API::updateEvent( $id, $_POST );
	$assert( get_post_meta( $id, '_EventStartDate', true ) === '2027-03-29 12:00:00', 'Editor loses changed start' );
	$assert( get_post_meta( $id, '_EventEndDate', true ) === '2027-03-30 23:59:59', 'Editor loses final day' );
	$assert( strpos( tribe_events_event_schedule_details( $id ), '23:59' ) === false, 'Multi-day schedule leaks expiry' );
	unset( $_POST['mlyn_end_time_unknown'] ); $_POST['EventEndTime'] = '15:00:00';
	Tribe__Events__API::updateEvent( $id, $_POST );
	$assert( ! mlyn_event_end_time_unknown( $id ) && get_post_meta( $id, '_EventEndDate', true ) === '2027-03-30 15:00:00', 'Cannot restore known end' );
	$_POST = [];
	tribe_events()->where( 'id', $id )->set_args( [ 'all_day' => true ] )->save();
	mlyn_event_set_end_time_unknown( $id, true );
	$assert( ! mlyn_event_end_time_unknown( $id ), 'All day conflict' );
	echo "Unknown end time smoke test passed.\n";
} finally {
	$_POST = [];
	foreach ( $ids as $id ) { if ( is_numeric( $id ) ) wp_delete_post( $id, true ); }
}
