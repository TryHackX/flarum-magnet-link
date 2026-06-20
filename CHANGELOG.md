# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Note:** Features marked with **[Flarum 2.x]** apply specifically to the
2.x branch of this extension designed for Flarum 2.0+.

## [Unreleased]

## [2.9.1] - 2026-06-20

> Post-release follow-up from an external code review: a fail-secure default fix and an internal
> controller refactor. **PHP only — no migrations, no frontend rebuild** → `composer update` +
> `php flarum cache:clear`.

### Fixed
- **The scraper engine now fails secure when the setting is absent.** `TrackerScraper::scrapeSingle()`
  read `scraper_engine` with a PHP-level fallback of `'classic'`, contradicting the registered default
  of `'hardened'` (since 2.8.1). If the stored setting were ever missing, the scraper would silently
  pick the *less*-hardened engine. The fallback is now `'hardened'` and the choice is fail-secure: only
  an explicit `'classic'` selects the classic engine; anything else uses hardened.

### Changed
- **`DiscussionMagnetsController::handle()` split into smaller methods.** The magnet-list assembly
  (token refs → batch model load → payload build) and the budgeted scrape loop were extracted into
  private `collectMagnetData()` / `scrapeMagnets()` helpers. Pure refactor — the API response shape is
  byte-identical (verified).

## [2.9.0] - 2026-06-20

> Separates the tracker-scrape time budgets for the discussion-list tooltip and the open-topic
> card (both now admin-configurable), and adds a client-side concurrency limit for hover-driven
> scrape requests. **Frontend + PHP, no migrations** — rebuild assets: `composer update` +
> `npm --prefix js run build` + `php flarum cache:clear`.

### Added
- **Separate, configurable scrape time budgets for the tooltip vs the topic.** Two new admin
  number settings (Scraper / Tooltip sections):
  - **Topic scrape time budget** (`topic_scrape_budget`, default 15 s) — the total tracker-scrape
    time for a magnet shown in the open topic (the in-post card). Previously a fixed internal ceiling.
  - **Tooltip scrape time budget** (`tooltip_scrape_budget`, default 4 s) — the time budget shared
    across all magnets scraped for one discussion-list hover. Previously a hard-coded constant.
  - Both are clamped to `[2, 30]` seconds. The absolute per-magnet safety ceiling
    (`HARD_TIME_BUDGET`) is raised to 30 s and is now the hard cap both settings sit beneath — an
    admin can lengthen a scrape up to 30 s but never past it (one request can't tie up a worker for
    longer). The minimum is 2 s because a 1 s budget can't complete even a single tracker call.
  - This fixes the common case where a magnet with many trackers (especially private/dead trackers
    listed first) showed "no response" in the tooltip while the topic showed correct seeders — the
    tooltip simply ran out of its short budget before reaching a live tracker. Raising
    `tooltip_scrape_budget` (or listing a live tracker under Priority Trackers) resolves it.
- **Concurrency limit for hover-driven scrape requests** (`tooltip_max_concurrent`, default 2;
  range 1–6). On the discussion list, rapidly hovering across many discussions used to fire one
  scrape request per hover and let each run to completion, piling up synchronous work on PHP
  workers. A new client-side fetch manager now caps how many hover requests run at once; when you
  hover past the limit, the newest hover aborts the oldest in-flight request (`AbortController`).
  Leaving a discussion (or switching the hovered target) aborts that discussion's request, and
  opening a discussion / navigating aborts all pending ones — so requests whose result won't be
  shown are released instead of holding workers.
