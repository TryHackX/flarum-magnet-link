<?php

namespace TryHackX\MagnetLink\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Extension\ExtensionManager;
use Flarum\Locale\LocaleManager;
use Flarum\Post\Post;
use Illuminate\Mail\Events\MessageSent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use TryHackX\MagnetLink\Tests\integration\MagnetTestCase;

/**
 * Notification emails quote the post — the plain-text part its source, with the whole
 * [magnet] link — and go to the discussion's followers whatever their View magnet links
 * permission (a suspended member still gets them). The link is replaced by a note.
 */
class EmailTest extends MagnetTestCase
{
    /** @var Email[] */
    private static array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::$sent = [];
        $this->extension('flarum-subscriptions');
        $this->setting('mail_driver', 'log');
        $this->extend((new Extend\Event())->listen(MessageSent::class, function (MessageSent $event) {
            self::$sent[] = $event->message;
        }));

        $this->prepareDatabase([
            Discussion::class => [
                ['id' => 1, 'title' => 'Followed topic', 'user_id' => self::AUTHOR, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1, 'created_at' => Carbon::now()->subDay()],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'user_id' => self::AUTHOR, 'type' => 'comment', 'content' => '<t><p>Hello</p></t>', 'number' => 1, 'created_at' => Carbon::now()->subDay()],
            ],
            'discussion_user' => [
                ['user_id' => self::MEMBER, 'discussion_id' => 1, 'last_read_post_number' => 1, 'subscription' => 'follow'],
            ],
        ]);
    }

    /**
     * The test app boots without the extensions' translation files (a forum loads them),
     * so register the two this email needs: the reply email's text, which quotes the post
     * through its {content} placeholder, and the note.
     */
    private function loadTranslations(): void
    {
        $container = $this->app()->getContainer();
        $locales = $container->make(LocaleManager::class);

        $locales->addTranslations('en', __DIR__ . '/../../../resources/locale/en.yml');
        $locales->addTranslations('en', $container->make(ExtensionManager::class)->getExtension('flarum-subscriptions')->getPath() . '/locale/en.yml');
    }

    #[Test]
    public function a_reply_notification_email_carries_a_note_instead_of_the_magnet(): void
    {
        $this->loadTranslations();
        $this->database()->table('users')->where('id', self::MEMBER)
            ->update(['preferences' => json_encode(['notify_newPost_email' => true])]);

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => self::AUTHOR,
            'json' => ['data' => ['type' => 'posts', 'attributes' => [
                'content' => "The release:\n\n[magnet]" . self::MAGNET . '[/magnet]',
            ], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $this->assertCount(1, self::$sent, 'the follower gets one reply notification');
        $email = self::$sent[0];
        $this->assertSame('member@machine.local', $email->getTo()[0]->getAddress());

        $note = 'Magnet link — open the post on the forum to get it';
        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();

        $this->assertStringContainsString('The release:', $text, 'the rest of the post is still quoted');
        $this->assertStringContainsString('[' . $note . ']', $text);
        $this->assertStringContainsString('<em>' . $note . '</em>', $html);

        foreach (['text' => $text, 'html' => $html] as $part => $body) {
            $this->assertStringNotContainsString('magnet:?', $body, "$part part");
            $this->assertStringNotContainsStringIgnoringCase(self::HASH, $body, "$part part");
            $this->assertStringNotContainsString('MagnetLink', $body, "$part part");
        }
    }
}
