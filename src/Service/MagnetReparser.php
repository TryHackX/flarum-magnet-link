<?php

namespace TryHackX\MagnetLink\Service;

use Flarum\Post\CommentPost;
use Psr\Log\LoggerInterface;

/**
 * Re-parses comment posts whose magnet links were written before the Magnet
 * Link extension (and its [magnet] BBCode) was active.
 *
 * Such posts store the raw "[magnet]magnet:?…[/magnet]" text in their parsed
 * XML instead of <MAGNET> tags, so neither the in-post button nor the
 * discussion tooltip (which scans the stored XML for <MAGNET>) work.
 *
 * Re-parsing rebuilds the stored XML through the now-registered formatter:
 *   - the content accessor unparses the stored XML back to its source text;
 *   - the content mutator parses it again, producing <MAGNET> tags.
 *
 * It is safe and idempotent:
 *   - already-processed posts (containing <MAGNET>) are excluded by the query;
 *   - re-parsing a post that is already correct would yield identical XML;
 *   - failures on a single post are swallowed so the batch keeps going.
 */
class MagnetReparser
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * @param callable|null $onPost Optional callback invoked with each reparsed post.
     * @return int Number of posts that were updated.
     */
    public function reparseAll(?callable $onPost = null): int
    {
        $count = 0;

        CommentPost::query()
            ->where('type', 'comment')
            // Raw, unprocessed BBCode is present…
            ->where('content', 'like', '%[magnet]%')
            // …but the formatter never turned it into a <MAGNET> tag.
            ->where('content', 'not like', '%<MAGNET%')
            ->chunkById(50, function ($posts) use (&$count, $onPost) {
                foreach ($posts as $post) {
                    try {
                        // Accessor: stored XML -> original source text.
                        $source = $post->content;

                        if (! is_string($source) || stripos($source, '[magnet]') === false) {
                            continue;
                        }

                        // Mutator: source text -> XML (now with <MAGNET> tags),
                        // using the post author as the parsing actor.
                        $post->setContentAttribute($source, $post->user);
                        $post->save();

                        // Po re-parsie pierwszy post może teraz mieć <MAGNET> —
                        // zaktualizuj zdenormalizowaną flagę dyskusji (#2).
                        \TryHackX\MagnetLink\Listener\SyncDiscussionMagnetFlag::sync($post);

                        $count++;

                        if ($onPost) {
                            $onPost($post);
                        }
                    } catch (\Throwable $e) {
                        // Skip this post but continue the backfill — log it so a
                        // recurring reparse failure is diagnosable in production.
                        $this->logger->warning(
                            '[magnet-link] reparse skipped post ' . $post->id . ': ' . $e->getMessage()
                        );
                    }
                }
            });

        return $count;
    }
}
