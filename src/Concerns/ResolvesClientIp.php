<?php

namespace TryHackX\MagnetLink\Concerns;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Współdzielone wyznaczanie IP klienta dla kontrolerów API magnet-linka.
 *
 * Używamy IP wyznaczonego przez rdzeń Flarum (atrybut `ipAddress`, ustawiany
 * przez middleware ProcessIp z poszanowaniem konfiguracji zaufanych proxy),
 * z fallbackiem na REMOTE_ADDR. Wcześniejsza wersja ufała bezpośrednio
 * nagłówkom CF-Connecting-IP / X-Forwarded-For / X-Real-IP — a te są w pełni
 * spoofowalne przez klienta, co pozwalało omijać dedup kliknięć i system banów
 * (zawyżać licznik) albo wrabiać cudze IP w bana.
 */
trait ResolvesClientIp
{
    private function getClientIp(ServerRequestInterface $request): string
    {
        $ip = $request->getAttribute('ipAddress');
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}
