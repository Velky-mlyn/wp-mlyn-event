<?php

namespace Mlyn_Event;

if (! defined('ABSPATH')) {
	exit;
}

final class Promo_Admin
{
	public static function register(): void
	{
		add_action('admin_menu', [self::class, 'menu']);
		add_action('admin_enqueue_scripts', [self::class, 'assets']);
		add_action('add_meta_boxes_tribe_events', [self::class, 'box']);
		add_action('save_post_tribe_events', [self::class, 'save'], 200, 2);
		add_action('admin_post_mlyn_event_settings', [self::class, 'save_settings']);
		add_action('wp_ajax_mlyn_promo', [self::class, 'ajax']);
		add_action('admin_notices', [self::class, 'notice']);
	}

	public static function menu(): void
	{
		add_submenu_page('edit.php?post_type=tribe_events', 'Nastavení Mlýn Event', 'Nastavení Mlýn Event', 'manage_options', 'mlyn-event-settings', [self::class, 'page']);
	}

	public static function assets(): void
	{
		$screen = get_current_screen();
		if (! current_user_can('manage_options') || ! $screen || ! (('tribe_events' === $screen->post_type && 'post' === $screen->base) || strpos($screen->id, 'mlyn-event-settings') !== false)) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script('mlyn-promo', plugins_url('assets/promo.js', MLYN_EVENT_FILE), ['media-editor'], MLYN_EVENT_VERSION, true);
		wp_enqueue_style('mlyn-promo', plugins_url('assets/promo.css', MLYN_EVENT_FILE), [], MLYN_EVENT_VERSION);
		wp_localize_script('mlyn-promo', 'mlynPromo', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('mlyn_promo')]);
	}

	public static function box(\WP_Post $post): void
	{
		if (current_user_can('manage_options') && current_user_can('edit_post', $post->ID)) {
			add_meta_box('mlyn-promo', 'Promo banner akce', [self::class, 'editor'], 'tribe_events', 'normal', 'default');
		}
	}

	private static function select(string $name, string $label, string $value, array $choices): void
	{
		echo '<label class="mlyn-promo-field"><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '">';
		foreach ($choices as $key => $text) {
			echo '<option value="' . esc_attr($key) . '" ' . selected($value, $key, false) . '>' . esc_html($text) . '</option>';
		}
		echo '</select></label>';
	}

	private static function picker(string $name, int $id): void
	{
		echo '<div class="mlyn-promo-picker"><input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $id) . '"><div class="mlyn-promo-thumb">';
		if ($id) {
			echo wp_get_attachment_image($id, 'medium');
		}
		echo '</div><button class="button mlyn-promo-pick" type="button">Vybrat obrázek</button> <button class="button-link-delete mlyn-promo-remove" type="button" ' . ($id ? '' : 'hidden') . '>Odebrat</button></div>';
	}

	public static function editor(\WP_Post $post): void
	{
		$c = Promo_Banner::config($post->ID);
		wp_nonce_field('mlyn_promo_save_' . $post->ID, 'mlyn_promo_nonce');
		$state = Promo_Banner::state($post->ID);
		$automatic = [];
		try {
			$automatic = Promo_Banner::inputs($post->ID, array_merge($c, ['mode' => 'generated', 'title_mode' => 'auto', 'subtitle_mode' => 'auto', 'datetime_mode' => 'auto']));
		} catch (\Throwable $e) {
		}
?>
		<div class="mlyn-promo-editor" data-event="<?php echo esc_attr((string) $post->ID); ?>" data-organizers="<?php echo esc_attr(wp_json_encode(Promo_Banner::organizers($post->ID))); ?>">
			<label><input type="checkbox" name="mlyn_promo[enabled]" value="1" <?php checked($c['enabled']); ?>> <strong>Používat speciální promo banner</strong></label>
			<p class="description">Vypnutý banner znamená použití náhledového obrázku akce.</p>
			<?php self::select('mlyn_promo[mode]', 'Typ promo banneru', $c['mode'], ['generated' => 'Vygenerovat z fotografie', 'ready' => 'Použít hotový banner beze změn']); ?>
			<div data-ready-banner>
				<?php self::picker('mlyn_promo[ready_image]', (int) $c['ready_image']); ?>
				<p class="description">Hotový banner se použije přímo, bez vodoznaku, pruhu nebo dalších úprav. Náhledový obrázek akce zůstane původní.</p>
			</div>
			<div class="mlyn-promo-fields" data-generated-fields>
				<?php self::select('mlyn_promo[source]', 'Zdroj fotografie', $c['source'], ['featured' => 'Náhledový obrázek akce', 'custom' => 'Jiný obrázek']); ?>
				<div data-custom-image><?php self::picker('mlyn_promo[image]', (int) $c['image']); ?></div>
				<?php self::select('mlyn_promo[placement]', 'Černý pruh', $c['placement'], ['overlay' => 'Přes spodní část fotografie', 'below' => 'Pod fotografií (zvětší výšku)']); ?>
				<?php self::select('mlyn_promo[position]', 'Vodoznak', $c['position'], ['left' => 'Vlevo nahoře', 'right' => 'Vpravo nahoře', 'none' => 'Bez vodoznaku']); ?>
				<?php self::select('mlyn_promo[title_mode]', 'Název', $c['title_mode'], ['auto' => 'Z názvu akce', 'custom' => 'Vlastní text']); ?>
				<label class="mlyn-promo-field" data-title-custom><span>Vlastní název banneru</span><input type="text" maxlength="600" name="mlyn_promo[title]" value="<?php echo esc_attr($c['title']); ?>"></label>
				<p class="description" data-title-auto><?php echo esc_html($automatic['title'] ?? 'Nejprve uložte akci.'); ?></p>
				<?php self::select('mlyn_promo[subtitle_mode]', 'Podtitulek', $c['subtitle_mode'], ['auto' => 'Z úryvku nebo popisu akce', 'custom' => 'Vlastní text', 'hidden' => 'Nezobrazovat']); ?>
				<label class="mlyn-promo-field" data-subtitle-custom><span>Vlastní podtitulek</span><textarea maxlength="600" rows="2" name="mlyn_promo[subtitle]"><?php echo esc_textarea($c['subtitle']); ?></textarea></label>
				<p class="description" data-subtitle-auto><?php echo esc_html($automatic['subtitle'] ?? ''); ?></p>
				<?php self::select('mlyn_promo[datetime_mode]', 'Datum a čas', $c['datetime_mode'], ['auto' => 'Z termínu akce', 'custom' => 'Vlastní text']); ?>
				<label class="mlyn-promo-field" data-datetime-custom><span>Vlastní řádek data a času</span><input type="text" maxlength="600" name="mlyn_promo[datetime]" value="<?php echo esc_attr($c['datetime']); ?>"></label>
				<p class="description" data-datetime-auto><?php echo esc_html($automatic['datetime'] ?? 'Nejprve uložte termín akce.'); ?></p>
				<h4>Loga pořadatelů</h4>
				<div data-logo-list><?php echo self::logo_controls(Promo_Banner::organizers($post->ID), $c); ?></div>
				<p class="description">Vyberte loga pro banner. Pořadí odpovídá aktuálnímu seznamu pořadatelů akce. Výška se zadává v % výšky černého pruhu (výchozí 60 %). Pokud loga přesáhnou dostupnou šířku, zmenší se společně při zachování proporcí. Nejvýše 6 zobrazených log.</p>

			</div>
			<p class="description"><b>Automatický název, podtitulek, termín a náhledový obrázek vycházejí z naposledy uložených údajů akce. Náhled nic neukládá. Po uložení akce se banner automaticky aktualizuje.</b></p>
			<p><button type="button" class="button button-secondary" data-promo-preview>Vygenerovat náhled</button> <button type="button" class="button" data-promo-generate>Generovat z uložených hodnot</button></p>
			<p class="mlyn-promo-message" role="status" aria-live="polite"><?php echo esc_html($state['message']); ?></p>
			<div class="mlyn-promo-warnings"></div>
			<div class="mlyn-promo-output"><?php if ($state['url']) {
												echo '<img alt="Uložený promo banner" src="' . esc_url($state['url']) . '">';
											} ?></div>
		</div>
		<?php
	}

	public static function logo_controls(array $organizers, array $config): string
	{
		ob_start();
		foreach ($organizers as $organizer) {
			$logo = Organizer_Logo::get($organizer);
			$option = $config['logo_options'][$organizer] ?? ['show' => true, 'height' => 60];
			$prefix = 'mlyn_promo[logo_options][' . $organizer . ']';
		?>
			<div class="mlyn-promo-logo-row" data-organizer="<?php echo esc_attr((string) $organizer); ?>">
				<input type="hidden" name="<?php echo esc_attr($prefix . '[show]'); ?>" value="0">
				<label><input type="checkbox" data-logo-show name="<?php echo esc_attr($prefix . '[show]'); ?>" value="1" <?php checked($option['show']); ?>> <?php echo esc_html(get_the_title($organizer)); ?></label>
				<?php if ($logo) {
					echo wp_get_attachment_image($logo, 'thumbnail', false, ['class' => 'mlyn-promo-logo-thumb']);
				} else {
					echo '<span class="description">Logo není přiřazené.</span>';
				} ?>
				<label>Výška (% pruhu) <input type="number" data-logo-height name="<?php echo esc_attr($prefix . '[height]'); ?>" min="1" max="100" step="1" value="<?php echo esc_attr((string) $option['height']); ?>"></label>
			</div>
		<?php
		}
		if (! $organizers) {
			echo '<p class="description">Akce zatím nemá vybrané pořadatele.</p>';
		}
		return ob_get_clean();
	}

	private static function preview_organizers(int $id): ?array
	{
		if (! isset($_POST['organizers'])) {
			return null;
		}
		if (! is_string($_POST['organizers'])) {
			throw new \RuntimeException('Neplatný seznam pořadatelů.');
		}
		$ids = json_decode(wp_unslash($_POST['organizers']), true);
		if (! is_array($ids)) {
			throw new \RuntimeException('Neplatný seznam pořadatelů.');
		}
		return Promo_Banner::organizers($id, $ids);
	}

	public static function save(int $id, \WP_Post $post): void
	{
		if (wp_is_post_revision($id) || wp_is_post_autosave($id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || ! current_user_can('manage_options') || ! current_user_can('edit_post', $id)) {
			return;
		}
		$nonce = $_POST['mlyn_promo_nonce'] ?? '';
		if (! is_string($nonce) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'mlyn_promo_save_' . $id)) {
			return;
		}
		try {
			$raw = $_POST['mlyn_promo'] ?? null;
			if (! is_array($raw)) {
				throw new \RuntimeException('Chybí nastavení banneru.');
			}
			update_post_meta($id, Promo_Banner::CONFIG, wp_slash(Promo_Banner::sanitize(wp_unslash($raw))));
			Promo_Banner::after_save($id);
		} catch (\Throwable $e) {
			self::error_notice('Nastavení banneru nebylo uloženo: ' . $e->getMessage());
		}
	}

	private static function error_notice(string $message): void
	{
		set_transient('mlyn_promo_notice_' . get_current_user_id(), $message, 120);
	}

	public static function notice(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$message = get_transient('mlyn_promo_notice_' . get_current_user_id());
		if ($message) {
			delete_transient('mlyn_promo_notice_' . get_current_user_id());
			echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
		}
	}

	public static function page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$s = Promo_Banner::settings();
		?>
		<div class="wrap mlyn-promo-settings">
			<h1>Nastavení Mlýn Event</h1>
			<?php if (isset($_GET['saved'])) {
				echo '<div class="notice notice-success"><p>Nastavení bylo uloženo.</p></div>';
			} ?>
			<?php if (! class_exists('\Imagick')) {
				echo '<div class="notice notice-error"><p>Na serveru chybí PHP Imagick. Generování bannerů nebude fungovat.</p></div>';
			} ?>
			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
				<input type="hidden" name="action" value="mlyn_event_settings">
				<?php wp_nonce_field('mlyn_event_settings'); ?>
				<h2>Promo bannery</h2>
				<p>Společný vodoznak a výchozí volby nových bannerů. Fotografie si zachová poměr stran; výstup má šířku nejvýše 1920 px. Pruh pod fotografií zvětší výšku.</p>
				<h3>Vodoznak</h3>
				<?php self::picker('watermark', (int) $s['watermark']); ?>
				<p class="description">Doporučujeme průhledné PNG. Bez vybraného obrázku se vodoznak nevykreslí.</p>
				<label class="mlyn-promo-field"><span>Šířka vodoznaku (% šířky fotografie)</span><input type="number" name="watermark_width" min="5" max="35" value="<?php echo esc_attr((string) $s['watermark_width']); ?>"></label>
				<?php self::select('position', 'Výchozí umístění vodoznaku', $s['position'], ['left' => 'Vlevo nahoře', 'right' => 'Vpravo nahoře', 'none' => 'Bez vodoznaku']); ?>
				<?php self::select('placement', 'Výchozí umístění pruhu', $s['placement'], ['overlay' => 'Přes spodní část fotografie', 'below' => 'Pod fotografií (zvětší výšku)']); ?>
				<p class="description">Změna vodoznaku aktualizuje závislé bannery na pozadí. Výchozí umístění nepřepisuje již uložené volby jednotlivých akcí.</p>
				<p class="description">Hromadná aktualizace po změně log nebo vodoznaku používá WP-Cron. Pokud úlohy webu neběží, lze banner jednotlivé akce aktualizovat jejím uložením nebo tlačítkem generování.</p>
				<?php submit_button('Uložit nastavení'); ?>
			</form>
			<hr>
			<h2>Hromadně vypnout promo bannery</h2>
			<p>Vybere akce vytvořené do konce zvoleného dne v časovém pásmu webu <strong><?php echo esc_html(wp_timezone_string()); ?></strong>. Rozhoduje datum vytvoření záznamu, nikoli termín konání akce. U nových akcí se vytvoření ukládá samostatně. U starších akcí bez tohoto údaje se použije datum záznamu ve WordPressu; to může odpovídat datu publikování nebo plánované publikace. Zahrnuje publikované, naplánované, soukromé, čekající akce a koncepty; vynechává koš a automatické koncepty.</p>
			<p>Vypnutí zachová obrázky i nastavení bannerů. U jednotlivé akce lze banner později znovu zapnout.</p>
			<div class="mlyn-promo-bulk">
				<label>Akce vytvořené nejpozději dne <input type="date" data-cutoff value="<?php echo esc_attr(current_datetime()->modify('-1 day')->format('Y-m-d')); ?>"></label>
				<button type="button" class="button" data-bulk-preview>Zobrazit počet akcí</button>
				<p role="status" aria-live="polite" data-bulk-message></p>
				<button type="button" class="button button-primary" data-bulk-apply hidden>Potvrdit a vypnout bannery vybraných akcí</button>
			</div>
		</div>
