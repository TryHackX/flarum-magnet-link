<?php

namespace TryHackX\MagnetLink\Model;

use Flarum\Database\AbstractModel;
use Flarum\Settings\SettingsRepositoryInterface;
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
     * Wersja schematu tokenów (token = sha256(magnetUri + salt)).
     *
     * < 2 (legacy): salt = config('app.key') z PUBLICZNYM fallbackiem
     * 'flarum-magnet-salt'. Flarum nie ustawia app.key, więc na praktycznie
     * każdej instalacji salt był stały i publiczny — a token jest widoczny w
     * HTML (data-token), więc dało się offline zbudować tablicę token→URI dla
     * znanych torrentów i odzyskać magnet URI BEZ uprawnień.
     *
     * >= 2: salt = losowy, per-instalacja, sekret (ustawienie `token_salt`), więc
     * token nic nie zdradza. Migracja istniejących tokenów: Service\TokenRetokenizer
     * (komenda `magnet:retokenize` + przycisk w panelu).
     */
    public const TOKEN_SCHEME = 2;

    /**
     * Wyprowadź token dla magnet URI zgodnie z aktualnym schematem.
     *
     * Deterministyczny dla tego samego URI (dedup + stabilny identyfikator w API),
     * ale od schematu 2 nieodwracalny bez sekretnej soli.
     */
    public static function generateToken(string $magnetUri): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $scheme = (int) $settings->get('tryhackx-magnet-link.token_scheme', 1);

        if ($scheme >= self::TOKEN_SCHEME) {
            $salt = (string) $settings->get('tryhackx-magnet-link.token_salt', '');
            if ($salt === '') {
                // Bezpieczny schemat, ale soli brak — np. ktoś zresetował
                // ustawienia rozszerzenia i wymazał `token_salt`. NIE wolno
                // cofać się do publicznej, znanej stałej ('flarum-magnet-salt'):
                // to po cichu uczyniłoby NOWE tokeny odwracalnymi (każdy mógłby
                // offline policzyć token→URI dla znanego torrenta i obejść
                // uprawnienie viewMagnetLinks). Zamiast tego od razu dociągamy
                // świeżą, losową, sekretną sól i utrwalamy ją. Stare tokeny i tak
                // przepadły wraz z solą — `magnet:retokenize` je odbuduje.
                $salt = bin2hex(random_bytes(32));
                $settings->set('tryhackx-magnet-link.token_salt', $salt);
            }

            return hash('sha256', $magnetUri . $salt);
        }

        // Legacy (schemat < 2): prawdziwie stara instalacja — zachowujemy dawne
        // wyprowadzenie, aby tokeny sprzed sekretnej soli nadal się zgadzały.
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
