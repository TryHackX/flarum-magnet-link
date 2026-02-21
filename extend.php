<?php

/**
 * Magnet Link BBCode Extension for Flarum
 * Version: 1.0.0
 * Author: TryHackX
 * 2025
 */

namespace TryHackX\MagnetLink;

use Flarum\Extend;
use Flarum\Group\Group;
use TryHackX\MagnetLink\Api\Controller;
use TryHackX\MagnetLink\Provider\MagnetServiceProvider;
use TryHackX\MagnetLink\Formatter\MagnetConfigurator;
use TryHackX\MagnetLink\Formatter\MagnetRenderer;

return [
    // Rejestracja service providera
    (new Extend\ServiceProvider())
        ->register(MagnetServiceProvider::class),

    // Rejestracja assetów frontend
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/resources/less/admin.less'),

    // Rejestracja lokalizacji
    new Extend\Locales(__DIR__ . '/resources/locale'),

    // Rejestracja tras API
    (new Extend\Routes('api'))
        ->get('/magnet/info/{token}', 'magnet.info', Controller\InfoController::class)
        ->post('/magnet/click', 'magnet.click', Controller\ClickController::class),

    // Ustawienia rozszerzenia
    (new Extend\Settings())
        ->default('tryhackx-magnet-link.guest_visible', false)
        ->default('tryhackx-magnet-link.activated_only', false)
        ->default('tryhackx-magnet-link.scraper_enabled', true)
        ->default('tryhackx-magnet-link.http_only', false)
        ->default('tryhackx-magnet-link.check_all', false)
        ->default('tryhackx-magnet-link.display_type', 'average')
        ->default('tryhackx-magnet-link.tracker_timeout', 2)
        ->default('tryhackx-magnet-link.max_trackers', 0)
        ->default('tryhackx-magnet-link.click_tracking', true)
        ->default('tryhackx-magnet-link.ban_enabled', true)
        ->default('tryhackx-magnet-link.ban_time', 20)
        ->default('tryhackx-magnet-link.ban_interval', 10)
        ->default('tryhackx-magnet-link.ban_interval_count', 100)
        ->default('tryhackx-magnet-link.self_interval', 1)
        ->serializeToForum('magnetGuestVisible', 'tryhackx-magnet-link.guest_visible', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('magnetActivatedOnly', 'tryhackx-magnet-link.activated_only', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('magnetScraperEnabled', 'tryhackx-magnet-link.scraper_enabled', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('magnetClickTracking', 'tryhackx-magnet-link.click_tracking', function ($value) {
            return (bool) $value;
        }),

    // Konfiguracja formatera BBCode
    (new Extend\Formatter())
        ->configure(MagnetConfigurator::class)
        ->render(MagnetRenderer::class),
];
