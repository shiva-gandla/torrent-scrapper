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
 * File: TorrentProfileSection.php
 * Component: WordPress Frontend Pages
 * Description: Embeds torrent lists and download counts on WordPress user profile screens.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Frontend;

use TorrentScraper\Core\Repository\TorrentRepository;

/**
 * User profile torrent list section.
 *
 * Shows a list of torrents uploaded by a specific user on:
 *   - WordPress author pages (auto-injected via loop_start)
 *   - wpForo profile pages (auto-injected via wpforo_member_profile_extra_content)
 *
 * Shortcode: [tp_user_torrents user_id="0" limit="20"]
 *   user_id=0 means current logged-in user.
 */
final class TorrentProfileSection
{
    public function __construct(
        private readonly TorrentRepository $torrentRepo,
    ) {}

    /**
     * Register shortcode and auto-injection hooks.
     */
    public function register(): void
    {
        add_shortcode('tp_user_torrents', [$this, 'renderShortcode']);

        // WordPress author page — prepend before the post loop
        add_action('loop_start', [$this, 'injectOnAuthorPage'], 10, 1);
    }

    /**
     * Shortcode handler: [tp_user_torrents user_id="0" limit="20"]
     *
     * @param  array<string, string>|string $atts
     */
    public function renderShortcode(array|string $atts): string
    {
        $atts = shortcode_atts([
            'user_id' => '0',
            'limit'   => '20',
        ], $atts, 'tp_user_torrents');

        $userId = absint($atts['user_id']);
        $limit  = min(100, max(1, absint($atts['limit'])));

        // user_id=0 means current logged-in user
        if ($userId === 0) {
            $userId = get_current_user_id();
        }

        if ($userId <= 0) {
            return '';
        }

        return $this->buildUserTorrentsHtml($userId, $limit);
    }

    /**
     * Auto-inject on WordPress author archive pages.
     * Prepends a "Torrents by [Author]" section before the main post loop.
     */
    public function injectOnAuthorPage(\WP_Query $query): void
    {
        // Only on the main query for author archives, on the first loop_start call
        if (!$query->is_main_query() || !is_author() || !in_the_loop()) {
            return;
        }

        static $injected = false;
        if ($injected) {
            return;
        }
        $injected = true;

        $authorId = (int) get_queried_object_id();
        if ($authorId <= 0) {
            return;
        }

        $html = $this->buildUserTorrentsHtml($authorId, 20, true);
        if (!empty($html)) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — all output is already escaped in buildUserTorrentsHtml
        }
    }

    /**
     * Render the user's torrent list for the wpForo profile page.
     * Called by WpForoAdapter via the wpforo_member_profile_extra_content hook.
     *
     * @param int $userId  The WordPress user ID of the profile being viewed.
     */
    public function renderWpForoProfile(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $html = $this->buildUserTorrentsHtml($userId, 20);
        if (!empty($html)) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    // ─── Build HTML ─────────────────────────────────────────────────────

    /**
     * @param bool $showAuthorName  Whether to show the author name in the heading.
     */
    private function buildUserTorrentsHtml(int $userId, int $limit, bool $showAuthorName = false): string
    {
        try {
            $torrents = $this->torrentRepo->findByUserId($userId, $limit);
            $count    = $this->torrentRepo->countByUserId($userId);
        } catch (\Throwable $e) {
            return '';
        }

        // Build heading
        $heading = __('Uploaded Torrents', 'torrent-scraper');
        if ($showAuthorName) {
            $authorName = get_the_author_meta('display_name', $userId);
            if (!empty($authorName)) {
                $heading = sprintf(
                    /* translators: %s: author display name */
                    __('Torrents by %s', 'torrent-scraper'),
                    $authorName
                );
            }
        }

        $html  = '<div class="tp-wrap tp-profile-torrents" id="tp-profile-torrents">';
        $html .= '<h3 class="tp-profile-torrents-title">' . esc_html($heading) . '</h3>';

        if ($count > 0) {
            $html .= '<p class="tp-profile-torrents-count">'
                    . esc_html(sprintf(
                        /* translators: %s: number of torrents */
                        _n('%s torrent', '%s torrents', $count, 'torrent-scraper'),
                        number_format_i18n($count)
                    ))
                    . '</p>';
        }

        if (empty($torrents)) {
            $html .= '<p class="tp-browse-empty">' . esc_html__('No torrents uploaded yet.', 'torrent-scraper') . '</p>';
            $html .= '</div>';
            return $html;
        }

        $html .= '<div class="tp-browse-table-container">';
        $html .= '<table class="tp-table tp-profile-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . esc_html__('Name', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Size', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Seeds', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Peers', 'torrent-scraper') . '</th>';
        $html .= '<th>' . esc_html__('Added', 'torrent-scraper') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($torrents as $torrent) {
            $html .= '<tr>';
            $html .= '<td>'
                    . '<span class="tp-torrent-name">' . esc_html($torrent['name']) . '</span>';
            if (!empty($torrent['magnet_link'])) {
                $html .= ' <a href="' . esc_url($torrent['magnet_link']) . '" class="tp-magnet-icon" title="'
                        . esc_attr__('Magnet Link', 'torrent-scraper') . '">🧲</a>';
            }
            $html .= '</td>';
            $html .= '<td>' . esc_html($this->formatBytes((int) $torrent['total_size'])) . '</td>';
            $html .= '<td class="tp-badge-seeders">' . esc_html(number_format_i18n((int) $torrent['seeders'])) . '</td>';
            $html .= '<td class="tp-badge-leechers">' . esc_html(number_format_i18n((int) $torrent['leechers'])) . '</td>';
            $html .= '<td>' . esc_html(wp_date('M j, Y', strtotime($torrent['added_at']))) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        // Show "more" link if there are more than displayed
        if ($count > $limit) {
            $html .= '<p class="tp-profile-more">'
                    . sprintf(
                        /* translators: %d: remaining torrent count */
                        esc_html__('… and %d more', 'torrent-scraper'),
                        $count - $limit
                    )
                    . '</p>';
        }

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
