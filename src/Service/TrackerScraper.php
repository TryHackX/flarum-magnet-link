<?php

namespace TryHackX\MagnetLink\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Store;
use Scrapeer\Scraper;
use TryHackX\MagnetLink\Model\MagnetLink;

/**
 * Central tracker-scraping service shared by InfoController and
 * DiscussionMagnetsController.
 *
 * The magnet's tracker list comes from post content, i.e. it is fully
 * attacker-controlled. On top of the raw Scrapeer call this service therefore
 * adds the safety and feature layers the controllers used to lack:
 *
 *  - SSRF guard ({@see hostIsPublic}) — refuses trackers whose host resolves
 *    to a loopback / private / link-local / reserved address (LAN services,
 *    cloud metadata, …) unless the admin explicitly opts in via
 *    `allow_private_trackers`. Without this a post could make the server issue
 *    requests to internal hosts.
 *  - Hard limits — caps the number of trackers actually contacted
 *    ({@see HARD_TRACKER_CAP}) and enforces a wall-clock budget
 *    ({@see HARD_TIME_BUDGET}, plus an optional caller-shared deadline) so a
 *    single magnet — or a tooltip full of them — can't tie up a PHP-FPM worker.
 *  - check_all / display_type — optionally queries every allowed tracker and
 *    aggregates seeders/leechers/completed instead of stopping at the first
 *    responder (these admin settings previously did nothing).
 *  - Result cache — stores the computed payload per info-hash for a
 *    configurable, admin-toggleable TTL so repeated views/hovers don't re-hit
 *    the trackers (also the main brake on the hover-driven load).
 *
 * The returned payload keeps the exact shape the controllers already emitted
 * ({success, seeders, leechers, completed} or {success:false, error_type,
 * message}), so the frontend and other extensions are unaffected.
 */
class TrackerScraper
{
    /** Absolute ceiling on trackers contacted per magnet, whatever the settings say. */
    private const HARD_TRACKER_CAP = 12;

    /**
     * Absolute ceiling on trackers *examined* per magnet (DNS-resolved by the
     * SSRF guard, then maybe scraped). Higher than HARD_TRACKER_CAP so a few
     * blocked/dead hosts don't use up the contact budget, but still bounded so
     * a magnet stuffed with hundreds of hosts can't cause endless lookups.
     */
    private const HARD_CONSIDER_CAP = 25;

    /** Absolute wall-clock ceiling (seconds) for scraping a single magnet. */
    private const HARD_TIME_BUDGET = 15.0;

