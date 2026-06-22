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
 * File: RestController.php
 * Component: WordPress REST API
 * Description: Defines WP REST API custom endpoints for external query integrations and status scrapes.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Rest;

use TorrentScraper\Core\Repository\StatisticsRepository;
use TorrentScraper\Core\Repository\TorrentRepository;
use TorrentScraper\Core\Repository\CategoryRepository;

/**
 * WordPress REST API controller for the Torrent Scraper plugin.
 *
 * Namespace: /wp-json/tp/v1/
 *
 * Endpoints:
 *   GET /torrents              — List with pagination, per_page param.
 *   GET /torrents/{id}         — Single torrent full detail.
 *   GET /torrents/{id}/stats   — Live scraped stats only.
 *   GET /categories            — List all categories.
 *
 * Response format matches Section 14:
 *   { "success": true, "data": {...}, "meta": { "total", "page", "per_page", "total_pages" } }
 *   { "success": false, "error": { "code": "...", "message": "..." } }
 *
 * Permission: `read` capability for all public endpoints.
 * Rate limiting: applied via a simple transient-based counter.
 */
final class RestController
{
    private const NAMESPACE = 'tp/v1';

    /** Max requests per minute per IP for public endpoints. */
    private const RATE_LIMIT = 60;

    public function __construct(
        private readonly TorrentRepository    $torrentRepo,
        private readonly StatisticsRepository $statsRepo,
        private readonly CategoryRepository   $categoryRepo,
    ) {}

