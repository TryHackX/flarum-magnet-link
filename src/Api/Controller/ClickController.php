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
use Carbon\Carbon;

class ClickController implements RequestHandlerInterface
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

            // Sprawdź uprawnienia
            $guestVisible = (bool) $this->settings->get('tryhackx-magnet-link.guest_visible', false);
            $activatedOnly = (bool) $this->settings->get('tryhackx-magnet-link.activated_only', false);
            
            // Sprawdź gościa
            if ($actor->isGuest()) {
                if (!$guestVisible) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'guest_not_allowed',
                        'message' => 'Guests are not allowed'
                    ], 403);
                }
            } else {
                // Zalogowany użytkownik
                
                // Sprawdź aktywację email
                if ($activatedOnly && !$actor->is_email_confirmed) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'email_not_confirmed',
                        'message' => 'Please confirm your email'
                    ], 403);
                }
                
                // Sprawdź uprawnienie grupy
                if (!$actor->can('tryhackx-magnet-link.viewMagnetLinks')) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'permission_denied',
                        'message' => 'You do not have permission'
                    ], 403);
                }
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
            return new JsonResponse([
                'success' => false,
                'error' => 'server_error',
                'message' => 'An error occurred'
            ], 500);
        }
    }

    /**
     * Pobierz IP klienta uwzględniając proxy
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        
        // Sprawdź headery proxy
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($serverParams[$header])) {
                $ip = $serverParams[$header];
                // Dla X-Forwarded-For weź pierwszy IP
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }
}
