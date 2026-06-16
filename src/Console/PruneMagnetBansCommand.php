<?php

namespace TryHackX\MagnetLink\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Component\Console\Input\InputOption;
use TryHackX\MagnetLink\Model\MagnetBan;

/**
 * `php flarum magnet:prune-bans [--minutes=N] [--dry-run]`
 *
 * Kasuje WYGASŁE bany IP z tabeli `magnet_bans` (ban_time starszy niż okno bana).
 * Runtime czyści wygasłe bany leniwie (MagnetBan::isBanned) tylko dla ponownie
 * odpytanego IP, więc adresy, które już nie wracają, zostają na zawsze — na forum
 * atakowanym przez boty tabela rośnie bez końca i spowalnia sprawdzanie banów
 * (audyt #11).
 *
 * Domyślne okno = ustawienie `ban_time` (minuty, to samo, którego używa runtime);
 * --minutes nadpisuje. Świadomie OPT-IN (brak auto-harmonogramu) — wepnij w crona:
 *
 *     0 4 * * *  php /path/to/flarum magnet:prune-bans
 *
 * Kasowanie wygasłych banów jest bezpieczne: isBanned i tak traktuje je jak brak
 * bana, więc przycięcie niczego nie zmienia w zachowaniu limitera.
 */
class PruneMagnetBansCommand extends AbstractCommand
{
    public function __construct(protected SettingsRepositoryInterface $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('magnet:prune-bans')
            ->setDescription('Delete expired IP bans from magnet_bans (older than the ban window).')
            ->addOption('minutes', null, InputOption::VALUE_REQUIRED, 'Ban window in minutes; bans older than this are deleted. Defaults to the ban_time setting.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many rows would be deleted, without deleting.');
    }

    protected function fire(): int
    {
        $minutesOpt = $this->input->getOption('minutes');
        $minutes = ($minutesOpt !== null && $minutesOpt !== '')
            ? (int) $minutesOpt
            : (int) $this->settings->get('tryhackx-magnet-link.ban_time', 20);

        if ($minutes < 1) {
            $this->error('Provide a positive --minutes value (or set a positive ban_time).');

            return 1;
        }

        $cutoff = Carbon::now()->subMinutes($minutes);

        if ($this->input->getOption('dry-run')) {
            $count = MagnetBan::where('ban_time', '<', $cutoff)->count();
            $this->info("Dry run: {$count} expired ban(s) older than {$minutes} minute(s) (before {$cutoff->toDateTimeString()}) would be deleted.");

            return 0;
        }

        $this->info("Pruning expired magnet_bans older than {$minutes} minute(s) (before {$cutoff->toDateTimeString()})…");

        $deleted = MagnetBan::pruneExpired($minutes);

        $this->info("Done. Deleted {$deleted} expired ban(s).");

        return 0;
    }
}
