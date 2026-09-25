<?php

namespace Mlyn_Event;

if (! defined('ABSPATH')) {
	exit;
}

/** Deterministic raster renderer shared by previews and saved banners. */
final class Promo_Renderer
{
	const VERSION = 2;

	public static function path(int $id): string
	{
		if (! $id || 'trash' === get_post_status($id) || ! in_array(get_post_mime_type($id), ['image/jpeg', 'image/png', 'image/webp'], true)) {
			throw new \RuntimeException('Vyberte obrázek JPEG, PNG nebo WebP.');
		}
		$file = get_attached_file($id);
		$path = $file ? realpath($file) : false;
		$base = realpath(wp_get_upload_dir()['basedir']);
		if (! $path || ! $base || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || ! is_readable($path)) {
			throw new \RuntimeException('Zdrojový obrázek není dostupný v místní knihovně médií.');
		}
		return $path;
	}

	private static function read(int $id): \Imagick
	{
		$path = self::path($id);
		$size = wp_getimagesize($path);
		if (! $size || $size[0] * $size[1] > 40000000) {
			throw new \RuntimeException('Obrázek je neplatný nebo přesahuje limit 40 megapixelů.');
		}
		$image = new \Imagick();
		$image->readImage($path . '[0]');
		// Support Imagick versions without autoOrientImage().
		switch ($image->getImageOrientation()) {
			case 2:
				$image->flopImage();
				break;
			case 3:
				$image->rotateImage('none', 180);
				break;
			case 4:
				$image->flipImage();
				break;
			case 5:
				$image->transposeImage();
				break;
			case 6:
				$image->rotateImage('none', 90);
				break;
			case 7:
				$image->transverseImage();
				break;
			case 8:
				$image->rotateImage('none', 270);
				break;
		}
		$image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
		$image->setImagePage(0, 0, 0, 0);
		$image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
		return $image;
	}

	/** @return array JPEG bytes and dimensions. No database/filesystem mutations. */
	public static function render(array $data): array
	{
		if (! class_exists('\Imagick')) {
			throw new \RuntimeException('Generování vyžaduje rozšíření PHP Imagick. Požádejte správce hostingu o jeho zapnutí.');
		}
		$photo = self::read($data['image']);
		$w = min(1920, $photo->getImageWidth());
		$h = (int) round($photo->getImageHeight() * $w / $photo->getImageWidth());
		$strip = (int) round($w * .165);
		if ($w < 600 || $h < $strip * 2 || $h > $w * 3) {
			throw new \RuntimeException('Použijte fotografii širokou alespoň 600 px, která není extrémně úzká nebo vysoká.');
		}
		$photo->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
		$below = 'below' === $data['placement'];
		$height = $h + ($below ? $strip : 0);
		$top = $below ? $h : $h - $strip;
		$canvas = new \Imagick();
		$canvas->newImage($w, $height, new \ImagickPixel('black'), 'jpeg');
		$canvas->compositeImage($photo, \Imagick::COMPOSITE_OVER, 0, 0);
		$photo->clear();
		$draw = new \ImagickDraw();
		$draw->setFillColor('black');
		$draw->rectangle(0, $top, $w, $height);
		$canvas->drawImage($draw);
		$draw->clear();
		$pad = (int) round($w * .045);
		if ($data['watermark'] && 'none' !== $data['position']) {
			$mark = self::read($data['watermark']);
			$mark->thumbnailImage((int) round($w * $data['watermark_width'] / 100), max(1, (int) round(min($top - 2 * $pad, $h * .25))), true);
			$x = 'right' === $data['position'] ? $w - $pad - $mark->getImageWidth() : $pad;
			$canvas->compositeImage($mark, \Imagick::COMPOSITE_OVER, $x, $pad);
			$mark->clear();
		}
		$count = count($data['logos']);
		if ($count > 6) {
			throw new \RuntimeException('Šablona podporuje nejvýše 6 log. V panelu banneru odškrtněte nadbytečná loga.');
		}
		$text_width = $count ? (int) round($w * .59) : $w - 2 * $pad;
		self::text($canvas, $data, $pad, $top + (int) round($w * .017), $text_width, $strip - (int) round($w * .034), $w);
		if ($count) {
			$left = (int) round($w * .69);
			$area = $w - $left - $pad;
			$gap = (int) round($w * .015);
			$items = [];
			$total_width = 0;
			foreach ($data['logos'] as $i => $id) {
				$logo = self::read($id);
				$target_h = max(1, (int) round($strip * ($data['logo_heights'][$i] ?? 60) / 100));
				$target_w = max(1, (int) round($logo->getImageWidth() * $target_h / $logo->getImageHeight()));
				$items[] = [$logo, $target_w, $target_h];
				$total_width += $target_w;
			}
			// One common fit factor keeps the requested relative heights intact.
			$fit = min(1, ($area - $gap * ($count - 1)) / max(1, $total_width));
			$x = $left + (int) round(($area - $total_width * $fit - $gap * ($count - 1)) / 2);
			foreach ($items as [$logo, $target_w, $target_h]) {
				$logo->resizeImage(max(1, (int) round($target_w * $fit)), max(1, (int) round($target_h * $fit)), \Imagick::FILTER_LANCZOS, 1);
				$y = $top + (int) round(($strip - $logo->getImageHeight()) / 2);
				$canvas->compositeImage($logo, \Imagick::COMPOSITE_OVER, $x, $y);
				$x += $logo->getImageWidth() + $gap;
				$logo->clear();
			}
		}
		$canvas->setImageFormat('jpeg');
		$canvas->setImageCompressionQuality(92);
		$canvas->stripImage();
		$result = ['bytes' => $canvas->getImageBlob(), 'width' => $w, 'height' => $height];
		$canvas->clear();
		return $result;
	}

