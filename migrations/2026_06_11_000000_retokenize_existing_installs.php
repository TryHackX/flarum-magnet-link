<?php

use Illuminate\Database\Schema\Builder;

/**
 * Auto-retokenize existing installs onto the secret-salt scheme (v2).
 *
 * The 2026_06_07 migration provisioned a per-install secret salt but
 * deliberately left `token_scheme` at 1 (legacy) on installs that already had
 * magnet rows, so the operator had to run `magnet:retokenize` by hand. Until
 * they did, tokens stayed derived from the PUBLIC fallback salt
 * ('flarum-magnet-salt') and — crucially — every NEW magnet added in the
 * meantime kept being tokenized insecurely, leaving the token→URI lookup
 * brute-forceable from the page HTML for anyone without `viewMagnetLinks`.
 *
 * This migration closes that window automatically: it performs the same
 * idempotent re-tokenization that `Service\TokenRetokenizer` does (recomputed
 * from the stored magnet URI — the source of truth — as sha256(uri + secret
 * salt)) and only then flips the scheme to 2. So no install is ever left on the
 * public salt without operator action.
 *
 * Safe to run anywhere:
 *   - fresh / already-retokenized installs (scheme >= 2) → no-op;
 *   - only the `token` column changes; row ids stay put, so magnet_clicks /
 *     magnet_custom_names foreign keys are untouched;
 *   - re-running it is harmless (idempotent).
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        // Ensure a secret salt exists (normally created by 2026_06_07).
        $salt = $db->table('settings')
            ->where('key', 'tryhackx-magnet-link.token_salt')
            ->value('value');

        if (! $salt) {
            $salt = bin2hex(random_bytes(32));
            $db->table('settings')->updateOrInsert(
                ['key' => 'tryhackx-magnet-link.token_salt'],
                ['value' => $salt]
            );
        }

        $scheme = (int) ($db->table('settings')
            ->where('key', 'tryhackx-magnet-link.token_scheme')
            ->value('value') ?? 1);

        // Already on the secret-salt scheme — nothing to do.
        if ($scheme >= 2) {
            return;
        }

        // Recompute every token from its stored URI with the secret salt. Paging
        // by id is stable because we only rewrite the `token` column.
        $db->table('magnet_links')->orderBy('id')->chunkById(200, function ($rows) use ($db, $salt) {
            foreach ($rows as $row) {
                $newToken = hash('sha256', $row->magnet_uri . $salt);
                if ($newToken !== $row->token) {
                    $db->table('magnet_links')->where('id', $row->id)->update(['token' => $newToken]);
                }
            }
        });

        // Only after the tokens are rewritten: mark scheme 2 so render derives
        // tokens with the secret salt (matching the rows we just rewrote).
        $db->table('settings')->updateOrInsert(
            ['key' => 'tryhackx-magnet-link.token_scheme'],
            ['value' => '2']
        );
    },
    'down' => function (Builder $schema) {
        // No-op. Legacy (public-salt) tokens are intentionally not restored:
        // reverting would re-introduce the vulnerability and break links anyway
        // (the secret salt stays). `up` is idempotent, so re-migrating is safe.
    },
];
