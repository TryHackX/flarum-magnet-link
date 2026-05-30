# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Note:** Features marked with **[Flarum 2.x]** apply specifically to the
2.x branch of this extension designed for Flarum 2.0+.

## [Unreleased]

## [2.0.9] - 2026-05-30

### Added
- **Magnet backfill** for posts whose `[magnet]` BBCode was saved before
  the extension was active:
  - New service `TryHackX\MagnetLink\Service\MagnetReparser` — chunked
    (`chunkById(50)`), idempotent re-parse via the content
    accessor/mutator (unparse → parse) so the stored XML gets
    `<MAGNET>` tags.
  - New CLI command `magnet:reparse` (registered via
    `Extend\Console::command(ReparseMagnetsCommand::class)`).
  - New admin-only endpoint `POST /api/magnet/reparse`
    (`ReparseController`) that runs the same service synchronously.
  - New admin button *Re-parse old magnet links* in the extension
    settings, with loading state and result alert.
  - English and Polish locale strings.
- **`hasMagnetLinks` discussion attribute** — computed from the first
  post's stored XML (`<MAGNET` present), exposed on
  `DiscussionResource`. The first post is forced into the default
  `Index` includes so the check is zero-extra-query.
- **Discussion-list tooltip gates on `hasMagnetLinks`** —
  `setupMagnetTooltip` skips discussions without a magnet so no
  pointless `/magnet/discussion/{id}` request is fired (and no
  "Loading…" flashes on hover).
- **Tracker-error message in the tooltip** — when `scrape.success` is
  false the tooltip now renders the same localised error string the
  in-post widget shows (`forum.errors.*`), instead of leaving the
  stats line blank.
- **Permission-error message in the tooltip** — new admin setting
  `tryhackx-magnet-link.tooltip_show_permission_errors` (default **on**).
  When set, the tooltip renders
  "You must be logged in to view magnet links. Login or Register"
  (or the appropriate variant for unverified email / permission
  denied) instead of letting the loading state flash and disappear.
  English and Polish strings included.

### Changed
- `DiscussionTooltip.fetchDiscussionMagnets()` no longer throws on
  error — it surfaces the `{success:false, error, message}` response
  object so `show()` can decide whether to render a message or hide.

### Fixed
- **Cancel button in core's "Reset extension settings" modal** now
  uses Flarum's standard `Button--inverted` style so it doesn't render
  as a plain borderless button. Implemented with a small
  `MutationObserver` that adds the `Button--inverted` class to the
  Cancel button when the modal appears in the DOM (the modal class
  is lazy-loaded by core and not statically importable, so we can't
  extend its prototype directly). Each TryHackX extension registers
  this independently; repeated `classList.add` of the same class is
  a no-op.
- Old discussions imported from another platform (or written before
  the extension was enabled) now work end-to-end after running
  `magnet:reparse`: the in-post button renders, and the tooltip
  finds the magnets too (the controller already scans the post XML
  for `<MAGNET>` tags, which the re-parse now produces).
- Hovering a discussion with no magnet no longer makes a useless
  API request.

## [2.0.7] / [1.0.7] — 2026-04-09

### Changed
- Moved support button to the top of the admin settings page.
- Removed margin-top / padding-top / border-top CSS from the support
  button section.

## [2.0.6] / [1.0.6] — 2026-03-11

### Added
- **Copy to Clipboard** — copy icon next to magnet links that copies
  the URL in a single click.

## [2.0.3] / [1.0.3] — 2026-02-28

### Added
- **Discussion list tooltips** previewing magnet links and current
  stats (seeders / leechers / clicks) without opening the topic.
- **Custom magnet names** — post authors can rename their magnet links
  from the post UI via a rename modal.
- **Frontend caching** — 5-minute client-side cache for individual
  magnet requests and discussion tooltips.
- **Manual refresh** — per-link refresh button to re-pull tracker
  stats and click counts.
- **Advanced scraper modes** — average / average\_max\_downloads / max\_all.
- Admin toggles for the tooltip and tooltip item limit.
- Admin toggle to enable / disable custom renaming.
- **[Flarum 2.x]** Updated routing and request handlers for Flarum 2.x
  parameter merging.

### Changed / Improved
- **Editor button** — improved selection / cursor / wrapping logic.
- **Live counters** — click counts update across every instance of the
  same magnet on the page after clicking.
- **API optimisation** — scan for existing token attributes inside
  Flarum's XML post content before falling back to raw BBCode parsing.
- **Error handling** — return specific error states
  (`email_not_confirmed`, `guest_not_allowed`, `permission_denied`),
  enabling the frontend to render the right warning instead of generic
  403 / 500 errors.
- **[Flarum 2.x]** Frontend rewrite into `MagnetLinkManager` for better
  performance, state management, and separation of concerns.

### Fixed
- Potential race conditions when rapidly opening / closing list tooltips.
- Silent failures on specific API endpoints (proper 403 handling).

## [2.0.0] / [1.0.0] — 2025-01-XX

### Added
- Initial release.
- BBCode `[magnet]` tag support.
- Tracker scraping (Scrapeer): seeders, leechers, completed; HTTP(S)
  and UDP; configurable timeouts and max trackers.
- Click tracking with spam protection: IP duplicate prevention,
  configurable ban system, per-magnet click counter.
- File size display (from the `xl=` parameter).
- Permission system: guest visibility, email confirmation requirement,
  group permissions.
- Editor button for magnet insertion.
- Polish and English translations.

### Security
- Token-based architecture: magnet URIs are never exposed in HTML;
  they're replaced with SHA-256 tokens.
- Magnet URIs stored server-side and accessed via secure API endpoints.
- API endpoints validate user permissions before returning any payload.
