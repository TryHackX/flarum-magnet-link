<?php

namespace TryHackX\MagnetLink\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TryHackX\MagnetLink\Model\MagnetLink;
use TryHackX\MagnetLink\Model\MagnetCustomName;

class RenameController implements RequestHandlerInterface
{
    protected SettingsRepositoryInterface $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $actor = RequestUtil::getActor($request);

            // Musi być zalogowany
            if ($actor->isGuest()) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'guest_not_allowed',
                    'message' => 'You must be logged in'
                ], 403);
            }

            // Sprawdź czy funkcja jest włączona
            $renameEnabled = (bool) $this->settings->get('tryhackx-magnet-link.rename_enabled', true);
            if (!$renameEnabled) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'feature_disabled',
                    'message' => 'Custom names are disabled'
                ], 403);
            }

            $body = $request->getParsedBody();
            $token = $body['token'] ?? '';
            $postId = isset($body['post_id']) ? (int) $body['post_id'] : null;

            // Walidacja tokena
            if (empty($token) || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'invalid_token',
                    'message' => 'Invalid or missing token'
                ], 400);
            }

            if (!$postId) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'post_id_required',
                    'message' => 'Post ID is required'
                ], 400);
            }

            // Znajdź magnet link
            $magnetLink = MagnetLink::findByToken($token);
            if (!$magnetLink) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'not_found',
                    'message' => 'Magnet link not found'
                ], 404);
            }

            // Sprawdź czy post istnieje i czy użytkownik jest autorem
            $post = Post::find($postId);
            if (!$post || $post->user_id !== $actor->id) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'not_author',
                    'message' => 'You are not the author of this post'
                ], 403);
            }

            $method = $request->getMethod();

            if ($method === 'DELETE') {
                // Przywróć oryginalną nazwę
                MagnetCustomName::where('magnet_link_id', $magnetLink->id)
                    ->where('post_id', $postId)
                    ->delete();

                return new JsonResponse([
                    'success' => true,
                    'name' => $magnetLink->name,
                ]);
            }

            // POST - ustaw niestandardową nazwę
            $customName = trim($body['custom_name'] ?? '');
            if (empty($customName) || strlen($customName) > 500) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'invalid_name',
                    'message' => 'Name must be between 1 and 500 characters'
                ], 400);
            }

            $record = MagnetCustomName::firstOrNew([
                'magnet_link_id' => $magnetLink->id,
                'post_id' => $postId,
            ]);
            $record->user_id = $actor->id;
            $record->custom_name = $customName;
            $record->save();

            return new JsonResponse([
                'success' => true,
                'custom_name' => $customName,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'server_error',
                'message' => 'An error occurred'
            ], 500);
        }
    }
}
