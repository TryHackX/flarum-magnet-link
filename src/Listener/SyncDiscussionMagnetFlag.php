<?php

namespace TryHackX\MagnetLink\Listener;

use Flarum\Post\CommentPost;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;

/**
 * Utrzymuje zdenormalizowaną flagę `discussions.has_magnet_links` (czy PIERWSZY
 * post dyskusji zawiera magnet). Dzięki niej lista dyskusji nie musi dociągać
 * pierwszego posta tylko po to, by policzyć atrybut `hasMagnetLinks` — wcześniej
 * globalny `addDefaultInclude(['firstPost'])` ładował go dla KAŻDEJ dyskusji.
 *
 * Wołane z eventów Posted/Revised oraz z re-parsera (statyczny sync()).
 */
class SyncDiscussionMagnetFlag
{
    public function handle(Posted|Revised $event): void
    {
        self::sync($event->post);
    }

    public static function sync(CommentPost $post): void
    {
        $discussion = $post->discussion;
        if (! $discussion) {
            return;
        }

        // Liczy się tylko pierwszy post (zgodnie z semantyką atrybutu). Po save()
        // Eloquent synchronizuje "original", więc getRawOriginal('content') zwraca
        // świeżo zapisany XML. number==1 łapie też pierwszy post nowej dyskusji,
        // gdy first_post_id nie jest jeszcze ustawione w evencie Posted.
        $isFirst = ((int) $discussion->first_post_id === (int) $post->id) || ((int) $post->number === 1);
        if (! $isFirst) {
            return;
        }

        $xml = (string) $post->getRawOriginal('content');
        $has = stripos($xml, '<MAGNET') !== false;

        if ((bool) $discussion->has_magnet_links !== $has) {
            $discussion->has_magnet_links = $has;
            $discussion->save();
        }
    }
}