- **Tooltip-specific tracker tuning** (both default 0 = inherit the global value, so no behavioural
  change unless you set them):
  - **Tooltip tracker timeout** (`tooltip_tracker_timeout`) — a per-tracker response timeout used
    only for tooltip scrapes. Lowering it (e.g. 1 s) makes dead/slow trackers fail faster, so more
    trackers fit inside the tooltip time budget — the most effective way to make the tooltip reach a
    live tracker when private/dead trackers are listed first (in testing, a magnet that took ~6 s to
    resolve with the global 2 s timeout resolved in ~1.2 s at 1 s).
  - **Tooltip max trackers** (`tooltip_max_trackers`) — caps how many trackers the tooltip contacts
    per magnet, independently of the in-topic card. The effective max-trackers **and the effective
    per-tracker timeout** are both part of the scrape cache key, so a tooltip with a different cap or
    timeout gets its own cache entry and never poisons the topic's full result (with *Check All
    Trackers* on, a shorter timeout changes the aggregate). Note: the tooltip already falls through
    trackers that don't respond, so the cap is a cost limit, not a reach-more-trackers knob — for
    that, lower the timeout above.

### Notes
- Default behaviour is unchanged for existing installs: the topic still uses a 15 s budget and the
  tooltip a 4 s budget; the concurrency limit defaults to 2. Only an admin who changes the new
  settings sees different behaviour.
- Aborting a hover request on the client caps the NUMBER of concurrent requests; it does not by
  itself free a PHP worker already running a blocking tracker call (that work finishes server-side).
  The result cache still means only the first hover per magnet pays.

## [2.8.1] - 2026-06-18

> Reworks the `hardened` engine onto **cURL** (`CURLOPT_RESOLVE`), makes it the DEFAULT, and
> consolidates it as a subclass (no more scraper code duplication) — follow-up to the floxum audit
> of the 2.8.0 release. **Frontend + PHP, no migrations** — rebuild assets:
> `composer update` + `npm --prefix js run build` + `php flarum cache:clear`.

### Added
- **Scraper engine switch (`scraper_engine`: `hardened` | `classic`)** — new dropdown in the admin
  panel (Scraper section).
  - **`hardened` (DEFAULT)** = `Scrapeer\ScraperViaFix`, a **cURL**-based engine:
    - **IP pinning** — the tracker host is resolved ONCE (A + AAAA) and range-validated
      (`NO_PRIV_RANGE|NO_RES_RANGE`); HTTP/HTTPS uses **`CURLOPT_RESOLVE`** to connect to THAT IP
      while SNI / certificate verification and the `Host` header stay on the original name. No second
      DNS lookup = no rebinding window (for ALL hosts). `CURLOPT_PROTOCOLS` restricts to HTTP(S);
      response size is capped.
    - **IPv6** — UDP is dual-stack (`AF_INET6`) and connects to the pinned IP (classic UDP was
      IPv4-only). Both HTTP paths (`/scrape` and `/announce`) go through the same pinned
      `single_curl_request`. Respects `allow_private_trackers` (`set_allow_private()`).
  - **`classic`** = the original `Scrapeer\Scraper` (`file_get_contents`, NO cURL dependency) — with
    a documented residual rebinding window; choose it when cURL is unavailable.
- **The default engine is now `hardened`** — closes the residual DNS-rebinding/SSRF out of the box
  (floxum audit: a default of `classic` left the window open).

### Changed
- **No more scraper code duplication.** `ScraperViaFix` now **extends** `Scraper` and overrides ONLY
  the three connection methods (`http_request`, `http_announce`, `udp_create_connection`) plus the
  pinning helpers — **~314 lines instead of a full ~1000-line copy**. All of the BitTorrent
  protocol / parsing logic has a single source of truth in `Scraper` (three methods + 3 helpers
  there were widened to `protected`; no behavioural change). (audit: HIGH duplication)

### Notes
- `hardened` **requires the cURL extension** and strictly verifies TLS (`SSL_VERIFYPEER` +
  `VERIFYHOST=2`), so trackers with a broken/self-signed certificate may stop responding — without
  cURL, switch to `classic` (HTTP in hardened fails closed; UDP works without cURL).
- Minor hardening from an AI audit (in `Scraper`, inherited): CSPRNG `random_int` in
  `random_peer_id` (instead of `str_shuffle`); `str_starts_with` for the bencode prefix.
- **Deliberately SKIPPED** (risky on a vendored fork): rewriting the positional bencode parser, an
  exception model instead of an error array, `strict_types` / a wholesale PHP-8 lifting, a
  Mithril-component frontend refactor. **Known, separate:** the vendored Scrapeer license =
  CC BY-SA 3.0 (while `composer.json` = MIT) — to be resolved by packaging (a separate package) or a
  rewrite; `NOTICE.md` documents it.
