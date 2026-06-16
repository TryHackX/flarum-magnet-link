<?php

namespace TryHackX\MagnetLink\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository;

/**
 * Rate-limits the manual "refresh" of a magnet's tracker stats.
 *
 * Two independent limits, both backed by the shared cache (so they work for
 * guests too — keyed by IP):
 *
 *  1. Per-magnet cooldown (GLOBAL): once a magnet has been refreshed by anyone,
 *     it cannot be refreshed again for `refresh_cooldown` seconds. During that
 *     window the freshly scraped result lives in the scrape cache, so every
 *     viewer (post, tooltip, homepage) already sees the latest numbers — there
 *     is no point re-scraping, and it stops the same magnet being hammered.
 *
 *  2. Per-IP quota (SLIDING WINDOW): an IP may perform at most
 *     `refresh_limit_count` refreshes per `refresh_limit_window` seconds. The
 *     used slots are stored as a list of timestamps; each one "ages out" once
 *     it is older than the window, freeing up one slot at a time (oldest
 *     first).
 *
 * Setting a count or cooldown to 0 disables that particular limit.
 */
class RefreshLimiter
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Repository $cache
    ) {
    }

    /**
     * Decide whether a manual refresh of $infoHash from $ip is allowed and, if
     * so, consume the per-magnet cooldown plus one per-IP slot.
     *
     * @return array{allowed: bool, reason: string|null, retry_after: int}
     *   reason: 'cooldown' — the magnet was refreshed recently (already fresh
     *                        for everyone);
     *           'quota'    — this IP used up its refreshes for the window;
     *           null       — allowed.
     */
    public function tryConsume(string $infoHash, string $ip): array
    {
        $now = time();

        $cooldown = max(0, (int) $this->settings->get('tryhackx-magnet-link.refresh_cooldown', 30));
        $limit = max(0, (int) $this->settings->get('tryhackx-magnet-link.refresh_limit_count', 10));
        $window = max(1, (int) $this->settings->get('tryhackx-magnet-link.refresh_limit_window', 600));

        // 1) Per-magnet cooldown (global).
        if ($cooldown > 0) {
            $until = (int) $this->cache->get($this->cooldownKey($infoHash));
            if ($until > $now) {
                return ['allowed' => false, 'reason' => 'cooldown', 'retry_after' => $until - $now];
            }
        }

        // 2) Per-IP sliding-window quota.
        $log = [];
        if ($limit > 0) {
            $stored = $this->cache->get($this->quotaKey($ip));
            $log = is_array($stored) ? $stored : [];

            // Drop slots older than the window ("ujmujesz po jednym").
            $cutoff = $now - $window;
            $log = array_values(array_filter($log, static fn ($t) => is_int($t) && $t > $cutoff));

            if (count($log) >= $limit) {
                $oldest = min($log);
                return ['allowed' => false, 'reason' => 'quota', 'retry_after' => max(1, ($oldest + $window) - $now)];
            }
        }

        // Allowed → consume both limits.
        if ($cooldown > 0) {
            $this->cache->put($this->cooldownKey($infoHash), $now + $cooldown, $cooldown);
        }
        if ($limit > 0) {
            $log[] = $now;
            $this->cache->put($this->quotaKey($ip), $log, $window);
        }

        return ['allowed' => true, 'reason' => null, 'retry_after' => 0];
    }

    private function cooldownKey(string $infoHash): string
    {
        return 'tryhackx-magnet-link.refresh-cd.' . strtolower($infoHash);
    }

    private function quotaKey(string $ip): string
    {
        return 'tryhackx-magnet-link.refresh-q.' . sha1($ip);
    }
}
