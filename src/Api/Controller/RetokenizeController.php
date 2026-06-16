<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Service\TokenRetokenizer;

/**
 * Admin-only endpoint backing the "Re-secure tokens" button shown on the
 * settings page when an upgrade left magnet tokens on the old, public-salt
 * scheme. A short cache lock prevents two admins from running it at once
 * (the operation is idempotent anyway).
 */
class RetokenizeController implements RequestHandlerInterface
{
    private const LOCK_KEY = 'tryhackx-magnet-link.retokenize-lock';

    /**
     * Powyżej tylu magnetów odmawiamy synchronicznej re-tokenizacji na HTTP i
     * kierujemy na CLI — blokada cache chroni przed równoległym uruchomieniem, ale
     * NIE ogranicza czasu wykonania, więc dziesiątki tysięcy wierszy i tak zatkałyby
     * workera ponad TTL locka (audyt #2). Próg hojny dla małych/średnich for.
     */
    private const HTTP_LIMIT = 5000;

    public function __construct(
        protected TokenRetokenizer $retokenizer,
        protected CacheRepository $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // Guard skali: za dużo magnetów → CLI (idempotentne, bez limitu czasu).
        $pending = MagnetLink::count();
        if ($pending > self::HTTP_LIMIT) {
            return new JsonResponse([
                'success' => false,
                'error' => 'too_many_use_cli',
                'count' => $pending,
                'message' => "Too many magnets to re-tokenize over HTTP ({$pending}). Run on the server: php flarum magnet:retokenize",
            ], 422);
        }

        // Atomowe zajęcie blokady — jeśli ktoś już re-tokenizuje, nie ruszamy.
        if (! $this->cache->add(self::LOCK_KEY, 1, 300)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'already_running',
                'message' => 'Re-tokenization is already running.',
            ], 409);
        }

        try {
            $count = $this->retokenizer->retokenize();
        } finally {
            $this->cache->forget(self::LOCK_KEY);
        }

        return new JsonResponse([
            'success' => true,
            'count' => $count,
            'scheme' => MagnetLink::TOKEN_SCHEME,
        ]);
    }
}