    /**
     * Max TTL (seconds) for cached *failures*. Kept short so a transient miss —
     * e.g. a tooltip that ran out of its shared time budget before reaching a
     * tracker — recovers quickly (and the full-budget in-post view isn't stuck
     * with it), while still throttling repeated hits to genuinely dead trackers.
     */
    private const FAILURE_CACHE_TTL = 30;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Store $cache
    ) {
    }

    /**
     * Build the `scrape` payload for a magnet, or null when scraping is
     * globally disabled.
     *
     * @param float|null $deadline Optional shared unix timestamp (microtime)
     *   so a caller scraping several magnets (the tooltip) can bound the total
     *   time across all of them.
     * @param bool $bypassCache Skip the cached value (the manual refresh button)
     *   but still refresh and store it.
     */
    public function scrapeForMagnet(MagnetLink $magnet, ?float $deadline = null, bool $bypassCache = false): ?array
    {
        if (! (bool) $this->settings->get('tryhackx-magnet-link.scraper_enabled', true)) {
            return null;
        }

        $cacheEnabled = (bool) $this->settings->get('tryhackx-magnet-link.cache_enabled', true);
        $cacheKey = $this->cacheKey($magnet);

        if ($cacheEnabled && ! $bypassCache) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->computeScrape($magnet, $deadline);

        if ($cacheEnabled) {
            $ttl = (int) $this->settings->get('tryhackx-magnet-link.cache_ttl', 300);
            if ($ttl > 0) {
                // Cache successes for the full TTL; failures only briefly so a
                // transient miss doesn't stick around (see FAILURE_CACHE_TTL).
                $effectiveTtl = ! empty($result['success']) ? $ttl : min($ttl, self::FAILURE_CACHE_TTL);
                $this->cache->put($cacheKey, $result, $effectiveTtl);
            }
        }

        return $result;
    }

    private function cacheKey(MagnetLink $magnet): string
    {
        // Każde ustawienie, które zmienia KTÓRE trackery są odpytywane lub JAK
        // wyniki są agregowane, musi być częścią klucza — inaczej zmiana
        // ustawienia działałaby dopiero po wygaśnięciu TTL. Dotyczy to zwłaszcza
        // allow_private_trackers: to przełącznik bezpieczeństwa, więc po jego
        // wyłączeniu nie wolno dalej serwować wyników zebranych według starej
        // polityki. tracker_timeout pomijamy celowo (wpływa tylko na sukces/
        // porażkę, nie na same wartości), żeby nie fragmentować cache przy
        // każdej korekcie limitu czasu.
        $parts = [
            (string) $this->settings->get('tryhackx-magnet-link.display_type', 'average'),
            (bool) $this->settings->get('tryhackx-magnet-link.check_all', false) ? 'all' : 'first',
            (bool) $this->settings->get('tryhackx-magnet-link.http_only', false) ? 'http' : 'any',
            (bool) $this->settings->get('tryhackx-magnet-link.allow_private_trackers', false) ? 'priv' : 'pub',
            'm' . (int) $this->settings->get('tryhackx-magnet-link.max_trackers', 0),
        ];

        return 'tryhackx-magnet-link.scrape.' . strtolower($magnet->info_hash) . '.' . implode('.', $parts);
    }

    private function computeScrape(MagnetLink $magnet, ?float $deadline): array
    {
        $candidates = $this->candidateTrackers($magnet);

        if ($candidates === null) {
            return [
                'success' => false,
                'error_type' => 'no_trackers',
                'message' => 'Magnet link contains no trackers',
            ];
        }

        if (empty($candidates)) {
            // Had trackers, but none with a usable scheme (http/https/udp,
            // minus udp when http_only is on).
            return [
                'success' => false,
                'error_type' => 'no_http_trackers',
                'message' => 'No usable trackers available',
            ];
        }

        $timeout = (int) $this->settings->get('tryhackx-magnet-link.tracker_timeout', 2);
        $timeout = $timeout > 0 ? min($timeout, 30) : 2;

        $maxSetting = (int) $this->settings->get('tryhackx-magnet-link.max_trackers', 0);
        $maxTrackers = $maxSetting > 0 ? min($maxSetting, self::HARD_TRACKER_CAP) : self::HARD_TRACKER_CAP;

        $checkAll = (bool) $this->settings->get('tryhackx-magnet-link.check_all', false);
        $allowPrivate = (bool) $this->settings->get('tryhackx-magnet-link.allow_private_trackers', false);

        // Never let one magnet run past the absolute ceiling, even if the
        // caller passed a more generous shared deadline.
        $hardDeadline = microtime(true) + self::HARD_TIME_BUDGET;
        $budgetDeadline = $deadline !== null ? min($deadline, $hardDeadline) : $hardDeadline;

        $infoHash = $magnet->info_hash;
        $collected = [];
        $contacted = 0;
        $considered = 0;
        $blockedAny = false;

        foreach ($candidates as $tracker) {
            if ($contacted >= $maxTrackers
                || $considered >= self::HARD_CONSIDER_CAP
                || microtime(true) >= $budgetDeadline) {
                break;
            }
            $considered++;

            // SSRF guard (resolves DNS) — kept inside the capped/timed loop so
            // a magnet stuffed with hosts can't cause unbounded resolution; the
            // accumulated time is bounded by the deadline check above.
            if (! $allowPrivate && ! $this->hostIsPublic((string) parse_url($tracker, PHP_URL_HOST))) {
                $blockedAny = true;
                continue;
            }

            // Cap this single attempt to the time left in the budget, so one
            // slow tracker can't overrun it.
            $remaining = $budgetDeadline - microtime(true);
            if ($remaining < 1.0) {
                break;
            }
            $callTimeout = max(1, (int) min($timeout, (int) ceil($remaining)));

            $data = $this->scrapeSingle($infoHash, $tracker, $callTimeout);
            $contacted++;

            if ($data !== null) {
                $collected[] = $data;

                // Legacy behaviour: stop at the first tracker that answers.
                if (! $checkAll) {
                    break;
                }
            }
        }

        if (empty($collected)) {
            // Everything we looked at was blocked by the SSRF guard → report it
            // as "no usable trackers" rather than "no response".
            if ($contacted === 0 && $blockedAny) {
                return [
                    'success' => false,
                    'error_type' => 'no_http_trackers',
                    'message' => 'No usable trackers available',
                ];
            }

            return [
                'success' => false,
                'error_type' => 'no_response',
                'message' => 'No tracker responded',
            ];
        }

        return ['success' => true] + $this->aggregate($collected);
    }

    /**
     * Tracker URLs that pass the cheap scheme / http_only filters (no DNS).
     * The SSRF host check is applied later, inside the capped scrape loop.
     *
     * @return array<int, string>|null  Candidate tracker URLs, an empty array
     *   if the magnet had trackers but none with a usable scheme, or null if it
     *   has no tracker parameters at all.
     */
    private function candidateTrackers(MagnetLink $magnet): ?array
    {
        $trackers = $magnet->getTrackers();
        if (empty($trackers)) {
            return null;
        }

        $httpOnly = (bool) $this->settings->get('tryhackx-magnet-link.http_only', false);

        $candidates = [];
        foreach ($trackers as $tracker) {
            $parts = parse_url($tracker);
            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                continue;
            }

            $scheme = strtolower($parts['scheme']);
            if (! in_array($scheme, ['http', 'https', 'udp'], true)) {
                continue;
            }
            if ($httpOnly && $scheme === 'udp') {
                continue;
            }

            $candidates[] = $tracker;
        }

        return $candidates;
    }

    /**
     * True when every address the host resolves to is public and routable.
     *
     * Blocks IP literals and hostnames that point at loopback / private /
     * link-local / reserved ranges — the SSRF surface. A host that fails to
     * resolve is treated as not-public (skipped): it is a dead tracker anyway.
     *
     * Residual risk: DNS rebinding (host resolves public here but private when
     * Scrapeer re-resolves at fetch time) is not covered. Fully closing that
     * would require pinning the resolved IP through the request, which the
     * BitTorrent scrape path (Host header / TLS SNI / UDP connect) does not
     * allow cleanly.
     */
    private function hostIsPublic(string $host): bool
    {
        $host = trim($host, '[]'); // strip IPv6 literal brackets

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $record) {
                    if (! empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // at least one resolved address is private/reserved
            }
        }

        return true;
    }

    /**
     * Scrape a single tracker for a single info-hash.
     *
     * @return array{seeders:int, leechers:int, completed:int}|null
     */
    private function scrapeSingle(string $infoHash, string $tracker, int $timeout): ?array
    {
        try {
            $scraper = new Scraper();
            $result = $scraper->scrape($infoHash, [$tracker], null, $timeout, false);
        } catch (\Throwable $e) {
            return null;
        }

        if (empty($result[$infoHash])) {
            return null;
        }

        $data = $result[$infoHash];

        return [
            'seeders' => (int) ($data['seeders'] ?? 0),
            'leechers' => (int) ($data['leechers'] ?? 0),
            'completed' => (int) ($data['completed'] ?? 0),
        ];
    }

    /**
     * Aggregate per-tracker rows according to the display_type setting.
     *
     * @param array<int, array{seeders:int, leechers:int, completed:int}> $rows
     * @return array{seeders:int, leechers:int, completed:int}
     */
    private function aggregate(array $rows): array
    {
        $mode = (string) $this->settings->get('tryhackx-magnet-link.display_type', 'average');

        $seeders = array_column($rows, 'seeders');
        $leechers = array_column($rows, 'leechers');
        $completed = array_column($rows, 'completed');

        $avg = fn (array $values): int => empty($values) ? 0 : (int) round(array_sum($values) / count($values));
        $max = fn (array $values): int => empty($values) ? 0 : (int) max($values);

        switch ($mode) {
            case 'max_all':
                return [
                    'seeders' => $max($seeders),
                    'leechers' => $max($leechers),
                    'completed' => $max($completed),
                ];

            case 'average_max_downloads':
                return [
                    'seeders' => $avg($seeders),
                    'leechers' => $avg($leechers),
                    'completed' => $max($completed),
                ];

            case 'average':
            default:
                return [
                    'seeders' => $avg($seeders),
                    'leechers' => $avg($leechers),
                    'completed' => $avg($completed),
                ];
        }
    }
}
