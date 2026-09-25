<?php

namespace Mlyn_Event;

if (! defined('ABSPATH')) {
	exit;
}

/** Promo configuration, dependency tracking, generation and integration API. */
final class Promo_Banner
{
	const CONFIG = '_mlyn_event_promo';
	const CREATED = '_mlyn_event_created_at';
	const RESULT = '_mlyn_event_promo_result';
	const ERROR = '_mlyn_event_promo_error';
	const SETTINGS = 'mlyn_event_settings';
	const LEGACY = 'mlyn_event_promo_legacy_max_id';
	const JOB = 'mlyn_event_generate_promo';
	const SCAN = 'mlyn_event_promo_scan';
	private static $dirty = [];
	private static $after_save = [];

	public static function register(): void
	{
		add_action('init', [self::class, 'init']);
		add_action('wp_after_insert_post', [self::class, 'record_creation'], 10, 3);
		add_action(self::JOB, [self::class, 'generate']);
		add_action(self::SCAN, [self::class, 'scan']);
		add_action('save_post_tribe_events', [self::class, 'changed'], 999);
		foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
			add_action($hook, [self::class, 'meta_changed'], 10, 4);
		}
		add_action('shutdown', [self::class, 'flush']);
		add_action('update_option_' . self::SETTINGS, [self::class, 'shared_changed'], 10, 0);
		add_action('mlyn_event_organizer_logo_updated', [self::class, 'shared_changed'], 10, 0);
		add_action('delete_attachment', [self::class, 'shared_changed'], 10, 0);
		add_action('mlyn_event_duplicated', [self::class, 'duplicate'], 10, 2);
	}

	public static function init(): void
	{
		global $wpdb;
		// Snapshot the pre-feature population once; never silently brand historic uploads.
		if (false === get_option(self::LEGACY, false)) {
			add_option(self::LEGACY, (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'tribe_events'"), '', false);
		}
		if ((int) get_option('mlyn_event_promo_renderer_version', 0) !== Promo_Renderer::VERSION) {
			update_option('mlyn_event_promo_renderer_version', Promo_Renderer::VERSION, false);
			self::shared_changed();
		}
		foreach ([self::CONFIG, self::RESULT] as $key) {
			register_post_meta('tribe_events', $key, [
				'type' => 'object',
				'single' => true,
				'show_in_rest' => false,
				'auth_callback' => static function ($allowed, $key, $id) {
					return current_user_can('manage_options') && current_user_can('edit_post', $id);
				},
			]);
		}
	}

	/** WordPress post_date may change on publication; retain actual local creation time. */
	public static function record_creation(int $id, \WP_Post $post, bool $update): void
	{
		if (! $update && 'tribe_events' === $post->post_type) {
			add_post_meta($id, self::CREATED, current_time('mysql'), true);
		}
	}

	public static function settings(): array
	{
		return array_merge(['watermark' => 0, 'position' => 'left', 'placement' => 'overlay', 'watermark_width' => 18], (array) get_option(self::SETTINGS, []));
	}

	public static function config(int $id): array
	{
		$s = self::settings();
		return array_merge([
			'enabled' => $id > (int) get_option(self::LEGACY, PHP_INT_MAX),
			'source' => 'featured',
			'image' => 0,
			'placement' => $s['placement'],
			'position' => $s['position'],
			'title_mode' => 'auto',
			'title' => '',
			'subtitle_mode' => 'auto',
			'subtitle' => '',
			'mode' => 'generated',
			'ready_image' => 0,
			'datetime_mode' => 'auto',
			'datetime' => '',
			'logo_options' => [],
		], (array) get_post_meta($id, self::CONFIG, true));
	}

	public static function clean_text(string $text): string
	{
		$text = strip_shortcodes($text);
		$text = preg_replace('/<\/(?:p|div|h[1-6]|li)>|<br\s*\/?\s*>/i', ' ', $text);
		return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
	}

	public static function sanitize(array $raw): array
	{
		$raw = array_merge(['mode' => 'generated', 'ready_image' => 0, 'datetime_mode' => 'auto', 'datetime' => '', 'logo_options' => []], $raw);
		$config = ['enabled' => ! empty($raw['enabled'])];
		foreach (['mode' => ['generated', 'ready'], 'datetime_mode' => ['auto', 'custom'], 'source' => ['featured', 'custom'], 'placement' => ['overlay', 'below'], 'position' => ['none', 'left', 'right'], 'title_mode' => ['auto', 'custom'], 'subtitle_mode' => ['auto', 'custom', 'hidden']] as $key => $values) {
			if (! isset($raw[$key]) || ! in_array($raw[$key], $values, true)) {
				throw new \RuntimeException('Neplatné nastavení banneru.');
			}
			$config[$key] = $raw[$key];
		}
		foreach (['title', 'subtitle', 'datetime'] as $key) {
			if (! isset($raw[$key]) || ! is_string($raw[$key]) || mb_strlen($raw[$key]) > 600) {
				throw new \RuntimeException('Text banneru smí mít nejvýše 600 znaků.');
			}
			$config[$key] = self::clean_text($raw[$key]);
		}
		$config['image'] = isset($raw['image']) && is_scalar($raw['image']) ? absint($raw['image']) : 0;
		if ('generated' === $config['mode'] && 'custom' === $config['source'] && $config['enabled']) {
			Promo_Renderer::path($config['image']);
			if (! current_user_can('edit_post', $config['image'])) {
				throw new \RuntimeException('Nemáte oprávnění použít tento obrázek.');
			}
		}
		$config['ready_image'] = isset($raw['ready_image']) && is_scalar($raw['ready_image']) ? absint($raw['ready_image']) : 0;
		if ('ready' === $config['mode'] && $config['enabled']) {
			Promo_Renderer::path($config['ready_image']);
			if (! current_user_can('edit_post', $config['ready_image'])) {
				throw new \RuntimeException('Nemáte oprávnění použít tento banner.');
			}
		}
		if (! is_array($raw['logo_options']) || count($raw['logo_options']) > 100) {
			throw new \RuntimeException('Neplatný výběr log.');
		}
		$config['logo_options'] = [];
		foreach ($raw['logo_options'] as $organizer => $option) {
			if (! ctype_digit((string) $organizer) || ! is_array($option) || ! isset($option['height']) || ! is_scalar($option['height']) || ! ctype_digit((string) $option['height']) || (int) $option['height'] < 1 || (int) $option['height'] > 100) {
				throw new \RuntimeException('Výška loga musí být celé číslo od 1 do 100 % výšky pruhu.');
			}
			$config['logo_options'][(int) $organizer] = ['show' => ! empty($option['show']), 'height' => (int) $option['height']];
		}
		return $config;
	}

	public static function date_text(int $id): string
	{
		$start = (string) get_post_meta($id, '_EventStartDate', true);
		$end = (string) get_post_meta($id, '_EventEndDate', true);
		$zone = (string) get_post_meta($id, '_EventTimezone', true);
		if (! $start || ! $end) {
			throw new \RuntimeException('Nejprve uložte datum začátku a konce akce.');
		}
		$tz = new \DateTimeZone($zone ?: wp_timezone_string());
		$a = new \DateTimeImmutable($start, $tz);
		$b = new \DateTimeImmutable($end, $tz);
		$days = [1 => 'PO', 'ÚT', 'ST', 'ČT', 'PÁ', 'SO', 'NE'];
		$first = $days[(int) $a->format('N')] . ' ' . $a->format('j. n. Y');
		$last = $days[(int) $b->format('N')] . ' ' . $b->format('j. n. Y');
		$same = $a->format('Y-m-d') === $b->format('Y-m-d');
		if (tribe_event_is_all_day($id)) {
			return $first . ($same ? '' : ' – ' . $last) . ' · celý den';
		}
		$unknown = End_Time::unknown($id);
		if ($same) {
			return $first . ($unknown ? ' od ' : ' ') . $a->format('H.i') . ($unknown ? '' : ' – ' . $b->format('H.i'));
		}
		return $first . ' ' . $a->format('H.i') . ' – ' . $last . ($unknown ? '' : ' ' . $b->format('H.i'));
	}

	public static function inputs(int $id, ?array $config = null, ?array $preview_organizers = null): array
	{
		$post = get_post($id);
		if (! $post || 'tribe_events' !== $post->post_type) {
			throw new \RuntimeException('Akce neexistuje.');
		}
		$c = $config ?? self::config($id);
		$s = self::settings();
		if ('ready' === $c['mode']) {
			return ['mode' => 'ready', 'image' => (int) $c['ready_image'], 'warnings' => []];
		}
		$excerpt = self::clean_text($post->post_excerpt ?: $post->post_content);
		if (mb_strlen($excerpt) > 100) {
			$excerpt = preg_replace('/\s+\S*$/u', '', mb_substr($excerpt, 0, 101)) . '…';
		}
		$title = 'custom' === $c['title_mode'] ? $c['title'] : self::clean_text($post->post_title);
		$subtitle = 'auto' === $c['subtitle_mode'] ? $excerpt : ('hidden' === $c['subtitle_mode'] ? '' : $c['subtitle']);
		if ('' === $title) {
			throw new \RuntimeException('Vyplňte název banneru.');
		}
		$logos = [];
		$heights = [];
		$warnings = [];
		foreach (self::organizers($id, $preview_organizers) as $organizer) {
			$option = $c['logo_options'][$organizer] ?? ['show' => true, 'height' => 60];
			if (! $option['show']) {
				continue;
			}
			$logo = Organizer_Logo::get($organizer);
			if ($logo) {
				$logos[] = $logo;
				$heights[] = (int) $option['height'];
			} else {
				$warnings[] = 'Chybí logo pořadatele: ' . self::clean_text(get_the_title($organizer));
			}
		}

		if (! $s['watermark'] && 'none' !== $c['position']) {
			$warnings[] = 'V nastavení není vybraný vodoznak; banner bude bez něj.';
		}
		$data = [
			'image' => (int) ('custom' === $c['source'] ? $c['image'] : get_post_thumbnail_id($id)),
			'title' => $title,
			'subtitle' => $subtitle,
			'datetime' => 'custom' === $c['datetime_mode'] ? $c['datetime'] : self::date_text($id),
			'logos' => $logos,
			'logo_heights' => $heights,
			'watermark' => 'none' === $c['position'] ? 0 : (int) $s['watermark'],
			'watermark_width' => (int) $s['watermark_width'],
			'placement' => $c['placement'],
			'position' => $c['position'],
		];
		$files = [];
		foreach (array_unique(array_filter(array_merge([$data['image'], $data['watermark']], $logos))) as $attachment) {
			try {
				$path = Promo_Renderer::path($attachment);
				$files[$attachment] = [$path, filesize($path), filemtime($path), get_post_meta($attachment, '_wp_attachment_metadata', true)];
			} catch (\Throwable $e) {
				$files[$attachment] = 'missing';
			}
		}
		$data['hash'] = hash('sha256', wp_json_encode([Promo_Renderer::VERSION, $data, $files]));
		$data['warnings'] = $warnings;
		return $data;
	}

	/** A preview may supply current editor IDs; it never persists the association. */
	public static function organizers(int $id, ?array $preview = null): array
	{
		$ids = [];
		if (null === $preview) {
			array_walk_recursive(get_post_meta($id, '_EventOrganizerID', false), static function ($value) use (&$ids) {
				if (absint($value)) {
					$ids[] = absint($value);
				}
			});
		} else {
			if (count($preview) > 100) {
				throw new \RuntimeException('Příliš mnoho pořadatelů.');
			}
			foreach ($preview as $value) {
				if (! is_scalar($value) || ! ctype_digit((string) $value) || (int) $value < 1 || 'tribe_organizer' !== get_post_type((int) $value) || 'trash' === get_post_status((int) $value) || ! current_user_can('read_post', (int) $value)) {
					throw new \RuntimeException('Neplatný pořadatel v náhledu. Nového pořadatele nejprve uložte, aby mu bylo možné přiřadit logo.');
				}
				$ids[] = (int) $value;
			}
		}
		return array_values(array_unique($ids));
	}

	/** Never returns a stale banner; callers fall back to the clean featured image. */
	public static function attachment(int $id): int
	{
		$config = self::config($id);
		if (! $config['enabled']) {
			return 0;
		}
		if ('ready' === $config['mode']) {
			try {
				Promo_Renderer::path((int) $config['ready_image']);
				return (int) $config['ready_image'];
			} catch (\Throwable $e) {
				return 0;
			}
		}
		$r = (array) get_post_meta($id, self::RESULT, true);
		if (empty($r['attachment']) || empty($r['hash'])) {
			return 0;
		}
		try {
			if (self::inputs($id)['hash'] !== $r['hash']) {
				return 0;
			}
			Promo_Renderer::path((int) $r['attachment']);
			return (int) $r['attachment'];
		} catch (\Throwable $e) {
			return 0;
		}
	}

	public static function state(int $id): array
	{
		if (! self::config($id)['enabled']) {
			return ['status' => 'disabled', 'message' => 'Promo banner je vypnutý.', 'url' => ''];
		}
		$attachment = self::attachment($id);
		if ($attachment) {
			return ['status' => 'ready', 'message' => 'ready' === self::config($id)['mode'] ? 'Používá se hotový banner beze změn.' : 'Banner je aktuální.', 'url' => wp_get_attachment_url($attachment), 'warnings' => self::inputs($id)['warnings']];
		}
		if ('ready' === self::config($id)['mode']) {
			return ['status' => 'error', 'message' => 'Vyberte dostupný hotový banner z knihovny médií.', 'url' => ''];
		}
		$error = get_post_meta($id, self::ERROR, true);
		return ['status' => $error ? 'error' : 'pending', 'message' => $error ?: 'Banner čeká na vygenerování. Uložte akci nebo spusťte generování uložených hodnot.', 'url' => ''];
	}

	public static function changed(int $id): void
	{
		if ('tribe_events' === get_post_type($id) && ! in_array(get_post_status($id), ['auto-draft', 'trash'], true)) {
			self::$dirty[$id] = true;
		}
	}

	public static function meta_changed($meta_id, $id, $key, $value): void
	{
		if (in_array($key, [self::CONFIG, '_thumbnail_id', '_EventStartDate', '_EventEndDate', '_EventTimezone', '_EventAllDay', '_EventOrganizerID', End_Time::META], true)) {
			self::changed((int) $id);
		}
		if (in_array($key, [Organizer_Logo::META_KEY, '_wp_attached_file', '_wp_attachment_metadata'], true) && ! get_post_meta($id, '_mlyn_promo_owner', true)) {
			self::shared_changed();
		}
	}

	/** Finish explicit editor saves after TEC has persisted all event metadata. */
	public static function after_save(int $id): void
	{
		self::$after_save[$id] = true;
		self::$dirty[$id] = true;
	}

	public static function flush(): void
	{
		foreach (array_keys(self::$dirty) as $id) {
			if (isset(self::$after_save[$id])) {
				wp_clear_scheduled_hook(self::JOB, [$id]);
				self::generate($id);
			} else {
				self::queue($id);
			}
		}
		self::$after_save = [];
		self::$dirty = [];
	}

	public static function queue(int $id): void
	{
		if ('tribe_events' !== get_post_type($id) || ! self::config($id)['enabled'] || in_array(get_post_status($id), ['auto-draft', 'trash'], true)) {
			wp_clear_scheduled_hook(self::JOB, [$id]);
			return;
		}
		if ('ready' === self::config($id)['mode']) {
			wp_clear_scheduled_hook(self::JOB, [$id]);
			return;
		}
		if (self::attachment($id)) {
			return;
		}
		delete_post_meta($id, self::ERROR);
		if (! wp_next_scheduled(self::JOB, [$id])) {
			$result = wp_schedule_single_event(time() + 5, self::JOB, [$id], true);
			if (is_wp_error($result)) {
				update_post_meta($id, self::ERROR, 'Úlohu se nepodařilo naplánovat. Použijte tlačítko generování.');
			}
		}
	}

	public static function shared_changed(): void
	{
		if (! wp_next_scheduled(self::SCAN, [0])) {
			wp_schedule_single_event(time() + 10, self::SCAN, [0]);
		}
	}

	public static function scan(int $after = 0): void
	{
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tribe_events' AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID LIMIT 100", $after));
		foreach ($ids as $id) {
			self::queue((int) $id);
		}
		if (count($ids) === 100) {
			wp_schedule_single_event(time() + 10, self::SCAN, [(int) end($ids)]);
		}
	}

	/** Generate from saved values only; previews use the renderer directly. */
	public static function generate(int $id)
	{
		if ('tribe_events' !== get_post_type($id) || ! self::config($id)['enabled'] || in_array(get_post_status($id), ['trash', 'auto-draft'], true)) {
			return 0;
		}
		if ('ready' === self::config($id)['mode']) {
			$ready = self::attachment($id);
			if ($ready) {
				delete_post_meta($id, self::ERROR);
				do_action('mlyn_event_promo_updated', $id, $ready);
				return $ready;
			}
			return new \WP_Error('promo_missing', 'Vyberte dostupný hotový banner.');
		}
		$lock = 'mlyn_promo_lock_' . $id;
		$locked_at = (int) get_option($lock, 0);
		if ($locked_at && $locked_at < time() - 600) {
			delete_option($lock);
		}
		if (! add_option($lock, time(), '', false)) {
			return new \WP_Error('busy', 'Banner se právě generuje. Zkuste to za chvíli.');
		}
		$file = '';
		$attachment = 0;
		try {
			$current = self::attachment($id);
			if ($current) {
				return $current;
			}
			$data = self::inputs($id);
			$result = Promo_Renderer::render($data);
			// Editors may change the event while rendering runs.
			if (! self::config($id)['enabled'] || self::inputs($id)['hash'] !== $data['hash']) {
				throw new \RuntimeException('Akce se během generování změnila. Spusťte generování znovu.');
			}
			$upload = wp_upload_bits('mlyn-promo-' . $id . '-' . wp_generate_uuid4() . '.jpg', null, $result['bytes']);
			if ($upload['error']) {
				throw new \RuntimeException('Soubor banneru se nepodařilo uložit.');
			}
			$file = $upload['file'];
			$attachment = wp_insert_attachment([
				'post_mime_type' => 'image/jpeg',
				'post_title' => $data['title'] . ' – promo',
				'post_status' => 'inherit',
				'post_parent' => $id,
				'meta_input' => ['_mlyn_promo_owner' => $id, '_wp_attachment_image_alt' => $data['title'] . ' — ' . $data['datetime']],
			], $file, $id, true);
			if (is_wp_error($attachment)) {
				$attachment = 0;
				throw new \RuntimeException('Záznam banneru se nepodařilo uložit.');
			}
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$metadata = wp_generate_attachment_metadata($attachment, $file);
			if (empty($metadata['width'])) {
				throw new \RuntimeException('Náhledy banneru se nepodařilo vytvořit.');
			}
			wp_update_attachment_metadata($attachment, $metadata);
			if (! self::config($id)['enabled'] || self::inputs($id)['hash'] !== $data['hash']) {
				throw new \RuntimeException('Akce se během generování změnila. Spusťte generování znovu.');
			}
			update_post_meta($id, self::RESULT, ['attachment' => $attachment, 'hash' => $data['hash'], 'generated_at' => gmdate('c')]);
			delete_post_meta($id, self::ERROR);
			do_action('mlyn_event_promo_updated', $id, $attachment);
			return $attachment;
		} catch (\Throwable $e) {
			if ($attachment) {
				wp_delete_attachment($attachment, true);
			} elseif ($file) {
				wp_delete_file($file);
			}
			$message = $e instanceof \ImagickException ? 'Obrázek nelze zpracovat. Zkontrolujte formát obrázků a dostupnou paměť serveru.' : $e->getMessage();
			update_post_meta($id, self::ERROR, $message);
			return new \WP_Error('promo_failed', $message);
		} finally {
			delete_option($lock);
		}
	}

	public static function duplicate(int $new, int $old): void
	{
		update_post_meta($new, self::CONFIG, self::config($old));
		self::changed($new);
	}
}
