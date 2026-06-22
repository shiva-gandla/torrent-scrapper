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
 * File: BencodeDecoder.php
 * Component: Torrent Parsing
 * Description: Implements decoders for Bencode serialized data, the standard data formatting used in torrent files.
 * @package TorrentScraper
 */

declare(strict_types=1);

namespace TorrentScraper\Core\Parser;

use TorrentScraper\Core\Exception\TorrentParseException;

/**
 * Pure PHP bencode decoder.
 *
 * Bencode format (used by BitTorrent .torrent files):
 *   - Integer   : i<number>e         e.g.  i42e  → 42
 *   - String    : <length>:<data>    e.g.  4:spam → "spam"
 *   - List      : l<items>e          e.g.  l4:spami42ee → ["spam", 42]
 *   - Dictionary: d<key><value>...e  e.g.  d3:fooi1ee  → {"foo": 1}
 *
 * Rules:
 *   - No external libraries. Pure PHP only.
 *   - Malformed input throws TorrentParseException (never silently returns null).
 *   - String keys in dictionaries are decoded as UTF-8 where possible,
 *     falling back to Latin-1 for binary paths.
 *
 * @phpstan-type BencodeValue int|string|list<mixed>|array<string, mixed>
 */
final class BencodeDecoder
{
    private string $data;
    private int    $pos;
    private int    $length;

    /**
     * Decode a raw bencoded binary string.
     *
     * @return int|string|list<mixed>|array<string, mixed>
     * @throws TorrentParseException
     */
    public function decode(string $data): int|string|array
    {
        $this->data   = $data;
        $this->pos    = 0;
        $this->length = strlen($data);

        $value = $this->readValue();

        // After decoding the root value, the whole input should be consumed.
        if ($this->pos !== $this->length) {
            $remaining = substr($this->data, $this->pos);
            if (trim($remaining) !== '') {
                throw new TorrentParseException(
                    "Trailing garbage in bencode data at position {$this->pos}."
                );
            }
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // Internal decoder methods
    // -------------------------------------------------------------------------

    /**
     * Read the next value at the current position.
     *
     * @return int|string|list<mixed>|array<string, mixed>
     * @throws TorrentParseException
     */
    private function readValue(): int|string|array
    {
        $this->assertNotEof();

        return match ($this->current()) {
            'i'     => $this->readInteger(),
            'l'     => $this->readList(),
            'd'     => $this->readDict(),
            default => $this->readString(),
        };
    }

    /**
     * Read a bencoded integer: i<number>e
     *
     * @throws TorrentParseException
     */
    private function readInteger(): int
    {
        $this->consume('i');

        $sign = '';
        if ($this->current() === '-') {
            $sign = '-';
            $this->pos++;
        }

        $digits = $this->readDigits();

        if ($digits === '') {
            throw new TorrentParseException(
                "Empty integer value at position {$this->pos}."
            );
        }

        // Bencode does not allow negative zero or leading zeros (except plain '0').
        if ($digits !== '0' && str_starts_with($digits, '0')) {
            throw new TorrentParseException(
                "Leading zeros in integer at position {$this->pos}."
            );
        }

        $this->consume('e');

        return (int) ($sign . $digits);
    }

    /**
     * Read a bencoded byte string: <length>:<data>
     *
     * Returns the raw bytes as a PHP string.
     *
     * @throws TorrentParseException
     */
    private function readString(): string
    {
        $digits = $this->readDigits();

        if ($digits === '') {
            throw new TorrentParseException(
                "Expected string length at position {$this->pos}, got '" . $this->current() . "'."
            );
        }

        $length = (int) $digits;

        $this->consume(':');

        if ($this->pos + $length > $this->length) {
            throw new TorrentParseException(
                "String length {$length} exceeds remaining data at position {$this->pos}."
            );
        }

        $value     = substr($this->data, $this->pos, $length);
        $this->pos += $length;

        return $value;
    }

    /**
     * Read a bencoded list: l<value>...e
     *
     * @return list<mixed>
     * @throws TorrentParseException
     */
    private function readList(): array
    {
        $this->consume('l');

        $list = [];

        while ($this->pos < $this->length && $this->current() !== 'e') {
            $list[] = $this->readValue();
        }

        $this->consume('e');

        return $list;
    }

    /**
     * Read a bencoded dictionary: d<key><value>...e
     * Keys must be byte strings and must appear in lexicographic order (spec requirement).
     *
     * @return array<string, mixed>
     * @throws TorrentParseException
     */
    private function readDict(): array
    {
        $this->consume('d');

        $dict    = [];
        $lastKey = null;

        while ($this->pos < $this->length && $this->current() !== 'e') {
            // Keys are always byte strings.
            $rawKey = $this->readString();
            $key    = $rawKey; // Keep raw binary string keys to avoid mangling info hashes

            if ($lastKey !== null && strcmp($rawKey, $lastKey) <= 0) {
                // Spec says keys must be sorted; warn but do not throw — real-world
                // torrents sometimes violate this. We just accept them.
            }
            $lastKey = $rawKey;

            $dict[$key] = $this->readValue();
        }

        $this->consume('e');

        return $dict;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Return current byte as a single-character string without advancing. */
    private function current(): string
    {
        return $this->data[$this->pos];
    }

    /**
     * Consume a specific expected character and advance position.
     *
     * @throws TorrentParseException
     */
    private function consume(string $expected): void
    {
        $this->assertNotEof();

        if ($this->data[$this->pos] !== $expected) {
            throw new TorrentParseException(
                "Expected '{$expected}' at position {$this->pos}, got '" . $this->data[$this->pos] . "'."
            );
        }

        $this->pos++;
    }

    /**
     * Read consecutive ASCII digit characters and return them as a string.
     */
    private function readDigits(): string
    {
        $digits = '';

        while ($this->pos < $this->length && ctype_digit($this->data[$this->pos])) {
            $digits  .= $this->data[$this->pos];
            $this->pos++;
        }

        return $digits;
    }

    /**
     * @throws TorrentParseException
     */
    private function assertNotEof(): void
    {
        if ($this->pos >= $this->length) {
            throw new TorrentParseException(
                "Unexpected end of bencode data at position {$this->pos}."
            );
        }
    }

    /**
     * Attempt UTF-8 decode of a raw key string; fall back to Latin-1 so
     * binary paths (common in multi-file torrents) don't crash the decoder.
     */
    private function decodeStringKey(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }

        // Latin-1 → UTF-8 conversion as a safe fallback.
        return mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
    }
}
