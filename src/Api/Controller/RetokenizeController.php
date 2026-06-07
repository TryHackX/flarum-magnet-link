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

    public function __construct(
        protected TokenRetokenizer $retokenizer,
        protected CacheRepository $cache
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

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
