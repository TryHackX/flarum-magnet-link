<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Widen `magnet_clicks.user_id` / `post_id` from INT (4-byte) to BIGINT to match
 * Flarum 2.x core, whose `users.id` / `posts.id` are BIGINT (`$table->id()`).
 *
 * These columns carry no explicit foreign key, so there's no schema error today,
 * but on a forum whose user/post ids exceed the ~2.1B INT range the stored
 * post_id/user_id would overflow/truncate and attribute clicks to the wrong row.
 * Widening is the Flarum 2.x convention for FK columns.
 *
 * Uses `->change()` (doctrine/dbal, bundled with Flarum). Neither column is part
 * of an index, so the change is clean (no index to drop/recreate). On a very large
 * `magnet_clicks` table the ALTER rebuilds the table — plan the migration window.
 *
 * `magnet_link_id` is intentionally left INT: it references this extension's own
 * `magnet_links.id` (also INT/`increments`), so it stays internally consistent.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('magnet_clicks', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('post_id')->nullable()->change();
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('magnet_clicks', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable()->change();
            $table->unsignedInteger('post_id')->nullable()->change();
        });
    },
];
