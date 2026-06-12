<?php

namespace TryHackX\MagnetLink\Concerns;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Wyciąga parametr trasy z żądania, próbując po kolei:
 *   1) query params (Flarum 2.x merguje tu parametry trasy),
 *   2) atrybuty żądania,
 *   3) regex po ścieżce URI (ostatnia deska ratunku).
 *
 * Fallbacki są celowo zachowane — kontrakt routingu bywał różny między wersjami,
 * a wszystkie trzy źródła zwracają tę samą wartość, gdy jest obecna. Wcześniej ta
 * sama logika była skopiowana w InfoController i DiscussionMagnetsController; tu
 * trzymamy ją w jednym miejscu (DRY), więc ewentualna poprawka jest jedna.
 */
trait ResolvesRouteParam
{
    /**
     * @param string $uriPattern Wzorzec z grupą (1) dopasowującą wartość w ścieżce.
     * @return string|null Surowa wartość parametru lub null, gdy nieobecna.
     */
    protected function resolveRouteParam(ServerRequestInterface $request, string $name, string $uriPattern): ?string
    {
        $params = $request->getQueryParams();
        if (isset($params[$name]) && $params[$name] !== '') {
            return (string) $params[$name];
        }

        $attr = $request->getAttribute($name);
        if ($attr !== null && $attr !== '') {
            return (string) $attr;
        }

        if (preg_match($uriPattern, $request->getUri()->getPath(), $m)) {
            return $m[1];
        }

        return null;
    }
}
