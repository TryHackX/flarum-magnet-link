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
    /**
     * Plik logów do debugowania (ustaw null żeby wyłączyć)
     * Logi będą w: flarum/storage/logs/magnet_debug.log
     */
    private $logFile = null; // true = włączone, null/false = wyłączone
    
    private function getLogFile(): ?string
    {
        if ($this->logFile !== true) {
            return null;
        }
        
        // Próbuj znaleźć katalog storage Flarum
        $possiblePaths = [
            __DIR__ . '/../../../../storage/logs/magnet_debug.log',
            __DIR__ . '/../../../../../storage/logs/magnet_debug.log',
            'C:/wamp64/www/flarum/storage/logs/magnet_debug.log',
            '/tmp/magnet_debug.log',
        ];
        
        foreach ($possiblePaths as $path) {
            $dir = dirname($path);
            if (is_dir($dir) && is_writable($dir)) {
                return $path;
            }
        }
        
        return null;
    }

    public function __invoke(Renderer $renderer, $context, string $xml, ServerRequestInterface $request = null): string
    {
        $this->log("=== MagnetRenderer START ===");
        $this->log("Input XML: " . $xml);

        // Sprawdź czy w ogóle są tagi MAGNET
        if (stripos($xml, '<MAGNET') === false) {
            $this->log("No MAGNET tags found, returning unchanged");
            return $xml;
        }

        // Użyj DOMDocument dla bezpiecznego parsowania XML
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $dom->preserveWhiteSpace = true;

        // Załaduj XML (z root element żeby obsłużyć fragmenty)
        $loadResult = @$dom->loadXML('<root>' . $xml . '</root>', LIBXML_NOERROR | LIBXML_NOWARNING);
        
        if (!$loadResult) {
            $this->log("Failed to load XML, trying regex fallback");
            return $this->regexFallback($xml);
        }

        $modified = false;
        $magnetTags = $dom->getElementsByTagName('MAGNET');
        
        $this->log("Found " . $magnetTags->length . " MAGNET tags");

        // Iteruj od końca (żeby usuwanie nie psuło indeksów)
        for ($i = $magnetTags->length - 1; $i >= 0; $i--) {
            $tag = $magnetTags->item($i);
            if (!$tag) continue;

            // Pobierz zawartość tagu (magnet URI)
            $content = $this->getTagContent($tag);
            $this->log("Tag $i content: " . $content);

            // Przetwórz magnet URI na token
            $token = $this->processContent($content);
            $this->log("Tag $i token: " . $token);

            // Ustaw atrybut token
            $tag->setAttribute('token', $token);
            
            // Wyczyść zawartość tagu (zostaje tylko atrybut)
            while ($tag->firstChild) {
                $tag->removeChild($tag->firstChild);
            }

            $modified = true;
        }

        if (!$modified) {
            $this->log("No modifications made, returning unchanged");
            return $xml;
        }

        // Zapisz XML
        $newXml = $dom->saveXML($dom->documentElement);
        
        // Usuń wrapper <root> i </root>
        $newXml = preg_replace('/^<root>/', '', $newXml);
        $newXml = preg_replace('/<\/root>$/', '', $newXml);

        $this->log("Output XML: " . $newXml);
        $this->log("=== MagnetRenderer END ===");

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
                    $this->log("Skipping BBCode marker: <$nodeName>");
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

        $this->log("Processing URI: " . $magnetUri);

        // Walidacja
        if (!$this->isValidMagnetUri($magnetUri)) {
            $this->log("Invalid magnet URI");
            return 'invalid';
        }

        // Zapisz do bazy i pobierz token
        try {
            $magnetLink = MagnetLink::findOrCreateFromUri($magnetUri);
            
            if (!$magnetLink) {
                $this->log("findOrCreateFromUri returned null");
                return 'invalid';
            }
            
            if (empty($magnetLink->token)) {
                $this->log("Token is empty");
                return 'invalid';
            }

            $this->log("Success! Token: " . $magnetLink->token);
            return $magnetLink->token;
            
        } catch (\Exception $e) {
            $this->log("Exception: " . $e->getMessage());
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
            $this->log("Does not start with magnet:?");
            return false;
        }

        // Musi zawierać prawidłowy hash BitTorrent
        // 40 znaków hex lub 32 znaki base32
        if (!preg_match('/xt=urn:btih:([a-f0-9]{40}|[a-z2-7]{32})/i', $uri)) {
            $this->log("No valid btih hash found");
            return false;
        }

        return true;
    }

    /**
     * Fallback używający regex jeśli DOMDocument zawiedzie
     */
    private function regexFallback(string $xml): string
    {
        $this->log("Using regex fallback");

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

    /**
     * Logowanie do pliku (do debugowania)
     */
    private function log(string $message): void
    {
        $logFile = $this->getLogFile();
        if ($logFile === null) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents(
            $logFile,
            "[$timestamp] $message\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
