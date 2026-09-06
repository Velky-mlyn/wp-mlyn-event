<?php

namespace Mlyn_Event;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps each multi-day thumbnail inside its own spanning event bar. */
final class Month_Images {
	public static function register(): void {
		add_action( 'tribe_template_before_include:events/v2/month/calendar-body/day/multiday-events/multiday-event/bar/featured', array( self::class, 'render' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_styles' ) );
	}

	public static function enqueue_styles(): void {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			return;
		}
		wp_enqueue_style( 'mlyn-event-month-images', plugins_url( 'assets/month-images.css', MLYN_EVENT_FILE ), array(), MLYN_EVENT_VERSION );
	}

	public static function render( $file, $name, $template ): void {
		$event = $template->get( 'event' );
		if ( ! $event instanceof \WP_Post ) {
			return;
		}
		// TEC invokes this sub-template only for a visible weekly bar segment.
		// Its existing hidden link supplies the accessible name and click target.
		$image = get_the_post_thumbnail( $event->ID, 'medium', array( 'class' => 'mlyn-event-month-image', 'loading' => 'lazy', 'alt' => '' ) );
		if ( $image ) {
			echo '<span class="mlyn-event-month-image-wrapper" aria-hidden="true">' . $image . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress image markup.
		}
	}
}
