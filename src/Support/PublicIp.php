<?php

namespace TryHackX\MagnetLink\Support;

/**
 * "Is this a public, routable address?" — the one range check behind the scraper's
 * SSRF guard (TrackerScraper::hostIsPublic() and the hardened engine's IP pinning).
 *
 * PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE alone lets through
 * ranges that can still reach the server's own network: carrier-grade NAT
 * (100.64.0.0/10), 192.0.0.0/24, benchmarking (198.18.0.0/15), multicast, and IPv6
 * forms that embed or tunnel an IPv4 address (IPv4-mapped/compatible, NAT64
 * 64:ff9b::/96, 6to4 2002::/16, Teredo 2001::/32) plus ULA, link-local and multicast.
 */
final class PublicIp
{
    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (str_contains($ip, ':')) {
            $bin = inet_pton($ip);
            if ($bin === false) {
                return false;
            }
            $hex = bin2hex($bin);

            return ! (str_starts_with($hex, '00000000000000000000')      // ::a.b.c.d, ::ffff:a.b.c.d
                || str_starts_with($hex, '0064ff9b')                     // NAT64 64:ff9b::/96 (+ /48 local-use)
                || str_starts_with($hex, '2002')                         // 6to4
                || str_starts_with($hex, '20010000')                     // Teredo 2001::/32
                || in_array(substr($hex, 0, 2), ['fc', 'fd', 'ff'], true) // ULA fc00::/7, multicast ff00::/8
                || preg_match('/^fe[89ab]/', $hex) === 1);                // link-local fe80::/10
        }

        $n = ip2long($ip);
        if ($n === false) {
            return false;
        }
        foreach ([['0.0.0.0', 8], ['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['224.0.0.0', 3]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($n & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
