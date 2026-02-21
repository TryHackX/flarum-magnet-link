# Flarum Magnet Link BBCode Extension

A Flarum extension that adds support for magnet links using the `[magnet]...[/magnet]` BBCode tag.

## Features

* **BBCode Tag** `[magnet]<magnet_link>[/magnet]` for inserting magnet links.
* **Security** - The magnet link is NOT visible in the page source code, only a SHA256 token is exposed.
* **Tracker Scraping** - Displays seeders, leechers, and completed download counts (powered by the Scrapeer library).
* **Click Tracking** - Counts unique clicks for each magnet link.
* **Spam Protection** - Temporary IP banning for excessive clicking to prevent abuse.
* **Shared Counters** - The same magnet link used across different posts shares the same statistics.
* **Dynamic Loading** - Data loads automatically via API when the post is displayed (no page slowdowns).
* **Refresh Button** - Ability to manually refresh tracker data directly from the post.
* **Multilingual** - Full support for English and Polish languages.

## How it Works (Security)

1. The user inserts `[magnet]magnet:?xt=...[/magnet]` into a post.
2. Upon saving the post, the magnet link is:
* Validated.
* Saved to the database.
* Replaced with a unique SHA256 token.


3. In the page HTML, **ONLY** the token is visible, never the actual magnet link.
4. When a user clicks the link button, JavaScript sends the token to the API.
5. The API returns the magnet link **only** to authorized users.
6. The browser opens the magnet link.

**Guests cannot see or access magnet links in any way** (if the visibility setting is disabled).

## Requirements

* PHP >= 7.4 with the `sockets` extension enabled (required for UDP trackers).
* Flarum >= 1.8.0.

## Installation

Install the extension via Composer:

```bash
composer require tryhackx/flarum-magnet-link

```

### Updates

To update the extension to the latest version:

```bash
composer update tryhackx/flarum-magnet-link
php flarum migrate
php flarum cache:clear

```

## Configuration

Go to your Flarum Administration Panel → Extensions → Magnet Link BBCode.

### General Settings

* **Guest Visibility** - Determine if guests can see the magnet link buttons.

### Scraper Settings

* **Enable Tracker Scraping** - Turn on/off querying trackers for statistics.
* **HTTP(S) Trackers Only** - Disables UDP trackers (useful if your host blocks UDP or lacks the `sockets` extension).
* **Check All Trackers** - If enabled, scrapes all trackers in the list; otherwise, stops after the first successful response.
* **Display Mode** - How to aggregate data from multiple trackers.
* **Tracker Timeout** - Time limit for tracker responses.
* **Max Trackers** - Limit the number of trackers to query to improve performance.

### Click Tracking Settings

* **Enable Click Tracking** - Count how many times the magnet link is clicked.
* **Enable Spam Protection** - Temporarily ban IPs that click too frequently.
* **Ban Duration** - Duration of the temporary block (in minutes).
* **Self-click Interval** - How often (in days) the same user can increment the click counter.

## Usage

### Using the Post Editor

1. Click the magnet icon on the toolbar.
2. Paste the full magnet link (starting with `magnet:?xt=urn:btih:`).
3. Post your reply.

### Manual BBCode

```
[magnet]magnet:?xt=urn:btih:HASH&dn=NAME&tr=TRACKER[/magnet]
```

## Database Structure

* `magnet_links` - Stores tokens, info hashes, and encrypted full URIs.
* `magnet_clicks` - Stores click history.
* `magnet_bans` - Stores temporary IP bans.

## Troubleshooting

### UDP Trackers are not working

Enable the "HTTP(S) Trackers Only" option in settings or ensure the `sockets` PHP extension is installed and enabled on your server.

### 500 Error from API
Check your Flarum/PHP logs. Ensure that you have run the migrations:

```bash
php flarum migrate
```

### Links are not rendering
Clear the Flarum cache:

```bash
php flarum cache:clear
```

## License
MIT

## Credits
* Scraper library: [Scrapeer](https://github.com/medariox/scrapeer) by medariox
* Extension author: TryHackX © 2026