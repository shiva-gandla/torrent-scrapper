# Torrent Scrapper for Blogs & Forums

[![WordPress Compatibility](https://img.shields.io/badge/WordPress-6.0%20%2B-blue.svg)](https://wordpress.org)
[![PHP Version Compatibility](https://img.shields.io/badge/PHP-8.2%20%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2%20%2B-orange.svg)](LICENSE)

A powerful, theme-adaptive plugin designed to scrape, publish, and track public torrent files and magnet links. It automatically fetches and updates tracker health statistics (seeders, leechers, and completed downloads) and integrates seamlessly into blog homepages, posts, and **wpForo** communities.

---

## 📸 Screenshots & Showcase

### 🏠 Blog Homepage (Global Stats Bar)
*Displays aggregate torrent statistics in a responsive grid card layout immediately above the footer.*
![Blog Homepage](Wordpress%20Hompage.png)

### 💬 wpForo Forum Homepage
*Integrates a native-style Torrent Statistics box directly above the forum footer statistics.*
![wpForo Forum Homepage](Wordpress%20-%20Wpforo%20Forum%20homepage.png)

### 📂 Torrents Browse Page
*A customizable frontend dashboard list of all indexed torrents with a live search bar and direct magnet download links.*
![Torrents Browse Page](Wordpress%20torrent%20list%20page.png)

### 📝 WordPress Blog Post
*Appends styled torrent metadata card with real-time seeder/leecher counts and direct magnet links to standard posts.*
![Blog Post](Wordpress%20Blog%20Post.png)

### 📄 wpForo Topic Page
*Automatically appends a compact live stats badge alongside topic titles in topic listing tables.*
![wpForo Topic Page](Wordpress%20-%20Wpforo%20Forum%20Topic%20page.png)

---

## 🚀 Key Features

- **Automated Tracker Scraper**: Background scheduler periodically queries public tracker UDP/HTTP endpoints to update torrent health (seeders, leechers, and downloads).
- **wpForo Forum Integration**: Automatically displays live stats, compact topic list badges, and dedicated forum statistics wrappers.
- **Theme-Adaptive Design**: Core stylesheet utilizes standard CSS variables cascading from popular themes (like **Astra**, Kadence, GeneratePress) for a seamless native look.
- **Shortcode & Gutenberg Support**: Place search dashboards or stats blocks anywhere on your site.
- **Ajax Lazy Loading**: Front-end javascript lazy-loads live seeder/leecher counts dynamically to prevent tracker latencies from slowing down initial page loads.
- **Concurrent Edit Protection**: Heartbeat API integration ensures administrators don't overwrite each other's edits on the same torrent logs.

---

## 📂 Repository File Structure

```bash
├── torrent-scraper/                   # Main WordPress Plugin Folder
│   ├── assets/
│   │   ├── css/
│   │   │   └── torrent-scraper.css    # Premium frontend and admin styles
│   │   └── js/
│   │       ├── torrent-browse.js      # Frontend table sorting & filtering
│   │       └── torrent-scraper.js     # Clipboard helpers & live AJAX reloading
│   ├── core/                          # Non-WordPress PHP core scraping engine
│   │   └── src/                       # Models, Repositories, Trackers, Loggers
│   ├── src/                           # WordPress adapter glue code
│   │   ├── Adapter/                   # WordPress initialization adapters
│   │   ├── Admin/                     # Settings screen UI and fields
│   │   ├── Frontend/                  # Homepage widgets and search loops
│   │   ├── Shortcode/                 # Front-end shortcode declarations
│   │   └── WpForo/                    # wpForo specific layout integrations
│   ├── torrent-scraper.php            # Plugin entrypoint and autoloader
│   └── uninstall.php                  # Database cleanup script
├── LICENSE                            # GPLv2 License
├── Wordpress - Wpforo Forum homepage.png
├── Wordpress - Wpforo Forum Topic page.png
├── Wordpress Blog Post.png
├── Wordpress Hompage.png
└── Wordpress torrent list page.png
```

---

## 🛠️ Installation & Setup

1. **Upload**: Upload the entire `torrent-scraper` directory to your WordPress installation's `/wp-content/plugins/` directory (or zip it and upload via **Plugins → Add New**).
2. **Activate**: Go to your WordPress Admin panel and activate the **Torrent Scrapper for Blogs and Forums** plugin.
3. **Configure**: Navigate to **Forum Dashboard → Torrents Settings** or the custom admin tab to configure your update intervals, active trackers, and settings.
4. **Browse Page**: The plugin automatically creates a `/torrents` page on your site with the `[tp_torrent_browse]` shortcode to display the browse search table.

---

## 🏷️ Shortcodes

- **Browse Table**: `[tp_torrent_browse style="forum" limit="25"]` - Displays the main searchable torrent table.
- **Global Network Stats**: `[tp_global_stats]` - Renders the 4-column summary card block (Total Torrents, Seeds, Leechers, Downloads).

---

## 🤝 Custom Styling
To override core plugin colors and match your specific theme palette, override these variables in your theme's Additional CSS:

```css
:root {
    --tp-accent: #2271b1;          /* Main action button color */
    --tp-surface: #ffffff;         /* Card background color */
    --tp-radius: 8px;              /* Corner roundness */
    --tp-seeder-color: #10b981;    /* Seeders badge color */
    --tp-leecher-color: #f59e0b;   /* Leechers badge color */
}
```

---

## 📄 License
This project is licensed under the GPL-2.0 License - see the [LICENSE](LICENSE) file for details.
