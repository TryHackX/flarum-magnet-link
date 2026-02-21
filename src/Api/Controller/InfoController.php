<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use Scrapeer\Scraper;

class InfoController implements RequestHandlerInterface
{
    protected SettingsRepositoryInterface $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        // Sprawdź uprawnienia w kolejności:
        // 1. Gość - czy dozwolony?
        // 2. Użytkownik - czy ma aktywowany email (jeśli wymagane)?
        // 3. Użytkownik - czy ma uprawnienie do przeglądania magnet linków?
        
        $guestVisible = (bool) $this->settings->get('tryhackx-magnet-link.guest_visible', false);
        $activatedOnly = (bool) $this->settings->get('tryhackx-magnet-link.activated_only', false);
        
        // Sprawdź gościa
        if ($actor->isGuest()) {
            if (!$guestVisible) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'guest_not_allowed',
                    'message' => 'Guests are not allowed to view magnet links'
                ], 403);
            }
        } else {
            // Zalogowany użytkownik
            
            // Sprawdź aktywację email
            if ($activatedOnly && !$actor->is_email_confirmed) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'email_not_confirmed',
                    'message' => 'Please confirm your email to view magnet links'
                ], 403);
            }
            
            // Sprawdź uprawnienie grupy
            if (!$actor->can('tryhackx-magnet-link.viewMagnetLinks')) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'permission_denied',
                    'message' => 'You do not have permission to view magnet links'
                ], 403);
            }
        }

        // Pobierz token z URL - różne metody
        $token = $request->getAttribute('token');
        
        // Fallback: spróbuj pobrać z query params
        if (empty($token)) {
            $queryParams = $request->getQueryParams();
            $token = $queryParams['token'] ?? null;
        }
        
        // Fallback: spróbuj wyciągnąć z URI
        if (empty($token)) {
            $path = $request->getUri()->getPath();
            if (preg_match('/\/magnet\/info\/([a-f0-9]{64})$/i', $path, $matches)) {
                $token = $matches[1];
            }
        }
        
        if (empty($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'invalid_token',
                'message' => 'Invalid token format'
            ], 400);
        }

        // Znajdź magnet link po tokenie
        $magnetLink = MagnetLink::findByToken($token);
        
        if (!$magnetLink) {
            return new JsonResponse([
                'success' => false,
                'error' => 'not_found',
                'message' => 'Magnet link not found'
            ], 404);
        }

        // Przygotuj odpowiedź (BEZ magnet URI!)
        $response = [
            'success' => true,
            'token' => $magnetLink->token,
            'name' => $magnetLink->name,
            'info_hash' => $magnetLink->info_hash,
            'click_count' => $magnetLink->click_count,
        ];
        
        // Dodaj rozmiar pliku jeśli dostępny
        $fileSize = $magnetLink->getFileSize();
        if ($fileSize !== null) {
            $response['file_size'] = $fileSize;
            $response['file_size_formatted'] = $magnetLink->getFormattedFileSize();
        }

        // Scrapuj trackery jeśli włączone
        $scraperEnabled = (bool) $this->settings->get('tryhackx-magnet-link.scraper_enabled', true);
        
        if ($scraperEnabled) {
            $trackers = $magnetLink->getTrackers();
            
            if (!empty($trackers)) {
                $httpOnly = (bool) $this->settings->get('tryhackx-magnet-link.http_only', false);
                $timeout = (int) $this->settings->get('tryhackx-magnet-link.tracker_timeout', 2);
                $maxTrackers = (int) $this->settings->get('tryhackx-magnet-link.max_trackers', 0);

                // Filtruj trackery HTTP jeśli włączone
                if ($httpOnly) {
                    $trackers = array_filter($trackers, function ($tracker) {
                        return preg_match('/^https?:\/\//i', $tracker);
                    });
                    $trackers = array_values($trackers);
                }

                if (!empty($trackers)) {
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
                            
                            $response['scrape'] = [
                                'success' => true,
                                'seeders' => $data['seeders'] ?? 0,
                                'leechers' => $data['leechers'] ?? 0,
                                'completed' => $data['completed'] ?? 0,
                            ];
                        } else {
                            $response['scrape'] = [
                                'success' => false,
                                'error_type' => 'no_response',
                                'message' => 'No tracker responded'
                            ];
                        }
                    } catch (\Exception $e) {
                        $response['scrape'] = [
                            'success' => false,
                            'error_type' => 'scraper_error',
                            'message' => 'Failed to contact trackers'
                        ];
                    }
                } else {
                    $response['scrape'] = [
                        'success' => false,
                        'error_type' => 'no_http_trackers',
                        'message' => 'No HTTP(S) trackers available'
                    ];
                }
            } else {
                $response['scrape'] = [
                    'success' => false,
                    'error_type' => 'no_trackers',
                    'message' => 'Magnet link contains no trackers'
                ];
            }
        } else {
            $response['scrape'] = null;
        }

        return new JsonResponse($response);
    }
}
