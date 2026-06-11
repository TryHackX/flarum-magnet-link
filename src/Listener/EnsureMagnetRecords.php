<?php

namespace TryHackX\MagnetLink\Listener;

use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Psr\Log\LoggerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;

/**
 * Tworzy wiersze `magnet_links` w momencie ZAPISU posta (Posted/Revised),
 * a nie przy renderze/odczycie.
 *
 * Dzięki temu ścieżki odczytu są naprawdę tylko-do-odczytu:
 *   - tooltip dyskusji (DiscussionMagnetsController, żądanie GET) nie musi już
 *     wołać findOrCreateFromUri, więc znika zapis/INSERT w GET i jego wyścig na
 *     unikalnym tokenie (audyt #6);
 *   - render (MagnetRenderer) trafia na istniejący wiersz, więc findOrCreateFromUri
 *     degraduje się do zwykłego SELECT-a zamiast INSERT-a w pętli renderu (#4).
 *
 * Idempotentne i bezpieczne:
 *   - findOrCreateFromUri deduplikuje po tokenie (deterministycznym z URI), więc
 *     ponowne zapisy tego samego magnetu nie tworzą duplikatów, a token pokrywa
 *     się z tym, co policzy render;
 *   - błąd pojedynczego magnetu nie może wywrócić zapisu posta — łapiemy i
 *     logujemy, a render i tak utworzy wiersz leniwie, gdyby go zabrakło.
 */
class EnsureMagnetRecords
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function handle(Posted|Revised $event): void
    {
        $post = $event->post;

        // Świeżo zapisany XML posta (jeszcze w formie <MAGNET>uri</MAGNET> —
        // podmiana na token="..." dzieje się dopiero przy renderze).
        $xml = (string) $post->getRawOriginal('content');
        if (stripos($xml, '<MAGNET') === false) {
            return;
        }

        if (! preg_match_all('/<MAGNET[^>]*>(.*?)<\/MAGNET>/is', $xml, $matches)) {
            return;
        }

        foreach ($matches[1] as $content) {
            // Usuń znaczniki BBCode <s>/<e>, zdekoduj encje, zwaliduj.
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

            try {
                MagnetLink::findOrCreateFromUri($magnetUri);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[magnet-link] ensure record failed for post ' . $post->id . ': ' . $e->getMessage()
                );
            }
        }
    }
}
