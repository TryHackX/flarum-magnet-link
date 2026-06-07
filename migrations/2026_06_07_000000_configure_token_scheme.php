<?php

use Illuminate\Database\Schema\Builder;

/**
 * Token secret-salt scheme (v2).
 *
 * Magnet tokens are sha256(magnetUri + salt) and are exposed in post HTML as
 * data-token. The old salt came from config('app.key') with a PUBLIC fallback
 * ('flarum-magnet-salt'); since Flarum never sets app.key, that fallback was
 * effectively a constant public salt on every install — so anyone could
 * precompute token→URI for known torrents and recover the magnet URI from the
 * page source without permission.
 *
 * This migration provisions a per-install random secret salt:
 *  - Always: create a random `token_salt` if one isn't set yet.
 *  - Fresh installs (no magnet_links rows yet): set `token_scheme` = 2 so the
 *    very first token already uses the secret salt.
 *  - Existing installs (rows present): leave `token_scheme` unset → it defaults
 *    to 1 (legacy), so existing tokens keep resolving while the admin is
 *    prompted to run the one-off re-tokenization (CLI `magnet:retokenize` or the
 *    settings button), which flips the scheme to 2.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        if (! $db->table('settings')->where('key', 'tryhackx-magnet-link.token_salt')->exists()) {
            $db->table('settings')->insert([
                'key' => 'tryhackx-magnet-link.token_salt',
                'value' => bin2hex(random_bytes(32)),
            ]);
        }

        $hasMagnets = $db->table('magnet_links')->exists();
        $hasScheme = $db->table('settings')->where('key', 'tryhackx-magnet-link.token_scheme')->exists();

        if (! $hasMagnets && ! $hasScheme) {
            $db->table('settings')->insert([
                'key' => 'tryhackx-magnet-link.token_scheme',
                'value' => '2',
            ]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->whereIn('key', [
            'tryhackx-magnet-link.token_salt',
            'tryhackx-magnet-link.token_scheme',
        ])->delete();
    },
];
