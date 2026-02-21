<?php

namespace TryHackX\MagnetLink\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $token
 * @property string $info_hash
 * @property string $magnet_uri
 * @property string|null $name
 * @property int $click_count
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class MagnetLink extends AbstractModel
{
    protected $table = 'magnet_links';

    protected $fillable = ['token', 'info_hash', 'magnet_uri', 'name', 'click_count'];

    protected $casts = [
        'click_count' => 'integer',
    ];

    public $timestamps = true;

    /**
     * Generuj unikalny token dla magnet linka
     */
    public static function generateToken(string $magnetUri): string
    {
        // Używamy SHA256 z solą aby token był unikalny
        // ale deterministyczny dla tego samego magnet linka
        return hash('sha256', $magnetUri . config('app.key', 'flarum-magnet-salt'));
    }

    /**
     * Znajdź lub utwórz magnet link
     */
    public static function findOrCreateFromUri(string $magnetUri): ?self
    {
        // Wyodrębnij info_hash z magnet URI
        if (!preg_match('/btih:([a-f0-9]{40}|[a-z2-7]{32})/i', $magnetUri, $matches)) {
            return null;
        }

        $infoHash = strtoupper($matches[1]);
        
        // Konwertuj base32 na hex jeśli potrzebne
        if (strlen($infoHash) === 32) {
            $infoHash = self::base32ToHex($infoHash);
        }

        // Wyodrębnij nazwę
        $name = null;
        if (preg_match('/[&?]dn=([^&]+)/i', $magnetUri, $nameMatch)) {
            $encoded = $nameMatch[1];
            // Najpierw zamień + na spację (URL encoding style)
            $name = str_replace('+', ' ', $encoded);
            // Potem zdekoduj pozostałe znaki (%XX)
            $name = rawurldecode($name);
            // Zamień tylko podkreślenia i kropki na spacje (NIE myślniki!)
            $name = preg_replace('/[_.]+/', ' ', $name);
            // Usuń wielokrotne spacje
            $name = trim(preg_replace('/\s+/', ' ', $name));
            // Ogranicz długość
            if (strlen($name) > 500) {
                $name = substr($name, 0, 497) . '...';
            }
        }

        $token = self::generateToken($magnetUri);

        // Sprawdź czy już istnieje
        $existing = static::where('token', $token)->first();
        if ($existing) {
            return $existing;
        }

        // Utwórz nowy
        $model = new static();
        $model->token = $token;
        $model->info_hash = $infoHash;
        $model->magnet_uri = $magnetUri;
        $model->name = $name ?? $infoHash;
        $model->click_count = 0;
        $model->save();

        return $model;
    }

    /**
     * Znajdź po tokenie
     */
    public static function findByToken(string $token): ?self
    {
        return static::where('token', $token)->first();
    }

    /**
     * Znajdź po info_hash (zwraca pierwszy pasujący)
     */
    public static function findByInfoHash(string $infoHash): ?self
    {
        return static::where('info_hash', strtoupper($infoHash))->first();
    }

    /**
     * Zwiększ licznik kliknięć
     */
    public function incrementClicks(): void
    {
        $this->increment('click_count');
    }

    /**
     * Wyodrębnij trackery z magnet URI
     */
    public function getTrackers(): array
    {
        $trackers = [];
        if (preg_match_all('/[&?]tr=([^&]+)/i', $this->magnet_uri, $matches)) {
            foreach ($matches[1] as $tracker) {
                $trackers[] = urldecode($tracker);
            }
        }
        return $trackers;
    }

    /**
     * Wyodrębnij rozmiar pliku z magnet URI (parametr xl=)
     * @return int|null Rozmiar w bajtach lub null jeśli brak
     */
    public function getFileSize(): ?int
    {
        if (preg_match('/[&?]xl=(\d+)/i', $this->magnet_uri, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /**
     * Formatuj rozmiar pliku na czytelny string
     */
    public function getFormattedFileSize(): ?string
    {
        $size = $this->getFileSize();
        if ($size === null) {
            return null;
        }
        
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $i = 0;
        $floatSize = (float) $size;
        
        while ($floatSize >= 1024 && $i < count($units) - 1) {
            $floatSize /= 1024;
            $i++;
        }
        
        return round($floatSize, 2) . ' ' . $units[$i];
    }

    /**
     * Relacja do kliknięć
     */
    public function clicks()
    {
        return $this->hasMany(MagnetClick::class, 'magnet_link_id');
    }

    /**
     * Konwersja base32 na hex
     */
    private static function base32ToHex(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper($base32);
        
        $binary = '';
        foreach (str_split($base32) as $char) {
            $index = strpos($alphabet, $char);
            if ($index !== false) {
                $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $hex = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $hex .= str_pad(dechex(bindec($byte)), 2, '0', STR_PAD_LEFT);
            }
        }

        return strtoupper($hex);
    }
}
