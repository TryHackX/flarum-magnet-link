<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * The copy / open click: hands out the link and counts it once per address and interval.
 */
class ClickTest extends MagnetTestCase
{
    private function click(string $token, int $postId, int $as = self::MEMBER): ResponseInterface
    {
        return $this->send($this->request('POST', '/api/magnet/click', [
            'authenticatedAs' => $as,
            'json' => ['token' => $token, 'post_id' => $postId],
        ]));
    }

    #[Test]
    public function a_click_returns_the_link_and_counts_each_address_once(): void
    {
        $posted = $this->postMagnet();

        $first = $this->body($this->click($posted['token'], $posted['post']));
        $this->assertTrue($first['success']);
        $this->assertSame(self::MAGNET, $first['magnet_uri']);
        $this->assertSame(1, $first['click_count']);
        $this->assertTrue($first['click_recorded']);

        $again = $this->body($this->click($posted['token'], $posted['post'], self::AUTHOR));
        $this->assertSame(self::MAGNET, $again['magnet_uri']);
        $this->assertSame(1, $again['click_count'], 'same address within the self-click interval');
        $this->assertFalse($again['click_recorded']);

        $this->assertSame(1, $this->database()->table('magnet_clicks')->count());
        $this->assertSame(self::MEMBER, (int) $this->database()->table('magnet_clicks')->value('user_id'));
    }

    #[Test]
    public function cleared_number_settings_fall_back_to_their_defaults(): void
    {
        // Flarum stores a number field the admin cleared as ''. Read as 0, an empty
        // ban threshold banned the address on its very first click.
        foreach (['ban_interval_count', 'ban_interval', 'ban_time', 'self_interval'] as $key) {
            $this->setting('tryhackx-magnet-link.' . $key, '');
        }
        $posted = $this->postMagnet();

        $click = $this->body($this->click($posted['token'], $posted['post']));
        $this->assertTrue($click['click_recorded'], json_encode($click));
        $this->assertSame(1, $click['click_count']);
        $this->assertSame(0, $this->database()->table('magnet_bans')->count());

        // …and the default self-click interval (1 day) still applies.
        $this->assertFalse($this->body($this->click($posted['token'], $posted['post']))['click_recorded']);
    }
}
