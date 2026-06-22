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
 * File: BlockRegistrar.php
 * Component: WordPress Gutenberg Blocks
 * Description: Registers and configures custom Gutenberg blocks for showing torrent downloads and lists in the block editor.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Block;

use TorrentScraper\Core\Repository\StatisticsRepository;
use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * Registers all Gutenberg blocks via PHP (register_block_type), not block.json only.
 * This ensures shared-hosting compatibility where Node.js tooling is not available.
 *
 * Blocks:
 *   torrent-scraper/torrent-info   — Displays torrent metadata card.
 *   torrent-scraper/tracker-stats  — Displays live seeder/leecher counts.
 *   torrent-scraper/torrent-files  — File listing table.
 *   torrent-scraper/magnet-button  — One-click magnet link button.
 */
final class BlockRegistrar
{
    public function __construct(
        private readonly TorrentRepository    $torrentRepo,
        private readonly StatisticsRepository $statsRepo,
    ) {}

    /**
     * Called on the `init` hook.
     */
    public function register(): void
    {
        // Server-side rendered blocks — no build step required.
        register_block_type('torrent-scraper/torrent-info', [
            'api_version'     => 3,
            'title'           => __('Torrent Info', 'torrent-scraper'),
            'description'     => __('Displays torrent metadata (name, size, files).', 'torrent-scraper'),
            'category'        => 'widgets',
            'icon'            => 'download',
            'keywords'        => ['torrent', 'info', 'metadata'],
            'attributes'      => [
                'torrentId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
            ],
            'render_callback' => [$this, 'renderTorrentInfo'],
        ]);

        register_block_type('torrent-scraper/tracker-stats', [
            'api_version'     => 3,
            'title'           => __('Tracker Stats', 'torrent-scraper'),
            'description'     => __('Displays live seeder/leecher/completed counts.', 'torrent-scraper'),
            'category'        => 'widgets',
            'icon'            => 'chart-bar',
            'keywords'        => ['torrent', 'seeders', 'leechers', 'stats'],
            'attributes'      => [
                'torrentId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
            ],
            'render_callback' => [$this, 'renderTrackerStats'],
        ]);

        register_block_type('torrent-scraper/torrent-files', [
            'api_version'     => 3,
            'title'           => __('Torrent Files', 'torrent-scraper'),
            'description'     => __('Displays the file listing for a torrent.', 'torrent-scraper'),
            'category'        => 'widgets',
            'icon'            => 'list-view',
            'keywords'        => ['torrent', 'files', 'listing'],
            'attributes'      => [
                'torrentId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
            ],
            'render_callback' => [$this, 'renderTorrentFiles'],
        ]);

        register_block_type('torrent-scraper/magnet-button', [
            'api_version'     => 3,
            'title'           => __('Magnet Button', 'torrent-scraper'),
            'description'     => __('One-click magnet link button.', 'torrent-scraper'),
            'category'        => 'widgets',
            'icon'            => 'admin-links',
            'keywords'        => ['torrent', 'magnet', 'download'],
            'attributes'      => [
                'torrentId' => [
                    'type'    => 'number',
                    'default' => 0,
                ],
                'label' => [
                    'type'    => 'string',
                    'default' => '',
                ],
            ],
            'render_callback' => [$this, 'renderMagnetButton'],
        ]);
    }

    // ─── Render callbacks ────────────────────────────────────────────

    /**
     * Render: torrent-scraper/torrent-info
     *
     * @param  array<string, mixed> $attributes
     */
    public function renderTorrentInfo(array $attributes): string
    {
        $torrent = $this->findTorrent($attributes);
        if ($torrent === null) {
            return $this->placeholder(__('Torrent Info — select a torrent ID.', 'torrent-scraper'));
        }

        return sprintf(
            '<div class="tp-wrap tp-card wp-block-torrent-scraper-torrent-info">'
            . '<h3 class="tp-card-title">%s</h3>'
            . '<div class="tp-card-meta">'
            .   '<span class="tp-meta-size">%s</span> · '
            .   '<span class="tp-meta-files">%s</span>'
            . '</div>'
            . '%s'
            . '</div>',
            esc_html($torrent['name']),
            esc_html($this->formatBytes((int) $torrent['total_size'])),
            esc_html(sprintf(
                _n('%d file', '%d files', (int) $torrent['file_count'], 'torrent-scraper'),
                (int) $torrent['file_count'],
            )),
            $this->buildStatsBadges($torrent),
        );
    }

