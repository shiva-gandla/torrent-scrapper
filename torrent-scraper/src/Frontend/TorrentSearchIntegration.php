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
 * File: TorrentSearchIntegration.php
 * Component: WordPress Frontend Pages
 * Description: Integrates torrent custom post types into the standard WordPress global search queries.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Frontend;

use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * Torrent search integration for WordPress and wpForo.
 *
 * When a user searches on the blog or community (WordPress default search),
 * this class appends matching torrent results below the regular search results
 * via the `wp_footer` hook (theme-agnostic JS injection).
 *
 * This does NOT modify the WordPress search query or interfere with existing
 * search functionality — it supplements it with torrent results.
 */
final class TorrentSearchIntegration
{
    public function __construct(
        private readonly TorrentRepository $torrentRepo,
    ) {}

    /**
     * Register hooks.
     */
    public function register(): void
    {
        // Append torrent results on search result pages
        add_action('wp_footer', [$this, 'appendTorrentSearchResults'], 26);
    }

    /**
     * On WordPress search pages, inject matching torrent results at the bottom
     * of the search results area via JS (works with any theme).
     */
    public function appendTorrentSearchResults(): void
    {
        if (!is_search()) {
            return;
        }

        $searchQuery = get_search_query(false);
        if (empty($searchQuery)) {
            return;
        }

        $sanitized = sanitize_text_field($searchQuery);

        try {
            $torrents = $this->torrentRepo->findAll(
                limit:     10,
                offset:    0,
                orderBy:   'seeders',
                direction: 'DESC',
                filters:   ['status' => 'active', 'search' => $sanitized],
            );
            $total = $this->torrentRepo->count(['status' => 'active', 'search' => $sanitized]);
        } catch (\Throwable $e) {
            return;
        }

        if (empty($torrents)) {
            return;
        }

        $html = $this->buildSearchResultsHtml($torrents, $total, $sanitized);
        $escaped = wp_json_encode($html);
        ?>
        <script>
        (function() {
            var torrentHtml = <?php echo $escaped; ?>;
            if (!torrentHtml) return;

            var wrapper = document.createElement('div');
            wrapper.innerHTML = torrentHtml;
            var torrentEl = wrapper.firstElementChild;
            if (!torrentEl) return;

            // Inject after search results (theme-agnostic targets)
            var targets = [
                '.search-results',
                '#content',
                '.site-content',
                '.site-main',
                'main',
                '#primary',
                '.content-area',
            ];

            for (var i = 0; i < targets.length; i++) {
                var container = document.querySelector(targets[i]);
                if (container) {
                    container.appendChild(torrentEl);
                    return;
                }
            }
        })();
        </script>
        <?php
    }

    // ─── Build HTML ─────────────────────────────────────────────────────

    private function buildSearchResultsHtml(array $torrents, int $total, string $query): string
    {
        $html  = '<div class="tp-wrap tp-search-results" id="tp-search-results">';
        $html .= '<h3 class="tp-profile-torrents-title">';
        $html .= sprintf(
            /* translators: %1$d: count, %2$s: search query */
            esc_html__('Torrent Results (%1$d found for "%2$s")', 'torrent-scraper'),
            $total,
            esc_html($query)
        );
        $html .= '</h3>';

        $html .= '<div class="tp-browse-table-container">';
        $html .= '<table class="tp-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('Name', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Size', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Seeds', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Peers', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Downloads', 'torrent-scraper') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($torrents as $torrent) {
            $html .= '<tr>';
            $html .= '<td><span class="tp-torrent-name">' . esc_html($torrent['name']) . '</span>';
            if (!empty($torrent['magnet_link'])) {
                $html .= ' <a href="' . esc_url($torrent['magnet_link']) . '" class="tp-magnet-icon" title="'
                        . esc_attr__('Magnet Link', 'torrent-scraper') . '">🧲</a>';
            }
            $html .= '</td>';
            $html .= '<td>' . esc_html($this->formatBytes((int) $torrent['total_size'])) . '</td>';
            $html .= '<td class="tp-badge-seeders">' . esc_html(number_format_i18n((int) $torrent['seeders'])) . '</td>';
            $html .= '<td class="tp-badge-leechers">' . esc_html(number_format_i18n((int) $torrent['leechers'])) . '</td>';
            $html .= '<td>' . esc_html(number_format_i18n((int) $torrent['completed'])) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        if ($total > 10) {
            $html .= '<p class="tp-profile-more" style="margin-top:0.75rem;">'
                    . sprintf(
                        esc_html__('… and %d more torrents matching your search.', 'torrent-scraper'),
                        $total - 10
                    )
                    . '</p>';
        }

        $html .= '<div class="tp-stats-footer-label">';
        $html .= '<span>⚡ ' . esc_html__('Public Trackers Only', 'torrent-scraper') . '</span>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    // ─── Helpers ────────────────────────────────────────────────────────

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