- Verified locally: both engines scrape the same magnet to the same result (`seeders=1`), HTTP 200;
  pin/validate unit test (loopback/metadata/private/ULA v6 → blocked; public v4+v6 → allowed) ALL PASS.

## [2.8.0] - 2026-06-18

> Adds an admin-selectable scraper engine: classic (as before) or hardened, which PINS the
> resolved IP (closing the SSRF/DNS-rebinding window) and supports IPv6 over UDP.
> **New file + frontend + PHP, no migrations** — rebuild assets: `composer update` +
> `npm --prefix js run build` + `php flarum cache:clear`. Defaults to `classic` = no behavioural change.

### Added
- **Scraper engine switch (`scraper_engine`: `classic` | `hardened`).** New dropdown in the
  admin panel (Scraper section). `classic` (default) = the original `Scrapeer\Scraper`, unchanged.
  `hardened` = the new `Scrapeer\ScraperViaFix` (at this point `file_get_contents`-based + a
  `build_pinned()` helper):
  - **IP pinning** — the tracker host is resolved ONCE (A + AAAA), range-validated
    (`NO_PRIV_RANGE|NO_RES_RANGE`), and the connection goes to THAT IP — no second DNS lookup, so
    there is no window for rebinding between the check and the connection.
  - **IPv6** — UDP dual-stack (`AF_INET6`); the classic UDP path was IPv4-only.
  - HTTP/HTTPS — `/scrape` and `/announce` through a shared `build_pinned()` (Host + SNI/cert on
    the original name; redirects re-pinned). Respects `allow_private_trackers`.
- Minor AI-audit items in `ScraperViaFix`: CSPRNG `random_int` in `random_peer_id`; `str_starts_with`.

> Note: in 2.8.1 the `hardened` engine moved to cURL and became the DEFAULT, and `ScraperViaFix`
> is now a subclass of `Scraper` (no duplication). See the [2.8.1] entry.

## [2.7.1] - 2026-06-17

> Floxum audit (round 2) — registers the missing default for the tracker host allowlist so the
> opt-in SSRF guard is fully wired. The remaining round-2 findings are deliberate and documented
> below. **PHP only, no migrations, no frontend change** → `composer update` + `php flarum cache:clear`.

### Fixed
- **`scraper_host_allowlist` now has a registered `->default('')`** in extend.php, alongside
  `priority_trackers`. The UI field already existed since 2.6.1 — this closes the missing default
  registration, for consistency with the other settings. (floxum: missing `->default()` for the allowlist)

### Notes — deliberately deferred (documented)
- **`MagnetRenderer` can do an INSERT for old posts on render.** This is an intentional lazy
  self-heal for posts predating the `EnsureMagnetRecords` backfill; going read-only would regress
  rendering of old posts (they'd show "invalid" until `magnet:reparse`). It stays; `EnsureMagnetRecords`
  remains the primary write path. (floxum: DB writes during render)
- **`generateToken()` persists a fresh `token_salt` when it is empty.** A migration provisions the
  salt at install time, so this branch is a safeguard against a manual deletion; the TOCTOU window is
  negligible and the self-heal is better than breaking token derivation. It stays. (floxum: settings
  write in generateToken)
- Unchanged (as before): the synchronous tooltip scrape (queue deferred — `sync` driver, the cache
  softens cold hits), the static `generateToken` (the hash MUST stay byte-identical), the raw
  `->change()` migration, the body-wide MutationObserver (leaf-skip added in 2.7.0; rescoping would
  break magnets in the composer preview/modals), plain JS. (floxum: repeats)

## [2.7.0] - 2026-06-17

> Floxum audit follow-up (green 87/100). Closes the remaining `post_id` IDOR in the
> info endpoint, tightens the synchronous tooltip scrape budget, and trims the global
> MutationObserver. **Frontend change, no migrations** — rebuild assets:
> `composer update` + `php flarum assets:publish` + `php flarum cache:clear`.

### Security
- **`InfoController` now validates `post_id` against the actor's visibility.** The
  custom-name lookup used the client-supplied `post_id` verbatim, so a user with
  `viewMagnetLinks` could enumerate custom names for posts they can't see (hidden /
  moderated) by guessing the id. It now resolves the post with
  `Post::whereVisibleTo($actor)->find()` and falls back to "no post context" when the
  post is missing or not visible — mirroring the `ClickController` fix from 2.6.2. (floxum)

