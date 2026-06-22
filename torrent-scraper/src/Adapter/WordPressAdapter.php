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
 * File: WordPressAdapter.php
 * Component: WordPress Core Adapter
 * Description: Binds core backend service classes to WordPress hooks, filters, action triggers, and lifecycle methods.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Adapter;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;
use TorrentScraper\Core\Installer\SchemaInstaller;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Logger\DatabaseLogger;
use TorrentScraper\Core\Parser\BencodeDecoder;
use TorrentScraper\Core\Parser\MagnetParser;
use TorrentScraper\Core\Parser\TorrentParser;
use TorrentScraper\Core\Repository\CategoryRepository;
use TorrentScraper\Core\Repository\StatisticsRepository;
use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Repository\TrackerRepository;
use TorrentScraper\Core\Scheduler\Scheduler;
use TorrentScraper\Core\Service\StatisticsService;
use TorrentScraper\Core\Service\TorrentService;
use TorrentScraper\Core\Service\TrackerService;
use TorrentScraper\Core\Tracker\HttpTrackerClient;
use TorrentScraper\Core\Tracker\TrackerManager;
use TorrentScraper\Core\Tracker\UdpTrackerClient;
use TorrentScraper\WordPress\Admin\AdminUI;
use TorrentScraper\WordPress\Admin\UploadPage;
use TorrentScraper\WordPress\Block\BlockRegistrar;
use TorrentScraper\WordPress\BbPress\BbPressAdapter;
use TorrentScraper\WordPress\Cron\WpCronIntegration;
use TorrentScraper\WordPress\Ajax\StatsAjax;
use TorrentScraper\WordPress\Database\WordPressDatabase;
use TorrentScraper\WordPress\PostType\TorrentPostType;
use TorrentScraper\WordPress\Rest\RestController;
use TorrentScraper\WordPress\Shortcode\TorrentShortcode;
use TorrentScraper\WordPress\WpForo\WpForoAdapter;
use TorrentScraper\WordPress\Sync\SyncService;
use TorrentScraper\WordPress\Sync\EditLockService;
use TorrentScraper\WordPress\Sync\TorrentPostMapRepository;
use TorrentScraper\WordPress\Sync\PostLinkRepository;
use TorrentScraper\WordPress\Frontend\TorrentBrowsePage;
use TorrentScraper\WordPress\Frontend\TorrentStatsWidget;
use TorrentScraper\WordPress\Frontend\TorrentProfileSection;
use TorrentScraper\WordPress\Frontend\TorrentSearchIntegration;

/**
 * Main WordPress adapter — singleton that wires the core engine into WordPress.
 *
 * Responsibilities:
 *   - Creates all core service objects (manual DI — no container on shared hosting).
 *   - Registers WordPress hooks, post types, shortcodes, blocks, cron, admin pages.
 *   - Handles plugin activation and deactivation.
 */
final class WordPressAdapter
{
    private static ?self $instance = null;

    private ?DatabaseInterface $db = null;
    private ?LoggerInterface $logger = null;
    private ?TorrentRepository $torrentRepo = null;
    private ?TrackerRepository $trackerRepo = null;
    private ?StatisticsRepository $statsRepo = null;
    private ?CategoryRepository $categoryRepo = null;
    private ?TorrentService $torrentService = null;
    private ?StatisticsService $statsService = null;
    private ?TrackerService $trackerService = null;
    private ?Scheduler $scheduler = null;
    private ?SyncService $syncService = null;
    private ?EditLockService $editLockService = null;
    private ?TorrentPostMapRepository $postMapRepo = null;
    private ?PostLinkRepository $postLinkRepo = null;
    private ?TorrentProfileSection $profileSection = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Boot — called on `plugins_loaded`
    // -------------------------------------------------------------------------

