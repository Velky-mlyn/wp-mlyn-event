<?php

namespace Mlyn_Event;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private static $instance;
	private $duplicator;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->duplicator = new Event_Duplicator();
		add_action( 'init', array( Occupancy::class, 'register_meta' ) );
		add_action( 'init', array( Image_Focal_Point::class, 'register_meta' ) );
		add_action( 'add_meta_boxes_tribe_events', array( Occupancy::class, 'register_meta_box' ) );
		add_action( 'add_meta_boxes_tribe_events', array( Image_Focal_Point::class, 'register_meta_box' ) );
		add_action( 'save_post_tribe_events', array( Occupancy::class, 'save_from_editor' ), 10, 2 );
		add_action( 'save_post_tribe_events', array( Image_Focal_Point::class, 'save_from_editor' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'post_row_actions', array( $this, 'add_duplicate_row_action' ), 10, 2 );
		add_action( 'admin_post_mlyn_duplicate_event', array( $this, 'handle_duplicate_event' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	public function add_duplicate_row_action( array $actions, WP_Post $post ): array {
		if ( 'tribe_events' !== $post->post_type || 'trash' === $post->post_status || ! current_user_can( 'edit_post', $post->ID ) || $this->has_native_duplicate_feature() ) {
			return $actions;
		}
		$post_type = get_post_type_object( 'tribe_events' );
		if ( ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'mlyn_duplicate_event',
					'event'  => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'mlyn_duplicate_event_' . $post->ID
		);
		$actions['mlyn_duplicate_event'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Duplikovat', 'mlyn-event' ) );
		return $actions;
	}

	public function handle_duplicate_event(): void {
		$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0;
		check_admin_referer( 'mlyn_duplicate_event_' . $event_id );
		$post_type = get_post_type_object( 'tribe_events' );
		if ( ! $event_id || ! current_user_can( 'edit_post', $event_id ) || ! $post_type || ! current_user_can( $post_type->cap->create_posts ) ) {
			wp_die( esc_html__( 'Nemáte oprávnění tuto akci duplikovat.', 'mlyn-event' ), '', array( 'response' => 403 ) );
		}

		$duplicate_id = $this->duplicate_event( $event_id );
		if ( is_wp_error( $duplicate_id ) ) {
			wp_die( esc_html( $duplicate_id->get_error_message() ) );
		}
		$location = add_query_arg( 'mlyn_event_duplicated', '1', get_edit_post_link( $duplicate_id, 'raw' ) );
		wp_safe_redirect( $location );
		exit;
	}

	/**
	 * @return int|\WP_Error
	 */
	public function duplicate_event( int $event_id ) {
		return $this->duplicator->duplicate( $event_id );
	}

	public function enqueue_admin_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tribe_events' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'mlyn-event-admin', plugins_url( 'assets/admin.css', MLYN_EVENT_FILE ), array( 'wp-components' ), MLYN_EVENT_VERSION );
		wp_enqueue_script(
			'mlyn-event-admin',
			plugins_url( 'assets/admin.js', MLYN_EVENT_FILE ),
			array( 'wp-components', 'wp-element', 'media-editor' ),
			MLYN_EVENT_VERSION,
			true
		);
		wp_localize_script(
			'mlyn-event-admin',
			'mlynEventAdmin',
			array(
				'noImage'      => __( 'Nejprve nastavte náhledový obrázek akce.', 'mlyn-event' ),
				'previewLabel' => __( 'Náhled banneru detailu', 'mlyn-event' ),
				'reset'        => __( 'Nastavit na střed', 'mlyn-event' ),
			)
		);
	}

	public function render_admin_notices(): void {
		if ( isset( $_GET['mlyn_event_occupancy_invalid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Obsazenost nebyla uložena. Zadejte nezáporná celá čísla; pokud jsou vyplněna obě pole, počet volných míst nesmí překročit kapacitu.', 'mlyn-event' ) . '</p></div>';
		}
		if ( isset( $_GET['mlyn_event_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Akce byla duplikována jako koncept.', 'mlyn-event' ) . '</p></div>';
		}
		if ( isset( $_GET['mlyn_event_focal_point_invalid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Bod výřezu obrázku nebyl uložen. Zvolte hodnoty od 0 do 100.', 'mlyn-event' ) . '</p></div>';
		}
		if ( ! post_type_exists( 'tribe_events' ) && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Mlýn Event vyžaduje aktivní plugin The Events Calendar.', 'mlyn-event' ) . '</p></div>';
		}
	}

	private function has_native_duplicate_feature(): bool {
		return class_exists( 'Tribe__Events__Pro__Main' );
	}
}
