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
 * File: TorrentPostType.php
 * Component: WordPress Custom Post Type
 * Description: Registers the custom post type for torrent items (`torrent`) with rewrite rules and custom taxonomies.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\PostType;

/**
 * Registers the `tp_torrent` custom post type and `tp_torrent_category` taxonomy.
 *
 * Spec:
 *   - Post type slug: tp_torrent
 *   - Supports: title, editor, thumbnail, author
 *   - Rewrite: ['slug' => 'torrents']
 *   - Has archive: true
 *   - Capability type: post
 *   - Taxonomy: tp_torrent_category (hierarchical)
 */
final class TorrentPostType
{
    public const SLUG     = 'tp_torrent';
    public const TAXONOMY = 'tp_torrent_category';

    /**
     * Called on the `init` hook.
     */
    public function register(): void
    {
        $this->registerPostType();
        $this->registerTaxonomy();

        // Flush rewrite rules once to clear the CPT/Page URL routing conflict.
        $flushed = get_option('tp_rewrite_rules_flushed_v2', '0');
        if ($flushed !== '1') {
            flush_rewrite_rules(false);
            update_option('tp_rewrite_rules_flushed_v2', '1');
        }
    }

    private function registerPostType(): void
    {
        $labels = [
            'name'               => _x('Torrents', 'post type general name', 'torrent-scraper'),
            'singular_name'      => _x('Torrent', 'post type singular name', 'torrent-scraper'),
            'add_new'            => __('Add New', 'torrent-scraper'),
            'add_new_item'       => __('Add New Torrent', 'torrent-scraper'),
            'edit_item'          => __('Edit Torrent', 'torrent-scraper'),
            'new_item'           => __('New Torrent', 'torrent-scraper'),
            'view_item'          => __('View Torrent', 'torrent-scraper'),
            'search_items'       => __('Search Torrents', 'torrent-scraper'),
            'not_found'          => __('No torrents found.', 'torrent-scraper'),
            'not_found_in_trash' => __('No torrents found in Trash.', 'torrent-scraper'),
            'all_items'          => __('All Torrents', 'torrent-scraper'),
            'archives'           => __('Torrent Archives', 'torrent-scraper'),
            'menu_name'          => __('Torrents', 'torrent-scraper'),
        ];

        register_post_type(self::SLUG, [
            'labels'             => $labels,
            'public'             => true,
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'torrent', 'with_front' => false],
            'supports'           => ['title', 'editor', 'thumbnail', 'author'],
            'capability_type'    => 'post',
            'menu_icon'          => 'dashicons-download',
            'menu_position'      => 25,
            'show_in_rest'       => true, // Gutenberg support
            'show_in_admin_bar'  => true,
            'exclude_from_search' => false,
            'taxonomies'         => [self::TAXONOMY],
        ]);
    }

    private function registerTaxonomy(): void
    {
        $labels = [
            'name'              => _x('Torrent Categories', 'taxonomy general name', 'torrent-scraper'),
            'singular_name'     => _x('Torrent Category', 'taxonomy singular name', 'torrent-scraper'),
            'search_items'      => __('Search Categories', 'torrent-scraper'),
            'all_items'         => __('All Categories', 'torrent-scraper'),
            'parent_item'       => __('Parent Category', 'torrent-scraper'),
            'parent_item_colon' => __('Parent Category:', 'torrent-scraper'),
            'edit_item'         => __('Edit Category', 'torrent-scraper'),
            'update_item'       => __('Update Category', 'torrent-scraper'),
            'add_new_item'      => __('Add New Category', 'torrent-scraper'),
            'new_item_name'     => __('New Category Name', 'torrent-scraper'),
            'menu_name'         => __('Categories', 'torrent-scraper'),
        ];

        register_taxonomy(self::TAXONOMY, self::SLUG, [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'torrent-category', 'with_front' => false],
        ]);
    }
}
