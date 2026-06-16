# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Note:** Features marked with **[Flarum 2.x]** apply specifically to the
2.x branch of this extension designed for Flarum 2.0+.

## [Unreleased]

## [2.6.0] - 2026-06-15

> Large-forum hardening pass from a third-party audit. **One new CLI command**
> (`magnet:prune-bans`), no database migrations, no API / forum-attribute / table
> contract change — `flarum-homepage-blocks` (which consumes the click sorts) is
> unaffected. PHP + frontend (rebuilt `js/dist`): `composer update` +
> `php flarum cache:clear`.

### Fixed
- **`MagnetBan::banIp()` is now an atomic upsert.** `firstOrNew()` + `save()` on the
  unique `ip_address` column could throw a duplicate-key `QueryException` (bubbling
  to a 500 in `ClickController`) when two requests from the same IP raced past the
  ban check at once. Replaced with `upsert()` (INSERT … ON DUPLICATE KEY UPDATE).
  (audit #3)
- **Rename modal no longer leaks a global `keydown` listener.** The Escape handler
  was detached only on Escape, so closing the modal any other way (×, Cancel,
  backdrop, save) left a listener on `document` for the rest of the session.
  `closeModal()` now removes it on every close path. (audit #15)

### Added
- **`php flarum magnet:prune-bans [--minutes=N]`** — bulk-deletes expired IP bans
  from `magnet_bans`. `isBanned()` only clears expired rows lazily for IPs that get
  re-checked, so on a bot-targeted forum the table grew unbounded. Defaults to the
  `ban_time` window; opt-in (cron), safe to schedule (only removes already-expired
  bans). (audit #11)
- **Table-retention note on the settings page** pointing at `magnet:prune-clicks`
  and `magnet:prune-bans` crons, so the opt-in retention is discoverable, not only
  in the README. (audit #12)

### Changed
- **Admin re-parse / re-tokenize refuse oversized synchronous runs over HTTP.**
  Above a generous row threshold each endpoint returns 422 with a "run the CLI"
  message (surfaced by the admin button) instead of risking a mid-batch 500 /
  worker timeout on a large forum. The console commands remain the scale path.
  (audit #1, #2)
- **`DiscussionMagnetsController::collectTokenRefs()` parses post XML with
  `DOMDocument`** (regex fallback on parse failure), mirroring `MagnetRenderer`
  instead of being regex-only. (audit #16)
- **The in-post magnet element map is pruned.** `MagnetLinkManager.elements` now
  drops DOM nodes detached by SPA navigation (and empty token sets) instead of
  growing for the whole session. (audit #14)

### Security
- **`RenameController` resolves the post with `whereVisibleTo($actor)`** before the
  author check — defense-in-depth so a post the actor can't see is indistinguishable
  from one that doesn't exist (both `not_author`). (audit #9)
- **SSRF docblock notes the rebinding mitigation is already the default.** The
  audit's suggested partial mitigation (`tracker_timeout` 1–2 s,
  `scraper_max_redirects` 0) is already the shipped default (2 s / 0). (audit #10)

### Notes
- **Deliberately not changed** (documented, not regressions): the static
  `MagnetLink::generateToken()` `resolve()` (runs in the formatter/XSL context and
  self-heals the salt — extracting it risks changing token derivation, audit #6);
  the data/schema migrations using raw `Builder` (settings-data logic / `->change()`
  / `addIndex` have no Flarum helper, and rewriting *applied* migrations risks
  fresh-install drift, audit #5); the XSL `Loading…` placeholder (a no-JS fallback —
  the JS loading state is already translated via `renderLoading()`, audit #8); the
  bundled hardened Scrapeer fork (now documented in the README security section,
  audit #7); the `LIKE '%<MAGNET%'` tooltip filter (already scoped to one
  discussion; cross-DB tradeoff, audit #13); and the full `innerHTML` / custom-modal
  Mithril rewrite (real layout risk on a working in-post component — only the
  concrete listener/map leaks above were fixed, audit #14/#15).
- Verified on `http://flarum.localhost/`: PHP lint clean; `magnet:prune-bans`
  dry-run + real prune delete only expired rows; the discussion tooltip still
  resolves magnets through the new DOM parser; the in-post magnet card + rename
  modal work with no console errors; the retention note renders on the settings
  page.

## [2.5.1] - 2026-06-14

> Cross-extension prefix-safety pass (coordinated with topic-rating 2.4.11 /
> homepage-blocks 2.1.12) plus two small robustness/convention fixes from an audit.
> One additive migration (column widening). No frontend change, no API /
> forum-attribute / table-contract change — `flarum-homepage-blocks` (which consumes
> the click sorts) is unaffected.

### Fixed
- **The magnet-click discussion sorts are now table-prefix safe.**
  `MagnetClicksSort::expression()` builds raw correlated sub-queries over
  `magnet_clicks` / `posts` / `discussions`; the query builder prefixes
  column/`orderBy` references but not raw SQL, so on an install with a configured
  table prefix the sort referenced non-existent tables. `expression()` now takes the
  connection's table prefix, and both call sites (`apply()` + `MagnetClicksSortMutator`)
  pass `getTablePrefix()`. No effect on the default empty-prefix install (verified:
  all three sorts — sum/max/last — return `200` with unchanged ordering). Mirrors the
  same fix in topic-rating / homepage-blocks.

### Changed
- **`magnet_clicks.user_id` / `post_id` widened from INT to BIGINT** (additive
  migration) to match Flarum 2.x core (`users.id` / `posts.id` are BIGINT). The
  columns hold no FK constraint, so there was no error today, but a forum whose
  user/post ids exceeded the 4-byte INT range would have overflowed them. Uses
  `->change()` (doctrine/dbal, bundled); neither column is indexed, so the change is
  clean. `magnet_link_id` stays INT (references this extension's own INT `magnet_links.id`).

### Docs
- README: documented the `magnet:prune-clicks` command in the CLI table and added
  retention/cron guidance — pruning stays opt-in by design (it trims the topic-scoped
  click-sort window, so the retention window is the operator's call; there is
  deliberately no auto-scheduler).

### Notes — audit items deliberately not changed
- **Synchronous HTTP reparse/retokenize**: rare admin-only actions; the
  `magnet:reparse` / `magnet:retokenize` CLI commands are the documented path for
  large forums. A queued job gives no benefit on the default `sync` queue and would
  drop the admin's completion feedback (202, no count).
- **`resolve()` in `MagnetLink::generateToken()`**: a static factory woven into
  static `findOrCreateFromUri()` (called from the formatter), where no injected
  service is available — `resolve()` is the pragmatic pattern there. Extracting a
  `TokenService` would mean de-static-ing the security-critical token chain (risk of
  changing token derivation → invalidating every existing token) for a convention nit.
- **Body-wide `MutationObserver`**: load-bearing — it initialises magnet links in raw
  post HTML that Mithril doesn't manage; the per-insertion `querySelectorAll` is cheap
  (no layout forced). Safely re-scoping needs SPA-nav re-attachment, which risks
  breaking magnet rendering.
- **Denormalising `discussion_id` onto `magnet_clicks`**: a real scale optimisation,
  but the click sorts are opt-in and `post_id`-indexed (no baseline cost), and it
  needs schema + backfill + listener changes — deferred.
- **Raw `Builder` closures in newer migrations / `MagnetLinkManager.js` size**: the
  raw-closure form is valid Flarum (rewriting applied migrations risks fresh-install
  drift); the manager is working UI whose split is pure churn with layout risk.

## [2.5.0] - 2026-06-12

> Third audit pass: closes a silent token-salt regression, adds an opt-in
> click-log retention command, and de-duplicates route-param parsing. No
> migration, no frontend change — `tryhackx/flarum-homepage-blocks` and the rest
> of the stack are unaffected (no API / forum-attribute / table-contract change).

### Security
- **Never fall back to the public salt on a secure-scheme install.**
  `MagnetLink::generateToken()` dropped to the historical
  `config('app.key', 'flarum-magnet-salt')` derivation whenever the secret
  `token_salt` was missing — including after an admin reset the extension's
  settings (wiping `token_salt`) while the scheme stayed at 2. Since that
  fallback salt is a public constant, every new token would have been
  precomputable from the post HTML, bypassing `viewMagnetLinks`. On scheme ≥ 2
  with an empty salt it now provisions and persists a fresh random secret salt
  instead; the legacy public derivation is reserved for genuine scheme < 2
  installs whose pre-existing tokens must still resolve. (Tokens tied to a lost
  salt are unrecoverable regardless — run `magnet:retokenize` to rebuild them.)

### Added
- **`magnet:prune-clicks --days=N` console command** — opt-in retention for the
  `magnet_clicks` log. Deletes rows older than N days in portable chunks, with
  `--dry-run` to preview the count. There is no default schedule and no default
  retention: it runs only when you invoke it (e.g. from system cron), so nothing
  changes unless you opt in. The denormalized `magnet_links.click_count` totals
  are never touched. **Note:** the topic-scoped magnet-click sorts
  (`most_magnet_clicks` etc., consumed by `tryhackx/flarum-homepage-blocks`) read
  straight from `magnet_clicks`, so after pruning they reflect only the retained
  window — which is exactly why pruning is a manual, operator-chosen decision
  rather than an automatic default.

### Changed
- **Route-parameter extraction de-duplicated** into a shared
  `Concerns\ResolvesRouteParam` trait used by `InfoController` and
  `DiscussionMagnetsController`. Same three-source resolution (query → request
  attribute → URI regex) as before — the working defensive fallbacks are kept,
  just no longer copy-pasted across two controllers.

## [2.4.1] - 2026-06-12

> Small follow-up from a second audit pass: kills an N+1 in the tooltip endpoint,
> de-duplicates the controller's twin parsing blocks, and aligns the PHP
> constraint with Flarum 2.x. No migration, no frontend change, no API / forum-
> attribute change — `tryhackx/flarum-homepage-blocks` and the rest of the stack
> are unaffected.

### Performance
- **Tooltip endpoint batch-loads magnet rows (no more N+1).** `GET
  /api/magnet/discussion/{id}` looked up every token individually with
  `findByToken()` inside the post loop (k posts × m magnets → up to k×m
  queries). It now collects all tokens in a single pass and loads the rows with
  one `whereIn('token', …)->keyBy('token')`. Custom names were already
  bulk-loaded; magnet rows now are too.

### Changed
- **`DiscussionMagnetsController` parsing de-duplicated.** The two structurally
  identical blocks (the `<MAGNET>uri</MAGNET>` pass and the `<MAGNET token="…"/>`
  pass) were merged into one private `collectTokenRefs()` helper that handles
  both tag forms in a single pass and returns the unique token/post references
  feeding the batch load. The endpoint's output shape is unchanged (verified
  against the previous behaviour, including cross-post dedup).
- **`composer.json` now requires `php: ^8.3`** to match Flarum 2.x's own minimum
  (it declared `^8.2`, which a Flarum 2.x install can never actually satisfy).
  Metadata only — no runtime change.

## [2.4.0] - 2026-06-11

> Follow-up hardening pass from a code audit: moves magnet-row creation to post
> save time so the read paths (render and the tooltip endpoint) stop writing to
> the database, filters the tooltip query at the database level, and auto-closes
> the legacy public-salt window on upgraded installs. **One new migration**
> (idempotent, a no-op once you're on token scheme v2). No forum attributes, sort
> fields or `magnet_links` columns change, so `tryhackx/flarum-homepage-blocks`
> (and the rest of the stack) keep working as-is.

### Security
- **Existing installs are auto-retokenized onto the secret-salt scheme.** The
  2.3.0 secret-salt fix left upgraded installs on the legacy (public-fallback)
  salt until the admin manually ran `magnet:retokenize`; until they did, every
  *new* magnet was still tokenized with the public salt, keeping the token→URI
  lookup brute-forceable from the page HTML for users without `viewMagnetLinks`.
  A new migration now performs the same idempotent re-tokenization
  (recomputed from the stored URI as `sha256(uri + secret salt)`) and flips the
  scheme to v2 automatically. It's a **no-op** on fresh / already-retokenized
  installs (scheme ≥ 2), only rewrites the `token` column (row ids — and thus the
  `magnet_clicks` / `magnet_custom_names` foreign keys — are untouched), and the
  manual `magnet:retokenize` command/button remain as a fallback.

### Performance
- **Tooltip endpoint filters magnet posts in the database.** `GET
  /api/magnet/discussion/{id}` previously loaded `id` + full `content` for every
  visible comment in the discussion and discarded non-magnet posts in PHP. It now
  adds `where('content', 'like', '%<MAGNET%')` (same predicate the re-parser
  uses), so a long thread no longer transfers every post's body on each tooltip
  hover — only the posts that actually contain a magnet.

### Changed
- **Magnet rows are created when a post is saved, not when it is rendered.** A new
  `Listener\EnsureMagnetRecords` (on `Posted` / `Revised`) creates the
  `magnet_links` rows up front, so the read paths become side-effect-free:
  - the tooltip endpoint (`GET /api/magnet/discussion/{id}`) is now **read-only** —
    it derives the deterministic token from the URI and looks the row up
    (`findByToken`) instead of `findOrCreateFromUri`, removing a write (and the
    unique-token insert race) from a `GET` request;
  - `MagnetRenderer` keeps `findOrCreateFromUri` only as a lazy self-healing
    fallback, so composer previews and not-yet-reparsed/imported content still
    render correctly. In the normal flow the row already exists, so render no
    longer performs the initial `INSERT`.
  Tokens are unchanged (deterministic from the URI), so existing posts, the
  in-post card, and homepage-blocks' counts all keep matching.
- **Discussion tooltip no longer monkey-patches `History.prototype`.** It hid the
  tooltip on SPA navigation by overriding the global `pushState`; it now listens
  for `popstate` (back/forward), while click-driven navigation is still handled by
  the existing global `click` listener. (No behavioural change — the tooltip still
  closes on navigation — just no global prototype patching.)

### Fixed
- **Discussion tooltip: click counter sits beside the tracker-error message,
  not under it.** When a magnet has no live stats (e.g. *No tracker responded*)
  but does have clicks, the click badge was rendered on its own line below the
  message. It now shares the message's row, pushed to the right
  (`MagnetTooltip-info-row`, flex `space-between`). The success-stats row and the
  clicks-only case are unchanged.

### Internal
- `Service\MagnetReparser` now logs posts it skips on error (injected
  `Psr\Log\LoggerInterface`) instead of silently swallowing the exception, so a
  recurring reparse failure is diagnosable in production.

### Migrations
- `2026_06_11_000000_retokenize_existing_installs.php` — auto-retokenize existing
  installs onto token scheme v2. Idempotent; a no-op when already on v2. Run
  `php flarum migrate` after updating.

## [2.3.0] - 2026-06-06

### Added
- **Priority tracker list.** New admin setting **Priority Trackers**
  (`priority_trackers`, one tracker host per line). When a magnet contains any
  of the listed trackers, they are contacted first, in list order. This is a
  pure reorder of the trackers the magnet already has — it never injects
  trackers that aren't in the magnet, and the SSRF guard, scheme / `http_only`
  filters, tracker cap and time budget all still apply. Most useful in the
  default "stop at the first responding tracker" mode, where it makes stats come
  from a tracker you trust and improves latency/accuracy; with **Check All** it
  controls which trackers are reached first under the cap/budget. The list is
  part of the scrape cache key, so editing it takes effect immediately instead
  of after the TTL.

### Security
- **Tooltip endpoint now respects discussion/post visibility.** `GET
  /api/magnet/discussion/{id}` previously queried posts with no visibility
  scope, so any member holding `viewMagnetLinks` could brute-force discussion
  IDs and read magnet metadata (and, via the token, reach the magnet URI) from
  restricted discussions or moderator-hidden posts. It now resolves the
  discussion with `whereVisibleTo($actor)` and loads only posts visible to the
  actor; a non-visible discussion returns an empty result without revealing it
  exists.
- **Secret per-install token salt (token scheme v2).** Magnet tokens are exposed
  in post HTML (`data-token`) and were derived from `config('app.key')` with a
  public constant fallback (`flarum-magnet-salt`); since Flarum never sets
  `app.key`, that salt was public on practically every install, so anyone could
  precompute token→URI for known torrents and recover the magnet URI from the
  page source without permission. Tokens now use a random, per-install secret
  salt that is never sent to the client (stripped from the admin settings
  payload). Fresh installs use it immediately; existing installs keep resolving
  on the legacy salt until a one-off re-tokenization (`php flarum
  magnet:retokenize` or the **Re-secure magnet tokens** button shown in
  settings) flips them over — idempotent, and recomputed from the stored URI so
  it survives any number of version jumps.
- **Scraper redirects: off by default, configurable, SSRF-validated per hop.**
  The bundled Scrapeer followed `Location` headers automatically, so a malicious
  tracker could 3xx-redirect to an internal address past the up-front host check.
  Redirect-following is now off by default; a new **Max Tracker Redirects**
  setting (`scraper_max_redirects`, 0–5) lets you allow a few — e.g. for a tracker
  behind Cloudflare/CDN that redirects http→https — and **every hop is
  re-validated by the same SSRF guard**, so a redirect can never reach a
  private/internal host (and only http/https targets are followed).
- **Scraper caps the tracker response size.** Tracker responses are read with a
  64 KB limit, so a malicious tracker can't exhaust PHP's memory by returning a
  huge body (legitimate scrape responses are tiny).

### Performance
- **Discussion list no longer eager-loads the first post just for
  `hasMagnetLinks`.** The attribute is read from a denormalized
  `discussions.has_magnet_links` column (backfilled by a migration, kept in sync
  by `Posted`/`Revised` listeners and the re-parser) instead of the global
  `addDefaultInclude(['firstPost'])` this extension previously added. (Flarum's
  own discussion list still includes `mostRelevantPost`; this only removes the
  extra first-post load the extension itself caused.)
- **Tooltip endpoint loads custom names in one query.** The per-magnet
  `magnet_custom_names` lookup (an N+1 across a discussion's posts) is replaced by
  a single `whereIn` keyed into an in-memory map.

### Changed
- `RenameController` now gates on `$actor->assertRegistered()` (idiomatic,
  framework-formatted error); the per-post author check remains the real
  authorization.

### Internal
- New `Service\TokenRetokenizer`, `Console\RetokenizeMagnetsCommand`,
  `Api\Controller\RetokenizeController`, and a migration that provisions the
  secret salt and marks fresh installs as already on token scheme v2.
- New `Concerns\ChecksMagnetAccess` trait de-duplicates the guest/email/permission
  gate across the Info/Click/DiscussionMagnets controllers (kept as custom JSON
  responses rather than Flarum policies, to preserve the frontend's error shapes).
- Unexpected exceptions in the Click/DiscussionMagnets/Rename controllers are now
  logged instead of silently swallowed. Tracker failures don't reach these
  catches (they're handled inside `TrackerScraper`), so this doesn't flood logs.
- New `Listener\SyncDiscussionMagnetFlag` keeps the denormalized flag in sync.

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
