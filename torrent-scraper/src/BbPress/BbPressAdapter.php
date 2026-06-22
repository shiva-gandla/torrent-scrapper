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
 * File: BbPressAdapter.php
 * Component: bbPress Forum Adapter
 * Description: Extends bbPress topics and replies to embed torrent downloads and live tracking metrics within forum layouts.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\BbPress;

use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Security\InputSanitizer;
use TorrentScraper\WordPress\Sync\TorrentPostMapRepository;
use TorrentScraper\WordPress\Sync\SyncService;
use TorrentScraper\WordPress\Sync\EditLockService;

/**
 * bbPress integration — attaches torrent metadata to forum topics.
 *
 * Spec:
 *   - Creates a "Torrent Topic" concept via topic meta (not a new topic type).
 *   - Hooks into bbp_new_topic / bbp_edit_topic to attach torrent data.
 *   - Stores torrent reference in topic meta: bbp_topic_meta('tp_torrent_id').
 *   - Search integration: hooks into bbPress search to filter by torrent properties.
 *   - No new database tables — uses the core tp_* tables.
 */
final class BbPressAdapter
{
    public function __construct(
        private readonly TorrentRepository $torrentRepo,
        private readonly TorrentPostMapRepository $postMapRepo,
        private readonly SyncService $syncService,
        private readonly EditLockService $editLockService,
    ) {
    }

    /**
     * Register all bbPress hooks.
     * Should only be called if bbPress is active.
     */
    public function register(): void
    {
        if (!function_exists('bbpress')) {
            return; // bbPress not loaded.
        }

        // Topic creation/edit — attach torrent metadata.
        add_action('bbp_new_topic_post_extras', [$this, 'onNewTopic'], 10, 1);
        add_action('bbp_edit_topic_post_extras', [$this, 'onEditTopic'], 10, 1);
        add_action('before_delete_post', [$this, 'onDeleteTopic'], 10, 1);

        // Render torrent info inside topic content.
        add_filter('bbp_get_topic_content', [$this, 'appendTorrentInfoToTopic'], 20, 2);
        add_filter('bbp_get_reply_content', [$this, 'appendTorrentInfoToReply'], 20, 2);

        // Add torrent ID field to the topic form.
        add_action('bbp_theme_before_topic_form_content', [$this, 'renderTopicFormField']);

        // Search integration.
        add_filter('bbp_after_has_search_results_parse_args', [$this, 'enhanceSearch']);

        // Check locks on bbPress frontend topic edit form
        add_action('wp_footer', [$this, 'renderBbPressEditLockScript'], 23);
    }

    // ─── Topic creation / edit ───────────────────────────────────────

    /**
     * Save torrent association on new topic creation.
     */
    public function onNewTopic(int $topicId = 0): void
    {
        if ($topicId <= 0) {
            $topicId = (int) bbp_get_topic_id();
        }
        if ($topicId <= 0) {
            return;
        }

        $this->saveTorrentMeta($topicId);

        // Trigger sync if torrent attached.
        if (!$this->syncService->isSyncing()) {
            $attachments = $this->postMapRepo->findByPost('bbpress_topic', $topicId);
            if (!empty($attachments)) {
                $torrentId = (int) $attachments[0]['torrent_id'];
                $config = [
                    'wp_category_id' => absint($_POST['tp_sync_wp_category'] ?? 0),
                    'wpforo_forum_id' => absint($_POST['tp_sync_wpforo_forum'] ?? 0),
                ];
                $this->syncService->onTorrentAttached('bbpress_topic', $topicId, $torrentId, get_current_user_id(), $config);
            }
        }
    }

    /**
     * Update torrent association on topic edit.
     */
    public function onEditTopic(int $topicId = 0): void
    {
        if ($topicId <= 0) {
            $topicId = (int) bbp_get_topic_id();
        }
        if ($topicId <= 0) {
            return;
        }

        $this->saveTorrentMeta($topicId);

        // Trigger sync for edits.
        if (!$this->syncService->isSyncing()) {
            $post = get_post($topicId);
            if ($post) {
                $this->syncService->onPostEdited('bbpress_topic', $topicId, $post->post_title, $post->post_content);
            }
        }
    }