<?php
	}

	public static function save_settings(): void
	{
		if (! current_user_can('manage_options')) {
			wp_die('Nemáte oprávnění.', '', ['response' => 403]);
		}
		check_admin_referer('mlyn_event_settings');
		$ok = false;
		try {
			$raw = wp_unslash($_POST);
			foreach (['watermark', 'watermark_width', 'position', 'placement'] as $key) {
				if (! isset($raw[$key]) || ! is_string($raw[$key])) {
					throw new \RuntimeException('Neplatné nastavení.');
				}
			}
			if (! ctype_digit($raw['watermark']) || ! ctype_digit($raw['watermark_width']) || ! in_array($raw['position'], ['none', 'left', 'right'], true) || ! in_array($raw['placement'], ['overlay', 'below'], true) || (int) $raw['watermark_width'] < 5 || (int) $raw['watermark_width'] > 35) {
				throw new \RuntimeException('Neplatné nastavení vodoznaku.');
			}
			$id = (int) $raw['watermark'];
			if ($id) {
				Promo_Renderer::path($id);
				if (! current_user_can('edit_post', $id)) {
					throw new \RuntimeException('Nemáte oprávnění použít tento obrázek.');
				}
			}
			update_option(Promo_Banner::SETTINGS, array_merge(Promo_Banner::settings(), ['watermark' => $id, 'watermark_width' => (int) $raw['watermark_width'], 'position' => $raw['position'], 'placement' => $raw['placement']]), false);
			$ok = true;
		} catch (\Throwable $e) {
			self::error_notice($e->getMessage());
		}
		wp_safe_redirect(admin_url('edit.php?post_type=tribe_events&page=mlyn-event-settings' . ($ok ? '&saved=1' : '')));
		exit;
	}

	/** A stable preview boundary prevents later-created events joining a confirmed batch. */
	public static function bulk_preview(string $date): array
	{
		global $wpdb;
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
		if (! $parsed || $parsed->format('Y-m-d') !== $date) {
			throw new \RuntimeException('Vyberte platné datum.');
		}
		$job = ['cutoff' => $date . ' 23:59:59', 'max' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"), 'after' => 0, 'done' => 0];
		$job['count'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p WHERE post_type = 'tribe_events' AND post_status IN ('publish','future','private','pending','draft') AND COALESCE((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_mlyn_event_created_at' LIMIT 1), p.post_date) <= %s AND ID <= %d", $job['cutoff'], $job['max']));
		$token = wp_generate_uuid4();
		set_transient('mlyn_promo_bulk_' . get_current_user_id() . '_' . $token, $job, HOUR_IN_SECONDS);
		return ['token' => $token, 'count' => $job['count']];
	}

	public static function bulk_apply(string $token): array
	{
		global $wpdb;
		$key = 'mlyn_promo_bulk_' . get_current_user_id() . '_' . $token;
		$job = get_transient($key);
		if (! is_array($job)) {
			throw new \RuntimeException('Výběr vypršel. Zobrazte počet akcí znovu.');
		}
		$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} p WHERE post_type = 'tribe_events' AND post_status IN ('publish','future','private','pending','draft') AND COALESCE((SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_mlyn_event_created_at' LIMIT 1), p.post_date) <= %s AND ID <= %d AND ID > %d ORDER BY ID LIMIT 50", $job['cutoff'], $job['max'], $job['after']));
		foreach ($ids as $id) {
			if (! current_user_can('edit_post', (int) $id)) {
				continue;
			}
			$config = Promo_Banner::config((int) $id);
			$config['enabled'] = false;
			update_post_meta((int) $id, Promo_Banner::CONFIG, $config);
			wp_clear_scheduled_hook(Promo_Banner::JOB, [(int) $id]);
			++$job['done'];
		}
		$complete = count($ids) < 50;
		if ($complete) {
			delete_transient($key);
		} else {
			$job['after'] = (int) end($ids);
			set_transient($key, $job, HOUR_IN_SECONDS);
		}
		return ['done' => $job['done'], 'complete' => $complete];
	}

	public static function ajax(): void
	{
		if (! current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Nemáte oprávnění.'], 403);
		}
		check_ajax_referer('mlyn_promo', 'nonce');
		try {
			$op = isset($_POST['op']) && is_string($_POST['op']) ? $_POST['op'] : '';
			if ('bulk_preview' === $op) {
				wp_send_json_success(self::bulk_preview(sanitize_text_field(wp_unslash($_POST['cutoff'] ?? ''))));
			}
			if ('bulk_apply' === $op) {
				wp_send_json_success(self::bulk_apply(sanitize_text_field(wp_unslash($_POST['token'] ?? ''))));
			}
			$id = absint($_POST['event'] ?? 0);
			if ('tribe_events' !== get_post_type($id) || ! current_user_can('edit_post', $id)) {
				wp_send_json_error(['message' => 'Nemáte oprávnění upravit tuto akci.'], 403);
			}
			if ('organizers' === $op) {
				$organizers = self::preview_organizers($id) ?? Promo_Banner::organizers($id);
				wp_send_json_success(['html' => self::logo_controls($organizers, Promo_Banner::config($id))]);
			}
			if ('status' === $op) {
				wp_send_json_success(Promo_Banner::state($id));
			}
			if ('preview' === $op) {
				$raw = $_POST['config'] ?? [];
				if (! is_array($raw)) {
					throw new \RuntimeException('Neplatné nastavení.');
				}
				$c = Promo_Banner::sanitize(wp_unslash($raw));
				if (! $c['enabled']) {
					throw new \RuntimeException('Pro náhled nejprve zapněte promo banner.');
				}
				if ('ready' === $c['mode']) {
					wp_send_json_success(['url' => wp_get_attachment_url($c['ready_image']), 'message' => 'Náhled hotového banneru beze změn — pro použití aktualizujte akci.', 'warnings' => []]);
				}
				$data = Promo_Banner::inputs($id, $c, self::preview_organizers($id));
				$r = Promo_Renderer::render($data);
				wp_send_json_success(['url' => 'data:image/jpeg;base64,' . base64_encode($r['bytes']), 'message' => 'Náhled — není uložený. Pro použití těchto voleb aktualizujte akci.', 'warnings' => $data['warnings']]);
			}
			if ('generate' === $op) {
				$r = Promo_Banner::generate($id);
				if (is_wp_error($r)) {
					throw new \RuntimeException($r->get_error_message());
				}
				wp_send_json_success(Promo_Banner::state($id));
			}
			throw new \RuntimeException('Neznámá operace.');
		} catch (\Throwable $e) {
			$message = $e instanceof \ImagickException ? 'Obrázek nelze zpracovat. Zkontrolujte formát obrázků a paměť serveru.' : $e->getMessage();
			wp_send_json_error(['message' => $message], 400);
		}
	}
}
