<?php

namespace TryHackX\MagnetLink\Sort;

use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Schema\Sort;

/**
 * Sorts the discussion list by magnet-link click activity, scoped to each
 * discussion's own posts ("clicks from this topic").
 *
 * Source of truth: the `magnet_clicks` table. Every *counted* magnet click
 * (ClickController) inserts exactly one row carrying `post_id` and
 * `click_time`, in lockstep with `magnet_links.click_count`. Posts belong to
 * discussions, so `magnet_clicks.post_id -> posts.discussion_id` gives a
 * per-topic attribution with no extra tables, columns or backfill.
 *
 * Three modes:
 *   - 'sum'  → total magnet clicks across every magnet in the topic.
 *   - 'max'  → clicks of the single most-clicked magnet in the topic
 *              (groups clicks by magnet, takes the largest group).
 *   - 'last' → most recent magnet click time in the topic.
 *
 * Implemented as correlated sub-queries in `orderByRaw`. The SQL interpolates
 * only the trusted table prefix (config, never user input) and the validated
 * asc/desc direction, so it is injection-safe. INNER JOIN to `posts` naturally
 * excludes clicks whose post was deleted.
 *
 * Plugs into Flarum's sort pipeline like `Flarum\Api\Sort\SortColumn`:
 * `sortMap()` exposes friendly aliases (e.g. `most_magnet_clicks`), and the
 * framework dispatches to `apply()` by this field's `name`.
 */
class MagnetClicksSort extends Sort
{
    protected array $alias = [
        'asc' => null,
        'desc' => null,
    ];

    public function __construct(string $name, protected string $mode)
    {
        parent::__construct($name);
    }

    public static function mode(string $name, string $mode): static
    {
        return new static($name, $mode);
    }

    public function ascendingAlias(?string $alias): static
    {
        $this->alias['asc'] = $alias;

        return $this;
    }

    public function descendingAlias(?string $alias): static
    {
        $this->alias['desc'] = $alias;

        return $this;
    }

    public function sortMap(): array
    {
        $map = [];

        foreach ($this->alias as $direction => $alias) {
            if ($alias) {
                $map[$alias] = ($direction === 'asc' ? '' : '-').$this->name;
            }
        }

        return $map;
    }

    public function apply(object $query, string $direction, Context $context): void
    {
        // NOTE: For the discussion list this apply() is generally bypassed —
        // discussions are listed through Flarum's database Search, which orders
        // by column name and is overridden by MagnetClicksSortMutator. This
        // remains a correct fallback for any plain json-api-server listing.
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $prefix = method_exists($query, 'getModel') ? $query->getModel()->getConnection()->getTablePrefix() : '';
        $query->orderByRaw(self::expression($this->mode, $prefix).' '.$direction);
    }

    /**
     * The correlated sub-query (topic-scoped) for a given mode. Shared with
     * MagnetClicksSortMutator so the Search path and the resource path agree.
     */
    public static function expression(string $mode, string $prefix = ''): string
    {
        // Raw SQL is not prefixed by the query builder, so the table names carry
        // the connection's table prefix themselves. $prefix is trusted config
        // (configured prefix, never user input), so interpolating it is safe.
        $clicks = $prefix . 'magnet_clicks';
        $posts = $prefix . 'posts';
        $discussions = $prefix . 'discussions';

        return match ($mode) {
            // Total magnet clicks across all magnets in the topic.
            'sum' => "(select count(*) from {$clicks} mc"
                   . " inner join {$posts} p on p.id = mc.post_id"
                   . " where p.discussion_id = {$discussions}.id)",

            // Clicks of the single most-clicked magnet in the topic.
            'max' => "(select coalesce(max(c), 0) from ("
                   . "select count(*) as c from {$clicks} mc"
                   . " inner join {$posts} p on p.id = mc.post_id"
                   . " where p.discussion_id = {$discussions}.id"
                   . " group by mc.magnet_link_id) as sub)",

            // Most recent magnet click time in the topic (NULL when none).
            'last' => "(select max(mc.click_time) from {$clicks} mc"
                    . " inner join {$posts} p on p.id = mc.post_id"
                    . " where p.discussion_id = {$discussions}.id)",

            default => '0',
        };
    }

    /**
     * Pre-agregowana wersja {@see expression()} do LEFT JOIN: jeden wiersz na
     * discussion_id (kolumna thx_did) z metryką (thx_metric), policzona RAZ dla całej
     * listy zamiast korelowanego podzapytania per wiersz (audyt #4: N×1 → jedna
     * agregacja). Liczy nadal wprost z magnet_clicks, więc magnet:prune-clicks dalej
     * zawęża okno sortu (zachowana semantyka retencji). Surowy SQL: prefiks tabel
     * wstrzykiwany (zaufany config), nazwy kolumn wynikowych (thx_*) są nasze.
     */
    public static function aggregateExpression(string $mode, string $prefix = ''): string
    {
        $clicks = $prefix . 'magnet_clicks';
        $posts = $prefix . 'posts';

        return match ($mode) {
            'sum' => "select p.discussion_id as thx_did, count(*) as thx_metric"
                   . " from {$clicks} mc inner join {$posts} p on p.id = mc.post_id"
                   . " group by p.discussion_id",

            'max' => "select did as thx_did, coalesce(max(c), 0) as thx_metric from ("
                   . "select p.discussion_id as did, count(*) as c"
                   . " from {$clicks} mc inner join {$posts} p on p.id = mc.post_id"
                   . " group by p.discussion_id, mc.magnet_link_id) as sub"
                   . " group by did",

            'last' => "select p.discussion_id as thx_did, max(mc.click_time) as thx_metric"
                    . " from {$clicks} mc inner join {$posts} p on p.id = mc.post_id"
                    . " group by p.discussion_id",

            default => "select null as thx_did, null as thx_metric where 1 = 0",
        };
    }
}
