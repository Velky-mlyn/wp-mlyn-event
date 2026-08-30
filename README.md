# Mlýn Event

Site-specific WP add-on plugin providing event metadata and administrative tools for The Events Calendar WP plugin.

## Features

- Adds optional capacity, available-place, and occupancy-note fields to events.
- Treats an explicit zero available-place value as fully occupied even when capacity is empty.
- Exposes a small public API and update action for integrations such as Mlýn Event Intake.
- Adds a secure **Duplikovat** row action to the Events admin list.
- Creates duplicates as independent drafts and opens them for editing.
- Copies supported event content, dates, venue, organizers, terms, image, presentation settings, and occupancy without copying generated or intake synchronization identities.
- Defers to the native duplicate feature if Events Calendar Pro is active.

## Public API

- `mlyn_event_get_occupancy( $event_id )`
- `mlyn_event_set_occupancy( $event_id, $capacity, $available_places, $note, $notify = true )`
- `mlyn_event_occupancy_updated` action after an occupancy update
- `mlyn_event_duplicated` action after a successful duplicate

## Changelog

### 1.0.0

- Extracted event occupancy ownership from Mlýn Event Intake without changing existing meta keys.
- Added event duplication through The Events Calendar ORM.

## Data retention

Event metadata is deliberately retained when the plugin is removed.
