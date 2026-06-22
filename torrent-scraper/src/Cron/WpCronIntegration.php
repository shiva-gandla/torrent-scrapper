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
 * File: WpCronIntegration.php
 * Component: WordPress WP-Cron Integration
 * Description: Hooks the core background scheduler tasks into WordPress's WP-Cron scheduling engine.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\WordPress\Cron;

use TorrentScraper\Core\Scheduler\Scheduler;

/**
 * Integrates the core Scheduler with WordPress WP-Cron.
 *
 * Spec:
 *   - Custom schedule: tp_five_minutes (300 seconds)
 *   - Hook name: tp_run_scheduler
 *   - Scheduled on activation, removed on deactivation.
 *   - Callback runs Scheduler::run() — which queries due items and dispatches tracker scrapes.
 */
final class WpCronIntegration
{
    private const SCHEDULE_NAME = 'tp_five_minutes';
    private const HOOK_NAME     = 'tp_run_scheduler';

    public function __construct(
        private readonly Scheduler $scheduler,
    ) {}

    /**
     * Register cron schedule and hook.
     * Called during plugin boot (plugins_loaded).
     */
    public function register(): void
    {
        // Register our custom interval.
        add_filter('cron_schedules', [$this, 'addSchedule']);

        // Hook the scheduler callback.
        add_action(self::HOOK_NAME, [$this, 'runScheduler']);
    }

    /**
     * Called on plugin activation — schedule the recurring event.
     */
    public function activate(): void
    {
        // Ensure our custom schedule is registered BEFORE scheduling.
        // During activation, plugins_loaded hasn't fired yet, so the
        // cron_schedules filter from register() is not yet applied.
        add_filter('cron_schedules', [$this, 'addSchedule']);

        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::SCHEDULE_NAME, self::HOOK_NAME);
        }
    }

    /**
     * Called on plugin deactivation — remove the recurring event.
     */
    public function deactivate(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
    }

    /**
     * Filter callback — adds our 5-minute schedule to WP-Cron intervals.
     *
     * @param  array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public function addSchedule(array $schedules): array
    {
        $schedules[self::SCHEDULE_NAME] = [
            'interval' => 300,
            'display'  => esc_html__('Every 5 minutes (Torrent Scraper)', 'torrent-scraper'),
        ];

        return $schedules;
    }

    /**
     * WP-Cron callback — runs the core scheduler.
     */
    public function runScheduler(): void
    {
        $this->scheduler->run();
    }
}