### Performance
- **Tooltip scrape budget cut from 8 s to 4 s.** `DiscussionMagnetsController`'s
  `TOOLTIP_SCRAPE_BUDGET` bounds the synchronous tracker scraping that runs on a
  discussion-list hover. Halving it caps how long one cold hover can hold a PHP-FPM
  worker; the result cache still means only the first hover per magnet pays, and the
  in-topic view (full budget) keeps the complete data. (floxum)
- **The magnet `MutationObserver` now bails on leaf nodes before scanning.** Attached
  to `document.body` (subtree), it fired a `querySelectorAll` on every node added
  anywhere in the SPA; it now skips childless non-magnet nodes first. Deliberately
  *not* rescoped to `.App-content` — magnets also render in the composer preview /
  modals outside it. (floxum)

### Notes
- **Deliberately deferred:** moving the synchronous tooltip scrape to a queued job —
  the result cache plus the shorter budget already bound the cost, and a real queue
  driver (not `sync`) is needed for it to help at scale.
- **Deliberately unchanged** (documented): the vendored Scrapeer fork (must carry the
  local SSRF/limit hardening; baseline v0.5.4); `MagnetLink::generateToken()` stays
  static (changing the hash would invalidate every existing token); `magnet_links.id`
  stays INT (internal FK parity; widening is a heavy table rebuild for no practical
  gain); the documented DNS-rebinding residual (opt-in `scraper_host_allowlist` closes
  it). (floxum)

## [2.6.2] - 2026-06-17

> Audit follow-up — closes the click-attribution gap and de-correlates the
> magnet-click sort for scale. **No migrations, no frontend change** (PHP only):
> `composer update` + `php flarum cache:clear`.

