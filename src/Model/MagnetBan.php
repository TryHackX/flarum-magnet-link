<?php

namespace TryHackX\MagnetLink\Model;

use Flarum\Database\AbstractModel;
use Carbon\Carbon;

/**
 * @property int $id
 * @property string $ip_address
 * @property \Carbon\Carbon $ban_time
 */
class MagnetBan extends AbstractModel
{
    protected $table = 'magnet_bans';

    protected $fillable = ['ip_address', 'ban_time'];

    protected $casts = [
        'ban_time' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Sprawdź czy IP jest zbanowane
     */
    public static function isBanned(string $ip, int $banTimeMinutes): array
    {
        $ban = static::where('ip_address', $ip)->first();
        
        if (!$ban) {
            return ['banned' => false];
        }

        $banExpiry = $ban->ban_time->addMinutes($banTimeMinutes);
        
        if (Carbon::now()->gte($banExpiry)) {
            // Ban wygasł, usuń rekord
            $ban->delete();
            return ['banned' => false];
        }

        $timeLeft = (int) Carbon::now()->diffInSeconds($banExpiry);
        
        return [
            'banned' => true,
            'time_left' => $timeLeft
        ];
    }

    /**
     * Zbanuj IP
     */
    public static function banIp(string $ip): void
    {
        $ban = static::firstOrNew(['ip_address' => $ip]);
        $ban->ban_time = Carbon::now();
        $ban->save();
    }

    /**
     * Odbanuj IP
     */
    public static function unbanIp(string $ip): void
    {
        static::where('ip_address', $ip)->delete();
    }
}
