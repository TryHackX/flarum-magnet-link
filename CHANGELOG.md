# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Note:** Features marked with **[Flarum 2.x]** apply specifically to the
2.x branch of this extension designed for Flarum 2.0+.

## [Unreleased]

## [2.2.0] - 2026-06-05

> A big release: security & performance hardening of the tracker scraper, the
> previously non-functional multi-tracker aggregation now actually works, an
> admin-configurable result cache with rate-limited manual refresh, and a
> reworked responsive card (with an optional mobile-style-on-desktop). No
> database changes; forum attributes, sort fields and the `magnet_links` table
> are unchanged and API responses stay backward-compatible (only an optional
> `refresh` hint was added to the info endpoint), so
> `tryhackx/flarum-homepage-blocks` (and the rest of the stack) keep working
> as-is.

### Security
- **SSRF guard on tracker scraping.** Trackers come from post content, i.e.
  they are attacker-controlled. The scraper now resolves each tracker host and
  refuses any that point at a loopback / private / link-local / reserved IP
  range (LAN services, cloud metadata, …) before issuing a request. New admin
  toggle **Allow Private/Internal Trackers** (`allow_private_trackers`,
  default **off**) for forums that intentionally run a tracker on a private
  network. Centralised in the new `Service\TrackerScraper`.
- **Click IP now comes from Flarum core** (`ipAddress` request attribute,
  which honours the configured trusted proxies) instead of the raw,
  client-spoofable `CF-Connecting-IP` / `X-Forwarded-For` / `X-Real-IP`
  headers. The old behaviour let anyone forge an IP to bypass the per-IP click
  de-dup / ban system or frame another IP into a ban.

### Performance
- **Bounded scraping.** A magnet now contacts at most a hard-capped number of
  trackers (12) and is bound by a wall-clock budget (≤15 s per magnet), so a
  magnet pointing at dead/slow hosts can no longer tie up a PHP-FPM worker.
  The discussion tooltip scrapes magnets under a single shared 8 s budget. The
  SSRF host-resolution and each tracker attempt run *inside* that budget (a
  single attempt's timeout is clamped to the time remaining), and the number of
  hosts examined per magnet is itself capped.
- **Tooltip scrapes only what it shows.** The discussion tooltip now slices
  the magnet list to the configured limit *before* scraping, instead of
  scraping every magnet in the topic and throwing most of the work away.
- **Server-side result cache.** Seed/leech/download stats are cached per
  info-hash, so repeated post views and tooltip hovers don't re-hit the
  trackers (the main brake on hover-driven load).

### Added
- **Card display-style settings** (reactive admin group):
  - **Desktop card style** (`desktop_style`, default `standard`) — choose
    *Standard* (current single-row layout) or *Mobile style (also on desktop)*,
    which applies the phone layout at all widths via a `magnet-mobile-style`
    body class. A description under the select explains the difference and
    updates live with the selection.
  - When *Mobile* is selected, two extra options appear:
    **Max name lines** (`name_max_lines`, default 3) — caps the wrapped torrent
    name with a `line-clamp` (driven by the `--magnet-name-max-lines` CSS
    variable) — and **Stats alignment** (`stats_justify`, default
    `space-between`; also `space-around` / `center` / `flex-start`) for the
    seed/leech/download row (`--magnet-stats-justify`). Both apply to the phone
    layout and to desktop when the Mobile style is on.
  - Serialized as `magnetDesktopStyle` / `magnetNameMaxLines` /
    `magnetStatsJustify`; the values are whitelisted server-side before they
    reach CSS.
- **Cache settings** — *Cache Tracker Results* (`cache_enabled`, default on)
  and *Cache Lifetime (seconds)* (`cache_ttl`, default 300). They control both
  the new server cache and the in-browser cache (serialised to the forum as
  `magnetCacheEnabled` / `magnetCacheTtl`). Set the lifetime to 0 or turn the
  toggle off to disable caching entirely. The per-link **refresh** button now
  bypasses both caches (`?refresh=1`) for a genuine re-scrape.
- **Manual-refresh rate limiting** (`Service\RefreshLimiter`, wired into the
  `?refresh=1` path of `InfoController`):
  - **Refresh Cooldown** (`refresh_cooldown`, default 30s) — a *global*
    per-magnet cooldown. One manual refresh updates the shared cached result
    that everyone then sees (post, tooltip, homepage), so the same magnet can't
    be re-refreshed (or hammered) until the cooldown elapses.
  - **Per-IP quota** (`refresh_limit_count`, default 10, over
    `refresh_limit_window`, default 600s) — a sliding-window limit keyed by the
    client IP (covers guests); each used refresh frees up again once it ages out
    of the window (oldest first). 0 disables either limit.
  - When a refresh is blocked the API returns the current (fresh, cached) result
    plus a `refresh` hint (`{limited, reason: cooldown|quota, retry_after}`); the
    frontend shows a discreet message and greys the refresh button for the
    cooldown. `refresh_cooldown` is serialised to the forum as
    `magnetRefreshCooldown`. **Note:** caching must be enabled for the
    "everyone sees the refreshed result" sharing to work.

### Fixed
- **"Check All Trackers" and "Display Type" now actually do something.** They
  were exposed in the admin UI (and advertised since 2.0.3) but no code read
  them. The scraper now, when *Check All* is on, queries every allowed tracker
  and aggregates seeders/leechers/completed per *Display Type*
  (`average` / `average_max_downloads` / `max_all`). With *Check All* off it
  keeps the previous "first responder wins" behaviour.
- **Mobile layout of the in-post magnet card** (≤600px, e.g. iPhone SE),
  reworked to the design concept: row 1 = magnet icon + click-count badge +
  copy/refresh buttons; row 2 = the **full torrent name wrapped over multiple
  lines** (no longer truncated) with the rename button; the seed/leech/download
  stats stay on a single line whose font size scales with screen width
  (`clamp()` + `vw`) so large numbers still fit. Desktop is unchanged — the
  name/rename wrapper is `display: contents` on desktop (flattening into the
  existing single row) and only becomes its own row on phones. The header no
  longer uses a vertical stack or absolutely-positioned buttons, and the JS no
  longer hard-truncates the name to 80 chars (desktop now ellipsises a single
  line, with the full name in the `title` tooltip).
- **Mobile-layout polish:** the in-post loading spinner's icon is dimmed
  (translucent grey + softened glyph) so it blends into the dark card instead
  of looking like a bright tile; the mobile header rules are scoped to
  `.MagnetLink-header` so they no longer reorder the icon of the loading /
  error / permission states; and the admin **Support** button stays pinned to
  the top of the settings page (the new display-style group sits below it).

### Removed / internal
- Deleted the empty `Provider\MagnetServiceProvider` (both methods were
  no-ops) and its registration — controller/service dependencies are
  auto-wired by the container.
- Stripped the dead debug-logging scaffolding from `MagnetRenderer` (it was
  permanently disabled and carried a hard-coded developer path
  `C:/wamp64/www/flarum/...`).
- Removed unused code: `MagnetLink::findByInfoHash()`, `MagnetBan::unbanIp()`,
  the unused `Flarum\Group\Group` import and the stale `Version: 1.0.0`
  docblock in `extend.php`; fixed an implicit-nullable parameter deprecation.
- De-duplicated the scraping logic that was copy-pasted between
  `InfoController` and `DiscussionMagnetsController` into `TrackerScraper`.

## [2.1.0] - 2026-06-01

> Adds **magnet-click discussion sorts**, consumed by
> `tryhackx/flarum-homepage-blocks`' Advanced Filters (and usable directly
> via the API). Plus the Polish Support-modal locale fix.

