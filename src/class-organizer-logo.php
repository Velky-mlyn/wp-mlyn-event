<?php
namespace Mlyn_Event;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Organizer_Logo {
	public const META_KEY = '_mlyn_event_organizer_logo_id';

	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
		add_action( 'add_meta_boxes_tribe_organizer', array( self::class, 'register_meta_box' ) );
		add_action( 'save_post_tribe_organizer', array( self::class, 'save_from_editor' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
	}

	public static function register_meta(): void {
		register_post_meta( 'tribe_organizer', self::META_KEY, array(
			'type' => 'integer', 'single' => true, 'show_in_rest' => false,
			'sanitize_callback' => 'absint',
			'auth_callback' => static function ( $allowed, $key, $post_id ): bool {
				return current_user_can( 'manage_options' ) && current_user_can( 'edit_post', (int) $post_id );
			},
		) );
	}

	public static function register_meta_box( WP_Post $post ): void {
		if ( current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $post->ID ) ) {
			add_meta_box( 'mlyn-organizer-logo', __( 'Logo pořadatele', 'mlyn-event' ), array( self::class, 'render' ), 'tribe_organizer', 'side' );
		}
	}

	public static function get( int $organizer_id ): int {
		if ( 'tribe_organizer' !== get_post_type( $organizer_id ) ) { return 0; }
		$id = (int) get_post_meta( $organizer_id, self::META_KEY, true );
		return $id && 'trash' !== get_post_status( $id ) && wp_attachment_is_image( $id ) ? $id : 0;
	}

	public static function render( WP_Post $post ): void {
		$id = self::get( $post->ID );
		wp_nonce_field( 'mlyn_organizer_logo_' . $post->ID, 'mlyn_organizer_logo_nonce' );
		?>
		<div id="mlyn-organizer-logo-control">
			<input type="hidden" name="mlyn_organizer_logo_id" value="<?php echo esc_attr( (string) $id ); ?>">
			<div class="mlyn-organizer-logo-preview" style="background:#eee;padding:10px;margin-bottom:10px" <?php echo $id ? '' : 'hidden'; ?>>
				<?php if ( $id ) { echo wp_get_attachment_image( $id, 'medium', false, array( 'style' => 'max-width:100%;height:auto;display:block' ) ); } ?>
			</div>
			<button type="button" class="button mlyn-organizer-logo-select"><?php esc_html_e( 'Vybrat / změnit logo', 'mlyn-event' ); ?></button>
			<button type="button" class="button-link-delete mlyn-organizer-logo-remove" <?php echo $id ? '' : 'hidden'; ?>><?php esc_html_e( 'Odebrat logo', 'mlyn-event' ); ?></button>
			<p class="description"><?php esc_html_e( 'Doporučuje se PNG s průhledným pozadím vhodné na černý podklad. Výběr uložte aktualizací pořadatele.', 'mlyn-event' ); ?></p>
		</div>
		<?php
	}

	public static function enqueue_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tribe_organizer' !== $screen->post_type || 'post' !== $screen->base || ! current_user_can( 'manage_options' ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_script( 'mlyn-organizer-logo', plugins_url( 'assets/organizer-logo.js', MLYN_EVENT_FILE ), array( 'media-editor' ), MLYN_EVENT_VERSION, true );
		wp_localize_script( 'mlyn-organizer-logo', 'mlynOrganizerLogo', array(
			'title' => __( 'Vybrat logo pořadatele', 'mlyn-event' ),
			'button' => __( 'Použít logo', 'mlyn-event' ),
		) );
	}

	public static function save_from_editor( int $post_id, WP_Post $post ): void {
		if ( 'tribe_organizer' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		$nonce = $_POST['mlyn_organizer_logo_nonce'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'mlyn_organizer_logo_' . $post_id ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['mlyn_organizer_logo_id'] ) ) { return; }
		$raw = $_POST['mlyn_organizer_logo_id'];
		$id = is_string( $raw ) && ctype_digit( $raw ) ? (int) $raw : -1;
		if ( $id < 0 || ( $id && ( ! wp_attachment_is_image( $id ) || 'trash' === get_post_status( $id ) || ! current_user_can( 'edit_post', $id ) ) ) ) {
			add_filter( 'redirect_post_location', static function ( $url ) {
				return add_query_arg( 'mlyn_organizer_logo_invalid', '1', $url );
			} );
			return;
		}
		$previous = (int) get_post_meta( $post_id, self::META_KEY, true );
		if ( $id ) { update_post_meta( $post_id, self::META_KEY, $id ); }
		else { delete_post_meta( $post_id, self::META_KEY ); }
		if ( $previous !== $id ) { do_action( 'mlyn_event_organizer_logo_updated', $post_id, $id, $previous ); }
	}

	public static function notice(): void {
		if ( current_user_can( 'manage_options' ) && isset( $_GET['mlyn_organizer_logo_invalid'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Logo nebylo uloženo. Vyberte platný obrázek z knihovny médií.', 'mlyn-event' ) . '</p></div>';
		}
	}
}