    /**
     * Render: torrent-scraper/tracker-stats
     *
     * @param  array<string, mixed> $attributes
     */
    public function renderTrackerStats(array $attributes): string
    {
        $torrent = $this->findTorrent($attributes);
        if ($torrent === null) {
            return $this->placeholder(__('Tracker Stats — select a torrent ID.', 'torrent-scraper'));
        }

        return '<div class="tp-wrap wp-block-torrent-scraper-tracker-stats">'
             . $this->buildStatsBadges($torrent)
             . '</div>';
    }

    /**
     * Render: torrent-scraper/torrent-files
     *
     * @param  array<string, mixed> $attributes
     */
    public function renderTorrentFiles(array $attributes): string
    {
        $torrent = $this->findTorrent($attributes);
        if ($torrent === null) {
            return $this->placeholder(__('Torrent Files — select a torrent ID.', 'torrent-scraper'));
        }

        return sprintf(
            '<div class="tp-wrap tp-file-list wp-block-torrent-scraper-torrent-files">'
            . '<table class="tp-table">'
            . '<thead><tr><th>%s</th><th>%s</th></tr></thead>'
            . '<tbody><tr><td colspan="2">%s — %s</td></tr></tbody>'
            . '</table>'
            . '</div>',
            esc_html__('File', 'torrent-scraper'),
            esc_html__('Size', 'torrent-scraper'),
            esc_html(sprintf(
                _n('%d file', '%d files', (int) $torrent['file_count'], 'torrent-scraper'),
                (int) $torrent['file_count'],
            )),
            esc_html($this->formatBytes((int) $torrent['total_size'])),
        );
    }

    /**
     * Render: torrent-scraper/magnet-button
     *
     * @param  array<string, mixed> $attributes
     */
    public function renderMagnetButton(array $attributes): string
    {
        $torrent = $this->findTorrent($attributes);
        if ($torrent === null || empty($torrent['magnet_link'])) {
            return $this->placeholder(__('Magnet Button — select a torrent with a magnet link.', 'torrent-scraper'));
        }

        $label = !empty($attributes['label'])
            ? sanitize_text_field($attributes['label'])
            : __('Magnet Link', 'torrent-scraper');

        return sprintf(
            '<div class="tp-wrap wp-block-torrent-scraper-magnet-button">'
            . '<a href="%s" class="tp-magnet-btn" title="%s">🧲 %s</a>'
            . '</div>',
            esc_url($torrent['magnet_link']),
            esc_attr($torrent['name']),
            esc_html($label),
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Find a torrent by the block's torrentId attribute.
     *
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    private function findTorrent(array $attributes): ?array
    {
        $id = absint($attributes['torrentId'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->torrentRepo->findById($id);
    }

    /**
     * Build seeder/leecher/completed badges.
     *
     * @param  array<string, mixed> $torrent
     */
    private function buildStatsBadges(array $torrent): string
    {
        return sprintf(
            '<div class="tp-stats">'
            . '<span class="tp-badge tp-badge-seeders" title="%s">↑ %s</span>'
            . '<span class="tp-badge tp-badge-leechers" title="%s">↓ %s</span>'
            . '<span class="tp-badge tp-badge-completed" title="%s">✓ %s</span>'
            . '</div>',
            esc_attr__('Seeders', 'torrent-scraper'),
            esc_html(number_format_i18n((int) $torrent['seeders'])),
            esc_attr__('Leechers', 'torrent-scraper'),
            esc_html(number_format_i18n((int) $torrent['leechers'])),
            esc_attr__('Completed', 'torrent-scraper'),
            esc_html(number_format_i18n((int) $torrent['completed'])),
        );
    }

    /**
     * Placeholder shown in the editor when no torrent is selected.
     */
    private function placeholder(string $text): string
    {
        return '<div class="tp-wrap tp-card" style="opacity:0.6;"><p>' . esc_html($text) . '</p></div>';
    }

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
