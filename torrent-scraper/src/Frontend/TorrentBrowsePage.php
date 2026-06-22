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
 * File: TorrentBrowsePage.php
 * Component: WordPress Frontend Pages
 * Description: Renders custom search, filter, and pagination interface for browsing published torrents.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Frontend;

use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Repository\StatisticsRepository;

/**
 * Frontend torrent browse page — sortable table (forum style) and card grid (blog style).
 *
 * Shortcode: [tp_torrent_browse style="forum" limit="25" category="" orderby="added_at"]
 *
 * Features:
 *   - Forum style: full-width sortable table with clickable column headers
 *   - Blog style: responsive card grid
 *   - Search bar with name filtering
 *   - Page-number pagination with per-page selector (20/50/100)
 *   - Client-side column sorting via torrent-browse.js
 */
final class TorrentBrowsePage
{
    public function __construct(
        private readonly TorrentRepository    $torrentRepo,
        private readonly StatisticsRepository $statsRepo,
    ) {}

    /**
     * Register the shortcode and enqueue assets.
     */
    public function register(): void
    {
        add_shortcode('tp_torrent_browse', [$this, 'renderShortcode']);
    }

    /**
     * Shortcode handler: [tp_torrent_browse style="forum" limit="25" category="" orderby="added_at"]
     *
     * @param  array<string, string>|string $atts
     */
    public function renderShortcode(array|string $atts): string
    {
        $atts = shortcode_atts([
            'style'    => 'forum',
            'limit'    => '25',
            'category' => '',
            'orderby'  => 'added_at',
        ], $atts, 'tp_torrent_browse');

        // Enqueue browse JS (client-side sort)
        $this->enqueueBrowseAssets();

        // Read query params from URL
        $page    = max(1, absint($_GET['tp_page'] ?? 1));
        $perPage = absint($_GET['tp_per_page'] ?? $atts['limit']);
        $perPage = in_array($perPage, [20, 25, 50, 100], true) ? $perPage : 25;
        $orderBy = sanitize_text_field($_GET['tp_orderby'] ?? $atts['orderby']);
        $order   = strtoupper(sanitize_text_field($_GET['tp_order'] ?? 'DESC'));
        $search  = sanitize_text_field($_GET['tp_search'] ?? '');
        $style   = sanitize_text_field($_GET['tp_style'] ?? $atts['style']);

        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }
        if (!in_array($style, ['forum', 'blog'], true)) {
            $style = 'forum';
        }

        $offset  = ($page - 1) * $perPage;
        $filters = ['status' => 'active'];

        if (!empty($atts['category'])) {
            $filters['category_id'] = sanitize_text_field($atts['category']);
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        try {
            $torrents = $this->torrentRepo->findAll(
                limit:     $perPage,
                offset:    $offset,
                orderBy:   $orderBy,
                direction: $order,
                filters:   $filters,
            );
            $total = $this->torrentRepo->count($filters);
        } catch (\Throwable $e) {
            return '<div class="tp-wrap"><p>' . esc_html__('Unable to load torrents.', 'torrent-scraper') . '</p></div>';
        }

        $totalPages = max(1, (int) ceil($total / $perPage));

        $html  = '<div class="tp-wrap tp-browse-wrap" id="tp-browse">';
        $html .= $this->renderSearchBar($search, $style, $perPage);

        if (empty($torrents)) {
            $html .= '<p class="tp-browse-empty">' . esc_html__('No torrents found.', 'torrent-scraper') . '</p>';
        } elseif ($style === 'blog') {
            $html .= $this->renderBlogGrid($torrents);
        } else {
            $html .= $this->renderForumTable($torrents, $orderBy, $order);
        }

        $html .= $this->renderPagination($page, $totalPages, $perPage, $total);
        $html .= '</div>';

        return $html;
    }

    // ─── Search Bar ─────────────────────────────────────────────────────