	private static function pen(float $size, bool $bold = false): \ImagickDraw
	{
		$pen = new \ImagickDraw();
		$pen->setFont(MLYN_EVENT_DIR . 'assets/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf');
		$pen->setFontSize($size);
		$pen->setFillColor('white');
		return $pen;
	}

	private static function wrap(\Imagick $canvas, \ImagickDraw $pen, string $text, int $width): array
	{
		if ('' === $text) {
			return [];
		}
		$lines = [];
		$line = '';
		foreach (preg_split('/\s+/u', $text) as $word) {
			if ($canvas->queryFontMetrics($pen, $word)['textWidth'] > $width) {
				return array_fill(0, 99, '');
			}
			$next = '' === $line ? $word : $line . ' ' . $word;
			if ($canvas->queryFontMetrics($pen, $next)['textWidth'] > $width) {
				$lines[] = $line;
				$line = $word;
			} else {
				$line = $next;
			}
		}
		if ('' !== $line) {
			$lines[] = $line;
		}
		return $lines;
	}

	private static function text(\Imagick $canvas, array $data, int $x, int $y, int $width, int $height, int $w): void
	{
		$groups = [[$data['title'], .029, true, 2], [$data['subtitle'], .022, false, 2], [$data['datetime'], .026, false, 2]];
		for ($scale = 100; $scale >= 70; $scale -= 5) {
			$layout = [];
			$used = 0;
			$fits = true;
			foreach ($groups as $group) {
				$size = $w * $group[1] * $scale / 100;
				$pen = self::pen($size, $group[2]);
				$lines = self::wrap($canvas, $pen, $group[0], $width);
				if (count($lines) > $group[3]) {
					$fits = false;
				}
				$line_h = $size * 1.24;
				$used += count($lines) * $line_h + ($lines ? $w * .003 : 0);
				$layout[] = [$pen, $lines, $line_h, $size];
			}
			if ($fits && $used <= $height) {
				// Center the actual ink bounds, independent of font ascenders/line padding.
				$layer = new \Imagick();
				$layer->newImage($width, $height, new \ImagickPixel('transparent'), 'png');
				$line_y = 0;
				foreach ($layout as [$pen, $lines, $line_h, $size]) {
					foreach ($lines as $line) {
						$layer->annotateImage($pen, 0, $line_y + $size, 0, $line);
						$line_y += $line_h;
					}
					if ($lines) {
						$line_y += $w * .003;
					}
				}
				$layer->trimImage(0);
				$offset = $layer->getImagePage();
				$layer->setImagePage(0, 0, 0, 0);
				$canvas->compositeImage($layer, \Imagick::COMPOSITE_OVER, $x + $offset['x'], $y + (int) round(($height - $layer->getImageHeight()) / 2));
				$layer->clear();
				return;
			}
		}
		throw new \RuntimeException('Text se nevejde do pruhu při čitelné velikosti písma. Zkraťte vlastní název nebo podtitulek.');
	}
}
