<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * The discussion-list tooltip: the magnets of a discussion's posts, for discussions the
 * viewer can see.
 */
class TooltipTest extends MagnetTestCase
{
    private function magnets(int $discussionId, int $as = self::MEMBER): array
    {
        $response = $this->send($this->request('GET', '/api/magnet/discussion/' . $discussionId, ['authenticatedAs' => $as]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response)['magnets'];
    }

    #[Test]
    public function the_tooltip_lists_the_magnets_of_a_visible_discussion(): void
    {
        $posted = $this->postMagnet();

        $magnets = $this->magnets($posted['discussion']);
        $this->assertCount(1, $magnets);
        $this->assertSame('Some Movie 2020 1080p', $magnets[0]['name']);
        $this->assertSame($posted['token'], $magnets[0]['token']);
        $this->assertArrayNotHasKey('magnet_uri', $magnets[0]);
        $this->assertArrayNotHasKey('_model', $magnets[0]);
    }

    #[Test]
    public function a_discussion_the_viewer_cannot_see_has_no_magnets(): void
    {
        $posted = $this->postMagnet();
        Discussion::query()->whereKey($posted['discussion'])->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 1]);

        $this->assertSame([], $this->magnets($posted['discussion']));
    }
}
