# **Changelog**

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),

and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

**Note:** Features marked with \[Flarum 2.x\] apply specifically to the 2.x branch of this extension designed for Flarum 2.0+.

## **\[2.0.7\] / \[1.0.7\] \- 2026-04-09**

### **Changed**

* Moved support button to the top of the admin settings page
* Removed margin-top/padding-top/border-top CSS from the support button section

## **\[2.0.6\] / \[1.0.6\] \- 2026-03-11**

### **Added**

* **Copy to Clipboard:** Added a convenient copy icon next to magnet links, allowing users to instantly copy the URL to their clipboard with a single click.

## **\[2.0.3\] / \[1.0.3\] \- 2026-02-28**

### **Added**

* **Discussion Tooltips:** Added a hover tooltip on the discussion list that previews magnet links and their current stats (seeders/leechers/clicks) without opening the thread.  
* **Custom Magnet Names:** Post authors can now rename their magnet links directly from the post UI via a new rename modal.  
* **Frontend Caching:** Implemented a 5-minute client-side cache for both individual magnet requests and discussion tooltips to significantly reduce server API load.  
* **Manual Refresh:** Added a refresh button to individual magnet links to manually reload tracker statistics and click counts.  
* **Advanced Scraper Modes:** Added new Admin configuration options for display types of aggregated tracker data (average, average\_max\_downloads, max\_all).  
* Admin toggle for Tooltips and setting maximum item limits per tooltip.  
* Admin toggle to enable/disable the custom renaming feature.  
* **\[Flarum 2.x\] Flarum 2.x Compatibility:** Updated routing logic and request handlers to fully support Flarum 2.x parameter merging.

### **Changed / Improved**

* **Editor Button:** Improved the BBCode text editor button logic; it now properly handles text selection, cursor placement, and wraps highlighted text in \[magnet\]...\[/magnet\] tags.  
* **Live Counters:** Click counts now update dynamically in the DOM across all instances of the same magnet link on the page after clicking.  
* **API Optimization:** Optimized queries by scanning for existing token attributes inside Flarum's XML post content before falling back to raw BBCode parsing.  
* **Error Handling:** Enhanced API error handling to return specific error states (email\_not\_confirmed, guest\_not\_allowed, permission\_denied), allowing the frontend to render appropriate warning messages instead of generic 403 or 500 errors.  
* **\[Flarum 2.x\] Frontend Rewrite:** Completely refactored the frontend into MagnetLinkManager for better performance, state management, and separation of concerns.

### **Fixed**

* Fixed potential race conditions when rapidly opening and closing discussion list tooltips.  
* Improved silent failures for specific API endpoints (e.g., proper handling of 403 Access Denied messages).

## **\[2.0.0\] / \[1.0.0\] \- 2025-01-XX**

### **Added**

* Initial release.  
* BBCode \[magnet\] tag support for embedding magnet links.  
* Tracker scraping using Scrapeer library:  
  * Seeders, leechers, and completed downloads display.  
  * HTTP(S) and UDP tracker support.  
  * Configurable timeout and max trackers.  
* Click tracking with spam protection:  
  * IP-based duplicate prevention.  
  * Configurable ban system for abuse prevention.  
  * Click count display per magnet link.  
* File size display (extracted from xl= parameter in the URI).  
* Permission system for viewing magnet links:  
  * Guest visibility settings.  
  * Email confirmation requirement option.  
  * Group-based permissions via Flarum's permission grid.  
* Editor button for easy magnet link insertion.  
* Polish and English translations.

### **Security**

* Token-based security architecture: Magnet URIs are never exposed in HTML; they are replaced by SHA256 hashes.  
* Magnet URIs are stored safely server-side and accessed exclusively via secure API endpoints.  
* API endpoints validate user permissions (groups, email verification, guest status) before returning any payload.