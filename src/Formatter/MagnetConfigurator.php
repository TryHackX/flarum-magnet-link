<?php

namespace TryHackX\MagnetLink\Formatter;

use s9e\TextFormatter\Configurator;

/**
 * Konfiguracja BBCode [magnet]
 * 
 * Format użycia: [magnet]magnet:?xt=urn:btih:...[/magnet]
 * 
 * Proces:
 * 1. BBCode [magnet]uri[/magnet] -> XML <MAGNET>uri</MAGNET>
 * 2. MagnetRenderer zamienia XML na <MAGNET token="xxx"/>
 * 3. Szablon XSL renderuje HTML ze span.MagnetLink[data-token]
 */
class MagnetConfigurator
{
    public function __invoke(Configurator $configurator)
    {
        // Usuń istniejące definicje
        if (isset($configurator->tags['MAGNET'])) {
            unset($configurator->tags['MAGNET']);
        }
        if (isset($configurator->BBCodes['MAGNET'])) {
            unset($configurator->BBCodes['MAGNET']);
        }

        // 1. Utwórz tag MAGNET
        $tag = $configurator->tags->add('MAGNET');
        
        // 2. Szablon XSL - renderuje HTML
        // Token będzie dodany przez MagnetRenderer przed renderowaniem.
        //
        // UWAGA (audyt #8): „Loading..." to TYLKO fallback dla wyłączonego JS oraz
        // krótki błysk przed hydracją. Właściwy stan ładowania renderuje JS
        // (MagnetLinkManager.renderLoading) i JEST tłumaczony przez
        // app.translator.trans('…forum.loading'). Świadomie NIE wstrzykujemy tu
        // tekstu per-locale (szablon XSL jest kompilowany raz i współdzielony, a
        // tekst zapadłby w zapisany HTML każdego posta) ani nie zostawiamy pustego
        // placeholdera (layout shift). Bez JS magnety i tak nie działają.
        $tag->template = '<span class="MagnetLink" data-token="{@token}">' .
            '<span class="MagnetLink-placeholder">Loading...</span>' .
        '</span>';

        // 3. Dodaj BBCode - prosty format [magnet]content[/magnet]
        // Zawartość BBCode staje się zawartością tagu XML
        $configurator->BBCodes->add('MAGNET');
    }
}
