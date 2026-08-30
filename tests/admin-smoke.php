<?php

// Run with: wp eval-file wp-content/plugins/mlyn-event/tests/admin-smoke.php

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
$assert( ! empty( $admins ), 'No administrator is available for the admin smoke test.' );
wp_set_current_user( (int) $admins[0] );

$event_id = wp_insert_post(
	array(
		'post_type'   => 'tribe_events',
		'post_status' => 'draft',
		'post_title'  => 'Mlyn Event disposable admin event',
	),
	true
);
$assert( ! is_wp_error( $event_id ), 'Could not create the disposable admin event.' );

try {
	ob_start();
	Mlyn_Event\Occupancy::render_meta_box( get_post( $event_id ) );
	$html = ob_get_clean();
	foreach ( array( 'Kapacita', 'Volná místa', 'Poznámka k obsazenosti', 'mlyn_event_occupancy_nonce' ) as $expected ) {
		$assert( false !== strpos( $html, $expected ), 'The occupancy meta box is missing: ' . $expected );
	}

	$_POST['mlyn_event_occupancy_nonce']  = wp_create_nonce( 'mlyn_event_save_occupancy_' . $event_id );
	$_POST['mlyn_event_capacity']         = '';
	$_POST['mlyn_event_available_places'] = '0';
	$_POST['mlyn_event_occupancy_note']   = 'ZŠ Bohumila Hrabala';
	Mlyn_Event\Occupancy::save_from_editor( $event_id, get_post( $event_id ) );
	unset( $_POST['mlyn_event_occupancy_nonce'], $_POST['mlyn_event_capacity'], $_POST['mlyn_event_available_places'], $_POST['mlyn_event_occupancy_note'] );

	$occupancy = mlyn_event_get_occupancy( $event_id );
	$assert( null === $occupancy['capacity'], 'Blank capacity was not preserved.' );
	$assert( 0 === $occupancy['available_places'] && true === $occupancy['fully_occupied'], 'Zero available places did not mark the event fully occupied.' );
	$assert( 'ZŠ Bohumila Hrabala' === $occupancy['note'], 'The occupancy note was not saved.' );

	$actions = Mlyn_Event\Plugin::instance()->add_duplicate_row_action( array(), get_post( $event_id ) );
	$assert( isset( $actions['mlyn_duplicate_event'] ), 'The duplicate row action is missing.' );
	$assert( false !== strpos( $actions['mlyn_duplicate_event'], 'Duplikovat' ), 'The duplicate row action has the wrong label.' );
	$assert( false !== strpos( $actions['mlyn_duplicate_event'], 'mlyn_duplicate_event' ), 'The duplicate row action has the wrong target.' );

	echo "Mlyn Event admin smoke test passed.\n";
} finally {
	wp_delete_post( $event_id, true );
}
