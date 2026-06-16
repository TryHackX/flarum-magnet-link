<?php

namespace TryHackX\MagnetLink\Formatter;

use TryHackX\MagnetLink\Model\MagnetLink;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

/**
 * Renderer dla tagów MAGNET
 *
 * Modyfikuje XML przed renderowaniem:
 * - Znajduje tagi <MAGNET>magnet:?...</MAGNET>
 * - Zapisuje magnet URI do bazy i generuje token
 * - Zamienia na <MAGNET token="xxx"/>
 *
 * Token jest potem używany przez szablon XSL do renderowania HTML.
 */
class MagnetRenderer
{
    public function __invoke(Renderer $renderer, $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        // $context (drugi argument hooka renderera Flarum) jest celowo nieużywany —
        // przepisujemy wyłącznie XML tagów MAGNET, kontekst renderowania nie jest
        // tu potrzebny (audyt M7).

        // Sprawdź czy w ogóle są tagi MAGNET
        if (stripos($xml, '<MAGNET') === false) {
            return $xml;
        }

        // Użyj DOMDocument dla bezpiecznego parsowania XML
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        // Załaduj XML (z root element żeby obsłużyć fragmenty)
        $loadResult = @$dom->loadXML('<root>' . $xml . '</root>', LIBXML_NOERROR | LIBXML_NOWARNING);

        if (!$loadResult) {
            return $this->regexFallback($xml);
        }

        $modified = false;
        $magnetTags = $dom->getElementsByTagName('MAGNET');

        // Iteruj od końca (żeby usuwanie nie psuło indeksów)
        for ($i = $magnetTags->length - 1; $i >= 0; $i--) {
            $tag = $magnetTags->item($i);
            if (!$tag) continue;

            // Pobierz zawartość tagu (magnet URI)
            $content = $this->getTagContent($tag);

            // Przetwórz magnet URI na token
            $token = $this->processContent($content);

            // Ustaw atrybut token
            $tag->setAttribute('token', $token);

            // Wyczyść zawartość tagu (zostaje tylko atrybut)
            while ($tag->firstChild) {
                $tag->removeChild($tag->firstChild);
            }

            $modified = true;
        }

        if (!$modified) {
            return $xml;
        }

        // Zapisz XML
        $newXml = $dom->saveXML($dom->documentElement);

        // Usuń wrapper <root> i </root>
        $newXml = preg_replace('/^<root>/', '', $newXml);
        $newXml = preg_replace('/<\/root>$/', '', $newXml);

        return $newXml;
    }

    /**
     * Pobierz zawartość tagu (tekst, CDATA, lub dzieci)
     * Pomija znaczniki BBCode: <s> i <e>
     */
    private function getTagContent(\DOMElement $tag): string
    {
        $content = '';

        foreach ($tag->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $content .= $child->textContent;
            } elseif ($child->nodeType === XML_CDATA_SECTION_NODE) {
                $content .= $child->textContent;
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                // Pomijaj znaczniki BBCode <s> i <e> (start/end markers)
                $nodeName = strtolower($child->nodeName);
                if ($nodeName === 's' || $nodeName === 'e') {
                    // Pomiń - to są markery [magnet] i [/magnet]
                    continue;
                }
                // Rekurencyjnie pobierz tekst z innych elementów
                $content .= $child->textContent;
            }
        }

        return trim($content);
    }

    /**
     * Przetwórz zawartość (magnet URI) i zwróć token
     */
    private function processContent(string $content): string
    {
        // Dekoduj HTML entities jeśli są
        $magnetUri = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $magnetUri = trim($magnetUri);

        // Walidacja
        if (!$this->isValidMagnetUri($magnetUri)) {
            return 'invalid';
        }

        // Zapisz do bazy i pobierz token
        try {
            $magnetLink = MagnetLink::findOrCreateFromUri($magnetUri);

            if (!$magnetLink) {
                return 'invalid';
            }

            if (empty($magnetLink->token)) {
                return 'invalid';
            }

            return $magnetLink->token;

        } catch (\Exception $e) {
            return 'invalid';
        }
    }

    /**
     * Walidacja magnet URI
     */
    private function isValidMagnetUri(string $uri): bool
    {
        // Musi zaczynać się od magnet:?
        if (strpos($uri, 'magnet:?') !== 0) {
            return false;
        }

        // Musi zawierać prawidłowy hash BitTorrent
        // 40 znaków hex lub 32 znaki base32
        if (!preg_match('/xt=urn:btih:([a-f0-9]{40}|[a-z2-7]{32})/i', $uri)) {
            return false;
        }

        return true;
    }

    /**
     * Fallback używający regex jeśli DOMDocument zawiedzie
     */
    private function regexFallback(string $xml): string
    {
        return preg_replace_callback(
            '/<MAGNET([^>]*)>(.*?)<\/MAGNET>/is',
            function ($matches) {
                $attributes = $matches[1];
                $content = $matches[2];

                // Jeśli już ma prawidłowy token, nie zmieniaj
                if (preg_match('/token=["\']([^"\']+)["\']/', $attributes, $tokenMatch)) {
                    if (!empty($tokenMatch[1]) && $tokenMatch[1] !== 'invalid' && strlen($tokenMatch[1]) === 64) {
                        return $matches[0];
                    }
                }

                // Usuń znaczniki BBCode <s>...</s> i <e>...</e>
                $content = preg_replace('/<s>.*?<\/s>/is', '', $content);
                $content = preg_replace('/<e>.*?<\/e>/is', '', $content);
                $content = trim($content);

                $token = $this->processContent($content);
                return '<MAGNET token="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"/>';
            },
            $xml
        );
    }
}
