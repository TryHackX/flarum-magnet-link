# TryHackX Magnet Link

A powerful, secure, and highly customisable Flarum extension for embedding
and managing **magnet links**. Token-protected magnet URIs, live tracker
scraping (Seeders / Leechers / Completed), per-link click counters with
anti-spam protection, custom display names, a discussion-list hover
tooltip, and a CLI / one-click admin backfill for posts that were written
before the extension was enabled.

## 🚀 Versions & Compatibility

This extension is developed in parallel to support both legacy and modern
Flarum installations:

- **Version 2.x** — Fully compatible with the latest Flarum 2.x routing and
  frontend architecture. **Actively developed.**
- **Version 1.x** — Supports legacy Flarum 1.8.0 and above. **No longer
  actively developed** — stays available for legacy installs but won't
  receive new features.

> **Latest highlights:**
> - **Magnet backfill** — re-parse posts whose `[magnet]` BBCode was saved
>   before the extension was active (via `php flarum magnet:reparse` or
>   the new admin button). After running, both the in-post button and the
>   discussion tooltip work.
> - **Tooltip only fires when there's actually a magnet** — a new
>   `hasMagnetLinks` discussion attribute (computed from the first post's
>   stored XML, no extra queries) lets the client skip the request for
>   magnet-less discussions, eliminating the "Loading magnet info…" flash.
> - **Permission message in the tooltip** — for guests / unverified /
>   no-permission users, optionally render a clear message
>   ("You must be logged in… Login or Register") instead of letting the
>   loading state flash and disappear (toggle from admin, on by default).
> - **Tracker error in the tooltip** — when no tracker responds, the
>   tooltip now shows the same localised error message as the in-post
>   widget, instead of just blank.

## ✨ Features

### Core functionality

- **Secure embedding** — raw magnet URIs are never exposed in the HTML
  source. They're protected by SHA-256 tokens and retrieved via API.
  Guests can be locked out entirely.
- **Text editor integration** — adds a magnet icon to the Flarum editor;
  wraps the current selection or inserts `[magnet][/magnet]` at the
  cursor.
- **Multilingual** — English and Polish bundled.

### Real-time statistics (scraper)

