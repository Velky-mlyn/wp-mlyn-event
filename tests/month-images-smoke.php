<?php
// Run with wp eval-file wp-content/plugins/mlyn-event/tests/month-images-smoke.php.
$assert = static function ( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$events = get_posts( array( 'post_type' => 'tribe_events', 'posts_per_page' => 1, 'meta_key' => '_thumbnail_id' ) );
$assert( ! empty( $events ), 'No event image is available.' );
$render = static function ( $event ) {
 $template = new Tribe__Template();
 $template->set( 'event', $event );
 ob_start();
 do_action( 'tribe_template_before_include:events/v2/month/calendar-body/day/multiday-events/multiday-event/bar/featured', '', array(), $template );
 return ob_get_clean();
};
$assert( false === has_action( 'tribe_template_after_include:events/v2/month/calendar-body/day/multiday-events', array( Mlyn_Event\Month_Images::class, 'render' ) ), 'Old detached-thumbnail hook remains.' );
$html = $render( $events[0] );
$assert( 1 === substr_count( $html, 'class="mlyn-event-month-image-wrapper"' ), 'Bar thumbnail missing or duplicated.' );
$assert( false !== strpos( $html, 'aria-hidden="true"' ) && false !== strpos( $html, 'alt=""' ), 'Decorative image accessibility missing.' );
$assert( false === strpos( $html, '<a ' ), 'Thumbnail must use the existing TEC event link.' );
$assert( '' === $render( null ), 'Missing event produced markup.' );
$assert( '' === $render( new WP_Post( (object) array( 'ID' => 0 ) ) ), 'Missing image produced markup.' );
echo "Month bar image hook, missing-image and accessibility checks passed.\n";
