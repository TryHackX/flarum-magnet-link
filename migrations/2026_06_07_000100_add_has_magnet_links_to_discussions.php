<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Denormalized `discussions.has_magnet_links` — true when the discussion's FIRST
 * post contains a <MAGNET> tag. Lets the discussion list compute the
 * `hasMagnetLinks` API attribute without eager-loading every first post (the
 * previous global `addDefaultInclude(['firstPost'])`, which loaded the full
 * opening post — content and all — for every discussion in every listing).
 *
 * Kept in sync by Listener\SyncDiscussionMagnetFlag (Posted/Revised events) and
 * the re-parser; this migration backfills the current state in one statement.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('discussions', 'has_magnet_links')) {
            $schema->table('discussions', function (Blueprint $table) {
                $table->boolean('has_magnet_links')->default(false);
                $table->index('has_magnet_links', 'discussions_has_magnet_links_index');
            });
        }

        $schema->getConnection()->table('discussions')
            ->whereIn('first_post_id', function ($query) {
                $query->select('id')
                    ->from('posts')
                    ->where('type', 'comment')
                    ->where('content', 'like', '%<MAGNET%');
            })
            ->update(['has_magnet_links' => true]);
    },
    'down' => function (Builder $schema) {
        if ($schema->hasColumn('discussions', 'has_magnet_links')) {
            $schema->table('discussions', function (Blueprint $table) {
                $table->dropIndex('discussions_has_magnet_links_index');
                $table->dropColumn('has_magnet_links');
            });
        }
    },
];
