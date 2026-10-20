<?php

namespace TryHackX\MagnetLink\Tests\integration;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\RefreshesFormatterCache;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Psr\Http\Message\ResponseInterface;
use TryHackX\MagnetLink\Model\MagnetLink;

/**
 * Shared fixture: the install's admin (1), the member who posts the magnet (2) and
 * another member (3). The magnet announces only to a loopback tracker, which the
 * scraper's SSRF guard skips — so no test ever touches the network.
 */
abstract class MagnetTestCase extends TestCase
{
    use RefreshesFormatterCache;

    protected const AUTHOR = 2;
    protected const MEMBER = 3;

    protected const HASH = '0123456789abcdef0123456789abcdef01234567';
    protected const MAGNET = 'magnet:?xt=urn:btih:' . self::HASH . '&dn=Some.Movie_2020+1080p&tr=http%3A%2F%2F127.0.0.1%3A1%2Fannounce';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('tryhackx-magnet-link');

        $this->prepareDatabase([
            User::class => [
                ['id' => self::AUTHOR, 'username' => 'author', 'email' => 'author@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::MEMBER, 'username' => 'member', 'email' => 'member@machine.local', 'is_email_confirmed' => 1],
            ],
        ]);
    }

    /**
     * Start a discussion whose first post holds the magnet, the way the composer does.
     *
     * @return array{discussion: int, post: int, token: string}
     */
    protected function postMagnet(string $magnet = self::MAGNET, int $as = self::AUTHOR): array
    {
        $response = $this->send($this->request('POST', '/api/discussions', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'discussions', 'attributes' => [
                'title' => 'A magnet topic',
                'content' => "Grab it:\n\n[magnet]{$magnet}[/magnet]",
            ]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $discussionId = (int) $this->body($response)['data']['id'];
        $postId = (int) $this->database()->table('posts')->where('discussion_id', $discussionId)->value('id');

        $magnetLink = MagnetLink::query()->where('magnet_uri', $magnet)->first();
        $this->assertNotNull($magnetLink, 'the magnet was not stored as posted');

        return ['discussion' => $discussionId, 'post' => $postId, 'token' => $magnetLink->token];
    }

    /** Change a setting once the app is running ({@see setting()} only works before boot). */
    protected function changeSetting(string $key, string $value): void
    {
        $this->app()->getContainer()->make(SettingsRepositoryInterface::class)->set($key, $value);
    }

    protected function info(string $token, ?int $as = self::MEMBER, array $query = []): ResponseInterface
    {
        $request = $this->request('GET', '/api/magnet/info/' . $token, $as ? ['authenticatedAs' => $as] : []);

        return $this->send($query ? $request->withQueryParams($query) : $request);
    }

    protected function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