    private function renderSearchBar(string $currentSearch, string $currentStyle, int $perPage): string
    {
        $actionUrl = remove_query_arg(['tp_search', 'tp_page', 'tp_style', 'tp_per_page']);

        $html  = '<div class="tp-browse-header">';
        $html .= '<form method="get" action="" class="tp-browse-search-form">';

        // Preserve existing query params as hidden fields (except our own)
        $preserveKeys = ['p', 'page_id', 'pagename'];
        foreach ($preserveKeys as $key) {
            if (isset($_GET[$key])) {
                $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($_GET[$key]) . '">';
            }
        }

        $html .= '<div class="tp-browse-search-group">';
        $html .= '<input type="text" name="tp_search" value="' . esc_attr($currentSearch) . '" '
                . 'placeholder="' . esc_attr__('Search torrents…', 'torrent-scraper') . '" '
                . 'class="tp-browse-search-input" id="tp-browse-search">';
        $html .= '<button type="submit" class="tp-browse-search-btn">'
                . esc_html__('Search', 'torrent-scraper') . '</button>';
        $html .= '</div>';

        // View toggle
        $html .= '<div class="tp-view-toggle">';
        $forumActive = ($currentStyle === 'forum') ? ' active' : '';
        $blogActive  = ($currentStyle === 'blog') ? ' active' : '';
        $html .= '<button type="submit" name="tp_style" value="forum" class="tp-view-btn' . $forumActive . '" title="'
                . esc_attr__('Table View', 'torrent-scraper') . '">☰</button>';
        $html .= '<button type="submit" name="tp_style" value="blog" class="tp-view-btn' . $blogActive . '" title="'
                . esc_attr__('Grid View', 'torrent-scraper') . '">▦</button>';
        $html .= '</div>';

        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    // ─── Forum Style Table ──────────────────────────────────────────────

    private function renderForumTable(array $torrents, string $activeSort, string $activeDir): string
    {
        $columns = [
            'name'      => __('Name', 'torrent-scraper'),
            'total_size'=> __('Size', 'torrent-scraper'),
            'seeders'   => __('Seeds', 'torrent-scraper'),
            'leechers'  => __('Peers', 'torrent-scraper'),
            'completed' => __('Downloads', 'torrent-scraper'),
            'added_at'  => __('Added', 'torrent-scraper'),
        ];

        $sortTypes = [
            'name'       => 'string',
            'total_size' => 'number',
            'seeders'    => 'number',
            'leechers'   => 'number',
            'completed'  => 'number',
            'added_at'   => 'date',
        ];

        $html  = '<div class="tp-browse-table-container">';
        $html .= '<table class="tp-table tp-browse-table" id="tp-browse-table">';
        $html .= '<thead><tr>';

        foreach ($columns as $key => $label) {
            $sortClass = '';
            $ariaSort  = 'none';
            if ($key === $activeSort) {
                $sortClass = ($activeDir === 'ASC') ? ' tp-sort-asc' : ' tp-sort-desc';
                $ariaSort  = ($activeDir === 'ASC') ? 'ascending' : 'descending';
            }
            $html .= '<th class="tp-sortable' . $sortClass . '" '
                    . 'data-sort="' . esc_attr($key) . '" '
                    . 'data-sort-type="' . esc_attr($sortTypes[$key]) . '" '
                    . 'aria-sort="' . esc_attr($ariaSort) . '" '
                    . 'role="columnheader" tabindex="0">'
                    . esc_html($label)
                    . '<span class="tp-sort-arrow"></span>'
                    . '</th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($torrents as $torrent) {
            $html .= '<tr>';
            $html .= '<td data-value="' . esc_attr($torrent['name']) . '">'
                    . '<span class="tp-torrent-name">' . esc_html($torrent['name']) . '</span>';
            if (!empty($torrent['magnet_link'])) {
                $html .= ' <a href="' . esc_url($torrent['magnet_link']) . '" class="tp-magnet-icon" title="'
                        . esc_attr__('Magnet Link', 'torrent-scraper') . '">🧲</a>';
            }
            $html .= '</td>';
            $html .= '<td data-value="' . esc_attr((string)(int)$torrent['total_size']) . '">'
                    . esc_html($this->formatBytes((int) $torrent['total_size'])) . '</td>';
            $html .= '<td data-value="' . esc_attr((string)(int)$torrent['seeders']) . '" class="tp-badge-seeders">'
                    . esc_html(number_format_i18n((int) $torrent['seeders'])) . '</td>';
            $html .= '<td data-value="' . esc_attr((string)(int)$torrent['leechers']) . '" class="tp-badge-leechers">'
                    . esc_html(number_format_i18n((int) $torrent['leechers'])) . '</td>';
            $html .= '<td data-value="' . esc_attr((string)(int)$torrent['completed']) . '">'
                    . esc_html(number_format_i18n((int) $torrent['completed'])) . '</td>';
            $html .= '<td data-value="' . esc_attr($torrent['added_at']) . '">'
                    . esc_html(wp_date('M j, Y', strtotime($torrent['added_at']))) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    // ─── Blog Style Card Grid ───────────────────────────────────────────

    private function renderBlogGrid(array $torrents): string
    {
        $html = '<div class="tp-browse-grid">';

        foreach ($torrents as $torrent) {
            $html .= '<div class="tp-card tp-browse-card">';
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
            $html .= ' · ';
            $html .= '<span class="tp-meta-date">' . esc_html(wp_date('M j, Y', strtotime($torrent['added_at']))) . '</span>';
            $html .= '</div>';

            // Stats badges
            $html .= '<div class="tp-stats">';
            $html .= '<span class="tp-badge tp-badge-seeders" title="' . esc_attr__('Seeders', 'torrent-scraper') . '">↑ '
                    . esc_html(number_format_i18n((int) $torrent['seeders'])) . '</span>';
            $html .= '<span class="tp-badge tp-badge-leechers" title="' . esc_attr__('Leechers', 'torrent-scraper') . '">↓ '
                    . esc_html(number_format_i18n((int) $torrent['leechers'])) . '</span>';
            $html .= '<span class="tp-badge tp-badge-completed" title="' . esc_attr__('Completed', 'torrent-scraper') . '">✓ '
                    . esc_html(number_format_i18n((int) $torrent['completed'])) . '</span>';
            $html .= '</div>';

            if (!empty($torrent['magnet_link'])) {
                $html .= '<a href="' . esc_url($torrent['magnet_link']) . '" class="tp-magnet-btn">🧲 '
                        . esc_html__('Magnet Link', 'torrent-scraper') . '</a>';
            }

            $html .= '</div>'; // .tp-browse-card
        }

        $html .= '</div>'; // .tp-browse-grid

        return $html;
    }

    // ─── Pagination ─────────────────────────────────────────────────────

    private function renderPagination(int $currentPage, int $totalPages, int $perPage, int $totalItems): string
    {
        if ($totalPages <= 1 && $totalItems <= 20) {
            return '';
        }

        $html  = '<div class="tp-pagination">';

        // Per-page selector
        $html .= '<div class="tp-pagination-perpage">';
        $html .= '<span>' . esc_html__('Show:', 'torrent-scraper') . '</span>';
        foreach ([20, 50, 100] as $opt) {
            $activeClass = ($perPage === $opt) ? ' active' : '';
            $url = add_query_arg(['tp_per_page' => $opt, 'tp_page' => 1]);
            $html .= '<a href="' . esc_url($url) . '" class="tp-perpage-btn' . $activeClass . '">' . $opt . '</a>';
        }
        $html .= '</div>';

        // Page numbers
        if ($totalPages > 1) {
            $html .= '<div class="tp-pagination-pages">';
            $html .= '<span class="tp-pagination-info">'
                    . sprintf(
                        /* translators: %1$d: current page, %2$d: total pages */
                        esc_html__('Page %1$d of %2$d', 'torrent-scraper'),
                        $currentPage,
                        $totalPages
                    )
                    . ' (' . esc_html(number_format_i18n($totalItems)) . ' ' . esc_html__('total', 'torrent-scraper') . ')'
                    . '</span>';

            // Prev
            if ($currentPage > 1) {
                $html .= '<a href="' . esc_url(add_query_arg('tp_page', $currentPage - 1)) . '" class="tp-page-btn">«</a>';
            }

            // Page numbers with ellipsis
            $range = 2;
            for ($i = 1; $i <= $totalPages; $i++) {
                if ($i === 1 || $i === $totalPages || abs($i - $currentPage) <= $range) {
                    $activeClass = ($i === $currentPage) ? ' active' : '';
                    $html .= '<a href="' . esc_url(add_query_arg('tp_page', $i)) . '" class="tp-page-btn' . $activeClass . '">' . $i . '</a>';
                } elseif ($i === 2 || $i === $totalPages - 1) {
                    $html .= '<span class="tp-page-ellipsis">…</span>';
                }
            }

            // Next
            if ($currentPage < $totalPages) {
                $html .= '<a href="' . esc_url(add_query_arg('tp_page', $currentPage + 1)) . '" class="tp-page-btn">»</a>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    // ─── Asset Enqueue ──────────────────────────────────────────────────

    private function enqueueBrowseAssets(): void
    {
        static $enqueued = false;
        if ($enqueued) {
            return;
        }
        $enqueued = true;

        $jsUrl = TORRENT_SCRAPER_URL . 'assets/js/torrent-browse.js';
        if (is_ssl()) {
            $jsUrl = str_replace('http://', 'https://', $jsUrl);
        }

        wp_enqueue_script(
            'torrent-browse',
            $jsUrl,
            [],
            TORRENT_SCRAPER_VERSION,
            ['in_footer' => true],
        );
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
