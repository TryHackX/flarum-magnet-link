<?php

namespace TryHackX\MagnetLink\Tests\unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TryHackX\MagnetLink\Model\MagnetLink;

/**
 * The display name read from a magnet's dn= parameter (fully controlled by the post's
 * author). It is stored as-is apart from cleanup — escaping is the frontend's job, so
 * a quote must survive here and be escaped there (js/tests/escapeHtml.test.mjs).
 */
class MagnetNameTest extends TestCase
{
    private const HASH = 'xt=urn:btih:0123456789abcdef0123456789abcdef01234567';

    private function nameOf(string $dn): ?string
    {
        return MagnetLink::nameFromUri('magnet:?' . self::HASH . '&dn=' . $dn . '&tr=udp%3A%2F%2Ftracker.example.org%3A6969');
    }

    #[Test]
    public function separators_become_spaces_and_dashes_stay(): void
    {
        $this->assertSame('Some Movie 2020 1080p x264-GRP', $this->nameOf('Some.Movie_2020+1080p%20x264-GRP'));
    }

    #[Test]
    public function long_multibyte_names_are_cut_by_characters_into_valid_utf8(): void
    {
        $name = $this->nameOf(str_repeat(rawurlencode('ą'), 600));

        $this->assertTrue(mb_check_encoding($name, 'UTF-8'), 'a byte cut used to split a character');
        $this->assertSame(500, mb_strlen($name));
        $this->assertStringEndsWith('ąą...', $name);
    }

    #[Test]
    public function invalid_utf8_and_control_characters_are_cleaned(): void
    {
        $this->assertSame('abc?def', $this->nameOf('abc%FFdef'));
        $this->assertSame('a b c', $this->nameOf('a%00b%0Ac'));
    }

    #[Test]
    public function quotes_are_kept_for_the_frontend_to_escape(): void
    {
        $this->assertSame('x" onmouseover="a', $this->nameOf('x%22%20onmouseover%3D%22a'));
    }

    #[Test]
    public function no_usable_name_is_null(): void
    {
        $this->assertNull($this->nameOf('%20%20'));
        $this->assertNull(MagnetLink::nameFromUri('magnet:?' . self::HASH));
    }
}
