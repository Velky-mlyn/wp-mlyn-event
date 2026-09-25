# Mlýn Event

Site-specific WP add-on plugin providing event metadata and administrative tools for The Events Calendar WP plugin.

## Features

- Adds a separate administrator-only organizer logo selector with a Media Library preview.

- Adds optional capacity, available-place, and occupancy-note fields to events.
- Adds a featured-image focal-point picker and detail-banner preview to event editing.
- Treats an explicit zero available-place value as fully occupied even when capacity is empty.
- Exposes a small public API and update action for integrations such as Mlýn Event Intake.
- Adds a secure **Duplikovat** row action to the Events admin list.
- Creates duplicates as independent drafts and opens them for editing.
- Copies supported event content, dates, venue, organizers, terms, image, presentation settings, occupancy, and image focal point without copying generated or intake synchronization identities.
- Defers to the native duplicate feature if Events Calendar Pro is active.

## Month-view images

Multi-day events with featured images show a thumbnail inside their own date-span bar, beside the title, on each visible weekly segment. Weeks with images use equal-height multi-day lanes so overlapping bars and empty continuation slots stay aligned. Existing bars and hover tooltips remain available. Events without an image keep the standard TEC presentation. This uses TEC template hooks and does not modify the official plugin or duplicate its templates. The mobile month view continues to use TEC's native day-selection/event-list interface.

## Public API

- `mlyn_event_get_organizer_logo_id( $organizer_id )` returns a valid logo attachment ID or zero.
- `mlyn_event_organizer_logo_updated` action receives organizer ID, new logo ID, and previous logo ID after a change.

- `mlyn_event_get_occupancy( $event_id )`
- `mlyn_event_set_occupancy( $event_id, $capacity, $available_places, $note, $notify = true )`
- `mlyn_event_occupancy_updated` action after an occupancy update
- `mlyn_event_get_image_focal_point( $event_id )`
- `mlyn_event_set_image_focal_point( $event_id, $x, $y, $notify = true )`
- `mlyn_event_image_focal_point_updated` action after a focal-point update
- `mlyn_event_duplicated` action after a successful duplicate

## Unknown end times

The event editor includes **Čas konce není znám**, implemented with native WordPress metadata. Keep a final date for multi-day or overnight events. TEC stores 23:59:59 on that date in the event timezone for expiry, duration and occurrence queries; the clock time is omitted from public schedules, REST end fields, JSON-LD and ICS exports. All-day events retain their existing behavior. Google Calendar receives equal start/end timestamps and an explanatory note because its template links require a date range; calendar applications may assign their own duration. ICS includes X-MLYN-END-DATE to retain the final date without asserting a known end time.

`mlyn_event_end_time_unknown( $id )` reads the flag; `mlyn_event_set_end_time_unknown( $id, $unknown, $notify = true )` updates it and emits `mlyn_event_end_time_updated`. Duplicates preserve the flag. Existing events are not automatically reclassified, including events with equal start/end timestamps.

## Changelog

### 1.5.0

- Added administrator-only promo banner configuration, deterministic image generation and preview, shared watermark settings, automatic updates, and creation-date bulk disabling.
- Added ready-made promo banners used directly without rendering or changing the featured image.
- Added custom date/time text and organizer logo checkboxes with individual height percentages (default 60%).
- Centered the strip text vertically using its actual rendered bounds; logo sizing preserves relative heights across differing aspect ratios.
- Previews and logo controls follow the current unsaved organizer list, including additions, removals and reordering.
- Preserved existing events as disabled by default and exposed explicit promo image APIs for later theme/signage integration.

### 1.4.0

- Added **Logo pořadatele** to organizer editing for administrators, with image selection/upload, preview, replacement and removal. Save the organizer to persist changes. Logos remain separate from featured images for future promo-banner generation.

### 1.3.0

- Added per-event unknown end times, finite internal expiry, public output filters and duplication support.

### 1.2.1

- Moved multi-day thumbnails inside their event bars and aligned overlapping lanes across each week.

### 1.2.0

- Added visible featured-image thumbnails for multi-day events in the month grid.

### 1.1.0

- Added a WordPress-native focal-point picker with a live event-detail banner preview.
- Bound focal points to featured-image attachments so stale coordinates cannot affect replacement images.
- Included focal-point settings when duplicating events.

### 1.0.0

- Extracted event occupancy ownership from Mlýn Event Intake without changing existing meta keys.
- Added event duplication through The Events Calendar ORM.

## Data retention

Event metadata is deliberately retained when the plugin is removed.

## Promo banners (1.5.0)

Administrators configure **Promo banner akce** on the event edit screen. The featured image is always retained as the original; a different Media Library photo can be selected for the banner. The black strip overlays the photo by default. **Pod fotografií** appends the strip and increases image height without cropping the source. Output is a JPEG up to 1920 px wide, preserving the photo's aspect ratio. Sources must be local JPEG, PNG or WebP attachments, at least 600 px wide and no larger than 40 megapixels; extremely narrow/tall images are rejected with an editor message.