    public function boot(): void
    {
        // Register post type.
        $postType = new TorrentPostType();
        add_action('init', [$postType, 'register']);

        // Register early frontend/backend upload handler
        add_action('wp_loaded', [$this, 'handleRequestUploads']);
        add_action('wp_ajax_tp_reload_torrent', [$this, 'ajaxReloadTorrent']);
        add_action('wp_ajax_tp_debug_torrent', [$this, 'ajaxDebugTorrent']);
        add_action('wp_ajax_tp_delete_torrent', [$this, 'ajaxDeleteTorrent']);
        add_action('wp_ajax_tp_hard_delete_torrent', [$this, 'ajaxHardDeleteTorrent']);
        add_action('wp_ajax_tp_attach_torrent', [$this, 'ajaxAttachTorrent']);
        add_action('wp_ajax_tp_detach_torrent', [$this, 'ajaxDetachTorrent']);
        add_action('wp_ajax_tp_acquire_lock', [$this, 'ajaxAcquireLock']);
        add_action('wp_ajax_tp_release_lock', [$this, 'ajaxReleaseLock']);
        add_action('wp_ajax_tp_check_lock', [$this, 'ajaxCheckLock']);

        // Sync hooks for WP posts — only when sync is enabled.
        if ($this->getSettingValue('enable_sync', 'yes') === 'yes') {
            add_action('save_post', [$this, 'onWpPostSaved'], 20, 2);
            add_action('before_delete_post', [$this, 'onWpPostDeleted'], 10, 1);
            add_action('trashed_post', [$this, 'onWpPostDeleted'], 10, 1);
            add_action('transition_post_status', [$this, 'onWpPostStatusTransition'], 10, 3);
        }

        // Heartbeat API for edit lock renewal.
        add_filter('heartbeat_received', [$this, 'heartbeatLockRenewal'], 10, 2);

        // Auto append to standard posts/pages
        add_filter('the_content', [$this, 'appendTorrentInfoToContent']);
        add_filter('the_title',   [$this, 'appendTorrentBadgeToTitle'], 10, 2);

        // Register shortcodes.
        if ($this->getSettingValue('enable_shortcodes', 'yes') === 'yes') {
            $shortcodes = new TorrentShortcode($this->getTorrentRepo(), $this->getStatsRepo());
            add_action('init', [$shortcodes, 'register']);
        }

        // Register Gutenberg blocks.
        if ($this->getSettingValue('enable_gutenberg', 'yes') === 'yes') {
            $blocks = new BlockRegistrar($this->getTorrentRepo(), $this->getStatsRepo());
            add_action('init', [$blocks, 'register']);
        }

        // Enqueue frontend assets.
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_footer', [$this, 'renderInlineReloadScript'], 22);

        // Frontend browse page, global stats widget, and profile section.
        $browsePage = new TorrentBrowsePage($this->getTorrentRepo(), $this->getStatsRepo());
        $browsePage->register();

        $statsWidget = new TorrentStatsWidget($this->getTorrentRepo());
        $statsWidget->register();

        $profileSection = new TorrentProfileSection($this->getTorrentRepo());
        $profileSection->register();
        // Store for wpForo adapter to reference.
        $this->profileSection = $profileSection;

        $searchIntegration = new TorrentSearchIntegration($this->getTorrentRepo());
        $searchIntegration->register();

        // Register admin pages (admin only).
        if (is_admin()) {
            // Upload helper page (used embedded inside tabs).
            $uploadDir = wp_upload_dir();
            $upload = new UploadPage(
                torrentService: $this->getTorrentService(),
                logger: $this->getLogger(),
                storageDir: $uploadDir['basedir'] . '/torrent-scraper',
                maxUploadSizeKb: (int) $this->getSettingValue('max_upload_size', '512'),
                capability: $this->getSettingValue('upload_capability', 'upload_files'),
                enableTorrentUpload: $this->getSettingValue('enable_torrent_upload', 'yes') === 'yes',
                enableMagnetUpload: $this->getSettingValue('enable_magnet_upload', 'yes') === 'yes',
                trackerService: $this->getTrackerService(),
            );

            $admin = new AdminUI(
                torrentService: $this->getTorrentService(),
                torrentRepo: $this->getTorrentRepo(),
                categoryRepo: $this->getCategoryRepo(),
                db: $this->getDb(),
                logger: $this->getLogger(),
                scheduler: $this->getScheduler(),
                uploadPage: $upload,
            );
            add_action('admin_menu', [$admin, 'registerMenus']);
            add_action('admin_enqueue_scripts', [$admin, 'enqueueAdminAssets']);
            add_action('admin_enqueue_scripts', [$this, 'enqueuePostEditLockScript']);
            add_action('add_meta_boxes', [$admin, 'registerMetaBoxes']);
            add_action('save_post', [$admin, 'savePostMeta']);
            add_action('admin_footer', [$admin, 'printAdminReloadJs']);
        }

        // Register WP-Cron.
        if ($this->getSettingValue('enable_cron', 'yes') === 'yes') {
            $cron = new WpCronIntegration($this->getScheduler());
            $cron->register();
            // Self-heal: ensure the cron event is scheduled.
            // Covers the case where activation failed to register it.
            $cron->activate();
        }

        // bbPress integration (commented out for initial release).
        /*
        if ($this->getSettingValue('enable_bbpress', 'yes') === 'yes') {
            $bbpress = new BbPressAdapter(
                $this->getTorrentRepo(),
                $this->getPostMapRepo(),
                $this->getSyncService(),
                $this->getEditLockService(),
            );
            $bbpress->register();
        }
        */

        // wpForo integration
        if ($this->getSettingValue('enable_wpforo', 'yes') === 'yes') {
            $wpforo = new WpForoAdapter(
                $this->getTorrentRepo(),
                $this->getTorrentService(),
                $this->getTrackerService(),
                $this->getPostMapRepo(),
                $this->getSyncService(),
                $this->getEditLockService(),
            );
            $wpforo->register();
        }

        // REST API.
        if ($this->getSettingValue('enable_rest_api', 'yes') === 'yes') {
            $rest = new RestController($this->getTorrentRepo(), $this->getStatsRepo(), $this->getCategoryRepo());
            add_action('rest_api_init', [$rest, 'register']);
        }

        // AJAX stats endpoint (lazy-load live stats).
        if ($this->getSettingValue('enable_ajax_stats', 'yes') === 'yes') {
            $ajax = new StatsAjax($this->getTorrentRepo());
            $ajax->register();
        }
    }

    // -------------------------------------------------------------------------
    // Activation / Deactivation
    // -------------------------------------------------------------------------

    public function activate(): void
    {
        // Install/upgrade database schema.
        $installer = $this->getSchemaInstaller();
        $installer->install();

        // Create storage directory early if possible.
        $uploadDir = wp_upload_dir();
        $storageDir = $uploadDir['basedir'] . '/torrent-scraper';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        // Register post type early so rewrite rules flush correctly.
        $postType = new TorrentPostType();
        $postType->register();
        flush_rewrite_rules();

        // Schedule cron.
        $cron = new WpCronIntegration($this->getScheduler());
        $cron->activate();
    }

