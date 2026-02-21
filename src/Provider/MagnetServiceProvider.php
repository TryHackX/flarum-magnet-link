<?php

namespace TryHackX\MagnetLink\Provider;

use Flarum\Foundation\AbstractServiceProvider;

class MagnetServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        // Scraper jest ładowany bezpośrednio gdy potrzebny
    }

    public function boot()
    {
        // Dodatkowa inicjalizacja jeśli potrzebna
    }
}
