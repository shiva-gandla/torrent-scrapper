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
 *
 * File: uninstall.php
 * Component: Plugin Uninstallation
 * Description: Clean-up uninstall script. Triggers schema drops, deletes configurations, unschedules cron jobs, and flushes transients when the plugin is deleted.
 * @package TorrentScraper
 */

declare(strict_types=1);

// Prevent direct access.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Load the plugin bootstrap (registers autoloader and constants).
require_once __DIR__ . '/torrent-scraper.php';

// Run full schema uninstall.
$db = new \TorrentScraper\WordPress\Database\WordPressDatabase();
$installer = new \TorrentScraper\Core\Installer\SchemaInstaller(
    db: $db,
    migrationsDir: TORRENT_SCRAPER_CORE_DIR . 'database/migrations',
    getVersion: static fn (): int => (int) get_option('tp_db_version', 0),
    saveVersion: static fn (int $v): bool => update_option('tp_db_version', $v),
);

$installer->uninstall();

// Clean up WP options.
delete_option('tp_settings');
delete_option('tp_db_version');

// Clean up scheduled events.
$timestamp = wp_next_scheduled('tp_run_scheduler');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'tp_run_scheduler');
}

// Clean up transients.
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tp_%' OR option_name LIKE '_transient_timeout_tp_%'");
