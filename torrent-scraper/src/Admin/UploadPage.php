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
 * File: UploadPage.php
 * Component: WordPress Admin UI
 * Description: Renders and handles submission for the admin torrent and magnet uploading forms with proper authorization checks.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Admin;

use TorrentScraper\Core\Exception\FileValidationException;
use TorrentScraper\Core\Logger\Contracts\LoggerInterface;
use TorrentScraper\Core\Upload\FileStorage;
use TorrentScraper\Core\Upload\FileValidator;
use TorrentScraper\Core\Upload\TorrentUploadHandler;
use TorrentScraper\Core\Service\TorrentService;

/**
 * WordPress admin upload page — handles .torrent file and magnet link submissions.
 *
 * Security checklist (every request):
 *   ✅ current_user_can('upload_files')
 *   ✅ check_admin_referer('tp_upload_nonce')
 *   ✅ wp_check_filetype() as extra WP-layer validation
 *   ✅ FileValidator (magic bytes, size, extension)
 *   ✅ sanitize_text_field() / esc_url_raw() on all input
 */
final class UploadPage
{
    public function __construct(
        private readonly TorrentService  $torrentService,
        private readonly LoggerInterface $logger,
        private readonly string          $storageDir,
        private readonly int             $maxUploadSizeKb = 512,
        private readonly string          $capability = 'upload_files',
        private readonly bool            $enableTorrentUpload = true,
        private readonly bool            $enableMagnetUpload = true,
        private readonly ?\TorrentScraper\Core\Service\TrackerService $trackerService = null,
    ) {}

    /**
     * Register the submenu page under Torrent Scraper.
     */
    public function registerMenu(): void
    {
        add_submenu_page(
            parent_slug: 'tp_admin',
            page_title:  __('Upload Torrent', 'torrent-scraper'),
            menu_title:  __('Upload', 'torrent-scraper'),
            capability:  $this->capability,
            menu_slug:   'tp_upload',
            callback:    [$this, 'render'],
            position:    2, // After dashboard
        );
    }

    /**
     * Handle the upload form submission programmatically.
     *
     * @return array{0: string, 1: string}  [successMessage, errorMessage]
     */
    public function handleSubmission(): array
    {
        if (isset($_POST['tp_upload_submit'])) {
            return $this->processSubmission();
        }
        return ['', ''];
    }

    /**
     * Render the upload page (form + result messages).
     */
    public function render(): void
    {
        if (!current_user_can($this->capability)) {
            wp_die(esc_html__('You do not have permission to upload files.', 'torrent-scraper'));
        }

        $message = '';
        $error   = '';

        // Process form submission.
        if (isset($_POST['tp_upload_submit'])) {
            [$message, $error] = $this->processSubmission();
        }

        $this->renderForm($message, $error);
    }

    // -------------------------------------------------------------------------
    // Form processing
    // -------------------------------------------------------------------------

    /**
     * Process the upload form submission.
     *
     * @return array{0: string, 1: string}  [successMessage, errorMessage]
     */
    private function processSubmission(): array
    {
        // Capability check.
        if (!current_user_can($this->capability)) {
            return ['', __('You do not have permission to upload files.', 'torrent-scraper')];
        }

        // Nonce check.
        if (!check_admin_referer('tp_upload_nonce', 'tp_upload_nonce_field')) {
            return ['', __('Security check failed. Please try again.', 'torrent-scraper')];
        }

        $uploadType = sanitize_text_field($_POST['upload_type'] ?? 'file');

        if ($uploadType === 'magnet') {
            if (!$this->enableMagnetUpload) {
                return ['', __('Magnet uploads are disabled.', 'torrent-scraper')];
            }
            return $this->processMagnet();
        }

        if (!$this->enableTorrentUpload) {
            return ['', __('Torrent file uploads are disabled.', 'torrent-scraper')];
        }
        return $this->processFileUpload();
    }

