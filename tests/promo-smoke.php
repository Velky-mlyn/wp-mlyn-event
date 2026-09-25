<?php
// wp eval-file wp-content/plugins/mlyn-event/tests/promo-smoke.php
// Creates disposable fixtures only; historical bulk tests use year 1901.
use Mlyn_Event\Promo_Banner as Banner;
use Mlyn_Event\Promo_Admin as Admin;
use Mlyn_Event\Promo_Renderer as Renderer;

if (! defined('ABSPATH')) {
	exit(1);
}
$assert = static function ($ok, $message) {
	if (! $ok) {
		throw new RuntimeException($message);
	}
};
$ids = [];
$media = [];
$old_post = $_POST;
$admin_id = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ids'])[0];
wp_set_current_user($admin_id);
$fixture = static function (int $w, int $h, string $color) use (&$media): int {
	$image = new Imagick();
	$image->newImage($w, $h, new ImagickPixel($color), 'png');
	$upload = wp_upload_bits('promo-test-' . wp_generate_uuid4() . '.png', null, $image->getImageBlob());
	$image->clear();
	$id = wp_insert_attachment(['post_title' => 'Disposable promo test image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file']);
	$media[] = $id;
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
	return $id;
};
$settings_filter = null;
try {
	$photo = $fixture(1920, 1080, '#235a9c');
	$red = $fixture(200, 120, '#ff0000');
	$green = $fixture(200, 120, '#00ff00');
	$mark = $fixture(300, 100, 'white');
	$settings_filter = static function () use ($mark) {
		return ['watermark' => $mark, 'watermark_width' => 18, 'position' => 'left', 'placement' => 'overlay'];
	};
	add_filter('pre_option_' . Banner::SETTINGS, $settings_filter);
	$hash = hash_file('sha256', get_attached_file($photo));
	$orgs = [];
	foreach ([$red, $green] as $logo) {
		$org = wp_insert_post(['post_type' => 'tribe_organizer', 'post_title' => 'Disposable promo organizer', 'post_status' => 'publish']);
		$ids[] = $org;
		$orgs[] = $org;
		update_post_meta($org, Mlyn_Event\Organizer_Logo::META_KEY, $logo);
	}
	$event = tribe_events()->set_args(['title' => 'PŘEDNÁŠKA: Okna do orientu', 'description' => '<p>Cesta do Tibetu jako nástroj propagandy</p>', 'status' => 'draft', 'start_date' => '2027-09-24 17:00:00', 'end_date' => '2027-09-24 19:00:00', 'timezone' => 'Europe/Prague', 'image' => $photo, 'organizer' => $orgs])->create();
	$id = $event->ID;
	$ids[] = $id;
	$assert((bool) get_post_meta($id, Banner::CREATED, true), 'Actual creation timestamp missing.');
	$assert(Banner::config($id)['enabled'], 'New events must default to enabled.');
	$c = Banner::config($id);
	$_POST = ['mlyn_promo_nonce' => wp_create_nonce('mlyn_promo_save_' . $id), 'mlyn_promo' => $c];
	Admin::save($id, get_post($id));
	$_POST = [];
	$assert(get_post_meta($id, Banner::CONFIG, true)['enabled'], 'Editor settings did not save.');
	$data = Banner::inputs($id);
	$assert($data['logos'] === [$red, $green], 'Logo order changed.');
	$assert(strpos($data['datetime'], '17.00 – 19.00') !== false, 'Known date/time incorrect.');
	$count_before = wp_count_posts('attachment')->inherit;
	$preview = Renderer::render($data);
	$assert($preview['width'] === 1920 && $preview['height'] === 1080, 'Overlay changed photo dimensions.');
	$assert(wp_count_posts('attachment')->inherit === $count_before, 'Preview created an attachment.');
	$probe = new Imagick();
	$probe->readImageBlob($preview['bytes']);
	$assert($probe->getImagePixelColor(10, 1000)->getColor()['b'] < 5, 'Overlay strip is not black.');
	$assert($probe->getImagePixelColor(100, 100)->getColor()['r'] > 240, 'Left watermark missing.');
	$assert($probe->getImagePixelColor(1420, 920)->getColor()['r'] > 240 && $probe->getImagePixelColor(1680, 920)->getColor()['g'] > 240, 'Rendered logos are missing or out of order.');
	$probe->clear();
	$below = Renderer::render(array_merge($data, ['placement' => 'below', 'position' => 'right']));
	$assert($below['height'] === 1397, 'Below mode did not extend photo height.');
	$probe->readImageBlob($below['bytes']);
	$assert($probe->getImagePixelColor(10, 1000)->getColor()['b'] > 100, 'Below mode covered the source photo.');
	$assert($probe->getImagePixelColor(1750, 100)->getColor()['r'] > 240 && $probe->getImagePixelColor(100, 100)->getColor()['r'] < 100, 'Right watermark position incorrect.');
	$probe->clear();
	// New controls operate on copies for preview and do not mutate event relationships.
	$custom = $c;
	$custom['datetime_mode'] = 'custom';
	$custom['datetime'] = 'Každý pátek od 18 hodin';
	$custom['logo_options'] = [$orgs[0] => ['show' => false, 'height' => 60], $orgs[1] => ['show' => true, 'height' => 35]];
	$custom = Banner::sanitize($custom);
	$custom_data = Banner::inputs($id, $custom);
	$assert($custom_data['datetime'] === 'Každý pátek od 18 hodin' && $custom_data['logos'] === [$green] && $custom_data['logo_heights'] === [35], 'Custom date or logo settings ignored.');
	$custom['logo_options'][$orgs[1]]['show'] = false;
	$assert(Banner::inputs($id, $custom)['logos'] === [], 'Cannot hide every logo.');
	$assert(Banner::inputs($id, $c, array_reverse($orgs))['logos'] === [$green, $red], 'Preview organizer order not honored.');
	$assert(Banner::inputs($id, $c, [])['logos'] === [], 'Empty unsaved organizer list fell back to saved list.');
	$assert(Banner::organizers($id) === $orgs, 'Preview changed saved organizer associations.');
	$bad = $c;
	$bad['logo_options'] = [$orgs[0] => ['show' => true, 'height' => 101]];
	try {
		Banner::sanitize($bad);
		$assert(false, 'Invalid logo height accepted.');
	} catch (RuntimeException $e) {
		$assert(strpos($e->getMessage(), 'Výška loga') !== false, 'Unexpected logo validation error.');
	}
	// Measure colored pixels in the actual JPEG: differently shaped logos share a
	// default height, and the user's requested 2:1 height ratio survives width fitting.
	$tall_logo = $fixture(80, 160, '#00ff00');
	$bounds = static function (array $rendered): array {
		$im = new Imagick();
		$im->readImageBlob($rendered['bytes']);
		$box = ['red' => [], 'green' => [], 'text' => []];
		for ($y = 763; $y < 1080; ++$y) {
			$pixels = $im->exportImagePixels(0, $y, 1920, 1, 'RGB', Imagick::PIXEL_CHAR);
			$found = [];
			for ($x = 0; $x < 1920; ++$x) {
				$r = $pixels[$x * 3];
				$g = $pixels[$x * 3 + 1];
				$b = $pixels[$x * 3 + 2];
				if ($x > 1300 && $r > 220 && $g < 30 && $b < 30) {
					$found['red'] = true;
				}
				if ($x > 1300 && $g > 220 && $r < 30 && $b < 30) {
					$found['green'] = true;
				}
				if ($x < 1250 && $r > 220 && $g > 220 && $b > 220) {
					$found['text'] = true;
				}
			}
			foreach ($found as $key => $unused) {
				$box[$key][] = $y;
			}
		}
		$im->clear();
		return $box;
	};
	$layout = array_merge($data, ['logos' => [$red, $tall_logo], 'logo_heights' => [60, 60]]);
	$box = $bounds(Renderer::render($layout));
	$assert(abs(count($box['red']) - count($box['green'])) <= 2, 'Default heights differ with image aspect ratios.');
	$assert(abs((min($box['text']) - 763) - (1079 - max($box['text']))) <= 3, 'Text ink is not vertically centered.');
	$layout['logo_heights'] = [80, 40];
	$box = $bounds(Renderer::render($layout));
	$assert(abs(count($box['red']) - count($box['green']) * 2) <= 3, 'Per-logo relative height not honored.');
	// A ready-made image may be small and must bypass rendering and output creation.
	$ready = $c;
	$ready['mode'] = 'ready';
	$ready['ready_image'] = $red;
	update_post_meta($id, Banner::CONFIG, Banner::sanitize($ready));
	$ready_hash = hash_file('sha256', get_attached_file($red));
	$count_ready = wp_count_posts('attachment')->inherit;
	$assert(Banner::generate($id) === $red && mlyn_event_get_promo_banner_id($id) === $red, 'Ready banner not used directly.');
	$assert(wp_count_posts('attachment')->inherit === $count_ready && hash_file('sha256', get_attached_file($red)) === $ready_hash, 'Ready banner was modified or copied.');
	$assert((int) get_post_thumbnail_id($id) === $photo, 'Ready banner replaced the clean featured photo.');
	$assert(Banner::state($id)['status'] === 'ready', 'Ready banner status failed.');
	$ready['enabled'] = false;
	update_post_meta($id, Banner::CONFIG, $ready);
	$assert(mlyn_event_get_promo_image_id($id) === $photo, 'Disabled ready banner did not fall back.');
	update_post_meta($id, Banner::CONFIG, $c);
	$generated = Banner::generate($id);
	$assert(! is_wp_error($generated) && $generated > 0, 'Generation failed: ' . (is_wp_error($generated) ? $generated->get_error_message() : ''));
	$assert(mlyn_event_get_promo_banner_id($id) === $generated, 'Fresh banner not resolved.');
	$assert(Banner::generate($id) === $generated, 'Unchanged generation made another attachment.');
	$assert((int) get_post_thumbnail_id($id) === $photo && hash_file('sha256', get_attached_file($photo)) === $hash, 'Source image was modified.');
	$c['title_mode'] = 'custom';
	$c['title'] = 'Vlastní název';
	$c['subtitle_mode'] = 'hidden';
	update_post_meta($id, Banner::CONFIG, $c);
	$assert(mlyn_event_get_promo_image_id($id) === $photo, 'Stale banner did not fall back to featured image.');
	$data = Banner::inputs($id);
	$assert($data['title'] === 'Vlastní název' && $data['subtitle'] === '', 'Custom/hidden modes failed.');
	update_post_meta($id, '_EventEndDate', '2027-09-24 23:59:59');
	update_post_meta($id, Mlyn_Event\End_Time::META, 1);
	$assert(strpos(Banner::date_text($id), '23.59') === false && strpos(Banner::date_text($id), 'od 17.00') !== false, 'Unknown end time leaked expiry.');
	update_post_meta($id, '_EventEndDate', '2027-09-25 23:59:59');
	$assert(strpos(Banner::date_text($id), '25. 9. 2027') !== false, 'Multi-day final day missing.');
	update_post_meta($id, '_EventAllDay', 'yes');
	$assert(strpos(Banner::date_text($id), 'celý den') !== false && strpos(Banner::date_text($id), '17.00') === false, 'All-day includes a clock time.');
	update_post_meta($id, '_EventAllDay', 'no');
	$copy = Mlyn_Event\Plugin::instance()->duplicate_event($id);
	$assert(! is_wp_error($copy), 'Duplicate failed.');
	$ids[] = $copy;
	$assert(Banner::config($copy)['title'] === 'Vlastní název' && ! get_post_meta($copy, Banner::RESULT, true), 'Duplication lost config or reused output identity.');
	$too_long = array_merge($data, ['title' => str_repeat('Dlouhý název ', 80)]);
	try {
		Renderer::render($too_long);
		$assert(false, 'Overflow should be rejected.');
	} catch (RuntimeException $e) {
		$assert(strpos($e->getMessage(), 'Text se nevejde') !== false, 'Unexpected overflow error.');
	}
	$c['source'] = 'custom';
	$c['image'] = $red;
	update_post_meta($id, Banner::CONFIG, $c);
	$assert(Banner::inputs($id)['image'] === $red, 'Alternative source ignored.');
	$assert(is_wp_error(Banner::generate($id)) && mlyn_event_get_promo_image_id($id) === $photo, 'Failed generation exposed stale banner.');
	$c['enabled'] = false;
	update_post_meta($id, Banner::CONFIG, $c);
	$assert(Banner::state($id)['status'] === 'disabled' && mlyn_event_get_promo_image_id($id) === $photo, 'Disabled fallback failed.');
	$before = get_post_meta($id, Banner::CONFIG, true);
	$_POST = ['mlyn_promo_nonce' => 'invalid', 'mlyn_promo' => array_merge($c, ['enabled' => true])];
	Admin::save($id, get_post($id));
	$assert(get_post_meta($id, Banner::CONFIG, true) === $before, 'Invalid nonce changed config.');
	wp_get_current_user()->allcaps['manage_options'] = false;
	$_POST['mlyn_promo_nonce'] = wp_create_nonce('mlyn_promo_save_' . $id);
	Admin::save($id, get_post($id));
	$assert(get_post_meta($id, Banner::CONFIG, true) === $before, 'Non-admin changed config.');
	wp_get_current_user()->allcaps['manage_options'] = true;
	$_POST = [];
	// Refuse to bulk-test if this installation happens to contain genuine year-1901 events.
	$initial = Admin::bulk_preview('1901-01-02');
	$assert($initial['count'] === 0, 'Historical test date is not isolated.');
	$bulk_ids = [];
	for ($i = 0; $i < 53; ++$i) {
		$bid = wp_insert_post(['post_type' => 'tribe_events', 'post_title' => 'Disposable bulk promo test', 'post_status' => 'draft', 'post_date' => '1901-01-02 23:59:59', 'post_date_gmt' => '1901-01-02 22:59:59']);
		$ids[] = $bid;
		$bulk_ids[] = $bid;
		// Model legacy imported records without an immutable creation timestamp.
		delete_post_meta($bid, Banner::CREATED);
	}
	$next = wp_insert_post(['post_type' => 'tribe_events', 'post_title' => 'Disposable next-day exclusion', 'post_status' => 'draft', 'post_date' => '1901-01-03 00:00:00', 'post_date_gmt' => '1901-01-02 23:00:00']);
	$ids[] = $next;
	delete_post_meta($next, Banner::CREATED);
	$created_later = wp_insert_post(['post_type' => 'tribe_events', 'post_title' => 'Disposable backdated new record', 'post_status' => 'draft', 'post_date' => '1901-01-01 00:00:00']);
	$ids[] = $created_later;
	$job = Admin::bulk_preview('1901-01-02');
	$assert($job['count'] === 53, 'Inclusive local date cutoff is wrong.');
	$assert(Banner::config($bulk_ids[0])['enabled'], 'Bulk preview mutated events.');
	$a = Admin::bulk_apply($job['token']);
	$assert($a['done'] === 50 && ! $a['complete'], 'First batch incorrect.');
	$b = Admin::bulk_apply($job['token']);
	$assert($b['done'] === 53 && $b['complete'], 'Second batch incorrect.');
	foreach ($bulk_ids as $bid) {
		$assert(! Banner::config($bid)['enabled'], 'Bulk event still enabled.');
	}
	$assert(Banner::config($next)['enabled'], 'Following-day event incorrectly disabled.');
	$assert(Banner::config($created_later)['enabled'], 'Backdated publication overrode actual creation time.');
	echo "Promo smoke passed: rendering, preserved photo, Czech text, dates, watermark, logo order, preview isolation, generation, stale/error fallback, overrides, duplication, permissions and 53-event inclusive-date bulk processing.\n";
} finally {
	$_POST = $old_post;
	if ($settings_filter) {
		remove_filter('pre_option_' . Banner::SETTINGS, $settings_filter);
	}
	foreach ($ids as $id) {
		foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_mlyn_promo_owner', 'meta_value' => $id]) as $attachment) {
			wp_delete_attachment($attachment, true);
		}
		wp_clear_scheduled_hook(Banner::JOB, [(int) $id]);
		wp_delete_post($id, true);
	}
	foreach ($media as $id) {
		wp_delete_attachment($id, true);
	}
}
