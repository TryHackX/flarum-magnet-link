<?php

namespace TryHackX\MagnetLink\Console;

use Flarum\Console\AbstractCommand;
use TryHackX\MagnetLink\Service\TokenRetokenizer;

/**
 * `php flarum magnet:retokenize`
 *
 * Re-derives every magnet token onto the current (secret per-install salt)
 * scheme. Run once after upgrading from a version that used the old, public
 * fallback salt. Preferred over the admin button for large forums because it is
 * not bound by an HTTP request timeout. Idempotent.
 */
class RetokenizeMagnetsCommand extends AbstractCommand
{
    public function __construct(protected TokenRetokenizer $retokenizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('magnet:retokenize')
            ->setDescription('Re-derive all magnet tokens onto the current secret-salt scheme (run once after upgrading).');
    }

    protected function fire(): int
    {
        if (! $this->retokenizer->isNeeded()) {
            $this->info('Tokens are already on the current scheme. Nothing to do.');

            return 0;
        }

        $this->info('Re-tokenizing magnet links…');

        $count = $this->retokenizer->retokenize(function ($magnet) {
            $this->output->writeln("  • re-tokenized magnet #{$magnet->id}");
        });

        $this->info("Done. Re-tokenized {$count} magnet link(s).");

        return 0;
    }
}
