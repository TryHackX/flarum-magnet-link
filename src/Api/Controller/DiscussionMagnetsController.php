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
use TryHackX\MagnetLink\Concerns\ResolvesRouteParam;
use Psr\Log\LoggerInterface;

class DiscussionMagnetsController implements RequestHandlerInterface
{
    use ChecksMagnetAccess;
    use ResolvesRouteParam;

    /**
     * Wall-clock budget (seconds) for scraping ALL magnets shown in one tooltip.
     * Trzymany krótko, bo scrape biegnie SYNCHRONICZNIE na workerze PHP-FPM przy
     * najechaniu myszką (audyt: synchroniczny scraping wiąże workera). Zimny hover
     * zajmie workera najwyżej na tyle sekund; cache (cache_ttl) sprawia, że płaci
     * to tylko PIERWSZY hover dla danego magnetu. Pełny zestaw danych ma i tak
     * widok w temacie (InfoController — bez współdzielonego deadline, własny
     * HARD_TIME_BUDGET), więc skrócenie budżetu tooltipa nie psuje dokładności.
     */
    private const TOOLTIP_SCRAPE_BUDGET = 4.0;

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

            // Pobierz discussionId z trasy (query → atrybut → URI; patrz
            // ResolvesRouteParam).
            $discussionId = (int) ($this->resolveRouteParam($request, 'discussionId', '/\/magnet\/discussion\/(\d+)/') ?? 0);

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
            // moderację) i skanuj je za tagami MAGNET. Filtr SQL `<MAGNET` (jak w
            // MagnetReparser) odsiewa posty bez magnetu już na poziomie bazy, więc
            // nie ściągamy całej treści każdego posta w dyskusji tylko po to, by
            // odrzucić ją w PHP (audyt #2).
            // UWAGA (audyt #13): `LIKE '%<MAGNET%'` ma wiodący wildcard → nie użyje
            // indeksu B-tree na `content`. Jest jednak zawężony do JEDNEJ dyskusji
            // (`discussion_id`) + typu + widoczności, więc skan obejmuje garstkę
            // postów. Świadomy tradeoff cross-DB (FULLTEXT/MATCH byłby MySQL-only).
            $posts = Post::whereVisibleTo($actor)
                ->where('discussion_id', $discussionId)
                ->whereNotNull('content')
                ->where('type', 'comment')
                ->where('content', 'like', '%<MAGNET%')
                ->select('id', 'content')
                ->get();

            // #3: jedno zapytanie o WSZYSTKIE własne nazwy dla postów tej dyskusji
            // (zamiast N+1 w pętli). Mapa: "magnetId:postId" => custom_name.
            $customNames = [];
            $postIds = $posts->pluck('id')->all();
            if (! empty($postIds)) {
                foreach (MagnetCustomName::whereIn('post_id', $postIds)->get(['magnet_link_id', 'post_id', 'custom_name']) as $cn) {
                    $customNames[$cn->magnet_link_id . ':' . $cn->post_id] = $cn->custom_name;
                }
            }

            // Zbierz unikalne (po tokenie) referencje magnetów z obu form tagu
            // w jednym przebiegu (audyt #4 — koniec z dwoma bliźniaczymi blokami).
            $refs = $this->collectTokenRefs($posts);

            // Doładuj wszystkie wiersze magnetów JEDNYM zapytaniem zamiast
            // findByToken per token w pętli (audyt #2 — koniec z N+1).
            $tokens = array_column($refs, 'token');
            $models = empty($tokens)
                ? collect()
                : MagnetLink::whereIn('token', $tokens)->get()->keyBy('token');