    /**
     * Process a .torrent file upload.
     *
     * @return array{0: string, 1: string}
     */
    private function processFileUpload(): array
    {
        // Check that a file was uploaded.
        if (!isset($_FILES['torrent_file']) || $_FILES['torrent_file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['torrent_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            return ['', $this->uploadErrorMessage((int) $errorCode)];
        }

        $tempPath     = $_FILES['torrent_file']['tmp_name'];
        $originalName = sanitize_file_name($_FILES['torrent_file']['name']);

        // WordPress-level filetype check.
        $wpCheck = wp_check_filetype($originalName, ['torrent' => 'application/x-bittorrent']);
        if (empty($wpCheck['ext'])) {
            return ['', __('WordPress rejected this file type. Only .torrent files are accepted.', 'torrent-scraper')];
        }

        // Verify the temp file is an actual upload (prevents local file inclusion).
        if (!is_uploaded_file($tempPath)) {
            return ['', __('Invalid upload detected.', 'torrent-scraper')];
        }

        // Category ID (optional).
        $categoryId     = isset($_POST['category_id']) ? absint($_POST['category_id']) : null;
        $linkedPostId   = isset($_POST['linked_post_id']) ? absint($_POST['linked_post_id']) : 0;
        $linkedPostType = sanitize_text_field($_POST['linked_post_type'] ?? 'wp_post');

        try {
            $handler = $this->createUploadHandler();

            $torrentId = $handler->handleUpload($tempPath, $originalName, [
                'platform'         => 'wordpress',
                'platform_user_id' => get_current_user_id(),
                'category_id'      => $categoryId,
                'platform_post_id' => $linkedPostId > 0 ? $linkedPostId : null,
                'status'           => current_user_can('manage_options') ? 'active' : 'pending',
            ]);

            // Link to post/topic if specified.
            if ($torrentId > 0 && $linkedPostId > 0) {
                if ($linkedPostType === 'wpforo_topic') {
                    update_option('tp_wpforo_topic_' . $linkedPostId, $torrentId, false);
                } else {
                    update_post_meta($linkedPostId, 'tp_torrent_id', $torrentId);
                }
            }

            if ($torrentId > 0 && $this->trackerService !== null) {
                $torrent = $this->torrentService->getById($torrentId);
                if ($torrent !== null) {
                    try {
                        $this->trackerService->scrapeOne($torrentId, $torrent['info_hash'], true);
                    } catch (\Throwable $scr) {
                        $this->logger->warning(
                            "Immediate scrape failed: {$scr->getMessage()}",
                            ['event_type' => 'tracker.scrape_error', 'torrent_id' => $torrentId],
                        );
                    }
                }
            }

            return [
                sprintf(
                    /* translators: %d: torrent ID */
                    __('Torrent uploaded successfully! ID: %d', 'torrent-scraper'),
                    $torrentId,
                ),
                '',
            ];
        } catch (FileValidationException $e) {
            return ['', $e->getMessage()];
        } catch (\RuntimeException $e) {
            // Duplicate info_hash.
            return ['', $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error(
                "Upload failed: {$e->getMessage()}",
                ['event_type' => 'upload.error'],
            );
            return ['', __('An unexpected error occurred. Check the system log.', 'torrent-scraper')];
        }
    }

    /**
     * Process a magnet link submission.
     *
     * @return array{0: string, 1: string}
     */
    private function processMagnet(): array
    {
        $magnetUri      = isset($_POST['magnet_uri']) ? esc_url_raw(trim($_POST['magnet_uri'])) : '';
        $linkedPostId   = isset($_POST['linked_post_id']) ? absint($_POST['linked_post_id']) : 0;
        $linkedPostType = sanitize_text_field($_POST['linked_post_type'] ?? 'wp_post');

        if ($magnetUri === '' || !str_starts_with($magnetUri, 'magnet:?')) {
            return ['', __('Please enter a valid magnet link starting with magnet:?', 'torrent-scraper')];
        }

        try {
            $handler   = $this->createUploadHandler();
            $torrentId = $handler->handleMagnet($magnetUri, [
                'platform'         => 'wordpress',
                'platform_user_id' => get_current_user_id(),
                'platform_post_id' => $linkedPostId > 0 ? $linkedPostId : null,
                'status'           => current_user_can('manage_options') ? 'active' : 'pending',
            ]);

            // Link to post/topic if specified.
            if ($torrentId > 0 && $linkedPostId > 0) {
                if ($linkedPostType === 'wpforo_topic') {
                    update_option('tp_wpforo_topic_' . $linkedPostId, $torrentId, false);
                } else {
                    update_post_meta($linkedPostId, 'tp_torrent_id', $torrentId);
                }
            }

            if ($torrentId > 0 && $this->trackerService !== null) {
                $torrent = $this->torrentService->getById($torrentId);
                if ($torrent !== null) {
                    try {
                        $this->trackerService->scrapeOne($torrentId, $torrent['info_hash'], true);
                    } catch (\Throwable $scr) {
                        $this->logger->warning(
                            "Immediate scrape failed: {$scr->getMessage()}",
                            ['event_type' => 'tracker.scrape_error', 'torrent_id' => $torrentId],
                        );
                    }
                }
            }

            return [
                sprintf(
                    /* translators: %d: torrent ID */
                    __('Magnet link added successfully! ID: %d', 'torrent-scraper'),
                    $torrentId,
                ),
                '',
            ];
        } catch (\RuntimeException $e) {
            return ['', $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error(
                "Magnet submission failed: {$e->getMessage()}",
                ['event_type' => 'upload.magnet_error'],
            );
            return ['', __('Failed to process magnet link. Check the system log.', 'torrent-scraper')];
        }
    }

    // -------------------------------------------------------------------------
    // Form rendering
    // -------------------------------------------------------------------------

    public function renderForm(string $message, string $error, bool $embedded = false): void
    {
        $hasUploadOptions = $this->enableTorrentUpload || $this->enableMagnetUpload;
        $defaultUploadType = $this->enableTorrentUpload ? 'file' : 'magnet';
        if (!$embedded) : ?>
        <div class="wrap tp-wrap">
            <h1><?php echo esc_html__('Upload Torrent', 'torrent-scraper'); ?></h1>
        <?php endif; ?>

            <?php if ($message !== '') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($error !== '') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!$hasUploadOptions) : ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('Torrent and magnet uploads are currently disabled by the administrator.', 'torrent-scraper'); ?></p>
                </div>
            <?php else : ?>
                <div class="tp-card" style="max-width:600px;">
                    <form method="post" enctype="multipart/form-data" action="">
                        <?php wp_nonce_field('tp_upload_nonce', 'tp_upload_nonce_field'); ?>

                        <table class="form-table">
                            <?php if ($this->enableTorrentUpload && $this->enableMagnetUpload) : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html__('Upload Type', 'torrent-scraper'); ?></th>
                                    <td>
                                        <label style="margin-right:1rem;">
                                            <input type="radio" name="upload_type" value="file" checked />
                                            <?php echo esc_html__('.torrent File', 'torrent-scraper'); ?>
                                        </label>
                                        <label>
                                            <input type="radio" name="upload_type" value="magnet" />
                                            <?php echo esc_html__('Magnet Link', 'torrent-scraper'); ?>
                                        </label>
                                    </td>
                                </tr>
                            <?php else : ?>
                                <input type="hidden" name="upload_type" value="<?php echo esc_attr($defaultUploadType); ?>" />
                            <?php endif; ?>

                            <?php if ($this->enableTorrentUpload) : ?>
                                <tr class="tp-upload-file-row" <?php if ($defaultUploadType !== 'file') echo 'style="display:none;"'; ?>>
                                    <th scope="row">
                                        <label for="torrent_file"><?php echo esc_html__('Torrent File', 'torrent-scraper'); ?></label>
                                    </th>
                                    <td>
                                        <input type="file" name="torrent_file" id="torrent_file" accept=".torrent" />
                                        <p class="description">
                                            <?php
                                            echo esc_html(sprintf(
                                                /* translators: %d: max file size in KB */
                                                __('Maximum file size: %d KB. Only .torrent files accepted.', 'torrent-scraper'),
                                                $this->maxUploadSizeKb,
                                            ));
                                            ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($this->enableMagnetUpload) : ?>
                                <tr class="tp-upload-magnet-row" <?php if ($defaultUploadType !== 'magnet') echo 'style="display:none;"'; ?>>
                                    <th scope="row">
                                        <label for="magnet_uri"><?php echo esc_html__('Magnet Link', 'torrent-scraper'); ?></label>
                                    </th>
                                    <td>
                                        <input type="url" name="magnet_uri" id="magnet_uri"
                                               class="large-text" placeholder="magnet:?xt=urn:btih:..." />
                                        <p class="description">
                                            <?php echo esc_html__('Paste a magnet link. Must start with magnet:?', 'torrent-scraper'); ?>
                                        </p>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <tr>
                                <th scope="row">
                                    <label for="category_id"><?php echo esc_html__('Category', 'torrent-scraper'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="category_id" id="category_id" min="0" value=""
                                           class="small-text" placeholder="<?php echo esc_attr__('Optional', 'torrent-scraper'); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="linked_post_id"><?php echo esc_html__('Link to Post/Topic ID', 'torrent-scraper'); ?></label>
                                </th>
                                <td>
                                    <input type="number" name="linked_post_id" id="linked_post_id" min="0" value=""
                                           class="small-text" placeholder="<?php echo esc_attr__('Optional', 'torrent-scraper'); ?>" />
                                    <p class="description"><?php echo esc_html__('Optional: Link this torrent to a WP/bbPress post ID or wpForo topic ID.', 'torrent-scraper'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label><?php echo esc_html__('Post Type', 'torrent-scraper'); ?></label>
                                </th>
                                <td>
                                    <label style="margin-right:1rem;">
                                        <input type="radio" name="linked_post_type" value="wp_post" checked />
                                        <?php echo esc_html__('WordPress / bbPress Post', 'torrent-scraper'); ?>
                                    </label>
                                    <label>
                                        <input type="radio" name="linked_post_type" value="wpforo_topic" />
                                        <?php echo esc_html__('wpForo Topic', 'torrent-scraper'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Upload', 'torrent-scraper'), 'primary', 'tp_upload_submit'); ?>
                    </form>
                </div>

                <?php if ($this->enableTorrentUpload && $this->enableMagnetUpload) : ?>
                    <script>
                    (function() {
                        var radios = document.querySelectorAll('input[name="upload_type"]');
                        var fileRow = document.querySelector('.tp-upload-file-row');
                        var magnetRow = document.querySelector('.tp-upload-magnet-row');

                        radios.forEach(function(radio) {
                            radio.addEventListener('change', function() {
                                if (this.value === 'magnet') {
                                    fileRow.style.display = 'none';
                                    magnetRow.style.display = '';
                                } else {
                                    fileRow.style.display = '';
                                    magnetRow.style.display = 'none';
                                }
                            });
                        });
                    })();
                    </script>
                <?php endif; ?>
            <?php endif; ?>
        <?php if (!$embedded) : ?>
        </div>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUploadHandler(): TorrentUploadHandler
    {
        $validator = new FileValidator(maxSizeBytes: $this->maxUploadSizeKb * 1024);
        $storage   = new FileStorage($this->storageDir);

        return new TorrentUploadHandler(
            validator:      $validator,
            storage:        $storage,
            torrentService: $this->torrentService,
            logger:         $this->logger,
        );
    }

    /**
     * Map PHP upload error codes to human-readable messages.
     */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE   => __('File exceeds the server upload_max_filesize limit.', 'torrent-scraper'),
            UPLOAD_ERR_FORM_SIZE  => __('File exceeds the form MAX_FILE_SIZE limit.', 'torrent-scraper'),
            UPLOAD_ERR_PARTIAL    => __('File was only partially uploaded.', 'torrent-scraper'),
            UPLOAD_ERR_NO_FILE    => __('No file was selected for upload.', 'torrent-scraper'),
            UPLOAD_ERR_NO_TMP_DIR => __('Server is missing a temporary folder.', 'torrent-scraper'),
            UPLOAD_ERR_CANT_WRITE => __('Server failed to write the file to disk.', 'torrent-scraper'),
            UPLOAD_ERR_EXTENSION  => __('A PHP extension blocked the upload.', 'torrent-scraper'),
            default               => __('Unknown upload error.', 'torrent-scraper'),
        };
    }
}
