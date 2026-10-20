<?php

namespace TryHackX\MagnetLink\Support;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * A numeric setting as an int. Flarum stores a number field the admin cleared as ''
 * (and returns it instead of the default), so read that — or anything else that isn't
 * a number — as the default rather than as 0: for several settings 0 means something
 * else entirely (no cache, no cooldown, a 2-second budget, a ban on every click).
 */
final class IntSetting
{
    public static function get(SettingsRepositoryInterface $settings, string $key, int $default): int
    {
        $value = $settings->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
