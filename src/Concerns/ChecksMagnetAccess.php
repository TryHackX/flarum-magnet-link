<?php

namespace TryHackX\MagnetLink\Concerns;

use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;

/**
 * Współdzielona bramka uprawnień do oglądania magnet linków:
 *   - gość → tylko gdy `guest_visible`,
 *   - zalogowany → (opcjonalnie) potwierdzony e-mail + permisja `viewMagnetLinks`.
 *
 * Zwraca gotową odpowiedź 403 z DOTYCHCZASOWYM kształtem JSON
 * (`{success:false, error:<typ>, message}`), na którym polega frontend
 * (m.in. `tooltip_show_permission_errors`), albo null gdy dostęp dozwolony.
 * Celowo NIE używamy polityk Flarum, bo te zwróciłyby inny format błędu.
 *
 * Klasa używająca musi mieć `$this->settings` (SettingsRepositoryInterface).
 */
trait ChecksMagnetAccess
{
    protected function magnetAccessError(User $actor): ?JsonResponse
    {
        $guestVisible = (bool) $this->settings->get('tryhackx-magnet-link.guest_visible', false);
        $activatedOnly = (bool) $this->settings->get('tryhackx-magnet-link.activated_only', false);

        if ($actor->isGuest()) {
            if (! $guestVisible) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'guest_not_allowed',
                    'message' => 'Guests are not allowed to view magnet links',
                ], 403);
            }

            return null;
        }

        if ($activatedOnly && ! $actor->is_email_confirmed) {
            return new JsonResponse([
                'success' => false,
                'error' => 'email_not_confirmed',
                'message' => 'Please confirm your email to view magnet links',
            ], 403);
        }

        if (! $actor->can('tryhackx-magnet-link.viewMagnetLinks')) {
            return new JsonResponse([
                'success' => false,
                'error' => 'permission_denied',
                'message' => 'You do not have permission to view magnet links',
            ], 403);
        }

        return null;
    }
}
