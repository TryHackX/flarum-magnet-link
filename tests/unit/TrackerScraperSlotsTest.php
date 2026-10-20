<?php

namespace TryHackX\MagnetLink\Tests\unit;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Service\TrackerScraper;

/**
 * Uncached scrapes take one of `scrape_max_concurrent` server-wide slots (cache locks);
 * with every slot taken the API answers "busy" at once instead of tying up another
 * PHP worker. The magnet here announces only to a loopback tracker, which the SSRF
 * guard skips — so a scrape that gets a slot finishes at once (no network) with
 * "no_http_trackers".
 */
class TrackerScraperSlotsTest extends TestCase
{
    private const HASH = '0123456789abcdef0123456789abcdef01234567';

    private Repository $cache;

    private function scraper(array $settings = []): TrackerScraper
    {
        $this->cache ??= new Repository(new ArrayStore());

        $repository = new class($settings) implements SettingsRepositoryInterface {
            public function __construct(private array $values)
            {
            }

            public function all(): array
            {
                return $this->values;
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $keyLike): void
            {
                unset($this->values[$keyLike]);
            }
        };

        return new TrackerScraper($repository, $this->cache);
    }

    private function magnet(string $trackers = '&tr=http%3A%2F%2F127.0.0.1%3A1%2Fannounce'): MagnetLink
    {
        return new MagnetLink([
            'info_hash' => strtoupper(self::HASH),
            'magnet_uri' => 'magnet:?xt=urn:btih:' . self::HASH . '&dn=Test' . $trackers,
        ]);
    }

    /** Hold slots 0..$n-1 the way another worker would. @return array<int, \Illuminate\Contracts\Cache\Lock> */
    private function holdSlots(int $n): array
    {
        $locks = [];
        for ($i = 0; $i < $n; $i++) {
            $lock = $this->cache->getStore()->lock('tryhackx-magnet-link.scrape-slot.' . $i, 60);
            $this->assertTrue($lock->get());
            $locks[] = $lock;
        }

        return $locks;
    }

    #[Test]
    public function a_free_slot_scrapes_and_is_released_afterwards(): void
    {
        $scraper = $this->scraper(['tryhackx-magnet-link.scrape_max_concurrent' => '2']);

        $this->assertSame('no_http_trackers', $scraper->scrapeForMagnet($this->magnet())['error_type']);

        // Both slots are free again.
        foreach ($this->holdSlots(2) as $lock) {
            $lock->release();
        }
    }

    #[Test]
    public function every_slot_taken_means_busy_and_busy_is_not_cached(): void
    {
        $scraper = $this->scraper(['tryhackx-magnet-link.scrape_max_concurrent' => '2']);
        $held = $this->holdSlots(2);

        $busy = $scraper->scrapeForMagnet($this->magnet());
        $this->assertFalse($busy['success']);
        $this->assertSame('busy', $busy['error_type']);

        // One slot frees up: the next request scrapes (the "busy" answer wasn't cached).
        $held[0]->release();
        $this->assertSame('no_http_trackers', $scraper->scrapeForMagnet($this->magnet())['error_type']);
        $held[1]->release();
    }

    #[Test]
    public function a_cached_result_needs_no_slot(): void
    {
        $scraper = $this->scraper(['tryhackx-magnet-link.scrape_max_concurrent' => '1']);
        $scraper->scrapeForMagnet($this->magnet()); // fills the cache

        $held = $this->holdSlots(1);
        $this->assertSame('no_http_trackers', $scraper->scrapeForMagnet($this->magnet())['error_type']);
        $held[0]->release();
    }

    #[Test]
    public function a_magnet_with_nothing_to_contact_needs_no_slot(): void
    {
        $scraper = $this->scraper(['tryhackx-magnet-link.scrape_max_concurrent' => '1', 'tryhackx-magnet-link.cache_enabled' => '0']);
        $held = $this->holdSlots(1);

        $this->assertSame('no_trackers', $scraper->scrapeForMagnet($this->magnet(''))['error_type']);
        $this->assertSame('no_http_trackers', $scraper->scrapeForMagnet($this->magnet('&tr=wss%3A%2F%2Ftracker.example.org'))['error_type']);
        $held[0]->release();
    }

    #[Test]
    public function an_empty_or_zero_limit_falls_back_to_the_default(): void
    {
        $default = TrackerScraper::DEFAULT_MAX_CONCURRENT;

        foreach (['', '0'] as $value) {
            $this->cache = new Repository(new ArrayStore());
            $scraper = $this->scraper(['tryhackx-magnet-link.scrape_max_concurrent' => $value, 'tryhackx-magnet-link.cache_enabled' => '0']);

            $held = $this->holdSlots($default - 1);
            $this->assertSame('no_http_trackers', $scraper->scrapeForMagnet($this->magnet())['error_type'], "limit '$value': one default slot is still free");

            $last = $this->cache->getStore()->lock('tryhackx-magnet-link.scrape-slot.' . ($default - 1), 60);
            $this->assertTrue($last->get());
            $this->assertSame('busy', $scraper->scrapeForMagnet($this->magnet())['error_type'], "limit '$value': every default slot taken");

            foreach ([...$held, $last] as $lock) {
                $lock->release();
            }
        }
    }
}
