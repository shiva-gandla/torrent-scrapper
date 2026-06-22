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
 * File: AdminUI.php
 * Component: WordPress Admin UI
 * Description: Builds the plugin admin settings pages, tracker list dashboard, and category management tables.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Admin;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Installer\EnvironmentChecker;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Repository\CategoryRepository;
use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Service\TorrentService;

/**
 * Admin UI — registers menu pages and handles admin-side requests.
 *
 * Spec:
 *   - Top-level menu: Torrent Scraper (tp_admin)
 *   - Submenus: Dashboard, Torrents, Categories, Settings, System Check
 *   - All pages check current_user_can('manage_options')
 *   - All forms use nonces prefixed with tp_
 */
final class AdminUI
{
    private const CAPABILITY = 'manage_options';
    private const MENU_SLUG  = 'tp_admin';

    public function __construct(
        private readonly TorrentService     $torrentService,
        private readonly TorrentRepository  $torrentRepo,
        private readonly CategoryRepository $categoryRepo,
        private readonly DatabaseInterface  $db,
        private readonly LoggerInterface    $logger,
        private readonly \TorrentScraper\Core\Scheduler\Scheduler $scheduler,
        private readonly UploadPage         $uploadPage,
    ) {}

    // ─── Menu registration ───────────────────────────────────────────

    public function registerMenus(): void
    {
        add_menu_page(
            page_title: __('Torrent Scraper', 'torrent-scraper'),
            menu_title: __('Torrent Scraper', 'torrent-scraper'),
            capability: self::CAPABILITY,
            menu_slug:  self::MENU_SLUG,
            callback:   [$this, 'renderTorrentsTabbedPage'],
            icon_url:   'dashicons-download',
            position:   80,
        );

        add_submenu_page(
            parent_slug: self::MENU_SLUG,
            page_title:  __('Torrents', 'torrent-scraper'),
            menu_title:  __('Torrents', 'torrent-scraper'),
            capability:  self::CAPABILITY,
            menu_slug:   self::MENU_SLUG,
            callback:    [$this, 'renderTorrentsTabbedPage'],
        );

        add_submenu_page(
            parent_slug: self::MENU_SLUG,
            page_title:  __('Settings', 'torrent-scraper'),
            menu_title:  __('Settings', 'torrent-scraper'),
            capability:  self::CAPABILITY,
            menu_slug:   'tp_settings',
            callback:    [$this, 'renderSettingsTabbedPage'],
        );
    }

    // ─── Admin CSS/JS ────────────────────────────────────────────────

    public function enqueueAdminAssets(string $hookSuffix): void
    {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($page !== 'tp_admin' && $page !== 'tp_settings') {
            return;
        }

        $cssUrl = TORRENT_SCRAPER_URL . 'assets/css/torrent-scraper.css';
        if (is_ssl()) {
            $cssUrl = str_replace('http://', 'https://', $cssUrl);
        }

        wp_enqueue_style(
            'torrent-scraper-admin',
            $cssUrl,
            [],
            TORRENT_SCRAPER_VERSION,
        );

        // Inline stylesheet callback to guarantee premium design renders regardless of browser mixed-content or path issues
        add_action('admin_head', static function (): void {
            $cssPath = TORRENT_SCRAPER_DIR . 'assets/css/torrent-scraper.css';
            if (file_exists($cssPath)) {
                $css = file_get_contents($cssPath);
                if ($css !== false) {
                    echo '<style id="tp-admin-inline-css">' . $css . '</style>';
                }
            }
        });
    }

    // ─── Torrents Tabbed Page ────────────────────────────────────────

