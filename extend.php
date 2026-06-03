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
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Api\Endpoint;
use Flarum\Api\Context;
use Flarum\Discussion\Discussion;
use TryHackX\MagnetLink\Api\Controller;
use TryHackX\MagnetLink\Provider\MagnetServiceProvider;
use TryHackX\MagnetLink\Formatter\MagnetConfigurator;
use TryHackX\MagnetLink\Formatter\MagnetRenderer;
use TryHackX\MagnetLink\Sort\MagnetClicksSort;
use TryHackX\MagnetLink\Search\MagnetClicksSortMutator;
use Flarum\Search\Database\DatabaseSearchDriver;
use Flarum\Discussion\Search\DiscussionSearcher;

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

    // Flaga: czy pierwszy post dyskusji zawiera magnet link. Pozwala frontendowi
    // pominąć zapytanie o tooltip dla dyskusji bez magnetów (bez dodatkowych
    // zapytań — firstPost jest i tak dołączany do listy).
    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('hasMagnetLinks')
                ->get(function (Discussion $discussion, Context $context) {
                    try {
                        $firstPost = $discussion->firstPost;
                        if (! $firstPost || $firstPost->type !== 'comment') {
                            return false;
                        }
                        $xml = $firstPost->getRawOriginal('content');
                        return is_string($xml) && stripos($xml, '<MAGNET') !== false;
                    } catch (\Throwable $e) {
                        return false;
                    }
                }),
        ])
        ->endpoint(Endpoint\Index::class, fn (Endpoint\Index $endpoint) => $endpoint->addDefaultInclude(['firstPost']))
        // Discussion-list sorts by magnet-click activity (topic-scoped — counts
        // clicks made from this discussion's own posts). Consumed by
        // tryhackx/flarum-homepage-blocks' Advanced Filters, but registered here
        // because magnet-link owns the click data; also usable directly via the API
        // (?sort=most_magnet_clicks etc.).
        ->sorts(fn () => [
            MagnetClicksSort::mode('magnetClicksTotal', 'sum')
                ->descendingAlias('most_magnet_clicks')
                ->ascendingAlias('least_magnet_clicks'),
            MagnetClicksSort::mode('magnetClicksMax', 'max')
                ->descendingAlias('most_magnet_clicks_single')
                ->ascendingAlias('least_magnet_clicks_single'),
            MagnetClicksSort::mode('magnetLastClicked', 'last')
                ->descendingAlias('recently_magnet_clicked')
                ->ascendingAlias('oldest_magnet_clicked'),
        ]),

    // Rejestracja tras API
    (new Extend\Routes('api'))
        ->get('/magnet/info/{token}', 'magnet.info', Controller\InfoController::class)
        ->post('/magnet/click', 'magnet.click', Controller\ClickController::class)
        ->post('/magnet/rename', 'magnet.rename', Controller\RenameController::class)
        ->delete('/magnet/rename', 'magnet.rename.delete', Controller\RenameController::class)
        ->get('/magnet/discussion/{discussionId}', 'magnet.discussion', Controller\DiscussionMagnetsController::class)
        ->post('/magnet/reparse', 'magnet.reparse', Controller\ReparseController::class),

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
        ->default('tryhackx-magnet-link.tooltip_enabled', true)
        ->default('tryhackx-magnet-link.tooltip_max_magnets', 3)
        ->default('tryhackx-magnet-link.tooltip_show_permission_errors', true)
        ->default('tryhackx-magnet-link.rename_enabled', true)
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
        })
        ->serializeToForum('magnetTooltipEnabled', 'tryhackx-magnet-link.tooltip_enabled', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('magnetTooltipShowPermissionErrors', 'tryhackx-magnet-link.tooltip_show_permission_errors', function ($value) {
            return (bool) $value;
        })
        ->serializeToForum('magnetRenameEnabled', 'tryhackx-magnet-link.rename_enabled', function ($value) {
            return (bool) $value;
        }),

    // Discussion-list ordering for the magnet-click sorts. Flarum lists
    // discussions through the database Search, which orders by column name;
    // this mutator swaps in the topic-scoped click sub-query for our virtual
    // sort fields (see MagnetClicksSortMutator). Coexists with other
    // SearchDriver extenders (e.g. homepage-blocks' filters).
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addMutator(DiscussionSearcher::class, MagnetClicksSortMutator::class),

    // Konfiguracja formatera BBCode
    (new Extend\Formatter())
        ->configure(MagnetConfigurator::class)
        ->render(MagnetRenderer::class),

    // Komenda CLI: przelicza stare posty z magnet linkami sprzed instalacji
    (new Extend\Console())
        ->command(\TryHackX\MagnetLink\Console\ReparseMagnetsCommand::class),
];
