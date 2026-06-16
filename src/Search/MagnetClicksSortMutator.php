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
 * fields, drops the bogus column order and replaces it with a single
 * pre-aggregated LEFT JOIN from {@see MagnetClicksSort::aggregateExpression()}
 * (topic-scoped: clicks made from the discussion's own posts), plus a stable id
 * tie-breaker. The aggregate is computed once for the whole page, not per row.
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
            $mode = self::FIELDS[$field];

            $query = $state->getQuery();
            $connection = $query->getModel()->getConnection();
            $prefix = $connection->getTablePrefix();

            // Pre-agregowany LEFT JOIN zamiast korelowanego podzapytania per wiersz
            // (audyt #4): metryka liczona RAZ (GROUP BY discussion_id), nie raz na każdą
            // dyskusję na stronie. Discussion search selektuje `discussions.*`, więc
            // kolumny thx_mc nie wchodzą do hydracji modeli. Alias thx_mc trzymamy w
            // surowym SQL — builder prefiksowałby go jak tabelę (psując prefix-safe).
            // Dyskusje bez klików → thx_metric NULL: sum/max sortujemy z coalesce(0)
            // (zachowuje dawną kolejność), last zostaje NULL (jak max(click_time)).
            $sub = MagnetClicksSort::aggregateExpression($mode, $prefix);
            $metric = $mode === 'last' ? 'thx_mc.thx_metric' : 'coalesce(thx_mc.thx_metric, 0)';

            $query
                ->reorder()
                ->leftJoin(
                    $connection->raw('(' . $sub . ') as thx_mc'),
                    $connection->raw('thx_mc.thx_did'),
                    '=',
                    $connection->raw($prefix . 'discussions.id')
                )
                ->orderByRaw($metric . ' ' . $direction)
                ->orderBy('discussions.id', 'desc');

            return;
        }
    }
}
