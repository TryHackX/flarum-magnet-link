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
}
