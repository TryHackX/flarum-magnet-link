<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use Flarum\Group\PermissionCache;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * Who may read a magnet through the API: guests only when the admin allows it, members
 * with the view permission (granted to Members by default), and — with "activated users
 * only" — only members who confirmed their email.
 */
class AccessTest extends MagnetTestCase
{
    #[Test]
    public function guests_are_turned_away_unless_the_admin_allows_them(): void
    {
        $token = $this->postMagnet()['token'];

        $response = $this->info($token, null);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('guest_not_allowed', $this->body($response)['error']);

        $this->changeSetting('tryhackx-magnet-link.guest_visible', '1');
        $this->assertSame(200, $this->info($token, null)->getStatusCode());
    }

    #[Test]
    public function members_need_the_view_permission(): void
    {
        $token = $this->postMagnet()['token'];

        $response = $this->info($token);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame('Some Movie 2020 1080p', $this->body($response)['name']);

        // As the admin's Permissions page does: change the grants, flush the cache.
        $this->database()->table('group_permission')->where('permission', 'tryhackx-magnet-link.viewMagnetLinks')->delete();
        $this->app()->getContainer()->make(PermissionCache::class)->flush();

        $response = $this->info($token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('permission_denied', $this->body($response)['error']);
    }

    #[Test]
    public function activated_only_turns_away_unconfirmed_members(): void
    {
        $token = $this->postMagnet()['token'];
        $this->changeSetting('tryhackx-magnet-link.activated_only', '1');
        User::query()->whereKey(self::MEMBER)->update(['is_email_confirmed' => 0]);

        $response = $this->info($token);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('email_not_confirmed', $this->body($response)['error']);

        $this->assertSame(200, $this->info($token, self::AUTHOR)->getStatusCode());
    }

    #[Test]
    public function an_unknown_or_malformed_token_is_rejected(): void
    {
        $this->postMagnet();

        $this->assertSame(404, $this->info(str_repeat('a', 64))->getStatusCode());
        $this->assertSame(400, $this->send($this->request('POST', '/api/magnet/click', [
            'authenticatedAs' => self::MEMBER,
            'json' => ['token' => 'not-a-token'],
        ]))->getStatusCode());
    }
}
