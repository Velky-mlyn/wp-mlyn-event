<?php
namespace Mlyn_Event;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Unknown public end time, with a finite internal calendar expiry. */
final class End_Time {
	const META = '_mlyn_event_end_time_unknown';
	private static $saving = false;

	public static function register(): void {
		add_action( 'init', static function () {
			register_post_meta( 'tribe_events', self::META, [
				'type' => 'boolean', 'single' => true, 'default' => false,
				'show_in_rest' => false, 'sanitize_callback' => 'rest_sanitize_boolean',
			] );
		} );
		add_filter( 'render_block_tribe/event-datetime', static function ( $html, $block ) {
			$id = get_the_ID();
			return $id && self::unknown( $id ) ? '<div class="tribe-events-schedule"><h2 class="tribe-events-schedule__datetime">' . self::schedule( '', $id ) . '</h2></div>' : $html;
		}, 100, 2 );
		add_action( 'admin_init', static function () {
			$id = isset( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0;
			if ( $id && 'tribe_events' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) && ! empty( $_POST['mlyn_end_time_unknown'] ) && empty( $_POST['EventAllDay'] ) && isset( $_POST['mlyn_event_end_time_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mlyn_event_end_time_nonce'] ) ), 'mlyn_event_end_time' ) ) {
				// TEC requires both clock inputs to accept changes to the dates.
				$_POST['EventEndTime'] = '23:59:59';
			}
		} );
		add_action( 'add_meta_boxes_tribe_events', static function () {
			add_meta_box( 'mlyn-event-end-time', 'Čas ukončení', [ self::class, 'editor' ], 'tribe_events', 'normal', 'high' );
		} );
		add_action( 'save_post_tribe_events', [ self::class, 'save_editor' ], 100, 2 );
		add_action( 'tribe_events_update_meta', [ self::class, 'save_editor' ], 100, 1 );
		add_filter( 'tribe_events_event_schedule_details_inner', [ self::class, 'schedule' ], 100, 2 );
		add_filter( 'tribe_events_event_short_schedule_details_inner', [ self::class, 'schedule' ], 100, 2 );
		add_filter( 'tribe_template_pre_html:events/v2/month/calendar-body/day/calendar-events/calendar-event/date', static function ( $html, $file, $name, $template, $context ) {
			$event = $context['event'] ?? $template->get( 'event' );
			if ( $event && self::unknown( $event->ID ) ) {
				// Return this event's markup without changing shared template visibility.
				$base = 'month/calendar-body/day/calendar-events/calendar-event/date/';
				return '<div class="tribe-events-calendar-month__calendar-event-datetime">'
					. $template->template( $base . 'featured', [ 'event' => $event ], false )
					. '<time datetime="' . esc_attr( $event->dates->start_display->format( 'H:i' ) ) . '">'
					. esc_html( $event->dates->start_display->format( tribe_get_time_format() ) ) . '</time>'
					. $template->template( $base . 'meta', [ 'event' => $event ], false ) . '</div>';
			}
			return $html;
		}, 100, 5 );
		add_filter( 'tribe_json_ld_event_object', static function ( $data, $args, $post ) {
			if ( self::unknown( $post->ID ) ) { unset( $data->endDate ); }
			return $data;
		}, 100, 3 );
		add_filter( 'tribe_rest_event_data', static function ( $data, $event ) {
			$data['end_time_unknown'] = self::unknown( $event->ID );
			if ( $data['end_time_unknown'] ) {
				$data['end_day'] = substr( get_post_meta( $event->ID, '_EventEndDate', true ), 0, 10 );
				foreach ( [ 'end_date', 'end_date_details', 'utc_end_date', 'utc_end_date_details' ] as $key ) { $data[$key] = null; }
			}
			return $data;
		}, 100, 2 );
		add_filter( 'tribe_ical_feed_item', static function ( $item, $event ) {
			if ( self::unknown( $event->ID ) ) {
				unset( $item['DTEND'], $item['DURATION'] );
				$item['X-MLYN-END-TIME-UNKNOWN'] = 'X-MLYN-END-TIME-UNKNOWN:TRUE';
				$item['X-MLYN-END-DATE'] = 'X-MLYN-END-DATE:' . str_replace( '-', '', substr( get_post_meta( $event->ID, '_EventEndDate', true ), 0, 10 ) );
			}
			return $item;
		}, 100, 2 );
		add_filter( 'tec_views_v2_single_event_gcal_link_parameters', static function ( $pieces, $event ) {
			if ( self::unknown( $event->ID ) && isset( $pieces['dates'] ) ) {
				$start = explode( '/', $pieces['dates'] )[0];
				$pieces['dates'] = $start . '/' . $start;
				$pieces['details'] = ( $pieces['details'] ?? '' ) . urlencode( "\nČas konce není znám." );
			}
			return $pieces;
		}, 100, 2 );
	}

	public static function unknown( int $id ): bool {
		return (bool) get_post_meta( $id, self::META, true ) && ! tribe_event_is_all_day( $id );
	}

	/** Save via TEC so UTC timestamps, duration and occurrence tables stay consistent. */
	public static function set( int $id, bool $unknown, bool $notify = true ) {
		if ( 'tribe_events' !== get_post_type( $id ) ) { return new \WP_Error( 'invalid_event', 'Invalid event.' ); }
		$unknown = $unknown && ! tribe_event_is_all_day( $id );
		if ( $unknown ) {
			$stored_end = (string) get_post_meta( $id, '_EventEndDate', true );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} /', $stored_end ) ) {
				return new \WP_Error( 'missing_event_end_date', 'Nejprve vyplňte datum konce akce.' );
			}
			$end = substr( $stored_end, 0, 10 ) . ' 23:59:59';
			if ( get_post_meta( $id, '_EventEndDate', true ) !== $end ) {
				self::$saving = true;
				try { tribe_events()->where( 'id', $id )->set_args( [ 'end_date' => $end ] )->save(); }
				finally { self::$saving = false; }
				if ( get_post_meta( $id, '_EventEndDate', true ) !== $end ) {
					return new \WP_Error( 'event_end_save_failed', 'Datum konce akce se nepodařilo uložit.' );
				}
			}
		}
		update_post_meta( $id, self::META, $unknown ? 1 : 0 );
		if ( $notify ) { do_action( 'mlyn_event_end_time_updated', $id, $unknown ); }
		return true;
	}

	public static function editor( $post ): void {
		wp_nonce_field( 'mlyn_event_end_time', 'mlyn_event_end_time_nonce' );
		echo '<label><input type="checkbox" id="mlyn-end-time-unknown" name="mlyn_end_time_unknown" value="1" ' . checked( self::unknown( $post->ID ), true, false ) . '> Čas konce není znám</label><p class="description">Zobrazuje se pouze čas začátku. Datum konce ponechte vyplněné; akce se přestane nabízet na konci tohoto dne.</p>';
	}

	public static function save_editor( $id ): void {
		if ( self::$saving || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) || ! isset( $_POST['mlyn_event_end_time_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mlyn_event_end_time_nonce'] ) ), 'mlyn_event_end_time' ) ) { return; }
		self::set( (int) $id, ! empty( $_POST['mlyn_end_time_unknown'] ) );
	}

	public static function schedule( $html, $id ) {
		if ( ! self::unknown( (int) $id ) ) { return $html; }
		$start = tribe_get_start_date( $id, false, 'j. n. Y' );
		$end = tribe_get_end_date( $id, false, 'j. n. Y' );
		return esc_html( $start . ' od ' . tribe_get_start_time( $id ) . ( $start !== $end ? ' – ' . $end : '' ) );
	}
}
