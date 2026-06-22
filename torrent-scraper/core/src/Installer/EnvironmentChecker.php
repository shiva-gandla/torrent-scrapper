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
 * File: EnvironmentChecker.php
 * Component: Environment Verification
 * Description: Checks server prerequisites (PHP version, cURL/socket extensions, etc.) to ensure the scraper can execute reliably.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Installer;

use TorrentScraper\Core\Database\Contracts\DatabaseInterface;

/**
 * System environment checker.
 * Verifies hosting environment requirements: PHP version, extensions, writable folders, and database.
 */
final class EnvironmentChecker
{
    private const MIN_PHP_VERSION = '8.2.0';
    private const REQUIRED_EXTENSIONS = ['pdo', 'curl', 'openssl', 'mbstring', 'fileinfo'];

    /**
     * @param DatabaseInterface $db
     * @param string             $uploadDir  Absolute path to upload storage directory.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string            $uploadDir,
    ) {}

    /**
     * Run all system checks.
     *
     * @return array<CheckResult>
     */
    public function check(): array
    {
        return [
            $this->checkPhpVersion(),
            $this->checkExtensions(),
            $this->checkDatabase(),
            $this->checkUploadDir(),
            $this->checkTrackerConnectivity('http://retracker.local/announce', 'HTTP Tracker Connectivity'),
            $this->checkTrackerConnectivity('udp://tracker.coppersurfer.tk:6969/announce', 'UDP Tracker Connectivity'),
        ];
    }

    private function checkPhpVersion(): CheckResult
    {
        $ok = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');

        return new CheckResult(
            check: 'PHP Version',
            status: $ok ? CheckStatus::Pass : CheckStatus::Fail,
            message: $ok
                ? 'Running PHP ' . PHP_VERSION . ' (minimum ' . self::MIN_PHP_VERSION . ')'
                : 'Running PHP ' . PHP_VERSION . ' (minimum ' . self::MIN_PHP_VERSION . ' required)',
            requiredValue: self::MIN_PHP_VERSION,
            actualValue: PHP_VERSION,
        );
    }

    private function checkExtensions(): CheckResult
    {
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }

        $ok = empty($missing);

        return new CheckResult(
            check: 'Required PHP Extensions',
            status: $ok ? CheckStatus::Pass : CheckStatus::Fail,
            message: $ok
                ? 'All required extensions are loaded (' . implode(', ', self::REQUIRED_EXTENSIONS) . ')'
                : 'Missing required extensions: ' . implode(', ', $missing),
            requiredValue: implode(', ', self::REQUIRED_EXTENSIONS),
            actualValue: $ok ? 'All loaded' : 'Missing: ' . implode(', ', $missing),
        );
    }

    private function checkDatabase(): CheckResult
    {
        try {
            $version = $this->db->serverVersion();
            $isMaria = (stripos($version, 'mariadb') !== false);
            $cleanVer = preg_replace('/[^0-9.]/', '', $version);
            $cleanVer = explode('.', $cleanVer);
            $major = (int) ($cleanVer[0] ?? 0);
            $minor = (int) ($cleanVer[1] ?? 0);

            if ($isMaria) {
                // MariaDB 10.3+ required.
                $ok = ($major > 10) || ($major === 10 && $minor >= 3);
                $req = 'MariaDB 10.3+';
            } else {
                // MySQL 5.7+ required.
                $ok = ($major > 5) || ($major === 5 && $minor >= 7);
                $req = 'MySQL 5.7+';
            }

            return new CheckResult(
                check: 'Database Engine Version',
                status: $ok ? CheckStatus::Pass : CheckStatus::Warning,
                message: "Database engine: {$version}. Requirement met: " . ($ok ? 'Yes' : 'No (potential issues)'),
                requiredValue: $req,
                actualValue: $version,
            );
        } catch (\Throwable $e) {
            return new CheckResult(
                check: 'Database Engine Version',
                status: CheckStatus::Fail,
                message: 'Failed to query database server version: ' . $e->getMessage(),
            );
        }
    }

    private function checkTrackerConnectivity(string $url, string $label): CheckResult
    {
        $ok = false;
        try {
            if (str_starts_with($url, 'udp://')) {
                // Simple socket connect check for UDP.
                $parts = parse_url($url);
                $host  = $parts['host'] ?? '';
                $port  = $parts['port'] ?? 80;

                if ($host !== '') {
                    $fp = @fsockopen("udp://{$host}", $port, $errno, $errstr, 5);
                    if ($fp) {
                        $ok = true;
                        fclose($fp);
                    }
                }
            } else {
                // Curl check for HTTP.
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_NOBODY         => true,
                ]);
                curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $ok = ($httpCode >= 200 && $httpCode < 400);
            }
        } catch (\Throwable) {
            // Falls through to Warning.
        }

        return new CheckResult(
            check: $label,
            status: $ok ? CheckStatus::Pass : CheckStatus::Warning,
            message: $ok
                ? "{$label} to public trackers: OK."
                : "Could not reach {$url}. HTTP tracker scraping may not work on this host.",
        );
    }

    private function checkUploadDir(): CheckResult
    {
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }

        $writable = is_dir($this->uploadDir) && is_writable($this->uploadDir);

        return new CheckResult(
            check: 'Upload Directory Writable',
            status: $writable ? CheckStatus::Pass : CheckStatus::Fail,
            message: $writable
                ? "Upload directory is writable: {$this->uploadDir}"
                : "Upload directory is not writable: {$this->uploadDir}",
            requiredValue: 'Writable directory',
            actualValue: $this->uploadDir,
        );
    }
}
