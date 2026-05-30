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
    public function __construct(protected MagnetReparser $reparser)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $count = $this->reparser->reparseAll();

        return new JsonResponse([
            'success' => true,
            'count' => $count,
        ]);
    }
}
