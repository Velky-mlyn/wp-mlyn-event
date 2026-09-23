<?php
// Run with wp eval-file wp-content/plugins/mlyn-event/tests/organizer-logo-smoke.php.
if (! defined('ABSPATH')) {
	exit(1);
}
$assert = static function ($ok, $message) {
	if (! $ok) {
		throw new RuntimeException($message);
	}
};
$admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'));
wp_set_current_user((int) $admins[0]);
$images = get_posts(array('post_type' => 'attachment', 'post_mime_type' => 'image', 'posts_per_page' => 2, 'fields' => 'ids'));
$assert(count($images) === 2, 'Two existing images are required.');
$id = wp_insert_post(array('post_type' => 'tribe_organizer', 'post_title' => 'Disposable logo test', 'post_status' => 'draft'));
$old_post = $_POST;
try {
	set_post_thumbnail($id, $images[0]);
	$save = static function ($value) use ($id) {
		$_POST = array('mlyn_organizer_logo_nonce' => wp_create_nonce('mlyn_organizer_logo_' . $id), 'mlyn_organizer_logo_id' => (string) $value);
		wp_update_post(array('ID' => $id, 'post_title' => 'Disposable logo test'));
	};
	$save($images[0]);
	$assert(mlyn_event_get_organizer_logo_id($id) === (int) $images[0], 'Logo did not save through the WP hook.');
	$save($images[1]);
	$assert(mlyn_event_get_organizer_logo_id($id) === (int) $images[1], 'Replacement failed.');
	$save($id);
	$assert(mlyn_event_get_organizer_logo_id($id) === (int) $images[1], 'Non-image replaced the logo.');
	$_POST['mlyn_organizer_logo_nonce'] = 'invalid';
	$_POST['mlyn_organizer_logo_id'] = '0';
	Mlyn_Event\Organizer_Logo::save_from_editor($id, get_post($id));
	$assert(mlyn_event_get_organizer_logo_id($id) === (int) $images[1], 'Invalid nonce allowed removal.');
	$admin = wp_get_current_user();
	// Model an event editor who can edit posts but has no administrator capability.
	$admin->allcaps['manage_options'] = false;
	$_POST['mlyn_organizer_logo_nonce'] = wp_create_nonce('mlyn_organizer_logo_' . $id);
	Mlyn_Event\Organizer_Logo::save_from_editor($id, get_post($id));
	$assert(mlyn_event_get_organizer_logo_id($id) === (int) $images[1], 'Non-admin allowed removal.');
	$admin->allcaps['manage_options'] = true;
	ob_start();
	Mlyn_Event\Organizer_Logo::render(get_post($id));
	$html = ob_get_clean();
	$assert(strpos($html, '<img') !== false && strpos($html, 'mlyn_organizer_logo_nonce') !== false, 'Preview or nonce missing.');
	$save(0);
	$assert(mlyn_event_get_organizer_logo_id($id) === 0, 'Removal failed.');
	$assert((int) get_post_thumbnail_id($id) === (int) $images[0], 'Featured image changed.');
	echo "Organizer logo smoke test passed: save, replace, invalid image, nonce, permissions, preview, removal, featured-image preservation.\n";
} finally {
	$_POST = $old_post;
	wp_delete_post($id, true);
}