### Security
- **`ClickController` now validates `post_id` against the actor's visibility.**
  The endpoint stored the client-supplied `post_id` verbatim, so any authenticated
  user could attribute their magnet click to an arbitrary post — including posts in
  discussions they can't see — inflating `MagnetClicksSort` totals. It now resolves
  the post with `Post::whereVisibleTo($actor)->find()` and stores `null` when the
  post is missing or not visible (the click is still counted for the magnet, just not
  attributed). (audit #1)

### Performance
- **`MagnetClicksSort` no longer runs a correlated sub-query per discussion row.**
  The discussion-list sorts (`magnetClicksTotal` / `magnetClicksMax` /
  `magnetLastClicked`) now use a single pre-aggregated `LEFT JOIN` (grouped by
  `discussion_id`) computed once per page, instead of one correlated sub-query per
  row (the SQL-level N×1). It still reads from `magnet_clicks`, so
  `magnet:prune-clicks` keeps shrinking the sort window (semantics unchanged), and
  the discussion search's `select discussions.*` keeps the joined columns out of
  model hydration. Verified the per-discussion values and the resulting order are
  identical to the old correlated form. (audit #4)

### Fixed
- Removed an unused `Illuminate\Support\Str` import in `MagnetLink`. (audit #5)

### Notes
- **Deliberately not changed** (now documented inline): the three newer migrations
  keep raw `Builder` — they do settings data logic / a named index + backfill / a
  `->change()` widening, none of which map to a `Flarum\Database\Migration` helper,
  and they already run through Blueprint / the query builder (Laravel's own cross-DB
  layer — so the PostgreSQL/SQLite concern does not apply); rewriting an
  already-applied migration would risk schema drift between fresh and upgraded
  installs (audit #2). The static `generateToken()` `resolve()` (formatter/XSL
  context + salt self-heal, audit #3) and the in-tree hardened Scrapeer fork
  (CC BY-SA 3.0, `src/Scraper/NOTICE.md`, audit #6) also stay as-is.
- Verified on `http://flarum.localhost/`: PHP lint clean; a click with a fabricated
  `post_id` is stored as `NULL` (never the bogus id) through the CSRF-protected
  endpoint; the three magnet sorts return 200 with ordering byte-identical to the
  correlated version (per-discussion values compared row-by-row in SQL);
  `/api/discussions` 200.

## [2.6.1] - 2026-06-16

> Follow-up audit round (green check, non-blocking polish). **No migrations.**
> PHP + frontend (admin asset rebuilt for one new setting): `composer update` +
> `php flarum cache:clear`.

### Added
- **Opt-in tracker host allowlist (SSRF hardening).** New `scraper_host_allowlist`
  admin setting (one trusted host per line). When non-empty, the scraper contacts
  **only** those hosts — tracker URLs pulled from attacker-controlled post content
  that aren't listed are skipped entirely, closing the SSRF / DNS-rebinding surface.
  Empty (default) keeps current behaviour (any public host, still behind the SSRF
  guard). The allowlist is part of the scrape cache key. (audit M1)

### Changed
- **`TrackerScraper` and `RefreshLimiter` now inject `Illuminate\Contracts\Cache\Repository`**
  instead of the lower-level `…\Cache\Store`, so they use Flarum's full cache stack
  (decorators/tags) rather than the bare primitive. (audit M4)

### Fixed
- **`MagnetLink::findOrCreateFromUri()` is now race-safe.** It did
  `where('token')->first()` then `save()`; two concurrent renders of the same new
  magnet could both pass the SELECT and the loser hit the unique-`token` constraint
  (→ token rendered as 'invalid'). Replaced with `firstOrCreate()` (atomic
  create-or-first on Laravel 11), matching the `MagnetBan::banIp()` upsert. (audit M5)
- **Settings textareas no longer stretch the full desktop width.** Flarum core caps
  only `input` (not `textarea`) to 400px on the extension settings page, so *Priority
  Trackers* and the new *Tracker host allowlist* spanned edge-to-edge. Both are now
  constrained to 400px to match the other fields, scoped to the real Flarum 2.x page
  class (`{id}-Page`). Also dropped a stale `.ExtensionPage--{id}` admin-CSS block that
  matched nothing in Flarum 2.x (dead code — verified 0 matches in the live DOM, so its
  removal changes nothing).

### Docs / internal
- **`src/Scraper/NOTICE.md`** documents the vendored, hardened Scrapeer fork and its
  **CC BY-SA 3.0** license (the rest of the extension is MIT; this subtree keeps the
  upstream license + attribution; the fork stays in-tree on purpose). (audit M2)
- `MagnetRenderer::__invoke()`'s unused `$context` parameter is now documented as
  intentionally unused. (audit M7)

### Notes
- **Deliberately not changed** (documented): the residual DNS-rebinding window on the
  *initial* connect of non-allowlisted hosts (full IP-pinning would mean patching the
  vendored Scrapeer connect path; the new allowlist closes it opt-in, redirect hops
  are already re-validated, and `tracker_timeout` / `scraper_max_redirects` defaults
  stay tight — audit M1); publishing Scrapeer as a separate Composer package (it
  carries our hardening and isn't distributed elsewhere — audit M2); the static
  `generateToken()` `resolve()` (formatter/XSL context + salt self-heal — audit M3);
  the body-wide `MutationObserver` (load-bearing for raw-HTML post magnets; re-scoping
  needs SPA-nav re-attach — audit M6); and the `MagnetLinkManager.js` size (a Mithril
  rewrite is real layout risk on a working in-post component; the concrete leaks were
  fixed in 2.6.0 — audit M8).
- Verified on `http://flarum.localhost/`: PHP lint clean; build OK; the discussion
  tooltip resolves magnets through the `Repository`-injected `TrackerScraper`; with a
  non-matching allowlist every tracker is skipped (`no_http_trackers`) while the magnet
  still renders; `/api/discussions` 200.

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
