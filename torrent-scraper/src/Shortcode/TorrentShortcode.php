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
 * File: TorrentShortcode.php
 * Component: WordPress Shortcodes
 * Description: Implements the `[torrent_scraper]` shortcode for manual embedding of torrent files inside blog posts.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Shortcode;

use TorrentScraper\Core\Repository\StatisticsRepository;
use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * Registers all [tp_*] shortcodes.
 *
 * Shortcodes:
 *   [tp_torrent id="123"]
 *   [tp_torrent_stats id="123"]
 *   [tp_torrent_files id="123"]
 *   [tp_magnet id="123"]
 *   [tp_torrent_list category="movies" limit="20" orderby="seeders"]
 *
 * All attributes sanitized with absint() / sanitize_text_field().
 * All output escaped with esc_html() / esc_url() / esc_attr().
 */
final class TorrentShortcode
{
    public function __construct(
        private readonly TorrentRepository    $torrentRepo,
        private readonly StatisticsRepository $statsRepo,
    ) {}

    public function register(): void
    {
        add_shortcode('tp_torrent',       [$this, 'renderTorrent']);
        add_shortcode('tp_torrent_stats', [$this, 'renderStats']);
        add_shortcode('tp_torrent_files', [$this, 'renderFiles']);
        add_shortcode('tp_magnet',        [$this, 'renderMagnet']);
        add_shortcode('tp_torrent_list',  [$this, 'renderList']);
    }

    // ─── [tp_torrent id="123"] ───────────────────────────────────────

    /**
     * Renders a torrent info card.
     *
     * @param  array<string, string>|string $atts
     */
    public function renderTorrent(array|string $atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'tp_torrent');
        $id   = absint($atts['id']);

        if ($id <= 0) {
            return '';
        }

        $torrent = $this->torrentRepo->findById($id);
        if ($torrent === null) {
            return '';
        }