The title follows the event title unless overridden. The subtitle follows the excerpt (or cleaned event content), trimmed near 100 characters; it can be replaced or hidden. Overrides survive event updates. Dates follow saved event data, with Czech weekdays, the year, all-day ranges, and unknown end-time support; a custom date/time line can override the banner text without changing event dates. Organizer logos follow the assigned organizer order, with up to six selected logos. Missing logos are omitted with a warning. Text wraps and scales within limits; excessive text produces an error instead of clipping or illegibly small lettering.

**Vygenerovat náhled** renders the panel's current options against the event's last saved title, content, dates and featured image, plus the current unsaved organizer selection in the TEC event editor. It neither saves options nor creates a Media Library attachment. Save the event after changing those event fields. Saving the event persists the panel and renders from the final saved TEC data at request shutdown. **Generovat z uložených hodnot** retries rendering using saved values. Generated files use unique filenames and normal WordPress responsive sizes. Earlier generated attachments are deliberately retained, because external downloads or presentations may still reference them.

Under **Akce → Nastavení Mlýn Event**, select the shared watermark and its width, and the default strip/watermark positions. Defaults apply to unconfigured events; saved per-event choices are preserved. Changes to shared assets enqueue a batched scan; changed event inputs enqueue generation as well. These background jobs require working WordPress cron/loopback requests (or a server cron invoking `wp-cron.php` with the web PHP runtime). No visitor request renders an image. The editor shows pending/errors and offers an immediate retry. The renderer needs PHP Imagick with JPEG/PNG/WebP support and uses bundled DejaVu fonts; their license is in `assets/fonts/LICENSE.txt`. The separate local Docker WP-CLI image may lack image codecs even when the web container has them; run rendering tests/jobs in the web container.

On the first initialization of 1.5.0, the plugin records the highest existing event ID. Unconfigured events at or below that boundary default to **disabled**; newer events default to **enabled**. This prevents double-branding existing Canva images. No historic image is replaced or modified. Individual saved settings override this default.

The settings page also provides **Hromadně vypnout promo bannery**. Select an inclusive local creation-date cutoff, preview the event count, then explicitly confirm. Processing uses batches of 50 and a user-specific, expiring selection bounded by the maximum post ID at preview time. It includes publish/future/private/pending/draft events and excludes trash/auto-drafts. New events record an immutable site-local `_mlyn_event_created_at` timestamp. For old records lacking it, the cutoff uses WordPress `post_date`, which can be a publication/scheduled date rather than the original creation date. Disabling retains all settings and generated images; individual events can be re-enabled. Interrupted batches can safely be repeated. This action is never run automatically.

### Promo integration API

- `mlyn_event_get_promo_banner_id( $event_id )`: selected ready-made banner ID or current generated attachment ID,
  or zero when disabled, missing, failed or stale.
- `mlyn_event_get_promo_image_id( $event_id )`: current banner, falling back to the
  featured image. Intended only for explicitly promotional placements.
- `mlyn_event_promo_updated` action receives event ID and generated attachment ID.

The dependency fingerprint covers effective text, dates, source photo, organizer logos, watermark, layout and renderer version. Stale images are never returned by the API. Duplicates inherit configuration but generate their own output.

This release does not change theme, slider, signage or intake interfaces. Their consumer integrations are a separate next step. The existing metadata/save hooks already detect changes to banner inputs without exposing controls to organizers.

### Promo verification

Run `tests/promo-smoke.php` under a bootstrapped WordPress installation with the web server's PHP/Imagick runtime. It creates and removes disposable events and media, checks rendering pixels/dimensions, date formatting, stale-image fallback, permissions, duplication, and a 53-event date-cutoff batch. It refuses the bulk test if real events exist before its isolated year-1901 cutoff. Existing smoke tests remain applicable.

### Ready-made banners and per-event logo controls

**Typ promo banneru → Použít hotový banner beze změn** selects an existing or newly uploaded JPEG/PNG/WebP attachment. Preview and the promo API use that exact image: no watermark, strip, resizing, generated copy, or automatic date validation. The clean featured image and all generated-mode settings are retained when switching modes. Disabling promo banners still falls back to the featured image. If the selected ready-made image is deleted/unavailable, the API falls back as well.

In generated mode, each currently assigned organizer has a checkbox and a height from 1–100% of the strip (default 60%). New organizers default to included. Settings are keyed by organizer ID so adding/removing/reordering does not transfer a size or exclusion to another organizer. Logos retain their aspect ratios and are centered vertically in one row. When they exceed the logo area's width, a single common scale factor reduces the whole group, preserving relative requested heights. Height refers to the image canvas, including any transparent margins. The text block is centered vertically using its actual ink bounds.

**Datum a čas → Vlastní text** supplies a banner-only line. Switching back to
**Z termínu akce** restores automatic dates. A custom empty line omits it.

Logo controls follow the classic TEC editor's live organizer selectors. Preview passes their current order to a validated read-only endpoint; it does not save organizer associations, settings or a generated attachment. Added existing organizers can therefore appear immediately. A brand-new organizer must first be saved and assigned a logo. The other automatic event values still use saved data, as stated beside the preview button. Generated previews must be followed by an event update to publish the chosen settings. Renderer upgrades queue a background refresh of enabled generated banners; saving an event applies changes immediately.
