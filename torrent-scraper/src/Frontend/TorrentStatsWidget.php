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
 * File: TorrentStatsWidget.php
 * Component: WordPress Frontend Pages
 * Description: Renders a legacy sidebar widget displaying global tracker statistics and top downloaded torrents.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Frontend;

use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * Global torrent stats summary widget.
 *
 * Renders aggregate network statistics (total torrents, seeders, leechers, downloads)
 * for all active, non-private (public tracker) torrents.
 *
 * Shortcode: [tp_global_stats]
 *
 * Auto-injects into:
 *   - wpForo forum index: below "Forum Information" section via wp_footer + JS
 *   - WordPress homepage: as a sidebar widget via register_widget
 */
final class TorrentStatsWidget
{
    /** Cache TTL in seconds (5 minutes). */
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly TorrentRepository $torrentRepo,
    ) {
    }

    /**
     * Register shortcode, sidebar widget, and auto-injection hooks.
     */
    public function register(): void
    {
        add_shortcode('tp_global_stats', [$this, 'renderShortcode']);

        // Register as a WordPress sidebar widget for blog pages
        add_action('widgets_init', [$this, 'registerSidebarWidget']);

        // Auto-inject on wpForo forum pages (below Forum Information)
        add_action('wp_footer', [$this, 'autoInjectOnForum'], 25);

        // Auto-inject on WordPress blog homepage/frontpage
        add_action('wp_footer', [$this, 'autoInjectOnBlogHomepage'], 26);

        // Auto-create the "Torrents" page on first run
        add_action('init', [$this, 'ensureTorrentsPageExists'], 20);
    }

    /**
     * Shortcode handler: [tp_global_stats]
     *
     * @param  array<string, string>|string $atts
     */
    public function renderShortcode(array|string $atts): string
    {
        return $this->buildStatsHtml();
    }

    /**
     * Register the WordPress sidebar widget.
     */
    public function registerSidebarWidget(): void
    {
        register_widget(TorrentStatsWpWidget::class);
    }

    /**
     * Auto-inject stats on wpForo forum pages.
     * Placed inside the "Forum Information" box, between the counts and user stats.
     */
    public function autoInjectOnForum(): void
    {
        // Only inject if wpForo is active
        if (!class_exists('wpForo') && !function_exists('WPF')) {
            return;
        }

        $html = $this->buildForumStatsRowHtml();
        if (empty($html)) {
            return;
        }

        $escaped = wp_json_encode($html);
        ?>
        <script>
            (function () {
                function injectStats() {
                    var statsHtml = <?php echo $escaped; ?>;
                    if (!statsHtml) return;

                    // Avoid duplicate injections
                    if (document.querySelector('.tp-wpforo-stats-footer')) {
                        return;
                    }

                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = statsHtml;
                    var statsEl = wrapper.firstElementChild;
                    if (!statsEl) return;

                    // Target the entire native wpForo footer statistics block
                    var target = document.querySelector('#wpforo-footer');
                    if (target) {
                        // Insert the Torrent Statistics block directly BEFORE the native footer statistics box
                        target.parentNode.insertBefore(statsEl, target);
                        return;
                    }

                    // General fallback: insert at the bottom of wpforo-wrap
                    var wpforoWrap = document.querySelector('.wpforo-wrap');
                    if (wpforoWrap) {
                        wpforoWrap.appendChild(statsEl);
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', injectStats);
                } else {
                    injectStats();
                }
            })();
        </script>
        <?php
    }

    /**
     * Build a compact stats row styled to match wpForo's forum statistics.
     */
    public function buildForumStatsRowHtml(): string
    {
        $stats = $this->getCachedStats();
        $browseUrl = $this->getTorrentsPageUrl();

        // Build a separate block replicating the exact wpForo footer structure
        $html = '<div id="wpforo-footer" class="tp-wpforo-stats-footer">';

        // Header
        $html .= '<div id="wpforo-stat-header">';
        $html .= '<div class="wpf-footer-title">';
        // Torrent-specific SVG icon (globe/network)
        $html .= '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 18px; height: 18px; fill: currentColor; margin-right: 8px; vertical-align: middle;">';
        $html .= '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>';
        $html .= '</svg>';
        $html .= '<span>' . esc_html__('Torrent Statistics', 'torrent-scraper') . '</span>';
        $html .= '</div>';
        $html .= '</div>'; // #wpforo-stat-header

        // Body
        $html .= '<div id="wpforo-stat-body">';
        $html .= '<div class="wpf-footer-box">';
        $html .= '<ul>';

        // Torrents Item
        $html .= '<li class="tp-stat-torrents">';
        $html .= '<span class="wpf-stat-icon">📦</span> ';
        if ($browseUrl) {
            $html .= '<a href="' . esc_url($browseUrl) . '">';
        }
        $html .= '<span class="wpf-stat-value">' . esc_html($this->formatNumber($stats['total_torrents'])) . '</span> ';
        $html .= '<span class="wpf-stat-label">' . esc_html__('Torrents', 'torrent-scraper') . '</span>';
        if ($browseUrl) {
            $html .= '</a>';
        }
        $html .= '</li>';

        // Seeders Item
        $html .= '<li class="tp-stat-seeders">';
        $html .= '<span class="wpf-stat-icon">↑</span> ';
        $html .= '<span class="wpf-stat-value">' . esc_html($this->formatNumber($stats['total_seeders'])) . '</span> ';
        $html .= '<span class="wpf-stat-label">' . esc_html__('Seeders', 'torrent-scraper') . '</span>';
        $html .= '</li>';

        // Leechers Item
        $html .= '<li class="tp-stat-leechers">';
        $html .= '<span class="wpf-stat-icon">↓</span> ';
        $html .= '<span class="wpf-stat-value">' . esc_html($this->formatNumber($stats['total_leechers'])) . '</span> ';
        $html .= '<span class="wpf-stat-label">' . esc_html__('Peers', 'torrent-scraper') . '</span>';
        $html .= '</li>';

        // Downloads Item
        $html .= '<li class="tp-stat-downloads">';
        $html .= '<span class="wpf-stat-icon">✓</span> ';
        $html .= '<span class="wpf-stat-value">' . esc_html($this->formatNumber($stats['total_completed'])) . '</span> ';
        $html .= '<span class="wpf-stat-label">' . esc_html__('Downloads', 'torrent-scraper') . '</span>';
        $html .= '</li>';

        $html .= '</ul>';
        $html .= '</div>'; // .wpf-footer-box
        $html .= '</div>'; // #wpforo-stat-body

        $html .= '</div>'; // #wpforo-footer (.tp-wpforo-stats-footer)

        return $html;
    }

    /**
     * Auto-inject stats on WordPress blog homepage/frontpage only.
     * Placed above/inside the footer area if the widget is not already rendered.
     */
    public function autoInjectOnBlogHomepage(): void
    {
        // Only inject on homepage or front page
        if (!is_home() && !is_front_page()) {
            return;
        }

        // Avoid injecting on wpForo page layouts
        if (class_exists('wpForo') || function_exists('WPF')) {
            if ($this->isWpForoPage()) {
                return;
            }
        }

        $html = $this->buildStatsHtml();
        $sidebarHtml = $this->buildSidebarStatsHtml(true);
        if (empty($html) || empty($sidebarHtml)) {
            return;
        }

        $escaped = wp_json_encode($html);
        $escapedSidebar = wp_json_encode($sidebarHtml);
        ?>
        <script>
            (function () {
                // Check if stats widget is already present on the page
                if (document.getElementById('tp-global-stats') || document.getElementById('tp-sidebar-stats')) {
                    return;
                }

                // 1. Try to inject below the Logo tagline/description inside the footer (highest priority for layout alignment)
                var taglineSelectors = [
                    'footer .wp-block-site-tagline',
                    'footer .site-description',
                    'footer .wp-block-site-title',
                    'footer .site-title'
                ];
                for (var k = 0; k < taglineSelectors.length; k++) {
                    var tagline = document.querySelector(taglineSelectors[k]);
                    if (tagline) {
                        var sbHtml = <?php echo $escapedSidebar; ?>;
                        var wrapper = document.createElement('div');
                        wrapper.innerHTML = sbHtml;
                        var sidebarEl = wrapper.firstElementChild;
                        if (sidebarEl) {
                            // Insert directly below the tagline/description inside the footer column
                            tagline.parentNode.insertBefore(sidebarEl, tagline.nextSibling);
                            return;
                        }
                    }
                }

                // 2. Fallback: Try to inject inside the sidebar first (if it exists on this theme layout)
                var sidebarSelectors = [
                    '.widget-area',
                    '#secondary',
                    '.sidebar',
                    'aside.sidebar',
                    '#sidebar'
                ];
                for (var i = 0; i < sidebarSelectors.length; i++) {
                    var sidebar = document.querySelector(sidebarSelectors[i]);
                    if (sidebar) {
                        var sbHtml = <?php echo $escapedSidebar; ?>;
                        var wrapper = document.createElement('div');
                        wrapper.innerHTML = sbHtml;
                        var sidebarEl = wrapper.firstElementChild;
                        if (sidebarEl) {
                            // Append as a widget block inside the sidebar
                            sidebar.appendChild(sidebarEl);
                            return;
                        }
                    }
                }

                // 3. Fallback: Inject inside the footer wrapper container, below the links
                var footerSelectors = [
                    'footer.wp-block-template-part',        // Block themes footer
                    'footer',                               // Standard HTML5 footer
                    '#colophon',                            // Common theme footer ID
                    '.site-footer',                         // Common theme footer class
                    '.footer',                              // Generic footer class
                    '#footer',                              // Generic footer ID
                ];

                var ftHtml = <?php echo $escaped; ?>;
                var footerWrapper = document.createElement('div');
                footerWrapper.innerHTML = ftHtml;
                var footerEl = footerWrapper.firstElementChild;
                if (!footerEl) return;

                for (var j = 0; j < footerSelectors.length; j++) {
                    var footer = document.querySelector(footerSelectors[j]);
                    if (footer) {
                        // Append to the inner centering wrapper of the footer (e.g. wp-block-group or first div)
                        // to ensure it renders inside the styled footer container with correct margins.
                        var innerContainer = footer.querySelector('.wp-block-group, div');
                        if (innerContainer) {
                            innerContainer.appendChild(footerEl);
                        } else {
                            footer.appendChild(footerEl);
                        }
                        return;
                    }
                }

                // General fallback: append to body
                var body = document.querySelector('body');
                if (body) {
                    body.appendChild(footerEl);
                }
            })();
        </script>
        <?php
    }

    /**
     * Build the stats HTML block. Used by shortcode, widget, and auto-injection.
     */
    public function buildStatsHtml(): string
    {
        $stats = $this->getCachedStats();

        $html = '<div class="tp-wrap tp-global-stats-bar" id="tp-global-stats">';
        $html .= '<div class="tp-global-stats-grid">';

        $html .= $this->buildStatCard(
            '📦',
            __('Total Torrents', 'torrent-scraper'),
            $this->formatNumber($stats['total_torrents']),
            'accent-border'
        );
        $html .= $this->buildStatCard(
            '↑',
            __('Total Seeders', 'torrent-scraper'),
            $this->formatNumber($stats['total_seeders']),
            'seeder-border'
        );
        $html .= $this->buildStatCard(
            '↓',
            __('Total Leechers', 'torrent-scraper'),
            $this->formatNumber($stats['total_leechers']),
            'leecher-border'
        );
        $html .= $this->buildStatCard(
            '✓',
            __('Total Downloads', 'torrent-scraper'),
            $this->formatNumber($stats['total_completed']),
            'accent-border'
        );

        $html .= '</div>'; // .tp-global-stats-grid

        // "Browse All Torrents" link
        $browseUrl = $this->getTorrentsPageUrl();
        if ($browseUrl) {
            $html .= '<div class="tp-stats-browse-link">';
            $html .= '<a href="' . esc_url($browseUrl) . '" class="tp-browse-all-btn">'
                . esc_html__('Browse All Torrents', 'torrent-scraper') . ' →</a>';
            $html .= '</div>';
        }

        $html .= '<div class="tp-stats-footer-label">';
        $html .= '<span>⚡ ' . esc_html__('Public Trackers Only', 'torrent-scraper') . '</span>';
        $html .= '</div>';

        $html .= '</div>'; // .tp-global-stats-bar

        return $html;
    }

    /**
     * Build the sidebar-optimized stats HTML (compact vertical layout).
     */
    public function buildSidebarStatsHtml(bool $includeTitle = false): string
    {
        $stats = $this->getCachedStats();

        $html = '<div class="tp-wrap tp-sidebar-stats" id="tp-sidebar-stats">';

        if ($includeTitle) {
            $html .= '<div class="tp-sidebar-title">' . esc_html__('Torrent Stats:', 'torrent-scraper') . '</div>';
        }

        // Row 1: Torrents and Downloads
        $html .= '<div class="tp-sidebar-row">';
        $html .= '<span class="tp-sidebar-stat-item">';
        $html .= '<span class="tp-sidebar-emoji">📦</span>';
        $html .= '<span class="tp-sidebar-stat-value">' . esc_html($this->formatNumber($stats['total_torrents'])) . '</span> ';
        $html .= '<span class="tp-sidebar-stat-label">' . esc_html__('Torrents', 'torrent-scraper') . '</span>';
        $html .= '</span>';
        $html .= '<span class="tp-sidebar-separator">, </span>';
        $html .= '<span class="tp-sidebar-stat-item">';
        $html .= '<span class="tp-sidebar-emoji">✓</span>';
        $html .= '<span class="tp-sidebar-stat-value">' . esc_html($this->formatNumber($stats['total_completed'])) . '</span> ';
        $html .= '<span class="tp-sidebar-stat-label">' . esc_html__('Downloads', 'torrent-scraper') . '</span>';
        $html .= '</span>';
        $html .= '</div>';

        // Row 2: Seeds and Peers/Leechers
        $html .= '<div class="tp-sidebar-row seeder-leecher-row">';
        $html .= '<span class="tp-sidebar-stat-item seeder">';
        $html .= '<span class="tp-sidebar-emoji">↑</span>';
        $html .= '<span class="tp-sidebar-stat-value">' . esc_html($this->formatNumber($stats['total_seeders'])) . '</span> ';
        $html .= '<span class="tp-sidebar-stat-label">' . esc_html__('Seeds', 'torrent-scraper') . '</span>';
        $html .= '</span>';
        $html .= '<span class="tp-sidebar-separator">, </span>';
        $html .= '<span class="tp-sidebar-stat-item leecher">';
        $html .= '<span class="tp-sidebar-emoji">↓</span>';
        $html .= '<span class="tp-sidebar-stat-value">' . esc_html($this->formatNumber($stats['total_leechers'])) . '</span> ';
        $html .= '<span class="tp-sidebar-stat-label">' . esc_html__('Peers/Leechers', 'torrent-scraper') . '</span>';
        $html .= '</span>';
        $html .= '</div>';

        // Row 3: Public Trackers Only note
        $html .= '<div class="tp-sidebar-note">';
        $html .= '⚡ ' . esc_html__('Public Trackers Only', 'torrent-scraper') . '<br>';
        $html .= '<span style="font-weight: normal; opacity: 0.85;">' . esc_html__('(Note: No public/private tracker hosted in this server)', 'torrent-scraper') . '</span>';
        $html .= '</div>';

        // Row 4: Disclaimer
        $html .= '<div class="tp-sidebar-disclaimer">';
        $html .= esc_html__('All torrents shared here are indexed from external public sources.', 'torrent-scraper') . '<br>'
            . esc_html__('This blog does not host any files and is not responsible for their content;', 'torrent-scraper') . '<br>'
            . esc_html__('links will be removed if any discrepancies are found.', 'torrent-scraper');
        $html .= '</div>';

        // "Browse All Torrents" link
        $browseUrl = $this->getTorrentsPageUrl();
        if ($browseUrl) {
            $html .= '<div class="tp-stats-browse-link sidebar">';
            $html .= '<a href="' . esc_url($browseUrl) . '" class="tp-browse-all-btn">'
                . esc_html__('Browse All Torrents', 'torrent-scraper') . ' →</a>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private function buildStatCard(string $icon, string $label, string $value, string $borderClass): string
    {
        return '<div class="tp-stat-card ' . esc_attr($borderClass) . '">'
            . '<h3>' . esc_html($icon . ' ' . $label) . '</h3>'
            . '<p class="tp-stat-number">' . esc_html($value) . '</p>'
            . '</div>';
    }

    // ─── Caching ────────────────────────────────────────────────────────

    /**
     * @return array{total_torrents: int, total_seeders: int, total_leechers: int, total_completed: int}
     */
    private function getCachedStats(): array
    {
        $cached = get_transient('tp_global_stats');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $stats = $this->torrentRepo->getGlobalStats();
        } catch (\Throwable $e) {
            $stats = [
                'total_torrents' => 0,
                'total_seeders' => 0,
                'total_leechers' => 0,
                'total_completed' => 0,
            ];
        }

        set_transient('tp_global_stats', $stats, self::CACHE_TTL);

        return $stats;
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function formatNumber(int $num): string
    {
        if ($num >= 1_000_000) {
            return number_format($num / 1_000_000, 1) . 'M';
        }
        if ($num >= 1_000) {
            return number_format($num / 1_000, 1) . 'K';
        }
        return number_format_i18n($num);
    }

    /**
     * Get the URL to the auto-created "Torrents" page.
     */
    private function getTorrentsPageUrl(): string
    {
        $pageId = (int) get_option('tp_torrents_page_id', 0);
        if ($pageId > 0 && get_post_status($pageId) === 'publish') {
            return (string) get_permalink($pageId);
        }
        return '';
    }

    /**
     * Auto-create the "Torrents" browse page on first plugin load.
     * Stores the page ID in wp_options so it persists across theme changes.
     */
    public function ensureTorrentsPageExists(): void
    {
        // Only run in admin or on first-load (not every frontend request)
        $pageId = (int) get_option('tp_torrents_page_id', 0);
        if ($pageId > 0 && get_post_status($pageId) !== false) {
            return; // Page exists (published, draft, or trashed)
        }

        // Check if a page with this shortcode already exists
        $existing = get_posts([
            'post_type' => 'page',
            'post_status' => 'publish',
            's' => '[tp_torrent_browse',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        if (!empty($existing)) {
            update_option('tp_torrents_page_id', $existing[0]);
            return;
        }

        // Create the page
        $newPageId = wp_insert_post([
            'post_title' => __('Torrents', 'torrent-scraper'),
            'post_content' => '[tp_torrent_browse style="forum" limit="25"]',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_author' => 1,
            'comment_status' => 'closed',
        ]);

        if (!is_wp_error($newPageId) && $newPageId > 0) {
            update_option('tp_torrents_page_id', $newPageId);
        }
    }

    private function isWpForoPage(): bool
    {
        if (function_exists('is_wpforo_page')) {
            return is_wpforo_page();
        }

        // Fallback: check if wpForo forum page by slug
        if (function_exists('WPF') && is_page()) {
            $forumPageId = (int) (WPF()->board->get_current('pageid') ?? 0);
            return $forumPageId > 0 && is_page($forumPageId);
        }

        return false;
    }
}

// ─── WordPress Sidebar Widget ───────────────────────────────────────────

/**
 * WordPress sidebar widget for global torrent stats.
 *
 * Appears in Appearance → Widgets as "Torrent Stats".
 * Users can drag it into any sidebar — works with all themes.
 */
class TorrentStatsWpWidget extends \WP_Widget
{
    public function __construct()
    {
        parent::__construct(
            'tp_torrent_stats_widget',
            __('Torrent Stats', 'torrent-scraper'),
            [
                'description' => __('Shows global torrent network statistics (seeders, leechers, downloads). Public trackers only.', 'torrent-scraper'),
                'classname' => 'tp-stats-widget',
            ]
        );
    }

    /**
     * Front-end display of the widget.
     *
     * @param array<string, string> $args     Widget arguments (before_widget, after_widget, etc.).
     * @param array<string, mixed>  $instance Saved values from the widget form.
     */
    public function widget($args, $instance): void
    {
        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $title = !empty($instance['title'])
            ? $instance['title']
            : __('Torrent Stats', 'torrent-scraper');

        echo $args['before_title'] . esc_html($title) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        // Get the singleton stats widget instance and render sidebar HTML
        $adapter = \TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance();
        $torrentRepo = $adapter->getTorrentRepo();

        $statsWidget = new TorrentStatsWidget($torrentRepo);
        echo $statsWidget->buildSidebarStatsHtml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Widget form in the admin.
     *
     * @param array<string, mixed> $instance Previously saved values.
     */
    public function form($instance): void
    {
        $title = $instance['title'] ?? __('Torrent Stats', 'torrent-scraper');
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">
                <?php esc_html_e('Title:', 'torrent-scraper'); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text"
                value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    /**
     * Save widget form values.
     *
     * @param  array<string, mixed> $new_instance New values.
     * @param  array<string, mixed> $old_instance Old values.
     * @return array<string, mixed>
     */
    public function update($new_instance, $old_instance): array
    {
        return [
            'title' => sanitize_text_field($new_instance['title'] ?? ''),
        ];
    }
}
