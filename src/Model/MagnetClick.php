<?php

namespace TryHackX\MagnetLink\Model;

use Flarum\Database\AbstractModel;
use Carbon\Carbon;

/**
 * @property int $id
 * @property int $magnet_link_id
 * @property string $ip_address
 * @property int|null $user_id
 * @property int|null $post_id
 * @property \Carbon\Carbon $click_time
 */
class MagnetClick extends AbstractModel
{
    protected $table = 'magnet_clicks';

    protected $fillable = ['magnet_link_id', 'ip_address', 'user_id', 'post_id', 'click_time'];

    protected $casts = [
        'magnet_link_id' => 'integer',
        'user_id' => 'integer',
        'post_id' => 'integer',
        'click_time' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Relacja do magnet linka
     */
    public function magnetLink()
    {
        return $this->belongsTo(MagnetLink::class, 'magnet_link_id');
    }

    /**
     * Sprawdź czy IP kliknęło ostatnio ten sam magnet
     */
    public static function hasRecentClick(int $magnetLinkId, string $ip, int $intervalDays): bool
    {
        return static::where('magnet_link_id', $magnetLinkId)
            ->where('ip_address', $ip)
            ->where('click_time', '>=', Carbon::now()->subDays($intervalDays))
            ->exists();
    }

    /**
     * Policz kliknięcia IP w określonym czasie
     */
    public static function countRecentClicks(string $ip, int $intervalMinutes): int
    {
        return static::where('ip_address', $ip)
            ->where('click_time', '>=', Carbon::now()->subMinutes($intervalMinutes))
            ->count();
    }

    /**
     * Usuń wpisy starsze niż $cutoff — retencja logu kliknięć (opt-in,
     * Console\PruneMagnetClicksCommand). Kasuje porcjami przez SELECT id + DELETE
     * whereIn, żeby nie blokować tabeli jednym wielkim DELETE i być przenośnym
     * (DELETE ... LIMIT nie jest wspierane wszędzie).
     *
     * Zagregowany licznik magnet_links.click_count NIE jest ruszany — sumy zostają.
     *
     * @return int Liczba usuniętych wierszy.
     */
    public static function pruneOlderThan(Carbon $cutoff, int $chunkSize = 5000): int
    {
        $total = 0;

        do {
            $ids = static::where('click_time', '<', $cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += static::whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunkSize);

        return $total;
    }
}
