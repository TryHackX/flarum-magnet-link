# **TryHackX Magnet Link**

A powerful, secure, and highly customizable extension for Flarum that allows users to seamlessly share and manage Magnet links.

It provides real-time BitTorrent tracker scraping, click statistics, advanced security (hiding raw URIs behind secure hashes), and interactive UI elements like discussion tooltips and custom names.

## **🚀 Versions & Compatibility**

This extension is developed in parallel to support both legacy and modern Flarum installations:

* **Version 2.x (Current: 2.0.5):** Fully compatible with the latest Flarum 2.x routing and frontend architecture.  
* **Version 1.x (Current: 1.0.5):** Supports legacy Flarum 1.8.0 and above.

## **✨ Features**

### **Core Functionality**

* **Secure Embedding**: Raw magnet URIs are never exposed in the HTML source code. They are protected by SHA256 tokens and retrieved securely via API. Guests cannot see or access magnet links in any way (if restricted).  
* **Text Editor Integration**: Adds a handy magnet icon to the Flarum text editor, allowing users to quickly wrap selected text or insert the \[magnet\]\[/magnet\] BBCode.  
* **Multilingual**: Full support for English and Polish languages.

### **Real-Time Statistics (Scraper)**

* **Tracker Scraping**: Scrapes HTTP/HTTPS/UDP trackers to display live **Seeders, Leechers, and Completed** downloads (powered by the Scrapeer library).  
* **Advanced Display Options**: Choose between showing average stats, average max downloads, or max overall stats across all trackers.  
* **Performance Control**: Configurable timeouts and max tracker limits to ensure forum performance isn't impacted.  
* **Manual Refresh**: Users can click the refresh button on any magnet link to fetch the latest swarm statistics instantly.

### **Interactive UI Enhancements**

* **Discussion List Tooltips**: Hovering over a discussion on the homepage reveals a clean tooltip displaying the magnet links inside it along with their current stats.  
* **Frontend Caching**: Implemented a 5-minute client-side cache for both individual magnet links and discussion tooltips to significantly reduce API calls.  
* **Custom Magnet Names**: Post authors can rename their magnet links directly from the frontend UI without editing the raw post content.  
* **File Size Display**: Extracts and formats human-readable file sizes (if provided in the magnet URI xl= parameter).

### **Analytics & Protection**

* **Click Tracking**: Live-updating click counters for each magnet link. Shared counters ensure the same link used across different posts shares the exact same statistics.  
* **Anti-Spam & IP Banning**: Built-in protection against click-spamming, including configurable cooldowns, self-click intervals, and temporary IP bans.

### **Access Control**

* **Granular Permissions**: Restrict viewing of magnet links based on Flarum User Groups.  
* **Email Confirmation**: Option to require users to have confirmed email addresses before viewing links.  
* **Guest Control**: Hide or show magnet links to unregistered guests.

## **🔐 How it Works (Security)**

1. The user inserts \[magnet\]magnet:?xt=...\[/magnet\] into a post.  
2. Upon saving the post, the magnet link is validated, saved to the database, and **replaced with a unique SHA256 token**.  
3. In the page HTML, **ONLY** the token is visible. The actual magnet URI is never exposed.  
4. When a user clicks the link, JavaScript sends the token to the API.  
5. The API validates permissions (Group, Guest status, Email verification) and returns the magnet link **only** to authorized users.  
6. The browser opens the magnet link.

## **📦 Installation**

Choose the command corresponding to your Flarum version:

**For Flarum 2.x:**
```bash
composer require tryhackx/flarum-magnet-link:"^2.0"
```

**For Flarum 1.x:**
```bash
composer require tryhackx/flarum-magnet-link:"^1.0"
```

### **🔄 Updating**
```bash
composer update tryhackx/flarum-magnet-link
php flarum migrate
php flarum cache:clear
```

## **⚙️ Configuration**

Go to your Flarum Administration Panel → Extensions → Magnet Link.

1. **General & Visibility Settings**: Control who can see magnet links (Guests, Unverified Users) and set up group permissions.  
2. **Scraper Settings**: Enable/disable the tracker scraper, enforce HTTP(S)-only trackers (useful if your host blocks UDP), and adjust display logic (Average vs Max).  
3. **Click Tracking & Bans**: Configure intervals, max clicks, and temporary ban durations for spam protection.  
4. **Display & UI**: Enable the discussion list tooltip, set the maximum number of magnets to preview, and allow post authors to rename their links.

## **🛠 Usage**

Users can simply paste a magnet link wrapped in the BBCode, use the editor button, or highlight text and click the editor button:

\[magnet\]magnet:?xt=urn:btih:EXAMPLEHASH\&dn=Example.File\[/magnet\]

## **Database Structure**

* magnet\_links \- Stores tokens, info hashes, and encrypted full URIs.  
* magnet\_clicks \- Stores click history.  
* magnet\_bans \- Stores temporary IP bans.  
* magnet\_custom\_names \- Stores custom display names set by post authors.

## **🚨 Troubleshooting**

* **UDP Trackers are not working:** Enable the "HTTP(S) Trackers Only" option in settings or ensure the sockets PHP extension is installed and enabled on your server.  
* **500 Error from API:** Check your Flarum/PHP logs. Ensure that you have run the migrations: php flarum migrate.  
* **Links are not rendering:** Clear the Flarum cache: php flarum cache:clear.

## **🔗 Links & Credits**

* [GitHub Repository](https://github.com/TryHackX/flarum-magnet-link)  
* [Report an Issue](https://github.com/TryHackX/flarum-magnet-link/issues)  
* Scraper library: [Scrapeer](https://github.com/medariox/scrapeer) by medariox  
* Extension author: TryHackX © 2026