<?php

namespace TryHackX\MagnetLink\Search;

use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchCriteria;
use TryHackX\MagnetLink\Sort\MagnetClicksSort;

/**
 * Makes the magnet-click discussion sorts actually order the list.
 *
 * Flarum lists discussions through its database Search, whose `applySort()`
 * orders by `Str::snake($field)` as a *column* — there is no such column for
 * our virtual `magnetClicksTotal` / `magnetClicksMax` / `magnetLastClicked`
 * sort fields, so the bare query would fail. This mutator runs *after*
 * `applySort()` (see AbstractSearcher::search), detects one of our sort
 * fields, drops the bogus column order and replaces it with the correlated
 * sub-query from {@see MagnetClicksSort::expression()} (topic-scoped: clicks
 * made from the discussion's own posts), plus a stable id tie-breaker.
 *
 * The `MagnetClicksSort` objects registered on the DiscussionResource still
 * provide the friendly aliases (`most_magnet_clicks`, …) and field validity;
 * this mutator only supplies the ordering for the Search path.
 */
class MagnetClicksSortMutator
{
    /** Sort field name => expression mode. */
    private const FIELDS = [
        'magnetClicksTotal' => 'sum',
        'magnetClicksMax' => 'max',
        'magnetLastClicked' => 'last',
    ];

    public function __invoke(DatabaseSearchState $state, SearchCriteria $criteria): void
    {
        $sort = $criteria->sort;

        if (! is_array($sort)) {
            return;
        }

        foreach ($sort as $field => $order) {
            if (! isset(self::FIELDS[$field])) {
                continue;
            }

            $direction = (is_string($order) && strtolower($order) === 'asc') ? 'asc' : 'desc';

            $query = $state->getQuery();
            // Raw ORDER BY must carry the table prefix itself (the builder prefixes
            // column/orderBy refs, but not raw SQL).
            $prefix = $query->getModel()->getConnection()->getTablePrefix();
            $expression = MagnetClicksSort::expression(self::FIELDS[$field], $prefix);

            $query
                ->reorder()
                ->orderByRaw($expression.' '.$direction)
                ->orderBy('discussions.id', 'desc');

            return;
        }
    }
}