    public function renderTorrentsTabbedPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Unauthorized.', 'torrent-scraper'));
        }

        $currentTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';

        $uploadMessage = '';
        $uploadError   = '';
        if ($currentTab === 'upload' && isset($_POST['tp_upload_submit'])) {
            [$uploadMessage, $uploadError] = $this->uploadPage->handleSubmission();
        }

        $dashboardMessage = '';
        $dashboardMessageType = 'success';
        if ($currentTab === 'dashboard' && isset($_POST['tp_run_scraper_manual'])) {
            if (check_admin_referer('tp_run_scraper_nonce', 'tp_run_scraper_nonce_field')) {
                try {
                    $this->scheduler->runManual();
                    $dashboardMessage = __('Scraper executed successfully! Checked queue and updated active torrents.', 'torrent-scraper');
                } catch (\Throwable $e) {
                    $dashboardMessage = __('Error running scraper: ', 'torrent-scraper') . $e->getMessage();
                    $dashboardMessageType = 'error';
                }
            } else {
                $dashboardMessage = __('Security check failed.', 'torrent-scraper');
                $dashboardMessageType = 'error';
            }
        }

        $tabs = [
            'dashboard'    => __('Dashboard', 'torrent-scraper'),
            'upload'       => __('Upload Torrent', 'torrent-scraper'),
            'attached'     => __('Attached', 'torrent-scraper'),
            'top_seeders'  => __('Top Seeders', 'torrent-scraper'),
            'top_leechers' => __('Top Leechers', 'torrent-scraper'),
            'possible_dead'=> __('Possible Dead', 'torrent-scraper'),
            'dead'         => __('Dead Torrents', 'torrent-scraper'),
            'categories'   => __('Categories', 'torrent-scraper'),
        ];

        ?>
        <div class="wrap tp-wrap">
            <div class="tp-header">
                <h1><?php echo esc_html__('Torrent Scraper', 'torrent-scraper'); ?></h1>
                <p class="tp-subtitle"><?php echo esc_html__('Manage and track your blog and forum torrent scraping status.', 'torrent-scraper'); ?></p>
            </div>

            <div class="tp-tabs-nav">
                <?php foreach ($tabs as $tabKey => $tabLabel) : 
                    $activeClass = ($currentTab === $tabKey) ? 'active' : '';
                    $tabUrl = admin_url('admin.php?page=' . self::MENU_SLUG . '&tab=' . $tabKey);
                    ?>
                    <a href="<?php echo esc_url($tabUrl); ?>" class="tp-tab-link <?php echo esc_attr($activeClass); ?>">
                        <?php echo esc_html($tabLabel); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="tp-tab-content-container">
                <?php
                switch ($currentTab) {
                    case 'upload':
                        $this->uploadPage->renderForm($uploadMessage, $uploadError, true);
                        break;
                    case 'attached':
                        $this->renderCategorizedTorrentTab('attached');
                        break;
                    case 'top_seeders':
                        $this->renderCategorizedTorrentTab('top_seeders');
                        break;
                    case 'top_leechers':
                        $this->renderCategorizedTorrentTab('top_leechers');
                        break;
                    case 'possible_dead':
                        $this->renderCategorizedTorrentTab('possible_dead');
                        break;
                    case 'dead':
                        $this->renderCategorizedTorrentTab('dead');
                        break;
                    case 'categories':
                        $this->renderCategoriesTabContent();
                        break;
                    case 'dashboard':
                    default:
                        $this->renderDashboardTabContent($dashboardMessage, $dashboardMessageType);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function renderDashboardTabContent(string $message = '', string $messageType = 'success'): void
    {
        $totalActive  = $this->torrentRepo->count(['status' => 'active']);
        $totalPending = $this->torrentRepo->count(['status' => 'pending']);

        ?>
        <?php if ($message !== '') : ?>
            <div class="notice notice-<?php echo esc_attr($messageType); ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>

        <div class="tp-dashboard-grid">
            <div class="tp-card tp-stat-card seeder-border">
                <h3><?php echo esc_html__('Active Torrents', 'torrent-scraper'); ?></h3>
                <p class="tp-stat-number" style="color:var(--tp-seeder-color);"><?php echo esc_html(number_format_i18n($totalActive)); ?></p>
            </div>
            <div class="tp-card tp-stat-card leecher-border">
                <h3><?php echo esc_html__('Pending Review', 'torrent-scraper'); ?></h3>
                <p class="tp-stat-number" style="color:var(--tp-leecher-color);"><?php echo esc_html(number_format_i18n($totalPending)); ?></p>
            </div>
            <div class="tp-card tp-stat-card accent-border">
                <h3><?php echo esc_html__('Plugin Version', 'torrent-scraper'); ?></h3>
                <p class="tp-stat-number"><?php echo esc_html(TORRENT_SCRAPER_VERSION); ?></p>
            </div>
            <div class="tp-card tp-action-card">
                <h3><?php echo esc_html__('Scraper Engine', 'torrent-scraper'); ?></h3>
                <p class="tp-card-desc"><?php echo esc_html__('Run a manual scrape of torrents in the scraping queue immediately.', 'torrent-scraper'); ?></p>
                <form method="post" action="" style="margin-top: 1rem;">
                    <?php wp_nonce_field('tp_run_scraper_nonce', 'tp_run_scraper_nonce_field'); ?>
                    <button type="submit" name="tp_run_scraper_manual" class="tp-btn-primary button button-primary">
                        <span class="dashicons dashicons-update" style="margin-right:4px;"></span>
                        <?php echo esc_html__('Run Scraper Now', 'torrent-scraper'); ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="tp-section-header" style="margin-top:2.5rem; display: flex; justify-content: space-between; align-items: center;">
            <h2><?php echo esc_html__('Recent Torrents', 'torrent-scraper'); ?></h2>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tp_admin&tab=upload')); ?>" class="tp-btn-primary button">
                <span class="dashicons dashicons-plus" style="margin-right:4px;"></span>
                <?php echo esc_html__('Upload New', 'torrent-scraper'); ?>
            </a>
        </div>
        
        <div class="tp-table-container">
            <?php $this->renderTorrentTable(limit: 10); ?>
        </div>
        <?php
    }

    private function renderCategorizedTorrentTab(string $tabKey): void
    {
        $prefix = $this->db->tablePrefix();

        $title = match ($tabKey) {
            'attached'      => __('Attached Torrents', 'torrent-scraper'),
            'top_seeders'   => __('Top Seeders', 'torrent-scraper'),
            'top_leechers'  => __('Top Leechers', 'torrent-scraper'),
            'possible_dead' => __('Possible Dead', 'torrent-scraper'),
            'dead'          => __('Dead Torrents', 'torrent-scraper'),
            default         => __('Torrents', 'torrent-scraper'),
        };

        $query = match ($tabKey) {
            'attached' => "SELECT t.* FROM `{$prefix}tp_torrents` t
                           WHERE t.status = 'active'
                             AND t.id IN (SELECT DISTINCT torrent_id FROM `{$prefix}tp_torrent_post_map`)
                           ORDER BY t.added_at DESC LIMIT 50",
            'top_seeders' => "SELECT * FROM `{$prefix}tp_torrents`
                              WHERE status = 'active'
                              ORDER BY seeders DESC LIMIT 50",
            'top_leechers' => "SELECT * FROM `{$prefix}tp_torrents`
                               WHERE status = 'active' AND seeders >= 20
                               ORDER BY leechers DESC LIMIT 50",
            'possible_dead' => "SELECT * FROM `{$prefix}tp_torrents`
                                WHERE status = 'active' AND seeders > 0 AND seeders < 20
                                ORDER BY seeders ASC, leechers DESC LIMIT 50",
            'dead' => "SELECT * FROM `{$prefix}tp_torrents`
                       WHERE status = 'active' AND seeders = 0
                       ORDER BY added_at DESC LIMIT 50",
            default => "SELECT * FROM `{$prefix}tp_torrents`
                        WHERE status = 'active' ORDER BY added_at DESC LIMIT 50",
        };

        $torrents = $this->db->query($query, []);

        ?>
        <div class="tp-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h2 style="margin:0;"><?php echo esc_html($title); ?></h2>
                <span class="tp-badge" style="font-size:0.9em;">
                    <?php echo esc_html(sprintf(__('%d torrents', 'torrent-scraper'), count($torrents))); ?>
                </span>
            </div>
        <?php $this->renderTorrentTableFromData($torrents); ?>
        </div>
        <?php
    }

    private function renderCategoriesTabContent(): void
    {
        $categories = $this->categoryRepo->findAll(activeOnly: false);

        ?>
        <div class="tp-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h2 style="margin: 0;"><?php echo esc_html__('Torrent Categories', 'torrent-scraper'); ?></h2>
                <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=tp_torrent_category&post_type=tp_torrent')); ?>" class="tp-btn-primary button">
                    <span class="dashicons dashicons-category" style="margin-right:4px;"></span>
                    <?php echo esc_html__('Manage Categories', 'torrent-scraper'); ?>
                </a>
            </div>

            <div class="tp-table-container">
                <table class="wp-list-table widefat fixed striped tp-table">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('ID', 'torrent-scraper'); ?></th>
                            <th><?php echo esc_html__('Name', 'torrent-scraper'); ?></th>
                            <th><?php echo esc_html__('Slug', 'torrent-scraper'); ?></th>
                            <th><?php echo esc_html__('Parent', 'torrent-scraper'); ?></th>
                            <th><?php echo esc_html__('Active', 'torrent-scraper'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)) : ?>
                            <tr><td colspan="5"><?php echo esc_html__('No categories found.', 'torrent-scraper'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($categories as $cat) : ?>
                                <tr>
                                    <td><?php echo esc_html((string) $cat['id']); ?></td>
                                    <td><?php echo esc_html($cat['name']); ?></td>
                                    <td><code><?php echo esc_html($cat['slug']); ?></code></td>
                                    <td><?php echo $cat['parent_id'] ? esc_html((string) $cat['parent_id']) : '—'; ?></td>
                                    <td><?php echo $cat['is_active'] ? '✅' : '❌'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ─── Settings Tabbed Page ────────────────────────────────────────

    public function renderSettingsTabbedPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Unauthorized.', 'torrent-scraper'));
        }

        $currentTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        // Handle settings save.
        $message = '';
        if (isset($_POST['tp_save_settings'])) {
            check_admin_referer('tp_settings_nonce');

            $settings = [
                'log_level'             => sanitize_text_field($_POST['log_level'] ?? 'warning'),
                'tracker_timeout'       => absint($_POST['tracker_timeout'] ?? 10),
                'scheduler_batch_size'  => absint($_POST['scheduler_batch_size'] ?? 50),
                'max_upload_size'       => absint($_POST['max_upload_size'] ?? 512),
                'enable_shortcodes'     => isset($_POST['enable_shortcodes']) ? 'yes' : 'no',
                'enable_gutenberg'      => isset($_POST['enable_gutenberg']) ? 'yes' : 'no',
                'enable_cron'           => isset($_POST['enable_cron']) ? 'yes' : 'no',
                'enable_bbpress'        => 'no', // Commented out for initial release
                'enable_wpforo'         => isset($_POST['enable_wpforo']) ? 'yes' : 'no',
                'enable_rest_api'       => isset($_POST['enable_rest_api']) ? 'yes' : 'no',
                'enable_ajax_stats'     => isset($_POST['enable_ajax_stats']) ? 'yes' : 'no',
                'enable_torrent_upload' => isset($_POST['enable_torrent_upload']) ? 'yes' : 'no',
                'enable_magnet_upload'  => isset($_POST['enable_magnet_upload']) ? 'yes' : 'no',
                'upload_capability'     => sanitize_text_field($_POST['upload_capability'] ?? 'upload_files'),
                'enable_sync'           => isset($_POST['enable_sync']) ? 'yes' : 'no',
                'wpforo_version'        => sanitize_text_field($_POST['wpforo_version'] ?? '2.x'),
            ];

            update_option('tp_settings', $settings);
            $message = __('Settings saved successfully.', 'torrent-scraper');
        }

        $settings = get_option('tp_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        // Default helpers
        $getSetting = function(string $key, string $default) use ($settings): string {
            return (string) ($settings[$key] ?? $default);
        };

        $tabs = [
            'general'      => __('General Settings', 'torrent-scraper'),
            'integrations' => __('Integrations', 'torrent-scraper'),
            'security'     => __('Upload & Security', 'torrent-scraper'),
            'system'       => __('System Check', 'torrent-scraper'),
        ];

        ?>
        <div class="wrap tp-wrap">
            <div class="tp-header">
                <h1><?php echo esc_html__('Torrent Scraper Settings', 'torrent-scraper'); ?></h1>
                <p class="tp-subtitle"><?php echo esc_html__('Configure scraper settings, enable/disable integrations, and control upload permissions.', 'torrent-scraper'); ?></p>
            </div>

            <?php if ($message !== '') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <div class="tp-tabs-nav">
                <?php foreach ($tabs as $tabKey => $tabLabel) : 
                    $activeClass = ($currentTab === $tabKey) ? 'active' : '';
                    $tabUrl = admin_url('admin.php?page=tp_settings&tab=' . $tabKey);
                    ?>
                    <a href="<?php echo esc_url($tabUrl); ?>" class="tp-tab-link <?php echo esc_attr($activeClass); ?>">
                        <?php echo esc_html($tabLabel); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="tp-tab-content-container">
                <?php if ($currentTab === 'system') : ?>
                    <?php $this->renderSystemCheckTabContent(); ?>
                <?php else : ?>
                    <form method="post" action="">
                        <?php wp_nonce_field('tp_settings_nonce'); ?>

                        <?php if ($currentTab === 'general') : ?>
                            <div class="tp-card">
                                <h2><?php echo esc_html__('Core Scraper Engine Settings', 'torrent-scraper'); ?></h2>
                                <table class="form-table tp-form-table">
                                    <tr>
                                        <th scope="row"><label for="log_level"><?php echo esc_html__('Log Level', 'torrent-scraper'); ?></label></th>
                                        <td>
                                            <select name="log_level" id="log_level">
                                                <?php foreach (['error', 'warning', 'info', 'debug'] as $level) : ?>
                                                    <option value="<?php echo esc_attr($level); ?>" <?php selected($getSetting('log_level', 'warning'), $level); ?>><?php echo esc_html(ucfirst($level)); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php echo esc_html__('Set minimum logging severity level for the core engine database logger.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="tracker_timeout"><?php echo esc_html__('Tracker Timeout (seconds)', 'torrent-scraper'); ?></label></th>
                                        <td>
                                            <input type="number" name="tracker_timeout" id="tracker_timeout" value="<?php echo esc_attr($getSetting('tracker_timeout', '10')); ?>" min="3" max="30" />
                                            <p class="description"><?php echo esc_html__('Maximum time in seconds to wait for a scrape request to a tracker before timing out.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="scheduler_batch_size"><?php echo esc_html__('Scheduler Batch Size', 'torrent-scraper'); ?></label></th>
                                        <td>
                                            <input type="number" name="scheduler_batch_size" id="scheduler_batch_size" value="<?php echo esc_attr($getSetting('scheduler_batch_size', '50')); ?>" min="5" max="200" />
                                            <p class="description"><?php echo esc_html__('How many queue items to scrape/update in a single cron scrape cycle.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><label for="max_upload_size"><?php echo esc_html__('Max Upload Size (KB)', 'torrent-scraper'); ?></label></th>
                                        <td>
                                            <input type="number" name="max_upload_size" id="max_upload_size" value="<?php echo esc_attr($getSetting('max_upload_size', '512')); ?>" min="64" max="2048" />
                                            <p class="description"><?php echo esc_html__('Limit the file size of uploaded .torrent files (default 512KB).', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php elseif ($currentTab === 'integrations') : ?>
                            <div class="tp-card">
                                <h2><?php echo esc_html__('Enable / Disable Plugin Features', 'torrent-scraper'); ?></h2>
                                <table class="form-table tp-form-table">
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Automatic Scraper (Cron)', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_cron" value="yes" <?php checked($getSetting('enable_cron', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Enables WP-Cron background task to query and scrape trackers every 5 minutes.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Gutenberg Blocks', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_gutenberg" value="yes" <?php checked($getSetting('enable_gutenberg', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Enable the registration of custom block themes and Gutenberg editor blocks.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Shortcodes', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_shortcodes" value="yes" <?php checked($getSetting('enable_shortcodes', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Enable core shortcodes for displaying torrent downloads, metadata, and tables.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <?php /*
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('bbPress Forum Integration', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_bbpress" value="yes" <?php checked($getSetting('enable_bbpress', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Attach torrent download blocks automatically to bbPress forum topic posts.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    */ ?>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('wpForo Forum Integration', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_wpforo" value="yes" <?php checked($getSetting('enable_wpforo', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Attach torrent downloads automatically to wpForo forum topics and replies.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Cross-Platform Sync', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_sync" value="yes" <?php checked($getSetting('enable_sync', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('When a torrent is attached to a WP post or forum topic, automatically create/sync a counterpart on the other platform. Edits and deletions are also synced.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('wpForo Version', 'torrent-scraper'); ?></th>
                                        <td>
                                            <select name="wpforo_version">
                                                <option value="2.x" <?php selected($getSetting('wpforo_version', '2.x'), '2.x'); ?>><?php echo esc_html__('wpForo 2.x (Current)', 'torrent-scraper'); ?></option>
                                                <option value="1.x" <?php selected($getSetting('wpforo_version', '2.x'), '1.x'); ?>><?php echo esc_html__('wpForo 1.x (Legacy)', 'torrent-scraper'); ?></option>
                                            </select>
                                            <p class="description"><?php echo esc_html__('Select the wpForo version installed on your site. This determines which API calls to use.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('REST API Endpoints', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_rest_api" value="yes" <?php checked($getSetting('enable_rest_api', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Enable REST route endpoint /wp-json/torrent-scraper/v1/...', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('AJAX Live Stats', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_ajax_stats" value="yes" <?php checked($getSetting('enable_ajax_stats', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Enables AJAX requests to pull seeders/leechers live stats on frontend page-loads.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php elseif ($currentTab === 'security') : ?>
                            <div class="tp-card">
                                <h2><?php echo esc_html__('Upload & Security Permissions', 'torrent-scraper'); ?></h2>
                                <table class="form-table tp-form-table">
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Upload Capability', 'torrent-scraper'); ?></th>
                                        <td>
                                            <select name="upload_capability" id="upload_capability">
                                                <option value="manage_options" <?php selected($getSetting('upload_capability', 'upload_files'), 'manage_options'); ?>><?php echo esc_html__('Administrator (manage_options)', 'torrent-scraper'); ?></option>
                                                <option value="upload_files" <?php selected($getSetting('upload_capability', 'upload_files'), 'upload_files'); ?>><?php echo esc_html__('Author & Above (upload_files)', 'torrent-scraper'); ?></option>
                                                <option value="edit_posts" <?php selected($getSetting('upload_capability', 'upload_files'), 'edit_posts'); ?>><?php echo esc_html__('Contributor & Above (edit_posts)', 'torrent-scraper'); ?></option>
                                                <option value="read" <?php selected($getSetting('upload_capability', 'upload_files'), 'read'); ?>><?php echo esc_html__('Any Logged-in User (read)', 'torrent-scraper'); ?></option>
                                            </select>
                                            <p class="description"><?php echo esc_html__('Select the minimum user permission role capability required to upload torrents/magnets.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Allow .torrent File Uploads', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_torrent_upload" value="yes" <?php checked($getSetting('enable_torrent_upload', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Allow users to upload physical .torrent files to be parsed and saved.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row"><?php echo esc_html__('Allow Magnet Link Uploads', 'torrent-scraper'); ?></th>
                                        <td>
                                            <label class="tp-switch">
                                                <input type="checkbox" name="enable_magnet_upload" value="yes" <?php checked($getSetting('enable_magnet_upload', 'yes'), 'yes'); ?> />
                                                <span class="tp-slider"></span>
                                            </label>
                                            <p class="description"><?php echo esc_html__('Allow users to upload/submit magnet links directly instead of files.', 'torrent-scraper'); ?></p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 1.5rem;">
                            <?php submit_button(__('Save Settings', 'torrent-scraper'), 'primary', 'tp_save_settings', false, ['class' => 'tp-btn-primary button button-primary']); ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderSystemCheckTabContent(): void
    {
        $uploadDir = wp_upload_dir();
        $checker   = new EnvironmentChecker(
            db: $this->db,
            uploadDir: $uploadDir['basedir'] . '/torrent-scraper',
        );

        $results = $checker->check();

        ?>
        <div class="tp-card">
            <h2><?php echo esc_html__('System Check', 'torrent-scraper'); ?></h2>
            <p style="margin-bottom: 1.5rem;"><?php echo esc_html__('Verify your hosting environment meets all plugin requirements.', 'torrent-scraper'); ?></p>

            <div class="tp-table-container">
                <table class="wp-list-table widefat fixed striped tp-table">
                    <thead>
                        <tr>
                            <th style="width:30%;"><?php echo esc_html__('Check', 'torrent-scraper'); ?></th>
                            <th style="width:10%;"><?php echo esc_html__('Status', 'torrent-scraper'); ?></th>
                            <th><?php echo esc_html__('Details', 'torrent-scraper'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($result->check); ?></strong></td>
                                <td>
                                    <?php echo match ($result->statusLabel()) {
                                        'Pass'    => '<span style="color:var(--tp-seeder-color,#00a32a);">✅ Pass</span>',
                                        'Warning' => '<span style="color:var(--tp-leecher-color,#dba617);">⚠️ Warning</span>',
                                        'Fail'    => '<span style="color:#d63638;">❌ Fail</span>',
                                        default   => '',
                                    }; ?>
                                </td>
                                <td><?php echo esc_html($result->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    // ─── Shared table renderer ───────────────────────────────────────

    private function renderTorrentTable(int $limit = 20): void
    {
        $torrents = $this->torrentRepo->findAll(limit: $limit, direction: 'DESC');

        ?>
        <table class="wp-list-table widefat fixed striped tp-table">
            <thead>
                <tr>
                    <th style="width:5%;"><?php echo esc_html__('ID', 'torrent-scraper'); ?></th>
                    <th><?php echo esc_html__('Name', 'torrent-scraper'); ?></th>
                    <th style="width:10%;"><?php echo esc_html__('Size', 'torrent-scraper'); ?></th>
                    <th style="width:5%;"><?php echo esc_html__('S', 'torrent-scraper'); ?></th>
                    <th style="width:5%;"><?php echo esc_html__('L', 'torrent-scraper'); ?></th>
                    <th style="width:10%;"><?php echo esc_html__('Status', 'torrent-scraper'); ?></th>
                    <th style="width:12%;"><?php echo esc_html__('Added', 'torrent-scraper'); ?></th>
                    <th style="width:8%;"><?php echo esc_html__('Actions', 'torrent-scraper'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($torrents)) : ?>
                    <tr><td colspan="8"><?php echo esc_html__('No torrents found.', 'torrent-scraper'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($torrents as $t) : ?>
                        <tr id="tp-row-<?php echo esc_attr((string)(int) $t['id']); ?>">
                            <td><?php echo esc_html((string) $t['id']); ?></td>
                            <td><strong><?php echo esc_html($t['name']); ?></strong></td>
                            <td><?php echo esc_html($this->formatBytes((int) $t['total_size'])); ?></td>
                            <td class="tp-badge-seeders"><?php echo esc_html((string) $t['seeders']); ?></td>
                            <td class="tp-badge-leechers"><?php echo esc_html((string) $t['leechers']); ?></td>
                            <td>
                                <span class="tp-status-pill status-<?php echo esc_attr($t['status']); ?>">
                                    <?php echo esc_html(ucfirst($t['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i', strtotime($t['added_at']))); ?></td>
                            <td style="white-space:nowrap;">
                                <?php if (current_user_can('manage_options')) : ?>
                                    <button type="button"
                                        class="button button-small tp-ajax-reload"
                                        data-torrent-id="<?php echo esc_attr((string)(int) $t['id']); ?>"
                                        data-row="row-<?php echo esc_attr((string)(int) $t['id']); ?>"
                                        title="<?php echo esc_attr__('Reload stats from trackers', 'torrent-scraper'); ?>">
                                        🔄
                                    </button>
                                    <button type="button"
                                        class="button button-small tp-ajax-delete"
                                        data-torrent-id="<?php echo esc_attr((string)(int) $t['id']); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('tp_delete_torrent_' . (int) $t['id'])); ?>"
                                        data-name="<?php echo esc_attr($t['name']); ?>"
                                        title="<?php echo esc_attr__('Delete this torrent', 'torrent-scraper'); ?>"
                                        style="color:#d63638; border-color:#d63638;">
                                        🗑️
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render a torrent table from pre-queried data (used by categorized tabs).
     * Includes both "Remove" (soft-delete) and "Delete Permanently" (hard-delete) buttons.
     *
     * @param array<int, array<string, mixed>> $torrents
     */
    private function renderTorrentTableFromData(array $torrents): void
    {
        ?>
        <table class="wp-list-table widefat fixed striped tp-table">
            <thead>
                <tr>
                    <th style="width:5%;"><?php echo esc_html__('ID', 'torrent-scraper'); ?></th>
                    <th><?php echo esc_html__('Name', 'torrent-scraper'); ?></th>
                    <th style="width:10%;"><?php echo esc_html__('Size', 'torrent-scraper'); ?></th>
                    <th style="width:5%;"><?php echo esc_html__('S', 'torrent-scraper'); ?></th>
                    <th style="width:5%;"><?php echo esc_html__('L', 'torrent-scraper'); ?></th>
                    <th style="width:5%;"><?php echo esc_html__('C', 'torrent-scraper'); ?></th>
                    <th style="width:10%;"><?php echo esc_html__('Status', 'torrent-scraper'); ?></th>
                    <th style="width:12%;"><?php echo esc_html__('Added', 'torrent-scraper'); ?></th>
                    <th style="width:12%;"><?php echo esc_html__('Actions', 'torrent-scraper'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($torrents)) : ?>
                    <tr><td colspan="9"><?php echo esc_html__('No torrents found.', 'torrent-scraper'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($torrents as $t) : ?>
                        <tr id="tp-row-<?php echo esc_attr((string)(int) $t['id']); ?>">
                            <td><?php echo esc_html((string) $t['id']); ?></td>
                            <td><strong><?php echo esc_html($t['name']); ?></strong></td>
                            <td><?php echo esc_html($this->formatBytes((int) $t['total_size'])); ?></td>
                            <td class="tp-badge-seeders"><?php echo esc_html((string) $t['seeders']); ?></td>
                            <td class="tp-badge-leechers"><?php echo esc_html((string) $t['leechers']); ?></td>
                            <td><?php echo esc_html((string) ($t['completed'] ?? 0)); ?></td>
                            <td>
                                <span class="tp-status-pill status-<?php echo esc_attr($t['status']); ?>">
                                    <?php echo esc_html(ucfirst($t['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html(wp_date('Y-m-d H:i', strtotime($t['added_at']))); ?></td>
                            <td style="white-space:nowrap;">
                                <?php if (current_user_can('manage_options')) : ?>
                                    <button type="button"
                                        class="button button-small tp-ajax-reload"
                                        data-torrent-id="<?php echo esc_attr((string)(int) $t['id']); ?>"
                                        title="<?php echo esc_attr__('Reload stats', 'torrent-scraper'); ?>">
                                        🔄
                                    </button>
                                    <button type="button"
                                        class="button button-small tp-ajax-delete"
                                        data-torrent-id="<?php echo esc_attr((string)(int) $t['id']); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('tp_delete_torrent_' . (int) $t['id'])); ?>"
                                        data-name="<?php echo esc_attr($t['name']); ?>"
                                        title="<?php echo esc_attr__('Soft delete', 'torrent-scraper'); ?>"
                                        style="color:#dba617; border-color:#dba617;">
                                        📦
                                    </button>
                                    <button type="button"
                                        class="button button-small tp-ajax-hard-delete"
                                        data-torrent-id="<?php echo esc_attr((string)(int) $t['id']); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('tp_delete_torrent_' . (int) $t['id'])); ?>"
                                        data-name="<?php echo esc_attr($t['name']); ?>"
                                        title="<?php echo esc_attr__('Delete permanently', 'torrent-scraper'); ?>"
                                        style="color:#d63638; border-color:#d63638;">
                                        🗑️
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
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

    public function registerMetaBoxes(): void
    {
        $postTypes = ['post', 'page', 'tp_torrent'];
        foreach ($postTypes as $postType) {
            add_meta_box(
                'tp_torrent_metabox',
                __('Torrent Attachment', 'torrent-scraper'),
                [$this, 'renderMetaBox'],
                $postType,
                'side',
                'default'
            );
        }
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        wp_nonce_field('tp_upload_action', 'tp_upload_nonce', false);
        $torrentId = (int) get_post_meta($post->ID, 'tp_torrent_id', true);

        $attachments = [];
        try {
            $postMapRepo = new \TorrentScraper\WordPress\Sync\TorrentPostMapRepository($this->db);
            $attachments = $postMapRepo->findByPost('wp_post', $post->ID);
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // Fallback to legacy
        if (empty($attachments) && $torrentId > 0) {
            try {
                $torrent = $this->torrentRepo->findById($torrentId);
                if ($torrent !== null) {
                    $attachments = [[
                        'torrent_id' => $torrentId,
                        'name'       => $torrent['name'],
                        'total_size' => $torrent['total_size'],
                        'status'     => $torrent['status'],
                    ]];
                }
            } catch (\Throwable $e) {
                // ignore database query failures
            }
        }
        
        ?>
        <div class="tp-metabox-wrapper">
            <?php if (!empty($attachments)) : ?>
                <div class="tp-meta-attachments" style="margin-bottom: 1.25rem;">
                    <label style="display:block; font-weight:600; margin-bottom: 8px;">
                        <?php echo esc_html__('Attached Torrents', 'torrent-scraper'); ?>
                    </label>
                    <?php foreach ($attachments as $att) : 
                        $attId = (int) $att['torrent_id'];
                        $sizeStr = $this->formatBytes((int)$att['total_size']);
                        ?>
                        <div class="tp-meta-attachment-item" data-torrent-id="<?php echo $attId; ?>" style="background:#f4f6fa; border:1px solid #ddd; border-radius:4px; padding:0.5rem 0.75rem; margin-bottom:0.4rem; display:flex; align-items:center; gap:0.4rem; justify-content:space-between;">
                            <div style="flex:1; min-width:0; font-size:0.9em; line-height:1.3;">
                                <strong style="display:block; color:#1a1a2e; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;" title="<?php echo esc_attr($att['name']); ?>"><?php echo esc_html($att['name']); ?></strong>
                                <span style="font-size:0.85em; color:#666;">ID: <?php echo $attId; ?> · <?php echo esc_html($sizeStr); ?></span>
                            </div>
                            <div style="display:flex; gap: 2px;">
                                <button type="button" class="button button-small tp-metabox-detach-btn" data-torrent-id="<?php echo $attId; ?>" style="color:#dba617; border-color:#dba617;" title="<?php echo esc_attr__('Remove from topic (soft unlink)', 'torrent-scraper'); ?>">
                                    ✕
                                </button>
                                <button type="button" class="button button-small tp-metabox-delete-btn" data-torrent-id="<?php echo $attId; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('tp_delete_torrent_' . $attId)); ?>" style="color:#d63638; border-color:#d63638;" title="<?php echo esc_attr__('Delete permanently from server', 'torrent-scraper'); ?>">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-bottom: 1rem;">
                <label for="tp_torrent_id" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php echo esc_html__('Attach Existing Torrent ID', 'torrent-scraper'); ?>
                </label>
                <input type="number" name="tp_torrent_id" id="tp_torrent_id"
                       value=""
                       min="0" style="width:100%; max-width:150px;"
                       placeholder="<?php echo esc_attr__('e.g. 12', 'torrent-scraper'); ?>" />
            </div>

            <div style="margin: 1rem 0; border-top: 1px dashed #ccc;"></div>

            <div style="margin-bottom: 1rem;">
                <label for="tp_torrent_file" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php echo esc_html__('Upload new .torrent File', 'torrent-scraper'); ?>
                </label>
                <input type="file" name="tp_torrent_file" id="tp_torrent_file" accept=".torrent" style="width:100%;" />
            </div>

            <div>
                <label for="tp_magnet_uri" style="display:block; font-weight:600; margin-bottom:4px;">
                    <?php echo esc_html__('OR Paste Magnet Link', 'torrent-scraper'); ?>
                </label>
                <input type="url" name="tp_magnet_uri" id="tp_magnet_uri" style="width:100%;"
                       placeholder="magnet:?xt=urn:btih:..." />
            </div>
            
            <?php
            $settings = get_option('tp_settings', []);
            $enableWpForo = false;
            if (($settings['enable_wpforo'] ?? 'yes') === 'yes' && function_exists('WPF')) {
                try {
                    $wpforoInstance = WPF();
                    if (is_object($wpforoInstance) && isset($wpforoInstance->forum) && is_object($wpforoInstance->forum)) {
                        $enableWpForo = true;
                    }
                } catch (\Throwable $e) {
                    $enableWpForo = false;
                }
            }

            $enableBbPress = ($settings['enable_bbpress'] ?? 'yes') === 'yes' && function_exists('bbpress');
            $enableSync = ($settings['enable_sync'] ?? 'yes') === 'yes';

            if ($enableSync && ($enableWpForo || $enableBbPress)) : 
                $wpforoSelected = (int) get_post_meta($post->ID, 'tp_sync_wpforo_forum', true);
                $bbpressSelected = (int) get_post_meta($post->ID, 'tp_sync_bbpress_forum', true);

                if ($post->ID > 0) {
                    $links = [];
                    try {
                        $postLinkRepo = new \TorrentScraper\WordPress\Sync\PostLinkRepository($this->db);
                        $links = $postLinkRepo->findTargets('wp_post', $post->ID);
                    } catch (\Throwable $e) {
                        $links = [];
                    }
                    if (is_array($links)) {
                        foreach ($links as $link) {
                        if ($link['target_platform'] === 'wpforo_topic') {
                            $topicId = (int) $link['target_id'];
                            if (function_exists('WPF')) {
                                try {
                                    $wpforoInstance = WPF();
                                    if (is_object($wpforoInstance) && isset($wpforoInstance->topic) && is_object($wpforoInstance->topic)) {
                                        $topic = $wpforoInstance->topic->get_topic($topicId);
                                        if ($topic && !empty($topic['forumid'])) {
                                            $wpforoSelected = (int) $topic['forumid'];
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    // ignore
                                }
                            }
                        } elseif ($link['target_platform'] === 'bbpress_topic') {
                            $topicId = (int) $link['target_id'];
                            if (function_exists('bbp_get_topic_forum_id')) {
                                try {
                                    $forumId = bbp_get_topic_forum_id($topicId);
                                    if ($forumId > 0) {
                                        $bbpressSelected = $forumId;
                                    }
                                } catch (\Throwable $e) {
                                    // ignore
                                }
                            }
                        }
                    }
                }
            }
            ?>
                <div style="margin: 1.5rem 0 1rem 0; border-top: 1px dashed #ccc;"></div>
                
                <div class="tp-forum-select-section">
                    <h4 style="margin: 0 0 0.75rem 0; font-size: 1.05em; font-weight: 600; color: #1d2327;">
                        <?php echo esc_html__('Post to Forum', 'torrent-scraper'); ?>
                    </h4>
                    
                    <?php if ($enableWpForo) : 
                        $wpforoForums = [];
                        try {
                            $wpforoInstance = WPF();
                            if (is_object($wpforoInstance) && isset($wpforoInstance->forum) && is_object($wpforoInstance->forum)) {
                                $wpforoForums = $wpforoInstance->forum->get_forums(['type' => 'forum']);
                            }
                        } catch (\Throwable $e) {
                            $wpforoForums = [];
                        }
                    ?>
                        <div style="margin-bottom: 0.75rem;">
                            <label for="tp_sync_wpforo_forum" style="display:block; font-size:0.9em; font-weight:500; margin-bottom:4px;">
                                <?php echo esc_html__('wpForo Forum Category', 'torrent-scraper'); ?> <span class="tp-required-star" style="color:#d63638; display:none;">*</span>
                            </label>
                            <select name="tp_sync_wpforo_forum" id="tp_sync_wpforo_forum" style="width:100%;">
                                <option value=""><?php echo esc_html__('— Select wpForo Category —', 'torrent-scraper'); ?></option>
                                <?php 
                                if (is_array($wpforoForums)) {
                                    foreach ($wpforoForums as $f) {
                                        $fId = is_object($f) ? (int)($f->forumid ?? 0) : (is_array($f) && isset($f['forumid']) ? (int)$f['forumid'] : 0);
                                        if ($fId <= 0) continue;
                                        
                                        $parentId = is_object($f) ? (int)($f->parentid ?? 0) : (is_array($f) && isset($f['parentid']) ? (int)$f['parentid'] : 0);
                                        $titleText = is_object($f) ? ($f->title ?? '') : (is_array($f) && isset($f['title']) ? $f['title'] : '');
                                        
                                        $indent = '';
                                        if ($parentId > 0) {
                                            $indent = '— ';
                                        }
                                        $selectedStr = ($wpforoSelected === $fId) ? 'selected' : '';
                                        echo sprintf(
                                            '<option value="%d" %s>%s%s</option>',
                                            $fId,
                                            $selectedStr,
                                            esc_html($indent),
                                            esc_html($titleText)
                                        );
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if ($enableBbPress) : 
                        $bbpForums = [];
                        try {
                            $bbpForums = get_posts([
                                'post_type' => 'forum',
                                'numberposts' => -1,
                                'post_status' => 'publish',
                                'orderby' => 'title',
                                'order' => 'ASC'
                            ]);
                        } catch (\Throwable $e) {
                            $bbpForums = [];
                        }
                    ?>
                        <div style="margin-bottom: 0.75rem;">
                            <label for="tp_sync_bbpress_forum" style="display:block; font-size:0.9em; font-weight:500; margin-bottom:4px;">
                                <?php echo esc_html__('bbPress Forum Category', 'torrent-scraper'); ?> <span class="tp-required-star" style="color:#d63638; display:none;">*</span>
                            </label>
                            <select name="tp_sync_bbpress_forum" id="tp_sync_bbpress_forum" style="width:100%;">
                                <option value=""><?php echo esc_html__('— Select bbPress Forum —', 'torrent-scraper'); ?></option>
                                <?php 
                                if (is_array($bbpForums)) {
                                    foreach ($bbpForums as $f) {
                                        $fId = is_object($f) ? (int)$f->ID : (is_array($f) && isset($f['ID']) ? (int)$f['ID'] : 0);
                                        if ($fId <= 0) continue;
                                        
                                        $parent = is_object($f) ? (int)$f->post_parent : (is_array($f) && isset($f['post_parent']) ? (int)$f['post_parent'] : 0);
                                        $titleText = is_object($f) ? $f->post_title : (is_array($f) && isset($f['post_title']) ? $f['post_title'] : '');
                                        
                                        $indent = '';
                                        if ($parent > 0) {
                                            $indent = '— ';
                                        }
                                        $selectedStr = ($bbpressSelected === $fId) ? 'selected' : '';
                                        echo sprintf(
                                            '<option value="%d" %s>%s%s</option>',
                                            $fId,
                                            $selectedStr,
                                            esc_html($indent),
                                            esc_html($titleText)
                                        );
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div id="tp-forum-validation-error" style="color:#d63638; font-size: 0.85em; font-weight:600; margin-top:5px; display:none; line-height: 1.3;">
                        ⚠️ <?php echo esc_html__('Forum category selection is mandatory when a torrent is attached!', 'torrent-scraper'); ?>
                    </div>
                </div>
            <?php endif; ?>

            <script>
            jQuery(document).ready(function($) {
                var fileInput = document.getElementById("tp_torrent_file");
                if (fileInput) {
                    var form = fileInput.closest("form");
                    if (form) {
                        form.setAttribute("enctype", "multipart/form-data");
                    }
                }

                var isLpLocked = false;

                function validateForumSelection() {
                    try {
                        var hasTorrent = false;
                        
                        // 1. Check existing attachments in metabox wrapper
                        if ($('.tp-meta-attachment-item').length > 0) {
                            hasTorrent = true;
                        }
                        
                        // 2. Check Attach ID input
                        var torrentIdVal = $('#tp_torrent_id').val();
                        if (torrentIdVal && torrentIdVal.trim() !== '' && parseInt(torrentIdVal, 10) > 0) {
                            hasTorrent = true;
                        }
                        
                        // 3. Check File upload input
                        var fileEl = document.getElementById('tp_torrent_file');
                        if (fileEl && fileEl.files && fileEl.files.length > 0) {
                            hasTorrent = true;
                        }
                        
                        // 4. Check Magnet link input
                        var magnetVal = $('#tp_magnet_uri').val();
                        if (magnetVal && magnetVal.trim() !== '') {
                            hasTorrent = true;
                        }

                        var wpforoSelect = document.getElementById('tp_sync_wpforo_forum');
                        var bbpressSelect = document.getElementById('tp_sync_bbpress_forum');

                        var wpforoRequired = !!wpforoSelect;
                        var bbpressRequired = !!bbpressSelect;

                        var wpforoValid = !wpforoRequired || (wpforoSelect.value !== '');
                        var bbpressValid = !bbpressRequired || (bbpressSelect.value !== '');

                        var isValid = true;
                        if (hasTorrent) {
                            if ((wpforoRequired && !wpforoValid) || (bbpressRequired && !bbpressValid)) {
                                isValid = false;
                            }
                        }

                        if (!isValid) {
                            $('#tp-forum-validation-error').show();
                            $('.tp-required-star').show();
                            
                            if (wpforoRequired && !wpforoValid) {
                                $(wpforoSelect).css('border-color', '#d63638');
                            } else if (wpforoSelect) {
                                $(wpforoSelect).css('border-color', '');
                            }
                            
                            if (bbpressRequired && !bbpressValid) {
                                $(bbpressSelect).css('border-color', '#d63638');
                            } else if (bbpressSelect) {
                                $(bbpressSelect).css('border-color', '');
                            }

                            // Lock Gutenberg save if block editor is active and lock is not already set
                            if (!isLpLocked) {
                                try {
                                    if (window.wp && wp.data && typeof wp.data.select === 'function' && typeof wp.data.dispatch === 'function') {
                                        var editorSelect = wp.data.select('core/editor');
                                        if (editorSelect) {
                                            var editorDispatch = wp.data.dispatch('core/editor');
                                            if (editorDispatch && typeof editorDispatch.lockPostSaving === 'function') {
                                                editorDispatch.lockPostSaving('tp_forum_lock');
                                                isLpLocked = true;
                                            }
                                        }
                                    }
                                } catch (e) {
                                    console.warn('[Torrent Scraper] Gutenberg lockPostSaving error:', e);
                                }
                            }
                            return false;
                        } else {
                            $('#tp-forum-validation-error').hide();
                            $('.tp-required-star').hide();
                            if (wpforoSelect) $(wpforoSelect).css('border-color', '');
                            if (bbpressSelect) $(bbpressSelect).css('border-color', '');

                            // Unlock Gutenberg save
                            if (isLpLocked) {
                                try {
                                    if (window.wp && wp.data && typeof wp.data.select === 'function' && typeof wp.data.dispatch === 'function') {
                                        var editorSelect = wp.data.select('core/editor');
                                        if (editorSelect) {
                                            var editorDispatch = wp.data.dispatch('core/editor');
                                            if (editorDispatch && typeof editorDispatch.unlockPostSaving === 'function') {
                                                editorDispatch.unlockPostSaving('tp_forum_lock');
                                                isLpLocked = false;
                                            }
                                        }
                                    }
                                } catch (e) {
                                    console.warn('[Torrent Scraper] Gutenberg unlockPostSaving error:', e);
                                }
                            }
                            return true;
                        }
                    } catch (ex) {
                        console.error('[Torrent Scraper] Error in validateForumSelection:', ex);
                        return true;
                    }
                }

                // Run on document change/input events
                $(document).on('change keyup input click', '#tp_torrent_id, #tp_torrent_file, #tp_magnet_uri, #tp_sync_wpforo_forum, #tp_sync_bbpress_forum, .tp-metabox-detach-btn, .tp-metabox-delete-btn', function() {
                    validateForumSelection();
                });

                // Subscribe to Gutenberg store updates with exception wrapper
                if (window.wp && wp.data && typeof wp.data.subscribe === 'function') {
                    try {
                        wp.data.subscribe(function() {
                            try {
                                validateForumSelection();
                            } catch (err) {
                                // silent
                            }
                        });
                    } catch (subscribeError) {
                        console.warn('[Torrent Scraper] Could not subscribe to Gutenberg store updates:', subscribeError);
                    }
                }

                // Handle Classic Editor form submit
                $('#post').on('submit', function(e) {
                    if (!validateForumSelection()) {
                        alert('<?php echo esc_js(__('Please select a forum category for the attached torrent before saving/publishing.', 'torrent-scraper')); ?>');
                        e.preventDefault();
                        return false;
                    }
                });

                // Initial validation run with short delays to ensure DOM is fully populated
                setTimeout(validateForumSelection, 500);
                setTimeout(validateForumSelection, 2000);
            });
            </script>
        </div>
        <?php
    }

    public function savePostMeta(int $postId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['tp_upload_nonce']) || !wp_verify_nonce($_POST['tp_upload_nonce'], 'tp_upload_action')) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $settings = get_option('tp_settings', []);
        $enableSync = ($settings['enable_sync'] ?? 'yes') === 'yes';
        $enableWpForo = false;
        if (($settings['enable_wpforo'] ?? 'yes') === 'yes' && function_exists('WPF')) {
            try {
                $wpforoInstance = WPF();
                if (is_object($wpforoInstance) && isset($wpforoInstance->forum) && is_object($wpforoInstance->forum)) {
                    $enableWpForo = true;
                }
            } catch (\Throwable $e) {
                $enableWpForo = false;
            }
        }
        $enableBbPress = ($settings['enable_bbpress'] ?? 'yes') === 'yes' && function_exists('bbpress');

        // Check if there are category selections submitted
        $wpforoForum = isset($_POST['tp_sync_wpforo_forum']) ? absint($_POST['tp_sync_wpforo_forum']) : 0;
        $bbpressForum = isset($_POST['tp_sync_bbpress_forum']) ? absint($_POST['tp_sync_bbpress_forum']) : 0;

        // Fetch old forum selections
        $oldWpforoForum = (int) get_post_meta($postId, 'tp_sync_wpforo_forum', true);
        $oldBbpressForum = (int) get_post_meta($postId, 'tp_sync_bbpress_forum', true);

        // Update post metadata for forum selections
        update_post_meta($postId, 'tp_sync_wpforo_forum', $wpforoForum);
        update_post_meta($postId, 'tp_sync_bbpress_forum', $bbpressForum);

        // Check if a torrent is attached or being attached
        $hasTorrent = false;
        $torrentId = 0;

        // 1. Existing attachments in post map
        try {
            $postMapRepo = new \TorrentScraper\WordPress\Sync\TorrentPostMapRepository($this->db);
            $attachments = $postMapRepo->findByPost('wp_post', $postId);
            if (!empty($attachments)) {
                $hasTorrent = true;
                $torrentId = (int) $attachments[0]['torrent_id'];
            }
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // 2. Incoming attached torrent ID
        if (isset($_POST['tp_torrent_id'])) {
            $incomingId = absint($_POST['tp_torrent_id']);
            if ($incomingId > 0) {
                $hasTorrent = true;
                $torrentId = $incomingId;
            }
        }

        // 3. Incoming file/magnet upload (handled in wp_loaded, but we check if $_POST has the new ID)
        if (isset($_FILES['tp_torrent_file']) && $_FILES['tp_torrent_file']['error'] === UPLOAD_ERR_OK) {
            $hasTorrent = true;
        }
        if (!empty($_POST['tp_magnet_uri'])) {
            $hasTorrent = true;
        }

        // Save/Attach torrent ID if present in incoming request
        if (isset($_POST['tp_torrent_id'])) {
            $incomingId = absint($_POST['tp_torrent_id']);
            if ($incomingId > 0) {
                // Keep backward compatibility
                update_post_meta($postId, 'tp_torrent_id', $incomingId);

                // Attach to many-to-many post map table
                try {
                    $postMapRepo = new \TorrentScraper\WordPress\Sync\TorrentPostMapRepository($this->db);
                    $postMapRepo->attach($incomingId, 'wp_post', $postId, get_current_user_id());
                } catch (\Throwable $e) {
                    // ignore database attach failures
                }
            }
        }

        // Trigger sync or move counterpart if sync is enabled
        if ($hasTorrent && $enableSync) {
            // Server-side validation: if category is required for enabled platforms but missing, log warning and return
            if (($enableWpForo && $wpforoForum <= 0) || ($enableBbPress && $bbpressForum <= 0)) {
                $this->logger->warning(sprintf(
                    "Sync bypassed for Post ID %d: Forum category is mandatory when a torrent is attached, but wpForo: %d, bbPress: %d selected.",
                    $postId,
                    $wpforoForum,
                    $bbpressForum
                ));
                return;
            }

            try {
                if (class_exists('\TorrentScraper\WordPress\Adapter\WordPressAdapter')) {
                    $adapter = \TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance();
                    $syncService = $adapter->getSyncService();
                    $postLinkRepo = new \TorrentScraper\WordPress\Sync\PostLinkRepository($this->db);

                    $config = [
                        'wpforo_forum_id'  => $wpforoForum,
                        'bbpress_forum_id' => $bbpressForum,
                    ];

                    // If counterpart exists, check if category was changed and sync it
                    $wpforoHasLink = $postLinkRepo->linkExists('wp_post', $postId, 'wpforo_topic');
                    $bbpressHasLink = $postLinkRepo->linkExists('wp_post', $postId, 'bbpress_topic');

                    $currentUserId = get_current_user_id();

                    // Defer heavy sync operations to the shutdown hook to prevent editor timeouts and JSON errors
                    add_action('shutdown', function () use ($syncService, $postId, $torrentId, $config, $wpforoHasLink, $bbpressHasLink, $wpforoForum, $oldWpforoForum, $bbpressForum, $oldBbpressForum, $currentUserId) {
                        try {
                            if ($wpforoHasLink || $bbpressHasLink) {
                                if (($wpforoForum !== $oldWpforoForum) || ($bbpressForum !== $oldBbpressForum)) {
                                    $syncService->onCategoryChanged('wp_post', $postId, $config);
                                }
                            }

                            // If counterpart doesn't exist yet, create it
                            if ($torrentId > 0) {
                                $syncService->onTorrentAttached('wp_post', $postId, $torrentId, $currentUserId, $config);
                            }
                        } catch (\Throwable $ex) {
                            // ignore errors in shutdown hook sync
                        }
                    });
                }
            } catch (\Throwable $e) {
                $this->logger->error("Sync trigger failed in savePostMeta: " . $e->getMessage());
            }
        }
    }

    /**
     * Inline JS for AJAX reload buttons in the admin torrent table.
     * Hooked on admin_footer via enqueueAdminAssets.
     */
    public function printAdminReloadJs(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <script>
        (function() {
            var ajaxUrl  = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce    = <?php echo wp_json_encode(wp_create_nonce('tp_reload_nonce')); ?>;

            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.tp-ajax-reload');
                if (!btn) return;

                var torrentId = btn.dataset.torrentId;
                var rowId     = btn.dataset.row;

                btn.disabled    = true;
                btn.textContent = '⏳';

                var formData = new FormData();
                formData.append('action',     'tp_reload_torrent');
                formData.append('nonce',      nonce);
                formData.append('torrent_id', torrentId);

                fetch(ajaxUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            // Update seeder/leecher cells in the same row.
                            var row = document.querySelector('tr[data-torrent-id="' + torrentId + '"]');
                            if (!row) {
                                // Fallback: find by button's parent row.
                                row = btn.closest('tr');
                            }
                            if (row) {
                                var sCell = row.querySelector('.tp-badge-seeders');
                                var lCell = row.querySelector('.tp-badge-leechers');
                                if (sCell) sCell.textContent = data.data.seeders;
                                if (lCell) lCell.textContent = data.data.leechers;
                            }
                            btn.textContent = '✅';
                            setTimeout(function() { btn.textContent = '🔄'; btn.disabled = false; }, 1500);
                        } else {
                            btn.textContent = '❌';
                            setTimeout(function() { btn.textContent = '🔄'; btn.disabled = false; }, 2000);
                        }
                    })
                    .catch(function() {
                        btn.textContent = '❌';
                        setTimeout(function() { btn.textContent = '🔄'; btn.disabled = false; }, 2000);
                    });
            });

            // ─── Delete torrent ───────────────────────────────────────────────
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.tp-ajax-delete');
                if (!btn) return;

                var torrentId = btn.dataset.torrentId;
                var name      = btn.dataset.name || 'this torrent';
                var rowNonce  = btn.dataset.nonce;

                if (!confirm('Delete "' + name + '"?\n\nThis removes the torrent record from the database. The .torrent file on disk will also be deleted. This cannot be undone.')) {
                    return;
                }

                btn.disabled    = true;
                btn.textContent = '⏳';

                var formData = new FormData();
                formData.append('action',     'tp_delete_torrent');
                formData.append('nonce',      rowNonce);
                formData.append('torrent_id', torrentId);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            var row = document.getElementById('tp-row-' + torrentId);
                            if (row) {
                                row.style.transition = 'opacity 0.4s';
                                row.style.opacity    = '0';
                                setTimeout(function() { row.remove(); }, 450);
                            }
                        } else {
                            alert('Delete failed: ' + (data.data || 'Unknown error'));
                            btn.disabled    = false;
                            btn.textContent = '🗑️';
                        }
                    })
                    .catch(function() {
                        alert('Delete request failed. Check network connection.');
                        btn.disabled    = false;
                        btn.textContent = '🗑️';
                    });
            });

            // ─── Hard-delete torrent (permanent) ────────────────────────────────
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.tp-ajax-hard-delete');
                if (!btn) return;

                var torrentId = btn.dataset.torrentId;
                var name      = btn.dataset.name || 'this torrent';
                var rowNonce  = btn.dataset.nonce;

                if (!confirm('PERMANENTLY DELETE "' + name + '"?\n\nThis will:\n• Remove ALL database records (stats, trackers, files)\n• Delete the .torrent file from disk\n• Detach from ALL posts/topics\n\nThis is IRREVERSIBLE and cannot be undone.')) {
                    return;
                }

                btn.disabled    = true;
                btn.textContent = '⏳';

                var formData = new FormData();
                formData.append('action',     'tp_hard_delete_torrent');
                formData.append('nonce',      rowNonce);
                formData.append('torrent_id', torrentId);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            var row = document.getElementById('tp-row-' + torrentId);
                            if (row) {
                                row.style.transition = 'opacity 0.4s, background-color 0.3s';
                                row.style.backgroundColor = '#fdd';
                                row.style.opacity    = '0';
                                setTimeout(function() { row.remove(); }, 450);
                            }
                        } else {
                            alert('Permanent delete failed: ' + (data.data || 'Unknown error'));
                            btn.disabled    = false;
                            btn.textContent = '🗑️';
                        }
                    })
                    .catch(function() {
                        alert('Delete request failed. Check network connection.');
                        btn.disabled    = false;
                        btn.textContent = '🗑️';
                    });
            });

            // ─── Metabox Detach Torrent ──────────────────────────────────────────
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.tp-metabox-detach-btn');
                if (!btn) return;

                var torrentId = btn.dataset.torrentId;
                var postEl = document.getElementById('post_ID');
                var postId = postEl ? parseInt(postEl.value, 10) : 0;
                if (!torrentId || !postId) return;

                if (!confirm('Remove this torrent from this post?')) {
                    return;
                }

                btn.disabled = true;
                var origText = btn.textContent;
                btn.textContent = '⏳';

                var formData = new FormData();
                formData.append('action', 'tp_detach_torrent');
                formData.append('nonce', nonce);
                formData.append('torrent_id', torrentId);
                formData.append('platform', 'wp_post');
                formData.append('post_id', postId);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            var item = btn.closest('.tp-meta-attachment-item');
                            if (item) {
                                item.style.transition = 'opacity 0.3s';
                                item.style.opacity = '0';
                                setTimeout(function() { item.remove(); }, 300);
                            }
                        } else {
                            alert('Remove failed: ' + (data.data || 'Unknown error'));
                            btn.disabled = false;
                            btn.textContent = origText;
                        }
                    })
                    .catch(function() {
                        alert('Request failed.');
                        btn.disabled = false;
                        btn.textContent = origText;
                    });
            });

            // ─── Metabox Delete Torrent Permanently ──────────────────────────────
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('.tp-metabox-delete-btn');
                if (!btn) return;

                var torrentId = btn.dataset.torrentId;
                var rowNonce = btn.dataset.nonce;
                if (!torrentId) return;

                if (!confirm('PERMANENTLY DELETE this torrent?\n\nThis will:\n• Remove ALL database records (stats, trackers, files)\n• Delete the .torrent file from disk\n• Detach from ALL posts/topics\n\nThis is IRREVERSIBLE.')) {
                    return;
                }

                btn.disabled = true;
                var origText = btn.textContent;
                btn.textContent = '⏳';

                var formData = new FormData();
                formData.append('action', 'tp_hard_delete_torrent');
                formData.append('nonce', rowNonce);
                formData.append('torrent_id', torrentId);

                fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            var item = btn.closest('.tp-meta-attachment-item');
                            if (item) {
                                item.style.transition = 'opacity 0.3s';
                                item.style.opacity = '0';
                                setTimeout(function() { item.remove(); }, 300);
                            }
                        } else {
                            alert('Delete failed: ' + (data.data || 'Unknown error'));
                            btn.disabled = false;
                            btn.textContent = origText;
                        }
                    })
                    .catch(function() {
                        alert('Request failed.');
                        btn.disabled = false;
                        btn.textContent = origText;
                    });
            });
        })();
        </script>
        <?php
    }
}
