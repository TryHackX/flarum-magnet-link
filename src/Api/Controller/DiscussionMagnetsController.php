<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Model\MagnetCustomName;
use Scrapeer\Scraper;

class DiscussionMagnetsController implements RequestHandlerInterface
{
    protected SettingsRepositoryInterface $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);

            // Sprawdź uprawnienia (identycznie jak InfoController)
            $guestVisible = (bool) $this->settings->get('tryhackx-magnet-link.guest_visible', false);
            $activatedOnly = (bool) $this->settings->get('tryhackx-magnet-link.activated_only', false);

            if ($actor->isGuest()) {
                if (!$guestVisible) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'guest_not_allowed',
                        'message' => 'Guests are not allowed to view magnet links'
                    ], 403);
                }
            } else {
                if ($activatedOnly && !$actor->is_email_confirmed) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'email_not_confirmed',
                        'message' => 'Please confirm your email to view magnet links'
                    ], 403);
                }

                if (!$actor->can('tryhackx-magnet-link.viewMagnetLinks')) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'permission_denied',
                        'message' => 'You do not have permission to view magnet links'
                    ], 403);
                }
            }

            // Sprawdź czy tooltip jest włączony
            $tooltipEnabled = (bool) $this->settings->get('tryhackx-magnet-link.tooltip_enabled', true);
            if (!$tooltipEnabled) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'feature_disabled',
                    'message' => 'Tooltip feature is disabled'
                ], 403);
            }

            // Pobierz discussionId - w Flarum 2.x route params są mergowane do query params
            $queryParams = $request->getQueryParams();
            $discussionId = (int) ($queryParams['discussionId'] ?? 0);

            // Fallback: spróbuj z atrybutów requestu
            if (!$discussionId) {
                $discussionId = (int) $request->getAttribute('discussionId');
            }

            // Fallback: wyciągnij z URI
            if (!$discussionId) {
                $path = $request->getUri()->getPath();
                if (preg_match('/\/magnet\/discussion\/(\d+)/', $path, $matches)) {
                    $discussionId = (int) $matches[1];
                }
            }

            if (!$discussionId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'invalid_discussion',
                    'message' => 'Invalid discussion ID'
                ], 400);
            }

            // Załaduj posty dyskusji i skanuj za tagami MAGNET
            $posts = Post::where('discussion_id', $discussionId)
                ->whereNotNull('content')
                ->where('type', 'comment')
                ->select('id', 'content')
                ->get();

            $magnets = [];
            $seenTokens = [];

            foreach ($posts as $post) {
                $xml = $post->content;

                // Szukaj tagów MAGNET w przechowywanym XML
                if (stripos($xml, '<MAGNET') === false) {
                    continue;
                }

                // Wyciągnij zawartość tagów MAGNET
                if (preg_match_all('/<MAGNET[^>]*>(.*?)<\/MAGNET>/is', $xml, $matches)) {
                    foreach ($matches[1] as $content) {
                        // Usuń znaczniki BBCode <s> i <e>
                        $content = preg_replace('/<s>.*?<\/s>/is', '', $content);
                        $content = preg_replace('/<e>.*?<\/e>/is', '', $content);
                        $content = trim($content);

                        if (empty($content)) {
                            continue;
                        }

                        // Zdekoduj HTML entities
                        $magnetUri = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $magnetUri = trim($magnetUri);

                        // Walidacja magnet URI
                        if (strpos($magnetUri, 'magnet:?') !== 0) {
                            continue;
                        }

                        $magnetLink = MagnetLink::findOrCreateFromUri($magnetUri);
                        if (!$magnetLink || in_array($magnetLink->token, $seenTokens)) {
                            continue;
                        }

                        $seenTokens[] = $magnetLink->token;

                        // Sprawdź niestandardową nazwę dla tego magneta w tym poście
                        $displayName = $magnetLink->name;
                        $customName = MagnetCustomName::findForMagnetAndPost($magnetLink->id, $post->id);
                        if ($customName) {
                            $displayName = $customName->custom_name;
                        }

                        $magnetData = [
                            'token' => $magnetLink->token,
                            'name' => $displayName,
                            'info_hash' => $magnetLink->info_hash,
                            'click_count' => $magnetLink->click_count,
                        ];

                        // Rozmiar pliku
                        $fileSize = $magnetLink->getFileSize();
                        if ($fileSize !== null) {
                            $magnetData['file_size_formatted'] = $magnetLink->getFormattedFileSize();
                        }

                        // Scrape trackerów
                        $magnetData['scrape'] = $this->scrapeTrackers($magnetLink);

                        $magnets[] = $magnetData;
                    }
                }

                // Sprawdź też tagi z atrybutem token (już przetworzone)
                if (preg_match_all('/<MAGNET\s+token="([a-f0-9]{64})"[^>]*\/>/i', $xml, $tokenMatches)) {
                    foreach ($tokenMatches[1] as $token) {
                        if (in_array($token, $seenTokens)) {
                            continue;
                        }

                        $magnetLink = MagnetLink::findByToken($token);
                        if (!$magnetLink) {
                            continue;
                        }

                        $seenTokens[] = $token;

                        // Sprawdź niestandardową nazwę dla tego magneta w tym poście
                        $displayName = $magnetLink->name;
                        $customName = MagnetCustomName::findForMagnetAndPost($magnetLink->id, $post->id);
                        if ($customName) {
                            $displayName = $customName->custom_name;
                        }

                        $magnetData = [
                            'token' => $magnetLink->token,
                            'name' => $displayName,
                            'info_hash' => $magnetLink->info_hash,
                            'click_count' => $magnetLink->click_count,
                        ];

                        $fileSize = $magnetLink->getFileSize();
                        if ($fileSize !== null) {
                            $magnetData['file_size_formatted'] = $magnetLink->getFormattedFileSize();
                        }

                        $magnetData['scrape'] = $this->scrapeTrackers($magnetLink);

                        $magnets[] = $magnetData;
                    }
                }
            }

            // Ogranicz liczbę magnetów w tooltipie
            $maxMagnets = (int) $this->settings->get('tryhackx-magnet-link.tooltip_max_magnets', 3);
            if ($maxMagnets > 0 && count($magnets) > $maxMagnets) {
                $magnets = array_slice($magnets, 0, $maxMagnets);
            }

            return new JsonResponse([
                'success' => true,
                'discussion_id' => $discussionId,
                'magnets' => $magnets,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'server_error',
                'message' => 'An error occurred'
            ], 500);
        }
    }

    /**
     * Scrapuj trackery dla magneta (logika wspólna z InfoController)
     */
    private function scrapeTrackers(MagnetLink $magnetLink): ?array
    {
        $scraperEnabled = (bool) $this->settings->get('tryhackx-magnet-link.scraper_enabled', true);

        if (!$scraperEnabled) {
            return null;
        }

        $trackers = $magnetLink->getTrackers();

        if (empty($trackers)) {
            return [
                'success' => false,
                'error_type' => 'no_trackers',
                'message' => 'Magnet link contains no trackers'
            ];
        }

        $httpOnly = (bool) $this->settings->get('tryhackx-magnet-link.http_only', false);
        $timeout = (int) $this->settings->get('tryhackx-magnet-link.tracker_timeout', 2);
        $maxTrackers = (int) $this->settings->get('tryhackx-magnet-link.max_trackers', 0);

        if ($httpOnly) {
            $trackers = array_filter($trackers, function ($tracker) {
                return preg_match('/^https?:\/\//i', $tracker);
            });
            $trackers = array_values($trackers);
        }

        if (empty($trackers)) {
            return [
                'success' => false,
                'error_type' => 'no_http_trackers',
                'message' => 'No HTTP(S) trackers available'
            ];
        }

        try {
            $scraper = new Scraper();
            $scrapeResult = $scraper->scrape(
                $magnetLink->info_hash,
                $trackers,
                $maxTrackers > 0 ? $maxTrackers : null,
                $timeout > 0 ? $timeout : 2,
                false
            );

            if (!empty($scrapeResult[$magnetLink->info_hash])) {
                $data = $scrapeResult[$magnetLink->info_hash];
                return [
                    'success' => true,
                    'seeders' => $data['seeders'] ?? 0,
                    'leechers' => $data['leechers'] ?? 0,
                    'completed' => $data['completed'] ?? 0,
                ];
            }

            return [
                'success' => false,
                'error_type' => 'no_response',
                'message' => 'No tracker responded'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error_type' => 'scraper_error',
                'message' => 'Failed to contact trackers'
            ];
        }
    }
}