- **Tracker scraping** for HTTP / HTTPS / UDP trackers, with live
  **Seeders / Leechers / Completed** counts (powered by the
  [Scrapeer](https://github.com/medariox/scrapeer) library).
- **Aggregation modes** — average, average-max-downloads, or max-all.
- **Performance knobs** — configurable per-tracker timeout and max
  tracker count.
- **Manual refresh** — per-link refresh button to re-pull fresh stats.

### Discussion-list tooltip

- **Hover tooltip** on the discussion list that previews the topic's
  magnets and their current stats — without opening the topic.
- **Smart gating** — the tooltip only fires for discussions that
  actually have a magnet in the opening post (`hasMagnetLinks`
  attribute), so non-magnet discussions never trigger an API call.
- **Tracker error rendering** — when no tracker responds (or the
  magnet has no trackers, no HTTP trackers, etc.) the tooltip shows the
  localised error message instead of nothing, mirroring the in-post
  widget.
- **Permission message rendering** — for guests / unverified /
  no-permission users you can choose to show
  "You must be logged in to view magnet links. Login or Register"
  (toggle in admin, on by default) instead of letting "Loading…" flash
  and disappear.
- **5-minute client cache** — same magnet hovered repeatedly only hits
  the API once.

### Backfill for old posts

For discussions whose `[magnet]…[/magnet]` BBCode was written **before**
this extension was enabled, the parsed XML stored in `posts.content`
doesn't contain `<MAGNET>` tags — so neither the in-post button nor the
tooltip works.

This release ships two ways to fix it:

- **CLI:** `php flarum magnet:reparse` — batched, idempotent, no HTTP
  timeout (preferred for large forums).
- **Admin button:** *Re-parse old magnet links* in the extension
  settings — calls a small admin-gated endpoint that runs the same
  re-parser synchronously (handy after a fresh install / content import).

Both use the same `MagnetReparser` service, which:

- Targets only posts whose XML contains the literal `[magnet]` BBCode but
  not yet a `<MAGNET>` tag.
- Goes through them in chunks of 50 (`chunkById`).
- Re-parses each post via the content accessor / mutator — which unparses
  the stored XML back to its source and re-parses it with the now-active
  formatter, producing `<MAGNET>` tags.
- Is safe to run repeatedly: posts that already have `<MAGNET>` are
  excluded by the query; re-parsing one that's already correct produces
  identical XML.

### Custom torrent names

- Post authors can rename their magnet links from the post UI.
- The custom name is stored per magnet × post pair, so the same magnet
  can appear under different names in different topics.

### Analytics & protection

- **Click counters** — live-updating clicks per magnet, shared across all
  posts that embed the same magnet.
- **Anti-spam / IP banning** — configurable cooldowns, self-click
  intervals and temporary IP bans against click spam.

### Access control

- **Group permissions** — restrict viewing of magnet links per Flarum
  user group (`tryhackx-magnet-link.viewMagnetLinks`, default Members).
- **Email confirmation gate** — optionally require email-verified users.
- **Guest gate** — show or hide magnet links to unregistered visitors.

## 🔐 How it works (security)

1. The user inserts `[magnet]magnet:?xt=...[/magnet]` into a post.
2. On post save the magnet URI is validated, stored in `magnet_links`
   and replaced in the stored XML with a unique SHA-256 token attribute.
3. In the rendered HTML *only* the token is visible — the actual magnet
   URI is never exposed.
4. When the user clicks the link, JavaScript sends the token to
   `/api/magnet/info/{token}`.
5. The API checks permissions (group, guest, email verification) and
   returns the magnet URI only to authorised users.
6. The browser opens the magnet link.

## Screenshots

![Mobile view of the discussion list across multiple TryHackX layout combinations](assets/ALL_MOBILE.png)

*Mobile view — discussion list rendered with different combinations of TryHackX extensions (thumbnails + ratings + views, thumbnails + views, thumbnails only, ratings only, views only, vanilla Flarum).*

![Magnet Link admin settings — backfill, access gates, tracker scraping, click tracking, tooltip and anti-spam controls](assets/Magnet_Link_BBCode.png)

*Magnet Link admin panel — magnet backfill button, guest / activated-users gates, tracker scraping toggles (HTTP(S)-only, check-all, display mode, timeout, max trackers), click tracking, discussion-list tooltip with permission-message option, custom torrent names, spam protection (ban duration / interval / threshold, self-click interval) and the `viewMagnetLinks` permission row.*

![Desktop discussion list with the full TryHackX stack — thumbnail sliders, star ratings and the magnet button](assets/ALL_VIA_MAGNETS.png)

*Desktop discussion list with the full TryHackX stack — thumbnail sliders on the left, star ratings on the right, the magnet button rendered next to each topic title.*

![Desktop discussion list — magnet tooltip mid-load on a topic](assets/ALL_VIA_MAGNETS_v2.png)

*Desktop discussion list — hover state showing the magnet tooltip loading inline (the new `hasMagnetLinks` gate keeps it from firing on magnet-less topics).*

## Support Development

If you find this extension useful, consider supporting its development:

- **Monero (XMR):** `45hvee4Jv7qeAm6SrBzXb9YVjb8DkHtFtFh7qkDMxS9zYX3NRi1dV27MtSdVC5X8T1YVoiG8XFiJkh4p9UncqWGxHi4tiwk`
- **Bitcoin (BTC):** `bc1qncavcek4kknpvykedxas8kxash9kdng990qed2`
- **Ethereum (ETH):** `0xa3d38d5Cf202598dd782C611e9F43f342C967cF5`

You can also find the donation option in the extension's admin settings panel.

## 📦 Installation

```bash
composer require tryhackx/flarum-magnet-link
```

### Updating

```bash
composer update tryhackx/flarum-magnet-link
php flarum migrate
php flarum cache:clear
```

After installing the extension on a forum that already has posts with
`[magnet]…[/magnet]` content, run the backfill **once**:

```bash
php flarum magnet:reparse
```

…or click *Re-parse old magnet links* in the admin settings panel.

## ⚙️ Configuration

Go to **Admin → Extensions → Magnet Link**.

| Setting | Default | Notes |
| --- | --- | --- |
| Visible to Guests | Off | If off, guests get a permission message (see below). |
| Activated Users Only | Off | Require email confirmation. |
| Enable Tracker Scraping | On | Off → only name + click counter shown. |
| HTTP(S) Trackers Only | Off | Useful if UDP is blocked on your host. |
| Check All Trackers | Off | Otherwise stop at first responder. |
| Display Type | Average | Aggregation across trackers. |
| Tracker Timeout | 2 s | Per-tracker. |
| Maximum Trackers | 0 (unlimited) | Bound total tracker checks. |
| Enable Click Tracking | On | Show click counts and feed the ban system. |
| Enable Discussion Tooltip | On | Show the hover tooltip on the discussion list. |
| Max Magnets in Tooltip | 3 | Truncate longer lists. |
| **Show permission message in tooltip** | **On** | Render "You must be logged in…" in the tooltip when permissions block the request, instead of letting the loading state flash and disappear. |
| Enable Custom Torrent Names | On | Authors can rename their own magnets. |
| Enable Spam Protection | On | Temporarily ban IPs that click too many magnets. |
| Ban Duration / Interval / Threshold | 20 min / 10 min / 100 | Tuning for the ban system. |
| Self-Click Interval | 1 day | How long before the same IP can re-bump a click. |

## 🛠 Usage

Wrap a magnet URI in BBCode:

```text
[magnet]magnet:?xt=urn:btih:EXAMPLEHASH&dn=Example.File[/magnet]
```

Or use the editor button to insert / wrap the selection automatically.

## Permissions

| Permission | Default | What it grants |
| --- | --- | --- |
| `tryhackx-magnet-link.viewMagnetLinks` | Members | Open the magnet link / use the tooltip / fetch stats. |

## CLI

| Command | Purpose |
| --- | --- |
| `php flarum magnet:reparse` | Re-parse posts whose `[magnet]` BBCode was saved before the extension was active. Idempotent; safe to run multiple times. |

## Database

| Table | Purpose |
| --- | --- |
| `magnet_links` | Tokens, info hashes, and the full magnet URIs. |
| `magnet_clicks` | Click history per IP / user. |
| `magnet_bans` | Temporary IP bans. |
| `magnet_custom_names` | Per-author custom display names per magnet × post. |

## 🚨 Troubleshooting

- **UDP trackers don't work** — enable *HTTP(S) Trackers Only* or
  install / enable the `sockets` PHP extension.
- **In-post magnets show as raw `[magnet]…[/magnet]` text** — run the
  backfill (`php flarum magnet:reparse` or the admin button).
- **500 from the API** — check the Flarum logs, ensure migrations have
  been run.
- **Stats don't refresh** — clear the Flarum cache.

## 🔗 Links & credits

- [GitHub Repository](https://github.com/TryHackX/flarum-magnet-link)
- [Report an Issue](https://github.com/TryHackX/flarum-magnet-link/issues)
- Scraper library: [Scrapeer](https://github.com/medariox/scrapeer) by medariox
- Extension author: TryHackX © 2026
