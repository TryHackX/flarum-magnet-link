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
 *
 * Raw Builder is deliberate: this adds a column WITH a named index AND runs a data
 * backfill — neither maps to a Flarum\Database\Migration helper. Blueprint and the
 * query builder already normalise across MySQL/PostgreSQL/SQLite (the helper is just
 * a wrapper over them), so this is cross-DB safe. It is not rewritten to the helper
 * form because changing an already-applied migration risks schema drift between fresh
 * and upgraded installs.
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
