<?php

namespace TryHackX\MagnetLink\Console;

use Carbon\Carbon;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;
use TryHackX\MagnetLink\Model\MagnetClick;

/**
 * `php flarum magnet:prune-clicks --days=N`
 *
 * Usuwa wpisy z tabeli `magnet_clicks` starsze niż N dni, bounding jej wzrost na
 * bardzo aktywnych forach. Świadomie OPT-IN (brak domyślnego harmonogramu i brak
 * domyślnej retencji): uruchamiasz ręcznie albo z systemowego crona, np.
 *
 *     php flarum magnet:prune-clicks --days=90
 *
 * Co zostaje nietknięte:
 *   - zagregowany `magnet_links.click_count` (sumy klików per magnet) — NIE jest
 *     liczony z tej tabeli, więc po przycięciu jest dalej poprawny.
 *
 * Czego dotyczy (dlatego jest ręczne, a nie domyślnie włączone):
 *   - topic-scoped sortowania klików (`most_magnet_clicks` itd. — konsumowane
 *     przez tryhackx/flarum-homepage-blocks) liczą wprost z `magnet_clicks`, więc
 *     po przycięciu odzwierciedlą tylko zachowane okno czasu. To świadoma decyzja
 *     operatora o retencji, nie coś, co robimy za niego.
 */
class PruneMagnetClicksCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this
            ->setName('magnet:prune-clicks')
            ->setDescription('Delete magnet_clicks rows older than --days (magnet_links.click_count totals are kept).')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days; rows older than this are deleted.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report how many rows would be deleted, without deleting.');
    }

    protected function fire(): int
    {
        $days = (int) $this->input->getOption('days');
        if ($days < 1) {
            $this->error('Provide a positive --days value, e.g. --days=90.');

            return 1;
        }

        $cutoff = Carbon::now()->subDays($days);

        if ($this->input->getOption('dry-run')) {
            $count = MagnetClick::where('click_time', '<', $cutoff)->count();
            $this->info("Dry run: {$count} click row(s) older than {$days} day(s) (before {$cutoff->toDateTimeString()}) would be deleted.");

            return 0;
        }

        $this->info("Pruning magnet_clicks older than {$days} day(s) (before {$cutoff->toDateTimeString()})…");

        $deleted = MagnetClick::pruneOlderThan($cutoff);

        $this->info("Done. Deleted {$deleted} row(s). Aggregate click_count totals are unchanged.");

        return 0;
    }
}
