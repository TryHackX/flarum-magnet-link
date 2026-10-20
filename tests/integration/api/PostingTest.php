<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Service\MagnetReparser;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * A posted [magnet] becomes a token-only card: the link itself is fetched later through
 * the permission-checked API and never appears in the post's HTML.
 */
class PostingTest extends MagnetTestCase
{
    private function postAs(int $postId, int $userId): array
    {
        $response = $this->send($this->request('GET', '/api/posts/' . $postId, ['authenticatedAs' => $userId]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->body($response)['data']['attributes'];
    }

    #[Test]
    public function a_posted_magnet_shows_only_its_token(): void
    {
        $posted = $this->postMagnet();

        $post = $this->postAs($posted['post'], self::MEMBER);
        $this->assertStringContainsString('data-token="' . $posted['token'] . '"', $post['contentHtml']);
        $this->assertStringNotContainsString('magnet:?', $post['contentHtml']);
        $this->assertStringNotContainsStringIgnoringCase(self::HASH, $post['contentHtml']);
        $this->assertArrayNotHasKey('content', $post, 'the source (with the link) is only for those who can edit the post');

        $magnet = MagnetLink::query()->where('token', $posted['token'])->first();
        $this->assertSame('Some Movie 2020 1080p', $magnet->name);
        $this->assertSame(strtoupper(self::HASH), $magnet->info_hash);

        $discussion = $this->send($this->request('GET', '/api/discussions/' . $posted['discussion'], ['authenticatedAs' => self::MEMBER]));
        $this->assertTrue($this->body($discussion)['data']['attributes']['hasMagnetLinks']);
    }

    #[Test]
    public function markup_inside_the_bbcode_stays_part_of_the_link(): void
    {
        // An unencoded http:// tracker (autolinked) and *…* in the name (Markdown
        // emphasis) used to close the tag at once and leave the link in the HTML.
        $magnet = 'magnet:?xt=urn:btih:' . self::HASH . '&dn=Some_Movie_*x*&tr=http://127.0.0.1:1/announce';
        $posted = $this->postMagnet($magnet);

        $post = $this->postAs($posted['post'], self::MEMBER);
        $this->assertMatchesRegularExpression('/data-token="[a-f0-9]{64}"/', $post['contentHtml']);
        $this->assertStringNotContainsString('magnet:?', $post['contentHtml']);
        $this->assertStringNotContainsString('127.0.0.1', $post['contentHtml']);

        $this->assertSame('Some Movie *x*', MagnetLink::query()->where('token', $posted['token'])->value('name'));
    }

    #[Test]
    public function the_backfill_repairs_links_an_older_version_closed_at_once(): void
    {
        $magnet = 'magnet:?xt=urn:btih:' . self::HASH . '&amp;dn=A&amp;tr=<URL url="http://127.0.0.1:1/announce">http://127.0.0.1:1/announce</URL>';
        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Old topic', 'user_id' => self::AUTHOR, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'number' => 1,
                    'content' => '<r><p><MAGNET>[magnet]</MAGNET>' . $magnet . '[/magnet]</p></r>'],
            ],
        ]);

        $reparser = $this->app()->getContainer()->make(MagnetReparser::class);
        $this->assertSame(1, $reparser->countPending());
        $this->assertSame(1, $reparser->reparseAll());

        $post = $this->postAs(1, self::MEMBER);
        $this->assertMatchesRegularExpression('/data-token="[a-f0-9]{64}"/', $post['contentHtml']);
        $this->assertStringNotContainsString('magnet:?', $post['contentHtml']);

        // Nothing left to do, and a second run changes nothing.
        $this->assertSame(0, $reparser->countPending());
        $this->assertSame(0, $reparser->reparseAll());
    }
}
