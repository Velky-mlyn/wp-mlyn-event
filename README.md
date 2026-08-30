# Mlýn Event

Site-specific WP add-on plugin providing event metadata and administrative tools for The Events Calendar WP plugin.

## Features

- Adds optional capacity, available-place, and occupancy-note fields to events.
- Adds a featured-image focal-point picker and detail-banner preview to event editing.
- Treats an explicit zero available-place value as fully occupied even when capacity is empty.
- Exposes a small public API and update action for integrations such as Mlýn Event Intake.
- Adds a secure **Duplikovat** row action to the Events admin list.
- Creates duplicates as independent drafts and opens them for editing.
- Copies supported event content, dates, venue, organizers, terms, image, presentation settings, occupancy, and image focal point without copying generated or intake synchronization identities.
- Defers to the native duplicate feature if Events Calendar Pro is active.

## Public API

- `mlyn_event_get_occupancy( $event_id )`
- `mlyn_event_set_occupancy( $event_id, $capacity, $available_places, $note, $notify = true )`
- `mlyn_event_occupancy_updated` action after an occupancy update
- `mlyn_event_get_image_focal_point( $event_id )`
- `mlyn_event_set_image_focal_point( $event_id, $x, $y, $notify = true )`
- `mlyn_event_image_focal_point_updated` action after a focal-point update
- `mlyn_event_duplicated` action after a successful duplicate

## Changelog

### 1.1.0

- Added a WordPress-native focal-point picker with a live event-detail banner preview.
- Bound focal points to featured-image attachments so stale coordinates cannot affect replacement images.
- Included focal-point settings when duplicating events.

### 1.0.0

- Extracted event occupancy ownership from Mlýn Event Intake without changing existing meta keys.
- Added event duplication through The Events Calendar ORM.

## Data retention

Event metadata is deliberately retained when the plugin is removed.
