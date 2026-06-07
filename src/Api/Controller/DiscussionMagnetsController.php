<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Model\MagnetCustomName;
use TryHackX\MagnetLink\Service\TrackerScraper;
use TryHackX\MagnetLink\Concerns\ChecksMagnetAccess;
use Psr\Log\LoggerInterface;

class DiscussionMagnetsController implements RequestHandlerInterface
{
    use ChecksMagnetAccess;

    /** Wall-clock budget (seconds) for scraping all magnets shown in one tooltip. */
    private const TOOLTIP_SCRAPE_BUDGET = 8.0;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TrackerScraper $scraper,
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

            // BEZPIECZEŃSTWO: rozwiąż dyskusję w zakresie widoczności aktora.
            // Bez tego dowolny członek mógł zgadywać ID dyskusji i wyciągać
            // metadane magnetów (nazwy, info-hashe, rozmiary, liczniki, a przez
            // token też URI) z dyskusji/postów, których nie wolno mu oglądać.
            // Niewidoczną dyskusję traktujemy jak „brak magnetów" — nie zdradzamy
            // nawet jej istnienia.
            if (! Discussion::whereVisibleTo($actor)->whereKey($discussionId)->exists()) {
                return new JsonResponse([
                    'success' => true,
                    'discussion_id' => $discussionId,
                    'magnets' => [],
                ]);
            }

            // Załaduj TYLKO posty widoczne dla aktora (pomija m.in. ukryte przez
            // moderację) i skanuj je za tagami MAGNET.
            $posts = Post::whereVisibleTo($actor)
                ->where('discussion_id', $discussionId)
                ->whereNotNull('content')
                ->where('type', 'comment')
                ->select('id', 'content')
                ->get();

            $magnets = [];
            $seenTokens = [];

            // #3: jedno zapytanie o WSZYSTKIE własne nazwy dla postów tej dyskusji
            // (zamiast N+1 w pętli). Mapa: "magnetId:postId" => custom_name.
            $customNames = [];
            $postIds = $posts->pluck('id')->all();
            if (! empty($postIds)) {
                foreach (MagnetCustomName::whereIn('post_id', $postIds)->get(['magnet_link_id', 'post_id', 'custom_name']) as $cn) {
                    $customNames[$cn->magnet_link_id . ':' . $cn->post_id] = $cn->custom_name;
                }
            }

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

                        // Własna nazwa z mapy (#3 — bez N+1).
                        $displayName = $customNames[$magnetLink->id . ':' . $post->id] ?? $magnetLink->name;

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

                        // Scrape odłożony do czasu obcięcia listy do limitu
                        // tooltipa (żeby nie scrapować magnetów, które odrzucimy).
                        $magnetData['_model'] = $magnetLink;

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

                        // Własna nazwa z mapy (#3 — bez N+1).
                        $displayName = $customNames[$magnetLink->id . ':' . $post->id] ?? $magnetLink->name;

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

                        $magnetData['_model'] = $magnetLink;

                        $magnets[] = $magnetData;
                    }
                }
            }

            // Ogranicz liczbę magnetów w tooltipie PRZED scrapowaniem.
            $maxMagnets = (int) $this->settings->get('tryhackx-magnet-link.tooltip_max_magnets', 3);
            if ($maxMagnets > 0 && count($magnets) > $maxMagnets) {
                $magnets = array_slice($magnets, 0, $maxMagnets);
            }

            // Scrapuj tylko faktycznie pokazywane magnety, ze wspólnym budżetem
            // czasu na całe żądanie — żeby jedno najechanie myszką nie mogło
            // zająć workera na długo.
            $deadline = microtime(true) + self::TOOLTIP_SCRAPE_BUDGET;
            foreach ($magnets as &$magnet) {
                $magnet['scrape'] = $this->scraper->scrapeForMagnet($magnet['_model'], $deadline);
                unset($magnet['_model']);
            }
            unset($magnet);

            return new JsonResponse([
                'success' => true,
                'discussion_id' => $discussionId,
                'magnets' => $magnets,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[magnet-link] discussion magnets failed: ' . $e->getMessage(), ['exception' => $e]);

            return new JsonResponse([
                'success' => false,
                'error' => 'server_error',
                'message' => 'An error occurred'
            ], 500);
        }
    }
}
