<?php

namespace TryHackX\MagnetLink\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use TryHackX\MagnetLink\Model\MagnetLink;

/**
 * Jednorazowa re-tokenizacja wszystkich magnet linków na bieżący schemat tokenu
 * (sekretny, per-instalacja salt). Idempotentna i bezpieczna do wielokrotnego
 * uruchamiania.
 *
 * Dlaczego jest bezpieczna i tania:
 *  - Treść posta przechowuje magnet URI (<MAGNET>magnet:?…</MAGNET>); token jest
 *    wyprowadzany z tego URI dopiero przy renderze. Wystarczy więc przepisać
 *    kolumnę `magnet_links.token` — żaden XML posta nie jest ruszany, a posty
 *    same wyrenderują nowy token przy następnym wyświetleniu.
 *  - `magnet_links` ma jeden wiersz na unikalny magnet (mała tabela), więc to
 *    szybkie.
 *  - Działa po dowolnej liczbie przeskoków wersji, bo przelicza token z URI
 *    (źródła prawdy), a nie z poprzedniego tokenu.
 *
 * Krótkie okno niespójności: między przepisaniem tokenów a ustawieniem nowego
 * schematu posty mogą przez moment renderować stary token. Operacja jest szybka
 * i jednorazowa (akcja admina), a po jej zakończeniu wszystko się zgadza.
 */
class TokenRetokenizer
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    /** True, gdy zapisany schemat jest starszy niż bieżący schemat w kodzie. */
    public function isNeeded(): bool
    {
        return (int) $this->settings->get('tryhackx-magnet-link.token_scheme', 1) < MagnetLink::TOKEN_SCHEME;
    }

    /**
     * Przelicz każdy token magneta bieżącym (sekretnym) schematem.
     *
     * @param callable|null $onProgress Opcjonalny callback wywoływany per wiersz.
     * @return int Liczba faktycznie zmienionych tokenów.
     */
    public function retokenize(?callable $onProgress = null): int
    {
        $salt = (string) $this->settings->get('tryhackx-magnet-link.token_salt', '');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(32));
            $this->settings->set('tryhackx-magnet-link.token_salt', $salt);
        }

        $changed = 0;

        MagnetLink::query()->chunkById(200, function ($magnets) use (&$changed, $salt, $onProgress) {
            foreach ($magnets as $magnet) {
                $newToken = hash('sha256', $magnet->magnet_uri . $salt);
                if ($newToken !== $magnet->token) {
                    $magnet->token = $newToken;
                    $magnet->save();
                    $changed++;
                }
                if ($onProgress) {
                    $onProgress($magnet);
                }
            }
        });

        // Dopiero po przepisaniu tokenów oznacz bieżący schemat — od tej chwili
        // generateToken() (przy renderze) używa sekretnej soli, więc posty
        // renderują się ze świeżym, pasującym tokenem.
        $this->settings->set('tryhackx-magnet-link.token_scheme', (string) MagnetLink::TOKEN_SCHEME);

        return $changed;
    }
}
