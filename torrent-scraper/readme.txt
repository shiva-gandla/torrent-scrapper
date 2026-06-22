=== Torrent Scraper for Blogs/Forums ===
Contributors: torrent-scraper
Tags: torrent, scraper, tracker, magnet, wpforo
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish torrent files and magnet links with live seeder/leecher stats for WordPress and wpForo.

== Description ==

Publish torrent files and magnet links with live seeder/leecher stats for WordPress and wpForo.

=== Requirements ===
* PHP: 8.2+
* WordPress: 6.0+
* MySQL: 5.7+ / MariaDB 10.3+
* PHP Extensions: sockets, curl, mbstring, fileinfo

=== Features ===
* **Torrent Parsing:** Upload .torrent files or paste magnet links. Full Bencode decoding, single/multi-file support, info hash extraction.
* **Live Tracker Stats:** Automatic seeder/leecher/completed counts via UDP (BEP 15) and HTTP tracker scraping every 5 minutes.
* **Theme-Adaptive Design:** Plugin UI automatically inherits your active theme's colors, fonts, borders, and spacing. Zero manual CSS needed.
* **Gutenberg Blocks:** 4 server-side rendered blocks (Torrent Info, Tracker Stats, Torrent Files, Magnet Button).
* **Shortcodes:** 5 shortcodes for embedding torrent data anywhere (`[tp_torrent]`, `[tp_torrent_stats]`, `[tp_torrent_files]`, `[tp_magnet]`, `[tp_torrent_list]`).
* **REST API:** Full JSON API at `/wp-json/tp/v1/` with pagination, filtering, and rate limiting.
* **wpForo Integration:** Attach torrent metadata to wpForo topics.
* **Admin Dashboard:** Manage torrents, categories, settings, and run system health checks.
* **Security:** Magic byte validation, SSRF protection, nonce verification, capability checks, parameterized queries.

== Installation ==

1. Upload the `torrent-scraper` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Visit **Torrent Scraper -> System Check** to verify your hosting environment.
4. Configure settings at **Torrent Scraper -> Settings**.

== Shortcodes ==

* `[tp_torrent id="123"]` - Torrent info card
* `[tp_torrent_stats id="123"]` - Seeder/leecher badges
* `[tp_torrent_files id="123"]` - File listing
* `[tp_magnet id="123"]` - Magnet link button
* `[tp_torrent_list category="movies" limit="20" orderby="seeders"]` - Torrent table list

== Changelog ==

=== 1.0.0 ===
* Initial Release.
