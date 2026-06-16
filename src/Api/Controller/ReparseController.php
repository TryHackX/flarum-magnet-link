<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Service\MagnetReparser;

/**
 * Admin-only endpoint that triggers the magnet backfill from the settings page.
 * Runs synchronously; for very large forums prefer `php flarum magnet:reparse`.
 */
class ReparseController implements RequestHandlerInterface
{
    /**
     * Powyżej tylu zaległych postów odmawiamy synchronicznego re-parse na HTTP i
     * kierujemy na CLI — inaczej duże fora dostają timeout/500 w połowie przebiegu
     * (audyt #1). Próg hojny: małe/średnie fora używają przycisku jak dotąd.
     */
    private const HTTP_LIMIT = 2000;

    public function __construct(protected MagnetReparser $reparser)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        // Guard skali: za dużo postów → nie ryzykuj timeoutu na wątku żądania,
        // odeślij na komendę CLI (idempotentną, bez limitu czasu wykonania).
        $pending = $this->reparser->countPending();
        if ($pending > self::HTTP_LIMIT) {
            return new JsonResponse([
                'success' => false,
                'error' => 'too_many_use_cli',
                'count' => $pending,
                'message' => "Too many posts to re-parse over HTTP ({$pending}). Run on the server: php flarum magnet:reparse",
            ], 422);
        }

        $count = $this->reparser->reparseAll();

        return new JsonResponse([
            'success' => true,
            'count' => $count,
        ]);
    }
}