    /**
     * Handle topic deletion — sync to counterparts.
     */
    public function onDeleteTopic(int $postId = 0): void
    {
        if ($postId <= 0) {
            return;
        }

        $post = get_post($postId);
        if (!$post || $post->post_type !== 'topic') {
            return;
        }

        if (!$this->syncService->isSyncing()) {
            $this->syncService->onPostDeleted('bbpress_topic', $postId);
        }
    }

    /**
     * Save the tp_torrent_id meta value from the form submission.
     */
    private function saveTorrentMeta(int $topicId): void
    {
        // Nonce check.
        if (!isset($_POST['tp_bbpress_nonce']) && !isset($_POST['tp_upload_nonce'])) {
            return;
        }

        if (isset($_POST['tp_bbpress_nonce']) && !wp_verify_nonce($_POST['tp_bbpress_nonce'], 'tp_bbpress_attach')) {
            return;
        }

        if (isset($_POST['tp_upload_nonce']) && !wp_verify_nonce($_POST['tp_upload_nonce'], 'tp_upload_action')) {
            return;
        }

        if (!isset($_POST['tp_torrent_id'])) {
            return;
        }

        $torrentId = absint($_POST['tp_torrent_id']);

        if ($torrentId <= 0) {
            return;
        }

        // Verify the torrent exists.
        $torrent = $this->torrentRepo->findById($torrentId);
        if ($torrent === null) {
            return;
        }

        // Store in the new multi-torrent post map table.
        $this->postMapRepo->attach($torrentId, 'bbpress_topic', $topicId, get_current_user_id());

        // Legacy: also store in wp_postmeta for backward compat.
        update_post_meta($topicId, 'tp_torrent_id', $torrentId);
    }

    // ─── Topic form field ────────────────────────────────────────────

