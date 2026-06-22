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
 * File: StatsAjax.php
 * Component: WordPress AJAX Integrations
 * Description: Handles admin-ajax.php endpoints for asynchronous client-side updates of seeder and leecher counts.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Ajax;

use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * AJAX endpoint for lazy-loading live torrent stats.
 *
 * Hooks:
 *   wp_ajax_tp_get_stats         — for logged-in users.
 *   wp_ajax_nopriv_tp_get_stats  — for public visitors.
 *
 * Request: POST with nonce and torrent_id.
 * Response: JSON { success: true, data: { seeders, leechers, completed } }
 */
final class StatsAjax
{
    public function __construct(
        private readonly TorrentRepository $torrentRepo,
    ) {}

    /**
     * Register AJAX hooks.
     */
    public function register(): void
    {
        add_action('wp_ajax_tp_get_stats', [$this, 'handleRequest']);
        add_action('wp_ajax_nopriv_tp_get_stats', [$this, 'handleRequest']);
    }

    /**
     * Handle the AJAX request.
     */
    public function handleRequest(): void
    {
        // Verify nonce.
        if (!check_ajax_referer('tp_stats_nonce', 'nonce', die: false)) {
            wp_send_json_error([
                'code'    => 'invalid_nonce',
                'message' => __('Security check failed.', 'torrent-scraper'),
            ], 403);
        }

        $torrentId = isset($_POST['torrent_id']) ? absint($_POST['torrent_id']) : 0;

        if ($torrentId <= 0) {
            wp_send_json_error([
                'code'    => 'invalid_id',
                'message' => __('Invalid torrent ID.', 'torrent-scraper'),
            ], 400);
        }

        $torrent = $this->torrentRepo->findById($torrentId);

        if ($torrent === null) {
            wp_send_json_error([
                'code'    => 'not_found',
                'message' => __('Torrent not found.', 'torrent-scraper'),
            ], 404);
        }

        wp_send_json_success([
            'id'        => $torrentId,
            'seeders'   => (int) $torrent['seeders'],
            'leechers'  => (int) $torrent['leechers'],
            'completed' => (int) $torrent['completed'],
        ]);
    }
}
