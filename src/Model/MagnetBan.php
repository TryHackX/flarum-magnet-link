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
     * Zbanuj IP (lub odśwież czas bana istniejącego wpisu).
     *
     * Atomowy UPSERT zamiast firstOrNew()+save(): dwa równoległe żądania z tego
     * samego IP, które oba przeszły isBanned() i trafią tu jednocześnie, nie
     * wywrócą się już na unikalnym indeksie `ip_address` (INSERT ... ON DUPLICATE
     * KEY UPDATE / ON CONFLICT). Wcześniej drugi INSERT rzucał QueryException 23000,
     * który bąblował do 500 w ClickController (audyt #3).
     */
    public static function banIp(string $ip): void
    {
        static::upsert(
            [['ip_address' => $ip, 'ban_time' => Carbon::now()]],
            ['ip_address'],
            ['ban_time']
        );
    }

    /**
     * Bulk-skasuj WYGASŁE bany (ban_time starszy niż okno $banTimeMinutes) —
     * porcjami i przenośnie (SELECT id + DELETE whereIn, jak
     * {@see MagnetClick::pruneOlderThan()}; DELETE ... LIMIT nie jest wszędzie).
     *
     * isBanned() czyści wygasłe bany leniwie, ale TYLKO dla ponownie odpytanego IP;
     * adresy, które już nie wracają, zostają na zawsze. Na forum atakowanym przez
     * boty tabela rośnie bez końca i spowalnia każde sprawdzenie bana — dlatego
     * operator może ją przyciąć (Console\PruneMagnetBansCommand, audyt #11).
     *
     * @return int Liczba usuniętych wierszy.
     */
    public static function pruneExpired(int $banTimeMinutes, int $chunkSize = 5000): int
    {
        $cutoff = Carbon::now()->subMinutes(max(0, $banTimeMinutes));
        $total = 0;

        do {
            $ids = static::where('ban_time', '<', $cutoff)
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
