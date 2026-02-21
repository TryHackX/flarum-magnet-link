# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-01-XX

### Added
- Initial release
- BBCode `[magnet]` tag support for embedding magnet links
- Tracker scraping using Scrapeer library
  - Seeders, leechers, and completed downloads display
  - HTTP(S) and UDP tracker support
  - Configurable timeout and max trackers
- Click tracking with spam protection
  - IP-based duplicate prevention
  - Configurable ban system for abuse prevention
  - Click count display per magnet link
- File size display (extracted from `xl=` parameter)
- Permission system for viewing magnet links
  - Guest visibility setting
  - Email confirmation requirement option
  - Group-based permissions via Flarum's permission system
- Editor button for easy magnet link insertion
- Token-based security (SHA256 hash, magnet URI never exposed in HTML)
- Polish and English translations

### Security
- Magnet URIs are stored server-side and accessed via secure tokens
- API endpoints validate user permissions before returning data
- Click tracking includes IP-based spam protection
