<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Model\MagnetCustomName;
use TryHackX\MagnetLink\Service\TrackerScraper;
use TryHackX\MagnetLink\Service\RefreshLimiter;
use TryHackX\MagnetLink\Concerns\ResolvesClientIp;

class InfoController implements RequestHandlerInterface
{
    use ResolvesClientIp;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TrackerScraper $scraper,
        protected RefreshLimiter $refreshLimiter
    ) {
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
        
        // Sprawdź niestandardową nazwę jeśli podano post_id
        $queryParams = $request->getQueryParams();
        $postId = isset($queryParams['post_id']) ? (int) $queryParams['post_id'] : null;

        if ($postId) {
            $customName = MagnetCustomName::findForMagnetAndPost($magnetLink->id, $postId);
            if ($customName) {
                $response['custom_name'] = $customName->custom_name;
                $response['has_custom_name'] = true;
            } else {
                $response['has_custom_name'] = false;
            }
        }

        // Dodaj rozmiar pliku jeśli dostępny
        $fileSize = $magnetLink->getFileSize();
        if ($fileSize !== null) {
            $response['file_size'] = $fileSize;
            $response['file_size_formatted'] = $magnetLink->getFormattedFileSize();
        }

        // Scrapuj trackery (walidacja hostów, limity, cache i agregacja są
        // w TrackerScraper). Przycisk odświeżania wysyła ?refresh=1, co — jeśli
        // limity na to pozwolą — pomija cache i wymusza świeże odpytanie
        // (a świeży wynik trafia do współdzielonego cache, więc widzą go potem
        // wszyscy: w temacie, w tooltipie i na stronie głównej).
        $doBypass = false;
        if (!empty($queryParams['refresh'])) {
            $check = $this->refreshLimiter->tryConsume($magnetLink->info_hash, $this->getClientIp($request));
            if ($check['allowed']) {
                $doBypass = true;
            } else {
                // Limit/cooldown — nie scrapujemy ponownie, zwracamy aktualny
                // (świeży) wynik z cache + podpowiedź dla frontendu.
                $response['refresh'] = [
                    'limited' => true,
                    'reason' => $check['reason'],          // 'cooldown' | 'quota'
                    'retry_after' => $check['retry_after'],
                ];
            }
        }

        $response['scrape'] = $this->scraper->scrapeForMagnet($magnetLink, null, $doBypass);

        return new JsonResponse($response);
    }
}