    public function deactivate(): void
    {
        // Remove scheduled cron.
        $cron = new WpCronIntegration($this->getScheduler());
        $cron->deactivate();

        flush_rewrite_rules();
    }

    // -------------------------------------------------------------------------
    // Frontend asset enqueue
    // -------------------------------------------------------------------------

    public function enqueueAssets(): void
    {
        $cssUrl = TORRENT_SCRAPER_URL . 'assets/css/torrent-scraper.css';
        $jsUrl = TORRENT_SCRAPER_URL . 'assets/js/torrent-scraper.js';

        if (is_ssl()) {
            $cssUrl = str_replace('http://', 'https://', $cssUrl);
            $jsUrl = str_replace('http://', 'https://', $jsUrl);
        }

        wp_enqueue_style(
            'torrent-scraper',
            $cssUrl,
            ['wp-block-library'], // load after WP core styles
            TORRENT_SCRAPER_VERSION,
        );

        wp_enqueue_script(
            'torrent-scraper',
            $jsUrl,
            [],
            TORRENT_SCRAPER_VERSION,
            ['in_footer' => true],
        );

        // Pass AJAX URL and nonce to frontend JS for lazy stats loading.
        wp_localize_script('torrent-scraper', 'tp_ajax', [
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tp_stats_nonce'),
            'reload_nonce' => wp_create_nonce('tp_reload_nonce'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Service getters (lazy initialization)
    // -------------------------------------------------------------------------

    public function getDb(): DatabaseInterface
    {
        return $this->db ??= new WordPressDatabase();
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger ??= new DatabaseLogger(
            db: $this->getDb(),
            logLevel: $this->getSettingValue('log_level', 'warning'),
        );
    }

    public function getTorrentRepo(): TorrentRepository
    {
        return $this->torrentRepo ??= new TorrentRepository($this->getDb());
    }

    public function getTrackerRepo(): TrackerRepository
    {
        return $this->trackerRepo ??= new TrackerRepository($this->getDb());
    }

    public function getStatsRepo(): StatisticsRepository
    {
        return $this->statsRepo ??= new StatisticsRepository($this->getDb());
    }

    public function getCategoryRepo(): CategoryRepository
    {
        return $this->categoryRepo ??= new CategoryRepository($this->getDb());
    }

    public function getTorrentService(): TorrentService
    {
        if ($this->torrentService !== null) {
            return $this->torrentService;
        }

        $decoder = new BencodeDecoder();

        return $this->torrentService = new TorrentService(
            torrentRepo: $this->getTorrentRepo(),
            trackerRepo: $this->getTrackerRepo(),
            statsRepo: $this->getStatsRepo(),
            torrentParser: new TorrentParser($decoder),
            magnetParser: new MagnetParser(),
            db: $this->getDb(),
            logger: $this->getLogger(),
        );
    }

    public function getStatsService(): StatisticsService
    {
        return $this->statsService ??= new StatisticsService(
            statsRepo: $this->getStatsRepo(),
            torrentRepo: $this->getTorrentRepo(),
            logger: $this->getLogger(),
        );
    }

    public function getTrackerService(): TrackerService
    {
        if ($this->trackerService !== null) {
            return $this->trackerService;
        }

        $decoder = new BencodeDecoder();

        $trackerManager = new TrackerManager(
            clients: [
                new UdpTrackerClient(),
                new HttpTrackerClient($decoder),
            ],
            logger: $this->getLogger(),
            timeoutSec: (int) $this->getSettingValue('tracker_timeout', '10'),
        );

        return $this->trackerService = new TrackerService(
            trackerManager: $trackerManager,
            statsService: $this->getStatsService(),
            trackerRepo: $this->getTrackerRepo(),
            logger: $this->getLogger(),
        );
    }

    public function getScheduler(): Scheduler
    {
        return $this->scheduler ??= new Scheduler(
            statsService: $this->getStatsService(),
            trackerService: $this->getTrackerService(),
            logger: $this->getLogger(),
            batchSize: (int) $this->getSettingValue('scheduler_batch_size', '50'),
        );
    }

    // -------------------------------------------------------------------------
    // Settings helper
    // -------------------------------------------------------------------------

    /**
     * Read a single value from the tp_settings option array.
     */
    public function getSettingValue(string $key, string $default = ''): string
    {
        $settings = get_option('tp_settings', []);
        if (!is_array($settings)) {
            return $default;
        }
        return (string) ($settings[$key] ?? $default);
    }

    /**
     * Intercept form submissions on frontend/backend and process torrent/magnet uploads.
     */
    public function handleRequestUploads(): void
    {
        // Check if our nonce is set and valid.
        if (!isset($_POST['tp_upload_nonce']) || !wp_verify_nonce($_POST['tp_upload_nonce'], 'tp_upload_action')) {
            return;
        }

        // Check user capability.
        $capability = $this->getSettingValue('upload_capability', 'upload_files');
        if (!current_user_can($capability)) {
            return;
        }

        $torrentId = 0;

        // Process File Upload if a file is provided.
        if (isset($_FILES['tp_torrent_file']) && $_FILES['tp_torrent_file']['error'] === UPLOAD_ERR_OK) {
            $tempPath = $_FILES['tp_torrent_file']['tmp_name'];
            $originalName = sanitize_file_name($_FILES['tp_torrent_file']['name']);

            // WordPress-level filetype check.
            $wpCheck = wp_check_filetype($originalName, ['torrent' => 'application/x-bittorrent']);
            if (!empty($wpCheck['ext']) && is_uploaded_file($tempPath)) {
                try {
                    $validator = new \TorrentScraper\Core\Upload\FileValidator(maxSizeBytes: (int) $this->getSettingValue('max_upload_size', '512') * 1024);
                    $uploadDir = wp_upload_dir();
                    $storage = new \TorrentScraper\Core\Upload\FileStorage($uploadDir['basedir'] . '/torrent-scraper');

                    $handler = new \TorrentScraper\Core\Upload\TorrentUploadHandler(
                        validator: $validator,
                        storage: $storage,
                        torrentService: $this->getTorrentService(),
                        logger: $this->getLogger(),
                    );

                    $torrentId = $handler->handleUpload($tempPath, $originalName, [
                        'platform' => 'wordpress',
                        'platform_user_id' => get_current_user_id(),
                        'status' => current_user_can('manage_options') ? 'active' : 'pending',
                    ]);
                } catch (\Throwable $e) {
                    $this->getLogger()->error(
                        "Frontend request file upload failed: {$e->getMessage()}",
                        ['event_type' => 'upload.error'],
                    );
                }
            }
        }
        // Process Magnet Link if provided.
        elseif (!empty($_POST['tp_magnet_uri'])) {
            $magnetUri = esc_url_raw(trim($_POST['tp_magnet_uri']));
            if (str_starts_with($magnetUri, 'magnet:?')) {
                try {
                    $validator = new \TorrentScraper\Core\Upload\FileValidator(maxSizeBytes: (int) $this->getSettingValue('max_upload_size', '512') * 1024);
                    $uploadDir = wp_upload_dir();
                    $storage = new \TorrentScraper\Core\Upload\FileStorage($uploadDir['basedir'] . '/torrent-scraper');

                    $handler = new \TorrentScraper\Core\Upload\TorrentUploadHandler(
                        validator: $validator,
                        storage: $storage,
                        torrentService: $this->getTorrentService(),
                        logger: $this->getLogger(),
                    );

                    $torrentId = $handler->handleMagnet($magnetUri, [
                        'platform' => 'wordpress',
                        'platform_user_id' => get_current_user_id(),
                        'status' => current_user_can('manage_options') ? 'active' : 'pending',
                    ]);
                } catch (\Throwable $e) {
                    $this->getLogger()->error(
                        "Frontend request magnet upload failed: {$e->getMessage()}",
                        ['event_type' => 'upload.magnet_error'],
                    );
                }
            }
        }

        // If a torrent was successfully created/uploaded, scrape the tracker stats immediately!
        if ($torrentId > 0) {
            $torrent = $this->getTorrentService()->getById($torrentId);
            if ($torrent !== null) {
                $infoHash = (string) $torrent['info_hash'];
                try {
                    $this->getTrackerService()->scrapeOne($torrentId, $infoHash, true);
                } catch (\Throwable $e) {
                    $this->getLogger()->warning(
                        "Synchronous immediate scraping failed for torrent_id={$torrentId}: {$e->getMessage()}",
                        ['event_type' => 'tracker.scrape_error', 'torrent_id' => $torrentId],
                    );
                }
            }

            // Set the POST variable so that standard metabox / forum topic save handlers find and attach it.
            $_POST['tp_torrent_id'] = $torrentId;
        }
    }

    /**
     * AJAX endpoint: scrape a single torrent immediately and return updated stats.
     * Called via wp_ajax_tp_reload_torrent.
     */
    public function ajaxReloadTorrent(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $torrentId = absint($_POST['torrent_id'] ?? 0);
        if ($torrentId <= 0) {
            wp_send_json_error(['message' => 'Invalid torrent ID.'], 400);
        }

        $torrent = $this->getTorrentService()->getById($torrentId);
        if ($torrent === null) {
            wp_send_json_error(['message' => 'Torrent not found.'], 404);
        }

        $infoHash = (string) $torrent['info_hash'];
        try {
            $this->getTrackerService()->scrapeOne($torrentId, $infoHash, true);
        } catch (\Throwable $e) {
            $this->getLogger()->warning(
                "AJAX reload scrape failed for torrent_id={$torrentId}: {$e->getMessage()}",
                ['event_type' => 'tracker.ajax_reload_error', 'torrent_id' => $torrentId],
            );
        }

        // Re-fetch torrent to get updated aggregated stats.
        $updated = $this->getTorrentService()->getById($torrentId);

        wp_send_json_success([
            'torrent_id' => $torrentId,
            'seeders' => (int) ($updated['seeders'] ?? 0),
            'leechers' => (int) ($updated['leechers'] ?? 0),
            'completed' => (int) ($updated['completed'] ?? 0),
        ]);
    }

    /**
     * AJAX endpoint: delete a torrent (soft-delete status=deleted + remove file from disk).
     * Uses a per-torrent nonce for extra security.
     */
    public function ajaxDeleteTorrent(): void
    {
        $torrentId = absint($_POST['torrent_id'] ?? 0);
        if ($torrentId <= 0) {
            wp_send_json_error('Invalid torrent ID.', 400);
        }

        check_ajax_referer('tp_delete_torrent_' . $torrentId, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $torrent = $this->getTorrentService()->getById($torrentId);
        if (!$torrent) {
            wp_send_json_error('Torrent not found.', 404);
        }

        // Delete physical .torrent file from disk if it exists.
        $filename = (string) ($torrent['torrent_filename'] ?? '');
        if ($filename !== '') {
            $uploadDir = wp_upload_dir();
            $filePath = trailingslashit($uploadDir['basedir']) . ltrim($filename, '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        // Soft-delete the torrent record.
        $this->getTorrentRepo()->softDelete($torrentId);

        // Clean up all wp_options links pointing to this torrent
        // (e.g. tp_wpforo_topic_* keys set by WpForoAdapter::ajaxLinkTopic).
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                  WHERE option_name LIKE %s
                    AND option_value = %s",
                'tp_wpforo_topic_%',
                (string) $torrentId,
            )
        );

        // Also clean up WP post meta links (standard posts/pages).
        $wpdb->delete(
            $wpdb->postmeta,
            ['meta_key' => 'tp_torrent_id', 'meta_value' => (string) $torrentId],
            ['%s', '%s'],
        );

        $this->getLogger()->info(
            "Torrent #{$torrentId} deleted by admin.",
            ['event_type' => 'torrent.deleted', 'torrent_id' => $torrentId],
        );

        wp_send_json_success(['torrent_id' => $torrentId]);
    }

    /**
     * AJAX endpoint: full diagnostic for a single torrent.
     * Returns trackers, raw scrape results, and stored stats.
     * Admin-only. Access: /wp-admin/admin-ajax.php?action=tp_debug_torrent&torrent_id=X&nonce=Y
     */
    public function ajaxDebugTorrent(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $torrentId = absint($_REQUEST['torrent_id'] ?? 0);
        if ($torrentId <= 0) {
            wp_send_json_error('Invalid torrent_id.', 400);
        }

        $torrent = $this->getTorrentService()->getById($torrentId);
        if (!$torrent) {
            wp_send_json_error("Torrent #{$torrentId} not found in database.", 404);
        }

        $infoHash = (string) ($torrent['info_hash'] ?? '');
        $trackers = $this->getTrackerRepo()->findByTorrentId($torrentId, activeOnly: false);
        $storedStats = $this->getStatsRepo()->findByTorrentId($torrentId);

        $scrapeResults = [];
        foreach ($trackers as $tracker) {
            $url = (string) $tracker['tracker_url'];
            $start = microtime(true);
            try {
                $result = $this->getTrackerService()->scrapeOne($torrentId, $infoHash, true);
                $elapsed = round((microtime(true) - $start) * 1000);
                $scrapeResults[] = [
                    'url' => $url,
                    'type' => $tracker['tracker_type'] ?? 'unknown',
                    'active' => (bool) $tracker['is_active'],
                    'elapsed' => $elapsed . 'ms',
                    'note' => 'see stored stats for result',
                ];
                break; // scrapeOne iterates internally — run once
            } catch (\Throwable $e) {
                $elapsed = round((microtime(true) - $start) * 1000);
                $scrapeResults[] = [
                    'url' => $url,
                    'type' => $tracker['tracker_type'] ?? 'unknown',
                    'active' => (bool) $tracker['is_active'],
                    'elapsed' => $elapsed . 'ms',
                    'error' => $e->getMessage(),
                ];
                break;
            }
        }

        // Re-fetch after scrape attempt.
        $updatedTorrent = $this->getTorrentService()->getById($torrentId);

        wp_send_json_success([
            'torrent' => [
                'id' => $torrentId,
                'name' => $torrent['name'],
                'info_hash' => $infoHash,
                'seeders' => $updatedTorrent['seeders'] ?? 0,
                'leechers' => $updatedTorrent['leechers'] ?? 0,
                'completed' => $updatedTorrent['completed'] ?? 0,
            ],
            'trackers' => $trackers,
            'scrape_attempt' => $scrapeResults,
            'stored_stats' => $storedStats,
            'php_sockets' => extension_loaded('sockets') ? 'yes' : 'no',
            'php_version' => PHP_VERSION,
        ]);
    }

    public function appendTorrentInfoToContent(string $content): string
    {
        if (!is_singular()) {
            return $content;
        }

        $postId = get_the_ID();
        if ($postId <= 0) {
            return $content;
        }

        // Avoid double rendering if manual shortcode is already inside content
        if (has_shortcode($content, 'tp_torrent') || has_shortcode($content, 'tp_torrent_stats')) {
            return $content;
        }

        // Use new multi-torrent post map.
        $attachments = [];
        try {
            $attachments = $this->getPostMapRepo()->findByPost('wp_post', $postId);
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // Fallback: check legacy wp_postmeta for backward compat.
        if (empty($attachments)) {
            $legacyId = (int) get_post_meta($postId, 'tp_torrent_id', true);
            if ($legacyId > 0) {
                return $content . do_shortcode("[tp_torrent id='{$legacyId}']");
            }
            return $content;
        }

        $extra = '';
        foreach ($attachments as $att) {
            $tid = (int) $att['torrent_id'];
            $extra .= do_shortcode("[tp_torrent id='{$tid}']");
        }

        return $content . $extra;
    }

    // -------------------------------------------------------------------------
    // Schema installer
    // -------------------------------------------------------------------------

    private function getSchemaInstaller(): SchemaInstaller
    {
        return new SchemaInstaller(
            db: $this->getDb(),
            migrationsDir: TORRENT_SCRAPER_CORE_DIR . 'database/migrations',
            getVersion: static fn(): int => (int) get_option('tp_db_version', 0),
            saveVersion: static fn(int $v): bool => update_option('tp_db_version', $v),
        );
    }

    // -------------------------------------------------------------------------
    // New service getters (sync, locks, post map)
    // -------------------------------------------------------------------------

    public function getPostMapRepo(): TorrentPostMapRepository
    {
        return $this->postMapRepo ??= new TorrentPostMapRepository($this->getDb());
    }

    public function getPostLinkRepo(): PostLinkRepository
    {
        return $this->postLinkRepo ??= new PostLinkRepository($this->getDb());
    }

    public function getSyncService(): SyncService
    {
        return $this->syncService ??= new SyncService(
            postLinkRepo: $this->getPostLinkRepo(),
            postMapRepo: $this->getPostMapRepo(),
            logger: $this->getLogger(),
        );
    }

    public function getEditLockService(): EditLockService
    {
        return $this->editLockService ??= new EditLockService($this->getDb());
    }

    // -------------------------------------------------------------------------
    // AJAX: Attach torrent to post
    // -------------------------------------------------------------------------

    public function ajaxAttachTorrent(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $torrentId = absint($_POST['torrent_id'] ?? 0);
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $postId = absint($_POST['post_id'] ?? 0);

        if ($torrentId <= 0 || $postId <= 0 || !in_array($platform, ['wp_post', 'wpforo_topic', 'bbpress_topic'], true)) {
            wp_send_json_error('Invalid parameters.', 400);
        }

        $torrent = $this->getTorrentService()->getById($torrentId);
        if (!$torrent) {
            wp_send_json_error('Torrent not found.', 404);
        }

        $attached = $this->getPostMapRepo()->attach($torrentId, $platform, $postId, get_current_user_id());

        // Trigger sync if enabled.
        if ($attached && $this->getSettingValue('enable_sync', 'yes') === 'yes') {
            $config = [
                'wp_category_id' => absint($_POST['wp_category_id'] ?? 0),
                'wpforo_forum_id' => absint($_POST['wpforo_forum_id'] ?? 0),
                'bbpress_forum_id' => absint($_POST['bbpress_forum_id'] ?? 0),
            ];
            $this->getSyncService()->onTorrentAttached($platform, $postId, $torrentId, get_current_user_id(), $config);
        }

        wp_send_json_success(['attached' => $attached, 'torrent_id' => $torrentId]);
    }

    // -------------------------------------------------------------------------
    // AJAX: Detach (soft unlink) torrent from post
    // -------------------------------------------------------------------------

    public function ajaxDetachTorrent(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $torrentId = absint($_POST['torrent_id'] ?? 0);
        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $postId = absint($_POST['post_id'] ?? 0);

        if ($torrentId <= 0 || $postId <= 0) {
            wp_send_json_error('Invalid parameters.', 400);
        }

        $this->getPostMapRepo()->detach($torrentId, $platform, $postId);

        // Sync detachment to counterparts.
        if ($this->getSettingValue('enable_sync', 'yes') === 'yes') {
            $this->getSyncService()->onTorrentDetached($platform, $postId, $torrentId);
        }

        wp_send_json_success(['detached' => true, 'torrent_id' => $torrentId]);
    }

    // -------------------------------------------------------------------------
    // AJAX: Hard delete torrent permanently
    // -------------------------------------------------------------------------

    public function ajaxHardDeleteTorrent(): void
    {
        $torrentId = absint($_POST['torrent_id'] ?? 0);
        if ($torrentId <= 0) {
            wp_send_json_error('Invalid torrent ID.', 400);
        }

        check_ajax_referer('tp_delete_torrent_' . $torrentId, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions.', 403);
        }

        $uploadDir = wp_upload_dir();
        $this->getTorrentService()->hardDelete($torrentId, $uploadDir['basedir']);

        wp_send_json_success(['torrent_id' => $torrentId, 'deleted' => 'permanent']);
    }

    // -------------------------------------------------------------------------
    // AJAX: Edit lock management
    // -------------------------------------------------------------------------

    public function ajaxAcquireLock(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');

        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $postId = absint($_POST['post_id'] ?? 0);

        if ($postId <= 0) {
            wp_send_json_error('Invalid parameters.', 400);
        }

        $lockInfo = $this->getEditLockService()->acquireLock($platform, $postId, get_current_user_id());

        if ($lockInfo !== null) {
            wp_send_json_error([
                'locked' => true,
                'user_name' => $lockInfo['user_name'],
                'message' => sprintf(
                    __('This post is currently being edited by %s. Please wait until they save or close.', 'torrent-scraper'),
                    $lockInfo['user_name'],
                ),
            ], 423);
        }

        wp_send_json_success(['locked' => false]);
    }

    public function ajaxReleaseLock(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');

        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $postId = absint($_POST['post_id'] ?? 0);

        $this->getEditLockService()->releaseLock($platform, $postId, get_current_user_id());

        wp_send_json_success(['released' => true]);
    }

    public function ajaxCheckLock(): void
    {
        check_ajax_referer('tp_reload_nonce', 'nonce');

        $platform = sanitize_text_field($_POST['platform'] ?? '');
        $postId = absint($_POST['post_id'] ?? 0);

        $lock = $this->getEditLockService()->checkLock($platform, $postId);

        if ($lock !== null && (int) $lock['user_id'] !== get_current_user_id()) {
            wp_send_json_success(['locked' => true, 'user_name' => $lock['user_name']]);
        }

        wp_send_json_success(['locked' => false]);
    }

    // -------------------------------------------------------------------------
    // Heartbeat API — edit lock renewal
    // -------------------------------------------------------------------------

    public function heartbeatLockRenewal(array $response, array $data): array
    {
        if (!empty($data['tp_lock_renew'])) {
            $lock = $data['tp_lock_renew'];
            $platform = sanitize_text_field($lock['platform'] ?? '');
            $postId = absint($lock['post_id'] ?? 0);

            if ($postId > 0) {
                $this->getEditLockService()->renewLock($platform, $postId, get_current_user_id());
                $response['tp_lock_renewed'] = true;
            }
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Sync hooks for WP posts
    // -------------------------------------------------------------------------

    /**
     * Called on save_post — sync edits to counterparts if post has torrents attached.
     */
    public function onWpPostSaved(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($postId) || $post->post_status === 'auto-draft') {
            return;
        }

        $sync = $this->getSyncService();
        if ($sync->isSyncing()) {
            return;
        }

        // Defer heavy sync operations to the shutdown hook to prevent editor timeouts and JSON errors
        add_action('shutdown', function () use ($sync, $postId, $post) {
            try {
                $sync->onPostEdited('wp_post', $postId, $post->post_title, $post->post_content);
            } catch (\Throwable $e) {
                // ignore
            }
        });
    }

    /**
     * Called on before_delete_post — sync deletion to counterparts.
     */
    public function onWpPostDeleted(int $postId): void
    {
        try {
            $this->getLogger()->warning("onWpPostDeleted hook called for post ID: " . $postId);
        } catch (\Throwable $e) {
            error_log("onWpPostDeleted logging failed: " . $e->getMessage());
        }

        $sync = $this->getSyncService();
        if ($sync->isSyncing()) {
            try {
                $this->getLogger()->warning("onWpPostDeleted: SyncService is already syncing, skipping.");
            } catch (\Throwable $e) {}
            return;
        }

        try {
            $sync->onPostDeleted('wp_post', $postId);
        } catch (\Throwable $e) {
            try {
                $this->getLogger()->error("onWpPostDeleted: exception thrown: " . $e->getMessage(), ['exception' => $e]);
            } catch (\Throwable $ex) {}
        }
    }

    /**
     * Hooked to transition_post_status. Detects when a post is trashed and triggers counterpart deletion.
     */
    public function onWpPostStatusTransition(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus === 'trash' && $oldStatus !== 'trash') {
            try {
                $this->getLogger()->warning("onWpPostStatusTransition: post ID {$post->ID} transitioned from {$oldStatus} to trash. Triggering counterpart deletion.");
            } catch (\Throwable $e) {}

            $this->onWpPostDeleted($post->ID);
        }
    }

    /**
     * Enqueue edit lock check scripts on WordPress post/page edit screen.
     */
    public function enqueuePostEditLockScript(string $hookSuffix): void
    {
        if ($hookSuffix !== 'post.php' && $hookSuffix !== 'post-new.php') {
            return;
        }

        $postId = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($postId <= 0) {
            return;
        }

        // Only load if sync is enabled
        if ($this->getSettingValue('enable_sync', 'yes') !== 'yes') {
            return;
        }

        // Check if this post is linked or has torrents
        $isLinked = false;
        try {
            $isLinked = !empty($this->getPostLinkRepo()->findAllLinked('wp_post', $postId)) ||
                !empty($this->getPostMapRepo()->findByPost('wp_post', $postId));
        } catch (\Throwable $e) {
            $isLinked = false;
        }

        if (!$isLinked) {
            return;
        }

        // Enqueue heartbeat and script
        wp_enqueue_script('heartbeat');

        add_action('admin_footer', function () use ($postId) {
            $lockNonce = wp_create_nonce('tp_reload_nonce');
            $ajaxUrl = admin_url('admin-ajax.php');
            ?>
            <script>
                (function ($) {
                    if (!$) return;

                    var postId = <?php echo $postId; ?>;
                    var platform = 'wp_post';
                    var lockNonce = <?php echo wp_json_encode($lockNonce); ?>;
                    var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;

                    // 1. Acquire Lock on page load
                    var fd = new FormData();
                    fd.append('action', 'tp_acquire_lock');
                    fd.append('nonce', lockNonce);
                    fd.append('platform', platform);
                    fd.append('post_id', postId);

                    fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data.success && data.data && data.data.locked) {
                                // Locked by someone else! Show warning banner
                                var alertHtml = '<div class="notice notice-warning is-dismissible" id="tp-lock-warning" style="border-left-color: #d63638; background:#fff; padding:12px; margin: 10px 0; border-radius:4px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">' +
                                    '<p style="margin:0; font-size:14px; font-weight:600; color:#c62828;">' +
                                    '<span class="dashicons dashicons-lock" style="vertical-align:middle; margin-right:6px;"></span>' +
                                    data.data.message +
                                    '</p></div>';

                                if ($('#wpbody-content').length) {
                                    $('#wpbody-content').prepend(alertHtml);
                                } else {
                                    $('.wrap').prepend(alertHtml);
                                }

                                // Disable publish buttons
                                setTimeout(function () {
                                    $('.editor-post-publish-panel__toggle, #publish').prop('disabled', true).addClass('disabled');
                                }, 1000);
                            }
                        });

                    // 2. Hook Heartbeat API to renew lock
                    $(document).on('heartbeat-send', function (e, data) {
                        data.tp_lock_renew = {
                            platform: platform,
                            post_id: postId
                        };
                    });

                    // 3. Release Lock on unload
                    window.addEventListener('beforeunload', function () {
                        var fdRelease = new FormData();
                        fdRelease.append('action', 'tp_release_lock');
                        fdRelease.append('nonce', lockNonce);
                        fdRelease.append('platform', platform);
                        fdRelease.append('post_id', postId);
                        navigator.sendBeacon(ajaxUrl, fdRelease);
                    });
                })(window.jQuery);
            </script>
            <?php
        });
    }

    /**
     * Append the torrent seeder/leecher stats badge next to the title on homepage/archive feeds.
     */
    public function appendTorrentBadgeToTitle(string $title, int $id = 0): string
    {
        if (is_admin() || is_feed()) {
            return $title;
        }

        $postId = $id > 0 ? $id : get_the_ID();
        if ($postId <= 0 || get_post_type($postId) !== 'post') {
            return $title;
        }

        // Exclude the main title of a singular post page (to avoid double displaying next to full card)
        if (is_singular() && $postId === get_queried_object_id()) {
            return $title;
        }

        $attachments = [];
        try {
            $attachments = $this->getPostMapRepo()->findByPost('wp_post', $postId);
        } catch (\Throwable $e) {
            $attachments = [];
        }
        
        if (empty($attachments)) {
            $legacyId = (int) get_post_meta($postId, 'tp_torrent_id', true);
            if ($legacyId > 0) {
                $attachments = [['torrent_id' => $legacyId]];
            }
        }

        if (empty($attachments)) {
            return $title;
        }

        $totalSeeders = 0;
        $totalLeechers = 0;
        $found = false;

        foreach ($attachments as $att) {
            try {
                $torrent = $this->getTorrentService()->getById((int) $att['torrent_id']);
                if ($torrent !== null) {
                    $totalSeeders += (int) ($torrent['seeders'] ?? 0);
                    $totalLeechers += (int) ($torrent['leechers'] ?? 0);
                    $found = true;
                }
            } catch (\Throwable $e) {
                // ignore database errors on frontend title rendering
            }
        }

        if (!$found) {
            return $title;
        }

        $badge = ' <span class="tp-compact-badge" style="'
            . 'font-size:0.75em; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:600; display:inline-flex; align-items:center; gap:4px; border:1px solid #bae6fd; margin-left:8px; vertical-align:middle;'
            . '">'
            . 'S: <span style="color:#15803d;">' . $totalSeeders . '</span> '
            . 'L: <span style="color:#b45309;">' . $totalLeechers . '</span>'
            . '</span>';

        return $title . $badge;
    }

    /**
     * wp_footer callback: Output inline JavaScript function for reload button
     * to guarantee it works even if external script files aren't enqueued.
     */
    public function renderInlineReloadScript(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <script>
        if (typeof window.tpReloadTorrent === 'undefined') {
            window.tpReloadTorrent = function(btn) {
                var torrentId = btn.getAttribute('data-torrent-id');
                var nonce = btn.getAttribute('data-nonce');
                var ajaxUrl = btn.getAttribute('data-ajax-url');
                if (!torrentId || !nonce || !ajaxUrl || btn.disabled) return;

                btn.disabled = true;
                var origContent = btn.innerHTML;
                btn.textContent = '⏳';

                var fd = new FormData();
                fd.append('action', 'tp_reload_torrent');
                fd.append('nonce', nonce);
                fd.append('torrent_id', torrentId);

                fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            var container = btn.closest('.tp-stats')
                                         || btn.closest('.tp-wpforo-compact')
                                         || btn.closest('.tp-card')
                                         || btn.parentElement;
                            if (container) {
                                var sEl = container.querySelector('.tp-badge-seeders');
                                var lEl = container.querySelector('.tp-badge-leechers');
                                var cEl = container.querySelector('.tp-badge-completed');
                                if (sEl) sEl.textContent = sEl.textContent.replace(/[—\d,]+/g, data.data.seeders.toLocaleString());
                                if (lEl) lEl.textContent = lEl.textContent.replace(/[—\d,]+/g, data.data.leechers.toLocaleString());
                                if (cEl) cEl.textContent = cEl.textContent.replace(/[—\d,]+/g, data.data.completed.toLocaleString());
                            }
                            btn.innerHTML = '✅' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Reload</span>' : '');
                        } else {
                            btn.innerHTML = '❌' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Error</span>' : '');
                        }
                        setTimeout(function() {
                            btn.innerHTML = origContent;
                            btn.disabled = false;
                        }, 2000);
                    })
                    .catch(function() {
                        btn.innerHTML = '❌' + (origContent.indexOf('Reload') !== -1 ? ' <span style="font-size:0.82em;">Error</span>' : '');
                        setTimeout(function() {
                            btn.innerHTML = origContent;
                            btn.disabled = false;
                        }, 2000);
                    });
            };
        }
        </script>
        <?php
    }
}