    /**
     * Render the "Attach Torrent" field in the bbPress topic form.
     */
    public function renderTopicFormField(): void
    {
        $topicId = bbp_get_topic_id();
        $torrentId = $topicId ? (int) get_post_meta($topicId, 'tp_torrent_id', true) : 0;

        wp_nonce_field('tp_upload_action', 'tp_upload_nonce');
        wp_nonce_field('tp_bbpress_attach', 'tp_bbpress_nonce');

        $attachments = [];
        if ($topicId) {
            try {
                $attachments = $this->postMapRepo->findByPost('bbpress_topic', $topicId);
            } catch (\Throwable $e) {
                $attachments = [];
            }
        }

        // Fallback to legacy
        if (empty($attachments) && $topicId > 0) {
            $legacyId = (int) get_post_meta($topicId, 'tp_torrent_id', true);
            if ($legacyId > 0) {
                try {
                    $torrent = $this->torrentRepo->findById($legacyId);
                    if ($torrent !== null) {
                        $attachments = [
                            [
                                'torrent_id' => $legacyId,
                                'name' => $torrent['name'],
                                'total_size' => $torrent['total_size'],
                                'status' => $torrent['status'],
                            ]
                        ];
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        $ajaxUrl = admin_url('admin-ajax.php');
        $linkNonce = wp_create_nonce('tp_reload_nonce');
        ?>
        <div class="tp-forum-upload-section"
            style="border: 1px solid #ccc; padding: 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; background: #fff;">
            <label style="font-weight: 600; font-size: 1.1em; display: block; margin-bottom: 0.75rem;">
                <?php echo esc_html__('Torrent Scraper Integration', 'torrent-scraper'); ?>
            </label>

            <?php if (!empty($attachments)): ?>
                <div class="tp-bbpress-attachments-list" style="margin-bottom:1rem;">
                    <?php foreach ($attachments as $att):
                        $attId = (int) $att['torrent_id'];
                        $sizeStr = $this->formatBytes((int) $att['total_size']);
                        ?>
                        <div class="tp-meta-attachment-item" data-torrent-id="<?php echo $attId; ?>"
                            style="background:#f9f9f9; border:1px solid #ddd; border-radius:6px; padding:0.5rem 0.75rem; margin-bottom:0.4rem; display:flex; align-items:center; gap:0.5rem; justify-content:space-between; flex-wrap:wrap;">
                            <div style="flex:1; min-width:0; font-size:0.9em;">
                                <strong
                                    style="display:block; color:#1a1a2e; text-overflow:ellipsis; overflow:hidden; white-space:nowrap;"><?php echo esc_html($att['name']); ?></strong>
                                <span style="font-size:0.85em; color:#666;">
                                    ID: <?php echo $attId; ?> · <?php echo esc_html($sizeStr); ?>
                                </span>
                            </div>
                            <?php if ($topicId > 0): ?>
                                <div style="display:flex; gap: 4px;">
                                    <button type="button" class="button tp-bbpress-detach-btn" data-torrent-id="<?php echo $attId; ?>"
                                        style="padding:2px 8px; background:#e65100; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:0.85em; white-space:nowrap;">
                                        <?php echo esc_html__('Remove', 'torrent-scraper'); ?>
                                    </button>
                                    <button type="button" class="button tp-bbpress-delete-btn" data-torrent-id="<?php echo $attId; ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('tp_delete_torrent_' . $attId)); ?>"
                                        style="padding:2px 8px; background:#c62828; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:0.85em; white-space:nowrap;">
                                        <?php echo esc_html__('Delete', 'torrent-scraper'); ?>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="tp-forum-input-group" style="margin-bottom: 1rem;">
                <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 0.25rem;" for="tp_torrent_id">
                    <?php echo esc_html__('Attach Existing Torrent ID', 'torrent-scraper'); ?>
                </label>
                <input type="number" name="tp_torrent_id" id="tp_torrent_id" value="" min="0" class="small-text"
                    style="max-width: 200px;"
                    placeholder="<?php echo esc_attr__('e.g. 123 (Optional)', 'torrent-scraper'); ?>" />
            </div>

            <div class="tp-forum-input-divider"
                style="margin: 1.5rem 0; border-bottom: 1px dashed #ccc; text-align: center; height: 10px; overflow: visible;">
                <span
                    style="background: #fff; padding: 0 10px; font-size: 0.85em; color: #777; font-style: italic; position: relative; top: -2px;">
                    <?php echo esc_html__('OR Upload/Submit New', 'torrent-scraper'); ?>
                </span>
            </div>

            <div class="tp-forum-input-group" style="margin-bottom: 1rem;">
                <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 0.25rem;"
                    for="tp_torrent_file">
                    <?php echo esc_html__('Upload .torrent File', 'torrent-scraper'); ?>
                </label>
                <input type="file" name="tp_torrent_file" id="tp_torrent_file" accept=".torrent" />
                <p class="description" style="font-size: 0.8em; margin-top: 0.25rem; color: #777; margin-bottom: 0;">
                    <?php echo esc_html__('Select a physical .torrent file to upload.', 'torrent-scraper'); ?>
                </p>
            </div>

            <div class="tp-forum-input-group" style="margin-bottom: 0;">
                <label style="font-weight: 500; font-size: 0.9em; display: block; margin-bottom: 0.25rem;" for="tp_magnet_uri">
                    <?php echo esc_html__('Paste Magnet Link', 'torrent-scraper'); ?>
                </label>
                <input type="url" name="tp_magnet_uri" id="tp_magnet_uri" style="width: 100%;"
                    placeholder="magnet:?xt=urn:btih:..." />
                <p class="description" style="font-size: 0.8em; margin-top: 0.25rem; color: #777; margin-bottom: 0;">
                    <?php echo esc_html__('Paste a magnet link starting with magnet:?', 'torrent-scraper'); ?>
                </p>
            </div>

            <script>
                (function () {
                    var fileInput = document.getElementById("tp_torrent_file");
                    if (fileInput) {
                        var form = fileInput.closest("form");
                        if (form) {
                            form.setAttribute("enctype", "multipart/form-data");
                        }
                    }

                    <?php if ($topicId > 0): ?>
                        var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;
                        var linkNonce = <?php echo wp_json_encode($linkNonce); ?>;
                        var topicId = <?php echo wp_json_encode($topicId); ?>;

                        // ── Detach attachment ─────────────────────────────────────────────
                        document.addEventListener('click', function (e) {
                            var btn = e.target.closest('.tp-bbpress-detach-btn');
                            if (!btn) return;

                            var torrentId = btn.dataset.torrentId;
                            if (!confirm('Remove this torrent from the topic?')) return;

                            btn.disabled = true;
                            btn.textContent = '⏳';

                            var fd = new FormData();
                            fd.append('action', 'tp_detach_torrent');
                            fd.append('nonce', linkNonce);
                            fd.append('torrent_id', torrentId);
                            fd.append('platform', 'bbpress_topic');
                            fd.append('post_id', topicId);

                            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    if (d.success) {
                                        window.location.reload();
                                    } else {
                                        alert('Error detaching torrent.');
                                        btn.disabled = false;
                                        btn.textContent = 'Remove';
                                    }
                                })
                                .catch(function () {
                                    btn.disabled = false;
                                    btn.textContent = 'Remove';
                                });
                        });

                        // ── Delete permanently ─────────────────────────────────────────────
                        document.addEventListener('click', function (e) {
                            var btn = e.target.closest('.tp-bbpress-delete-btn');
                            if (!btn) return;

                            var torrentId = btn.dataset.torrentId;
                            var rowNonce = btn.dataset.nonce;
                            if (!confirm('PERMANENTLY DELETE this torrent?\n\nThis will remove it from all topics and the server.')) return;

                            btn.disabled = true;
                            btn.textContent = '⏳';

                            var fd = new FormData();
                            fd.append('action', 'tp_hard_delete_torrent');
                            fd.append('nonce', rowNonce);
                            fd.append('torrent_id', torrentId);

                            fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    if (d.success) {
                                        window.location.reload();
                                    } else {
                                        alert('Error deleting torrent.');
                                        btn.disabled = false;
                                        btn.textContent = 'Delete';
                                    }
                                })
                                .catch(function () {
                                    btn.disabled = false;
                                    btn.textContent = 'Delete';
                                });
                        });
                    <?php endif; ?>
                })();
            </script>
        </div>
        <?php
    }

