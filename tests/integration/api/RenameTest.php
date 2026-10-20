<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * A post's author may show a magnet under their own name (per post). The name is the
 * author's text: the frontend escapes it (js/tests/escapeHtml.test.mjs).
 */
class RenameTest extends MagnetTestCase
{
    private function rename(array $posted, string $name, int $as = self::AUTHOR): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/magnet/rename', [
            'authenticatedAs' => $as,
            'json' => ['token' => $posted['token'], 'post_id' => $posted['post'], 'custom_name' => $name],
        ]));
    }

    #[Test]
    public function only_the_author_renames_and_the_name_is_per_post(): void
    {
        $posted = $this->postMagnet();

        $response = $this->rename($posted, 'Not mine', self::MEMBER);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('not_author', $this->body($response)['error']);

        $this->assertSame(200, $this->rename($posted, 'x" onmouseover="alert(1)')->getStatusCode());

        $info = $this->body($this->info($posted['token'], self::MEMBER, ['post_id' => (string) $posted['post']]));
        $this->assertSame('x" onmouseover="alert(1)', $info['custom_name']);
        $this->assertSame('Some Movie 2020 1080p', $info['name']);

        // Without the post, the magnet keeps its own name.
        $this->assertArrayNotHasKey('custom_name', $this->body($this->info($posted['token'])));

        $restore = $this->send($this->request('DELETE', '/api/magnet/rename', [
            'authenticatedAs' => self::AUTHOR,
            'json' => ['token' => $posted['token'], 'post_id' => $posted['post']],
        ]));
        $this->assertSame(200, $restore->getStatusCode());
        $this->assertFalse($this->body($this->info($posted['token'], self::MEMBER, ['post_id' => (string) $posted['post']]))['has_custom_name']);
    }

    #[Test]
    public function the_length_limit_counts_characters_not_bytes(): void
    {
        $posted = $this->postMagnet();

        // 500 Polish letters = 1000 bytes: fits the 500-character column.
        $this->assertSame(200, $this->rename($posted, str_repeat('ą', 500))->getStatusCode());
        $this->assertSame(400, $this->rename($posted, str_repeat('ą', 501))->getStatusCode());
        $this->assertSame(200, $this->rename($posted, '0')->getStatusCode(), '"0" is a name too');
    }
}