### Added
- **Discussion-list sorts by magnet-click activity** (topic-scoped — they
  count clicks made from each discussion's *own* posts, via
  `magnet_clicks.post_id → posts.discussion_id`). Three modes, each with a
  most/least (or recent/oldest) alias:
  - **Total** (`most_magnet_clicks` / `least_magnet_clicks`) — sum of all
    magnet clicks across every magnet in the topic.
  - **Top magnet** (`most_magnet_clicks_single` / `least_magnet_clicks_single`)
    — clicks of the single most-clicked magnet in the topic.
  - **Last clicked** (`recently_magnet_clicked` / `oldest_magnet_clicked`) —
    most recent magnet click time in the topic.

  Implemented as `Sort\MagnetClicksSort` (registers the aliases + validity
  on `DiscussionResource`) plus `Search\MagnetClicksSortMutator`. The mutator
  is needed because Flarum lists discussions through its database Search,
  which orders by *column name*; the mutator runs after the searcher's
  `applySort()` and swaps in a correlated sub-query. No denormalized
  columns, listeners or backfill — the existing `magnet_clicks` log (one row
  per counted click, in lockstep with `magnet_links.click_count`) is the
  single source of truth.
- Index `magnet_clicks(post_id, click_time)` (migration
  `2026_06_01_000000_add_post_id_index_to_magnet_clicks`) supporting the
  per-topic click sub-queries.

### Fixed
- **Missing Polish translations for the admin "Support" modal.** The
  `admin.support` block (`button`, `title`, `description`, `copy`,
  `thanks`) existed only in `en.yml`, so Polish users saw the English
  strings in the in-admin Support dialog. Added the Polish strings to
  `pl.yml`, matching the shared wording used by the other TryHackX
  extensions. `en.yml` and `pl.yml` now have matching key counts.

### Migrations
- `2026_06_01_000000_add_post_id_index_to_magnet_clicks.php` — adds a
  `(post_id, click_time)` index to `magnet_clicks`. Run `php flarum migrate`
  after updating.

### Notes
- The sorts only appear in homepage-blocks' filter bar when **this
  extension is enabled** (homepage-blocks gates the options on it). They
  rank by recorded clicks, so a discussion with no magnet clicks sorts to
  the bottom (and NULL last-clicked sorts last).

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