    // ─── Content rendering ───────────────────────────────────────────

    /**
     * Append torrent info card after the topic content.
     */
    public function appendTorrentInfoToTopic(string $content, int $topicId = 0): string
    {
        if ($topicId <= 0) {
            $topicId = (int) bbp_get_topic_id();
        }
        if ($topicId <= 0) {
            return $content;
        }

        // Use new multi-torrent post map.
        $attachments = [];
        try {
            $attachments = $this->postMapRepo->findByPost('bbpress_topic', $topicId);
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // Fallback: check legacy wp_postmeta.
        if (empty($attachments)) {
            $torrentId = (int) get_post_meta($topicId, 'tp_torrent_id', true);
            if ($torrentId <= 0 && isset($_POST['tp_torrent_id'])) {
                $torrentId = absint($_POST['tp_torrent_id']);
            }
            if ($torrentId <= 0) {
                return $content;
            }
            try {
                $torrent = $this->torrentRepo->findById($torrentId);
                if ($torrent === null) {
                    return $content;
                }
                return $content . $this->buildTorrentCard($torrent);
            } catch (\Throwable $e) {
                return $content;
            }
        }

        foreach ($attachments as $att) {
            try {
                $torrent = $this->torrentRepo->findById((int) $att['torrent_id']);
                if ($torrent !== null) {
                    $content .= $this->buildTorrentCard($torrent);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $content;
    }

    /**
     * Check replies for torrent references too (in case a reply links a torrent).
     */
    public function appendTorrentInfoToReply(string $content, int $replyId = 0): string
    {
        if ($replyId <= 0) {
            $replyId = (int) bbp_get_reply_id();
        }
        if ($replyId <= 0) {
            return $content;
        }

        // Use new multi-torrent post map.
        $attachments = [];
        try {
            $attachments = $this->postMapRepo->findByPost('bbpress_topic', $replyId);
        } catch (\Throwable $e) {
            $attachments = [];
        }

        // Fallback: check legacy wp_postmeta.
        if (empty($attachments)) {
            $torrentId = (int) get_post_meta($replyId, 'tp_torrent_id', true);
            if ($torrentId <= 0 && isset($_POST['tp_torrent_id'])) {
                $torrentId = absint($_POST['tp_torrent_id']);
            }
            if ($torrentId <= 0) {
                return $content;
            }
            try {
                $torrent = $this->torrentRepo->findById($torrentId);
                if ($torrent === null) {
                    return $content;
                }
                return $content . $this->buildTorrentCard($torrent);
            } catch (\Throwable $e) {
                return $content;
            }
        }

        foreach ($attachments as $att) {
            try {
                $torrent = $this->torrentRepo->findById((int) $att['torrent_id']);
                if ($torrent !== null) {
                    $content .= $this->buildTorrentCard($torrent);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $content;
    }

    // ─── Search ──────────────────────────────────────────────────────

    /**
     * Enhance bbPress search to include torrent name in search results.
     *
     * @param  array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function enhanceSearch(array $args = []): array
    {
        // If there's a search term, we could add a meta_query for tp_torrent_id.
        // For now, bbPress full-text search covers topic content which includes
        // the appended torrent card. More advanced filtering can be added later.
        return $args;
    }

    // ─── Rendering helpers ───────────────────────────────────────────

    /**
     * Build a compact torrent info card for display inside a bbPress topic.
     *
     * @param  array<string, mixed> $torrent
     */
    private function buildTorrentCard(array $torrent): string
    {
        $html = '<div class="tp-wrap tp-card tp-bbpress-card" style="margin-top:1rem;">';
        $html .= '<strong>' . esc_html($torrent['name']) . '</strong>';
        $html .= '<div class="tp-card-meta">';
        $html .= esc_html($this->formatBytes((int) $torrent['total_size']));
        $html .= ' · ';
        $html .= esc_html(sprintf(
            _n('%d file', '%d files', (int) $torrent['file_count'], 'torrent-scraper'),
            (int) $torrent['file_count'],
        ));
        $html .= '</div>';

        // Stats badges.
        $html .= '<div class="tp-stats">';
        $html .= '<span class="tp-badge tp-badge-seeders">↑ ' . esc_html(number_format_i18n((int) $torrent['seeders'])) . '</span>';
        $html .= '<span class="tp-badge tp-badge-leechers">↓ ' . esc_html(number_format_i18n((int) $torrent['leechers'])) . '</span>';
        $html .= '</div>';

        if (!empty($torrent['magnet_link'])) {
            $html .= sprintf(
                '<a href="%s" class="tp-magnet-btn" style="margin-top:0.5rem;">🧲 %s</a>',
                esc_url($torrent['magnet_link']),
                esc_html__('Magnet Link', 'torrent-scraper'),
            );
        }

        // Admin-only AJAX reload button.
        if (current_user_can('manage_options')) {
            $html .= sprintf(
                '<button type="button" class="tp-badge tp-badge-reload tp-ajax-reload-frontend"'
                . ' data-torrent-id="%s"'
                . ' data-nonce="%s"'
                . ' data-ajax-url="%s"'
                . ' onclick="window.tpReloadTorrent && window.tpReloadTorrent(this)"'
                . ' title="%s"'
                . ' style="border:none; background:none; cursor:pointer; font-size:inherit; display:inline-block; margin-top:0.5rem;">🔄 %s</button>',
                esc_attr((string) (int) $torrent['id']),
                esc_attr(wp_create_nonce('tp_reload_nonce')),
                esc_attr(admin_url('admin-ajax.php')),
                esc_attr__('Reload stats from trackers', 'torrent-scraper'),
                esc_html__('Reload', 'torrent-scraper'),
            );
        }

        $html .= '</div>';

        return $html;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    /**
     * Check edit locks when editing a topic in bbPress frontend.
     */
    public function renderBbPressEditLockScript(): void
    {
        if (!function_exists('bbp_is_topic_edit')) {
            return;
        }

        if (!bbp_is_topic_edit()) {
            return;
        }

        $topicId = bbp_get_topic_id();
        if ($topicId <= 0) {
            return;
        }

        // Only when sync is enabled
        $settings = get_option('tp_settings', []);
        if (($settings['enable_sync'] ?? 'yes') !== 'yes') {
            return;
        }

        $isLinked = false;
        try {
            $postLinkRepo = new \TorrentScraper\WordPress\Sync\PostLinkRepository(\TorrentScraper\WordPress\Adapter\WordPressAdapter::getInstance()->getDb());
            $isLinked = !empty($postLinkRepo->findAllLinked('bbpress_topic', $topicId)) ||
                !empty($this->postMapRepo->findByPost('bbpress_topic', $topicId));
        } catch (\Throwable $e) {
            $isLinked = false;
        }

        if (!$isLinked) {
            return;
        }

        $lockNonce = wp_create_nonce('tp_reload_nonce');
        $ajaxUrl = admin_url('admin-ajax.php');
        ?>
        <script>
            (function () {
                var topicId = <?php echo $topicId; ?>;
                var platform = 'bbpress_topic';
                var lockNonce = <?php echo wp_json_encode($lockNonce); ?>;
                var ajaxUrl = <?php echo wp_json_encode($ajaxUrl); ?>;

                // 1. Acquire Lock on page load
                var fd = new FormData();
                fd.append('action', 'tp_acquire_lock');
                fd.append('nonce', lockNonce);
                fd.append('platform', platform);
                fd.append('post_id', topicId);

                fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success && data.data && data.data.locked) {
                            // Locked by someone else! Show warning banner
                            var notice = document.createElement('div');
                            notice.style.borderLeft = '4px solid #d63638';
                            notice.style.background = '#fff3f3';
                            notice.style.padding = '12px';
                            notice.style.margin = '15px 0';
                            notice.style.borderRadius = '4px';
                            notice.style.fontWeight = '600';
                            notice.style.color = '#c62828';
                            notice.textContent = '⚠️ ' + data.data.message;

                            var form = document.querySelector('#new-post') || document.querySelector('form.bbp-topic-form');
                            if (form) {
                                form.insertBefore(notice, form.firstChild);
                            }

                            // Disable submit button
                            var submit = document.querySelector('#bbp_topic_submit') || document.querySelector('input[type="submit"]') || document.querySelector('button[type="submit"]');
                            if (submit) {
                                submit.disabled = true;
                                submit.style.opacity = '0.5';
                                submit.style.pointerEvents = 'none';
                            }
                        }
                    });

                // 2. Poll lock renewal every 2 minutes
                var renewInterval = setInterval(function () {
                    var fdRenew = new FormData();
                    fdRenew.append('action', 'tp_acquire_lock');
                    fdRenew.append('nonce', lockNonce);
                    fdRenew.append('platform', platform);
                    fdRenew.append('post_id', topicId);
                    fetch(ajaxUrl, { method: 'POST', body: fdRenew, credentials: 'same-origin' });
                }, 120000);

                // 3. Release Lock on unload
                window.addEventListener('beforeunload', function () {
                    clearInterval(renewInterval);
                    var fdRelease = new FormData();
                    fdRelease.append('action', 'tp_release_lock');
                    fdRelease.append('nonce', lockNonce);
                    fdRelease.append('platform', platform);
                    fdRelease.append('post_id', topicId);
                    navigator.sendBeacon(ajaxUrl, fdRelease);
                });
            })();
        </script>
        <?php
    }
}
