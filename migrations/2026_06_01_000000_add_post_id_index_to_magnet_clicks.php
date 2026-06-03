<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Index supporting the discussion-list magnet-click sorts (MagnetClicksSort).
 *
 * Those sorts run correlated sub-queries that filter `magnet_clicks` by
 * `post_id` (joined to `posts.discussion_id`) and aggregate `click_time`.
 * The table previously only had composite indexes leading with
 * `magnet_link_id` / `ip_address`, so a `post_id` lookup meant a scan.
 * A `(post_id, click_time)` index covers both the per-topic filter and the
 * "last clicked" MAX.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('magnet_clicks', 'post_id')) {
            return;
        }

        $schema->table('magnet_clicks', function (Blueprint $table) {
            $table->index(['post_id', 'click_time'], 'magnet_clicks_post_id_click_time_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('magnet_clicks', function (Blueprint $table) {
            $table->dropIndex('magnet_clicks_post_id_click_time_index');
        });
    },
];
