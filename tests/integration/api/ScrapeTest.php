<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * Tracker statistics in the info API: an uncached scrape needs one of the server-wide
 * slots (scrape_max_concurrent); with all of them taken the answer is "busy" at once,
 * and that answer is never cached.
 */
class ScrapeTest extends MagnetTestCase
{
    private function scrapeError(string $token): ?string
    {
        $response = $this->info($token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response)['scrape']['error_type'] ?? null;
    }

    private function holdSlot(int $slot)
    {
        $lock = $this->app()->getContainer()->make('cache.store')->getStore()
            ->lock('tryhackx-magnet-link.scrape-slot.' . $slot, 60);
        $this->assertTrue($lock->get());

        return $lock;
    }

    #[Test]
    public function every_slot_taken_answers_busy_and_busy_is_not_cached(): void
    {
        $this->setting('tryhackx-magnet-link.scrape_max_concurrent', '1');
        $token = $this->postMagnet()['token'];

        $held = $this->holdSlot(0);
        $this->assertSame('busy', $this->scrapeError($token));

        $held->release();
        $this->assertSame('no_http_trackers', $this->scrapeError($token), 'a free slot scrapes (the loopback tracker is skipped)');

        // Cached now: no slot needed.
        $held = $this->holdSlot(0);
        $this->assertSame('no_http_trackers', $this->scrapeError($token));
        $held->release();
    }

    #[Test]
    public function a_cleared_cache_ttl_still_caches(): void
    {
        // '' (a cleared field) used to read as 0 = no cache at all.
        $this->setting('tryhackx-magnet-link.cache_ttl', '');
        $this->setting('tryhackx-magnet-link.scrape_max_concurrent', '1');
        $token = $this->postMagnet()['token'];

        $this->assertSame('no_http_trackers', $this->scrapeError($token));

        $held = $this->holdSlot(0);
        $this->assertSame('no_http_trackers', $this->scrapeError($token), 'served from the cache');
        $held->release();
    }
}