            $magnets = [];
            foreach ($refs as $ref) {
                $magnetLink = $models->get($ref['token']);
                if (! $magnetLink) {
                    // Wiersza brak (np. import bez re-parse) — pomijamy; render
                    // utworzy go leniwie przy otwarciu wątku.
                    continue;
                }

                // Własna nazwa z mapy (#3 — bez N+1).
                $displayName = $customNames[$magnetLink->id . ':' . $ref['post_id']] ?? $magnetLink->name;

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

                // Scrape odłożony do czasu obcięcia listy do limitu tooltipa
                // (żeby nie scrapować magnetów, które i tak odrzucimy).
                $magnetData['_model'] = $magnetLink;

                $magnets[] = $magnetData;
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

    /**
     * Zbierz unikalne (po tokenie) referencje magnetów z postów — obie formy
     * tagu w jednym przebiegu, w kolejności wystąpienia. Wyłącznie odczyt:
     *   - <MAGNET>uri</MAGNET>  → token liczony deterministycznie (generateToken),
     *   - <MAGNET token="…"/>   → token brany wprost z atrybutu.
     *
     * Zwraca tokeny do doładowania jednym `whereIn` (bez N+1) — patrz handle().
     *
     * @param iterable<\Flarum\Post\Post> $posts
     * @return array<int, array{token: string, post_id: int}>
     */
    private function collectTokenRefs(iterable $posts): array
    {
        $seen = [];
        $refs = [];

        foreach ($posts as $post) {
            $xml = (string) $post->content;
            if (stripos($xml, '<MAGNET') === false) {
                continue;
            }
            $postId = (int) $post->id;

            // DOM-first, spójnie z MagnetRenderer (audyt #16): bezpiecznie obsługuje
            // zagnieżdżenia/encje, których ad-hoc regex mógłby nie ogarnąć. Regex
            // zostaje jako fallback, gdy XML się nie sparsuje (zachowuje dawne
            // zachowanie).
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $loaded = @$dom->loadXML('<root>' . $xml . '</root>', LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                $this->collectTokenRefsRegex($xml, $postId, $seen, $refs);
                continue;
            }

            foreach ($dom->getElementsByTagName('MAGNET') as $tag) {
                // Forma 2: <MAGNET token="…"/> — token wprost z atrybutu.
                $attrToken = $tag->getAttribute('token');
                if (preg_match('/^[a-f0-9]{64}$/i', $attrToken)) {
                    $this->addRef($attrToken, $postId, $seen, $refs);
                    continue;
                }

                // Forma 1: <MAGNET>uri</MAGNET> — policz token z URI.
                $magnetUri = trim(html_entity_decode($this->magnetTagContent($tag), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (strpos($magnetUri, 'magnet:?') !== 0) {
                    continue;
                }
                $this->addRef(MagnetLink::generateToken($magnetUri), $postId, $seen, $refs);
            }
        }

        return $refs;
    }

    /** Dodaj referencję tokena, dedupując po tokenie (kolejność wystąpienia). */
    private function addRef(string $token, int $postId, array &$seen, array &$refs): void
    {
        if (isset($seen[$token])) {
            return;
        }
        $seen[$token] = true;
        $refs[] = ['token' => $token, 'post_id' => $postId];
    }

    /**
     * Tekst tagu MAGNET z pominięciem markerów BBCode <s>/<e> — lustro
     * {@see \TryHackX\MagnetLink\Formatter\MagnetRenderer::getTagContent()}.
     */
    private function magnetTagContent(\DOMElement $tag): string
    {
        $content = '';
        foreach ($tag->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $name = strtolower($child->nodeName);
                if ($name === 's' || $name === 'e') {
                    continue;
                }
            }
            $content .= $child->textContent;
        }
        return trim($content);
    }

    /**
     * Fallback regexowy (gdy DOMDocument nie sparsuje XML-a) — dawne zachowanie:
     * obie formy tagu, z usunięciem markerów BBCode <s>/<e>.
     */
    private function collectTokenRefsRegex(string $xml, int $postId, array &$seen, array &$refs): void
    {
        // Forma 1: <MAGNET>uri</MAGNET>.
        if (preg_match_all('/<MAGNET[^>]*>(.*?)<\/MAGNET>/is', $xml, $matches)) {
            foreach ($matches[1] as $content) {
                $content = preg_replace('/<s>.*?<\/s>/is', '', $content);
                $content = preg_replace('/<e>.*?<\/e>/is', '', $content);
                $content = trim($content);
                if ($content === '') {
                    continue;
                }
                $magnetUri = trim(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (strpos($magnetUri, 'magnet:?') !== 0) {
                    continue;
                }
                $this->addRef(MagnetLink::generateToken($magnetUri), $postId, $seen, $refs);
            }
        }

        // Forma 2: <MAGNET token="…"/>.
        if (preg_match_all('/<MAGNET\s+token="([a-f0-9]{64})"[^>]*\/>/i', $xml, $tokenMatches)) {
            foreach ($tokenMatches[1] as $token) {
                $this->addRef($token, $postId, $seen, $refs);
            }
        }
    }
}