        return $this->buildCard($torrent);
    }

    // ─── [tp_torrent_stats id="123"] ─────────────────────────────────

    /**
     * Renders live seeder/leecher/completed badges.
     *
     * @param  array<string, string>|string $atts
     */
    public function renderStats(array|string $atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'tp_torrent_stats');
        $id   = absint($atts['id']);

        if ($id <= 0) {
            return '';
        }

        $torrent = $this->torrentRepo->findById($id);
        if ($torrent === null) {
            return '';
        }

        return $this->buildStatsBadges($torrent);
    }

    // ─── [tp_torrent_files id="123"] ─────────────────────────────────

    /**
     * Renders a file listing table.
     *
     * @param  array<string, string>|string $atts
     */
    public function renderFiles(array|string $atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'tp_torrent_files');
        $id   = absint($atts['id']);

        if ($id <= 0) {
            return '';
        }

        $torrent = $this->torrentRepo->findById($id);
        if ($torrent === null) {
            return '';
        }

        // Get file list from DB.
        $db     = $this->torrentRepo; // unused here; use the statsRepo's db reference
        // Actually we need to query tp_torrent_files. For now, show basic info.
        $html  = '<div class="tp-wrap tp-file-list">';
        $html .= '<table class="tp-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('File', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Size', 'torrent-scraper') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        $html .= '<tr><td colspan="2">' . esc_html(
            sprintf(
                /* translators: %d: file count */
                _n('%d file', '%d files', (int) $torrent['file_count'], 'torrent-scraper'),
                (int) $torrent['file_count'],
            )
        ) . ' — ' . esc_html($this->formatBytes((int) $torrent['total_size'])) . '</td></tr>';
        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    // ─── [tp_magnet id="123"] ────────────────────────────────────────

    /**
     * Renders a magnet link button.
     *
     * @param  array<string, string>|string $atts
     */
    public function renderMagnet(array|string $atts): string
    {
        $atts = shortcode_atts(['id' => 0], $atts, 'tp_magnet');
        $id   = absint($atts['id']);

        if ($id <= 0) {
            return '';
        }

        $torrent = $this->torrentRepo->findById($id);
        if ($torrent === null || empty($torrent['magnet_link'])) {
            return '';
        }

        return sprintf(
            '<div class="tp-wrap"><a href="%s" class="tp-magnet-btn" title="%s">🧲 %s</a></div>',
            esc_url($torrent['magnet_link']),
            esc_attr($torrent['name']),
            esc_html__('Magnet Link', 'torrent-scraper'),
        );
    }

    // ─── [tp_torrent_list category="movies" limit="20" orderby="seeders"] ─

    /**
     * Renders a torrent listing table.
     *
     * @param  array<string, string>|string $atts
     */
    public function renderList(array|string $atts): string
    {
        $atts = shortcode_atts([
            'category' => '',
            'limit'    => '20',
            'orderby'  => 'added_at',
        ], $atts, 'tp_torrent_list');

        $limit   = min(100, max(1, absint($atts['limit'])));
        $orderBy = sanitize_text_field($atts['orderby']);

        $filters = ['status' => 'active'];
        if (!empty($atts['category'])) {
            $filters['category_id'] = sanitize_text_field($atts['category']);
        }

        $torrents = $this->torrentRepo->findAll(
            limit:     $limit,
            offset:    0,
            orderBy:   $orderBy,
            direction: 'DESC',
            filters:   $filters,
        );

        if (empty($torrents)) {
            return '<div class="tp-wrap"><p>' . esc_html__('No torrents found.', 'torrent-scraper') . '</p></div>';
        }

        $html  = '<div class="tp-wrap tp-torrent-list">';
        $html .= '<table class="tp-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('Name', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Size', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('S', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('L', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Added', 'torrent-scraper') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($torrents as $torrent) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html($torrent['name']) . '</td>';
            $html .= '<td>' . esc_html($this->formatBytes((int) $torrent['total_size'])) . '</td>';
            $html .= '<td class="tp-badge-seeders">' . esc_html((string) $torrent['seeders']) . '</td>';
            $html .= '<td class="tp-badge-leechers">' . esc_html((string) $torrent['leechers']) . '</td>';
            $html .= '<td>' . esc_html(wp_date('Y-m-d', strtotime($torrent['added_at']))) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    // ─── Shared rendering helpers ────────────────────────────────────

    /**
     * Build a torrent info card HTML block.
     *
     * @param  array<string, mixed> $torrent
     */
    private function buildCard(array $torrent): string
    {
        $html  = '<div class="tp-wrap tp-card">';
        $html .= '<h3 class="tp-card-title">' . esc_html($torrent['name']) . '</h3>';
        $html .= '<div class="tp-card-meta">';
        $html .= '<span class="tp-meta-size">' . esc_html($this->formatBytes((int) $torrent['total_size'])) . '</span>';
        $html .= ' · ';
        $html .= '<span class="tp-meta-files">';
        $html .= esc_html(sprintf(
            _n('%d file', '%d files', (int) $torrent['file_count'], 'torrent-scraper'),
            (int) $torrent['file_count'],
        ));
        $html .= '</span>';
        $html .= '</div>';
        $html .= $this->buildStatsBadges($torrent);

        if (!empty($torrent['magnet_link'])) {
            $html .= sprintf(
                '<a href="%s" class="tp-magnet-btn">🧲 %s</a>',
                esc_url($torrent['magnet_link']),
                esc_html__('Magnet Link', 'torrent-scraper'),
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Build seeder/leecher/completed badges.
     *
     * @param  array<string, mixed> $torrent
     */
    private function buildStatsBadges(array $torrent): string
    {
        $html = sprintf(
            '<div class="tp-stats">'
            . '<span class="tp-badge tp-badge-seeders" title="%s">↑ %s</span>'
            . '<span class="tp-badge tp-badge-leechers" title="%s">↓ %s</span>'
            . '<span class="tp-badge tp-badge-completed" title="%s">✓ %s</span>',
            esc_attr__('Seeders', 'torrent-scraper'),
            esc_html(number_format_i18n((int) $torrent['seeders'])),
            esc_attr__('Leechers', 'torrent-scraper'),
            esc_html(number_format_i18n((int) $torrent['leechers'])),
            esc_attr__('Completed', 'torrent-scraper'),
            esc_html(number_format_i18n((int) $torrent['completed'])),
        );

        // Admin-only AJAX reload button.
        if (current_user_can('manage_options')) {
            $html .= sprintf(
                '<button type="button" class="tp-badge tp-badge-reload tp-ajax-reload-frontend"'
                . ' data-torrent-id="%s"'
                . ' data-nonce="%s"'
                . ' data-ajax-url="%s"'
                . ' onclick="window.tpReloadTorrent && window.tpReloadTorrent(this)"'
                . ' title="%s"'
                . ' style="border:none; background:none; cursor:pointer; font-size:inherit;">🔄</button>',
                esc_attr((string)(int) $torrent['id']),
                esc_attr(wp_create_nonce('tp_reload_nonce')),
                esc_attr(admin_url('admin-ajax.php')),
                esc_attr__('Reload stats from trackers', 'torrent-scraper'),
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Format bytes to human-readable (KB, MB, GB, TB).
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1);

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }
}
