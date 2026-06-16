# Bundled Scrapeer (vendored, hardened fork)

This `src/Scraper/` subtree is a **vendored fork** of the Scrapeer BitTorrent
tracker-scraping library — it is intentionally **not** pulled in as a Composer
dependency, because it carries project-specific security hardening that is not
published anywhere upstream.

- **Upstream:** Scrapeer by *medariox* — https://github.com/medariox/scrapeer
- **Upstream license:** **CC BY-SA 3.0** (Creative Commons Attribution-ShareAlike 3.0).
- **License of this subtree:** the rest of `tryhackx/flarum-magnet-link` is MIT,
  but this `src/Scraper/` directory **keeps its upstream CC BY-SA 3.0 license**
  (ShareAlike). Attribution to medariox is retained here and in the README credits.

## TryHackX modifications (hardening)

- `set_host_validator()` — per-hop SSRF host-validation callback (used by
  `TrackerScraper::hostIsPublic()` to refuse private/loopback/reserved targets).
- `set_max_redirects()` — cap or disable HTTP redirect following (default 0).
- A bounded maximum response size.

These modifications are distributed under the same **CC BY-SA 3.0** terms.

## Why vendored rather than a Composer package

The fork is maintained in-tree on purpose: the hardening above is specific to
this extension's threat model and is not distributed separately. Upstream changes
are reviewed and ported by hand. (Note: because it is vendored, `composer audit`
will not automatically flag upstream advisories for this code.)