    /**
     * Register all REST routes.
     * Called on the `rest_api_init` hook.
     */
    public function register(): void
    {
        // GET /torrents
        register_rest_route(self::NAMESPACE, '/torrents', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'listTorrents'],
            'permission_callback' => [$this, 'checkReadPermission'],
            'args'                => $this->getListArgs(),
        ]);

        // GET /torrents/{id}
        register_rest_route(self::NAMESPACE, '/torrents/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'getTorrent'],
            'permission_callback' => [$this, 'checkReadPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => static fn ($param): bool => is_numeric($param) && (int) $param > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // GET /torrents/{id}/stats
        register_rest_route(self::NAMESPACE, '/torrents/(?P<id>\d+)/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'getTorrentStats'],
            'permission_callback' => [$this, 'checkReadPermission'],
            'args'                => [
                'id' => [
                    'validate_callback' => static fn ($param): bool => is_numeric($param) && (int) $param > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // GET /categories
        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'listCategories'],
            'permission_callback' => [$this, 'checkReadPermission'],
        ]);
    }

    // ─── Permission callback ─────────────────────────────────────────

    /**
     * Public read endpoints — require `read` capability (logged-in users)
     * or allow unauthenticated access for public data.
     */
    public function checkReadPermission(\WP_REST_Request $request): bool|\WP_Error
    {
        // Rate limiting.
        if ($this->isRateLimited()) {
            return new \WP_Error(
                'rate_limited',
                __('Rate limit exceeded. Please try again later.', 'torrent-scraper'),
                ['status' => 429],
            );
        }

        return true; // Public read access.
    }

    // ─── GET /torrents ───────────────────────────────────────────────

    /**
     * List torrents with pagination.
     */
    public function listTorrents(\WP_REST_Request $request): \WP_REST_Response
    {
        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(100, max(1, (int) $request->get_param('per_page')));
        $orderBy = sanitize_text_field($request->get_param('orderby') ?? 'added_at');
        $order   = strtoupper(sanitize_text_field($request->get_param('order') ?? 'DESC'));

        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        $allowedOrderBy = ['added_at', 'name', 'seeders', 'leechers', 'total_size'];
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'added_at';
        }

        $offset = ($page - 1) * $perPage;

        $filters = ['status' => 'active'];

        // Optional category filter.
        $categoryId = $request->get_param('category_id');
        if ($categoryId !== null) {
            $filters['category_id'] = absint($categoryId);
        }

        // Optional search filter.
        $search = $request->get_param('search');
        if ($search !== null && $search !== '') {
            $filters['search'] = sanitize_text_field($search);
        }

        $torrents = $this->torrentRepo->findAll(
            limit:     $perPage,
            offset:    $offset,
            orderBy:   $orderBy,
            direction: $order,
            filters:   $filters,
        );

        $total      = $this->torrentRepo->count($filters);
        $totalPages = (int) ceil($total / $perPage);

        $data = array_map([$this, 'formatTorrentForApi'], $torrents);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
            ],
        ], 200);
    }

    // ─── GET /torrents/{id} ──────────────────────────────────────────

    /**
     * Get a single torrent by ID.
     */
    public function getTorrent(\WP_REST_Request $request): \WP_REST_Response
    {
        $id      = (int) $request->get_param('id');
        $torrent = $this->torrentRepo->findById($id);

        if ($torrent === null) {
            return $this->errorResponse('not_found', __('Torrent not found.', 'torrent-scraper'), 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => $this->formatTorrentForApi($torrent),
        ], 200);
    }

    // ─── GET /torrents/{id}/stats ────────────────────────────────────

    /**
     * Get live scraped stats for a single torrent.
     */
    public function getTorrentStats(\WP_REST_Request $request): \WP_REST_Response
    {
        $id      = (int) $request->get_param('id');
        $torrent = $this->torrentRepo->findById($id);

        if ($torrent === null) {
            return $this->errorResponse('not_found', __('Torrent not found.', 'torrent-scraper'), 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data'    => [
                'id'        => $id,
                'seeders'   => (int) $torrent['seeders'],
                'leechers'  => (int) $torrent['leechers'],
                'completed' => (int) $torrent['completed'],
                'last_check' => $torrent['last_check'] ?? null,
            ],
        ], 200);
    }

    // ─── GET /categories ─────────────────────────────────────────────

    /**
     * List all active categories.
     */
    public function listCategories(\WP_REST_Request $request): \WP_REST_Response
    {
        $categories = $this->categoryRepo->findAll(activeOnly: true);

        $data = array_map(static function (array $cat): array {
            return [
                'id'        => (int) $cat['id'],
                'name'      => $cat['name'],
                'slug'      => $cat['slug'],
                'parent_id' => $cat['parent_id'] ? (int) $cat['parent_id'] : null,
            ];
        }, $categories);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => $data,
            'meta'    => [
                'total' => count($data),
            ],
        ], 200);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Format a torrent row for API output.
     *
     * @param  array<string, mixed> $torrent
     * @return array<string, mixed>
     */
    private function formatTorrentForApi(array $torrent): array
    {
        return [
            'id'             => (int) $torrent['id'],
            'name'           => $torrent['name'],
            'info_hash'      => $torrent['info_hash'],
            'total_size'     => (int) $torrent['total_size'],
            'file_count'     => (int) $torrent['file_count'],
            'seeders'        => (int) $torrent['seeders'],
            'leechers'       => (int) $torrent['leechers'],
            'completed'      => (int) $torrent['completed'],
            'magnet_link'    => $torrent['magnet_link'] ?? null,
            'status'         => $torrent['status'],
            'is_private'     => (bool) ($torrent['is_private'] ?? false),
            'added_at'       => $torrent['added_at'],
            'last_check'     => $torrent['last_check'] ?? null,
        ];
    }

    /**
     * Build an error response matching Section 14 format.
     */
    private function errorResponse(string $code, string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => false,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * Define argument schema for the list endpoint.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getListArgs(): array
    {
        return [
            'page' => [
                'default'           => 1,
                'validate_callback' => static fn ($param): bool => is_numeric($param) && (int) $param > 0,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'default'           => 20,
                'validate_callback' => static fn ($param): bool => is_numeric($param) && (int) $param > 0 && (int) $param <= 100,
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'default'           => 'added_at',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order' => [
                'default'           => 'DESC',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'category_id' => [
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    // ─── Rate limiting ───────────────────────────────────────────────

    /**
     * Simple transient-based rate limiter per IP.
     * Limits to RATE_LIMIT requests per minute.
     */
    private function isRateLimited(): bool
    {
        $ip  = $this->getClientIp();
        $key = 'tp_rate_' . md5($ip);

        $current = (int) get_transient($key);

        if ($current >= self::RATE_LIMIT) {
            return true;
        }

        set_transient($key, $current + 1, 60); // 60 second window.

        return false;
    }

    /**
     * Get the client's IP address, respecting proxy headers.
     */
    private function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',    // Cloudflare
            'HTTP_X_FORWARDED_FOR',     // Generic proxy
            'HTTP_X_REAL_IP',           // nginx
            'REMOTE_ADDR',             // Direct connection
        ];

        foreach ($headers as $header) {
            $value = $_SERVER[$header] ?? '';
            if ($value !== '') {
                // X-Forwarded-For can contain multiple IPs — take the first.
                $ip = trim(explode(',', $value)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
