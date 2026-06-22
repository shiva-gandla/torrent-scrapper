<?php
/**
 * Plugin Name: Torrent Scrapper for Wordpress Blog/Forum
 * Description: Publish torrent files and magnet links with live seeder/leecher stats for WordPress, bbPress, and wpForo.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Shiva Gandla (https://github.com/shiva-gandla/)
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: torrent-scraper
 * Domain Path: /languages
 *
 * File: torrent-scraper.php
 * Component: Plugin Bootstrap
 * Description: Plugin entry point. Verifies PHP environment constraints, registers custom autoloader, and boots WordPress adapters.
 * @package TorrentScraper
 */

declare(strict_types=1);

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// ─── Plugin constants ────────────────────────────────────────────────
define('TORRENT_SCRAPER_VERSION',   '1.0.0');
define('TORRENT_SCRAPER_FILE',      __FILE__);
define('TORRENT_SCRAPER_DIR',       plugin_dir_path(__FILE__));
define('TORRENT_SCRAPER_URL',       plugin_dir_url(__FILE__));
define('TORRENT_SCRAPER_BASENAME',  plugin_basename(__FILE__));

// Core engine lives inside the plugin: /torrent-scraper/core/src/
define('TORRENT_SCRAPER_CORE_DIR',  TORRENT_SCRAPER_DIR . 'core/');

// ─── Autoloader ──────────────────────────────────────────────────────
// Single PSR-4 autoloader for both core and WP namespaces.
spl_autoload_register(static function (string $class): void {
    // Namespace → directory mapping.
    $map = [
        'TorrentScraper\\Core\\'      => TORRENT_SCRAPER_DIR . 'core/src/',
        'TorrentScraper\\WordPress\\' => TORRENT_SCRAPER_DIR . 'src/',
    ];

    foreach ($map as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Require consolidated exception files early since they contain multiple classes per file
// and won't match standard PSR-4 class mapping (one class per file).
// Load in inheritance order: base → domain → parse → tracker.
$exceptionFiles = [
    'TorrentScraperException.php',
    'DomainExceptions.php',
    'ParseException.php',
    'TrackerException.php',
];
foreach ($exceptionFiles as $exFile) {
    $exPath = TORRENT_SCRAPER_DIR . 'core/src/Exception/' . $exFile;
    if (is_file($exPath)) {
        require_once $exPath;
    }
}


// ─── PHP version gate ────────────────────────────────────────────────
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__('Torrent Scraper:', 'torrent-scraper'),
            sprintf(
                /* translators: %s: required PHP version */
                esc_html__('requires PHP %s or higher. You are running PHP %s.', 'torrent-scraper'),
                '8.2',
                PHP_VERSION
            )
        );
    });
    return;
}

// ─── Boot plugin ─────────────────────────────────────────────────────
add_action('plugins_loaded', static function (): void {
    // Load text domain.
    load_plugin_textdomain('torrent-scraper', false, dirname(TORRENT_SCRAPER_BASENAME) . '/languages');

    // Initialize the WordPress adapter.
    $adapter = \TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance();
    $adapter->boot();
}, priority: 10);

// ─── Activation / Deactivation hooks ─────────────────────────────────
register_activation_hook(__FILE__, static function (): void {
    $adapter = \TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance();
    $adapter->activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    $adapter = \TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance();
    $adapter->deactivate();
});

// ─── Uninstall ───────────────────────────────────────────────────────
// WordPress calls uninstall.php if it exists, or fires registered callback.
// We prefer the uninstall.php approach for safety — see uninstall.php.
