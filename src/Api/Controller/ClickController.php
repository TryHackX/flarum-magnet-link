<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Model\MagnetClick;
use TryHackX\MagnetLink\Model\MagnetBan;
use TryHackX\MagnetLink\Concerns\ResolvesClientIp;
use TryHackX\MagnetLink\Concerns\ChecksMagnetAccess;
use Psr\Log\LoggerInterface;
use Carbon\Carbon;

class ClickController implements RequestHandlerInterface
{
    use ResolvesClientIp;
    use ChecksMagnetAccess;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);

            // Bramka uprawnień (wspólna — patrz ChecksMagnetAccess).
            if ($error = $this->magnetAccessError($actor)) {
                return $error;
            }

            // Pobierz dane z body
            $body = $request->getParsedBody();
            $token = $body['token'] ?? '';
            $postId = isset($body['post_id']) ? (int) $body['post_id'] : null;

            // Walidacja tokena
            if (empty($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'invalid_token',
                    'message' => 'Invalid or missing token'
                ], 400);
            }

            // Znajdź magnet link
            $magnetLink = MagnetLink::findByToken($token);
            
            if (!$magnetLink) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Magnet link not found'
                ], 404);
            }

            // Pobierz IP klienta
            $clientIp = $this->getClientIp($request);

            // Sprawdź czy zliczanie jest włączone
            $clickTracking = (bool) $this->settings->get('tryhackx-magnet-link.click_tracking', true);
            
            $hasRecentClick = false;
            
            if ($clickTracking) {
                // Sprawdź bana
                $banEnabled = (bool) $this->settings->get('tryhackx-magnet-link.ban_enabled', true);
                
                if ($banEnabled) {
                    $banTime = (int) $this->settings->get('tryhackx-magnet-link.ban_time', 20);
                    $banStatus = MagnetBan::isBanned($clientIp, $banTime);
                    
                    if ($banStatus['banned']) {
                        return new JsonResponse([
                            'success' => true,
                            'magnet_uri' => $magnetLink->magnet_uri,
                            'click_count' => $magnetLink->click_count,
                            'click_recorded' => false,
                            'message' => 'Temporarily banned from click tracking',
                            'ban_time_left' => $banStatus['time_left']
                        ]);
                    }
                }

                // Sprawdź czy IP kliknęło ostatnio ten sam magnet
                $selfInterval = (int) $this->settings->get('tryhackx-magnet-link.self_interval', 1);
                $hasRecentClick = MagnetClick::hasRecentClick($magnetLink->id, $clientIp, $selfInterval);
                
                if (!$hasRecentClick) {
                    // Sprawdź spam i ewentualnie zbanuj
                    if ($banEnabled) {
                        $banInterval = (int) $this->settings->get('tryhackx-magnet-link.ban_interval', 10);
                        $banIntervalCount = (int) $this->settings->get('tryhackx-magnet-link.ban_interval_count', 100);
                        
                        $recentClicks = MagnetClick::countRecentClicks($clientIp, $banInterval);
                        
                        if ($recentClicks >= $banIntervalCount) {
                            MagnetBan::banIp($clientIp);
                            
                            return new JsonResponse([
                                'success' => true,
                                'magnet_uri' => $magnetLink->magnet_uri,
                                'click_count' => $magnetLink->click_count,
                                'click_recorded' => false,
                                'message' => 'Too many clicks, temporarily banned'
                            ]);
                        }
                    }

                    // Zapisz kliknięcie
                    $click = new MagnetClick();
                    $click->magnet_link_id = $magnetLink->id;
                    $click->ip_address = $clientIp;
                    $click->user_id = $actor->isGuest() ? null : $actor->id;
                    $click->post_id = $postId;
                    $click->click_time = Carbon::now();
                    $click->save();

                    // Zwiększ licznik
                    $magnetLink->incrementClicks();
                    $magnetLink->refresh();
                }
            }

            // Zwróć magnet link
            return new JsonResponse([
                'success' => true,
                'magnet_uri' => $magnetLink->magnet_uri,
                'click_count' => $magnetLink->click_count,
                'click_recorded' => $clickTracking && !$hasRecentClick
            ]);
            
        } catch (\Exception $e) {
            // Tu trafiają tylko NIEOCZEKIWANE błędy (DB, bugi) — awarie trackerów
            // są łapane w TrackerScraper, więc to nie zaleje logów. Logujemy, by
            // realne błędy nie znikały po cichu na produkcji.
            $this->logger->error('[magnet-link] click failed: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse([
                'success' => false,
                'error' => 'server_error',
                'message' => 'An error occurred'
            ], 500);
        }
    }
}
