<?php

namespace TryHackX\MagnetLink\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\MagnetLink\Support\PublicIp;

/**
 * The range check behind the scraper's SSRF guard: only public, routable addresses.
 */
class PublicIpTest extends TestCase
{
    public static function addresses(): array
    {
        return [
            'public IPv4' => ['8.8.8.8', true],
            'public IPv6' => ['2606:4700:4700::1111', true],
            'private 10/8' => ['10.0.0.1', false],
            'private 192.168/16' => ['192.168.1.10', false],
            'loopback' => ['127.0.0.1', false],
            'link-local / cloud metadata' => ['169.254.169.254', false],
            'carrier-grade NAT' => ['100.64.1.1', false],
            '192.0.0.0/24' => ['192.0.0.5', false],
            'benchmarking 198.18/15' => ['198.18.0.1', false],
            'multicast' => ['224.0.0.1', false],
            'this network 0/8' => ['0.1.2.3', false],
            'IPv6 loopback' => ['::1', false],
            'IPv4-mapped private' => ['::ffff:10.0.0.1', false],
            'NAT64' => ['64:ff9b::a00:1', false],
            '6to4' => ['2002::1', false],
            'Teredo' => ['2001:0::1', false],
            'ULA' => ['fd00::1', false],
            'IPv6 link-local' => ['fe80::1', false],
            'not an address' => ['tracker.example.org', false],
        ];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function only_public_routable_addresses_pass(string $ip, bool $public): void
    {
        $this->assertSame($public, PublicIp::isPublic($ip));
    }
}
