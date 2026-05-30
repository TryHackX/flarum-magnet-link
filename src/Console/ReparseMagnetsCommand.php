<?php

namespace TryHackX\MagnetLink\Console;

use Flarum\Console\AbstractCommand;
use TryHackX\MagnetLink\Service\MagnetReparser;

/**
 * `php flarum magnet:reparse`
 *
 * Backfills posts that contain magnet links saved before the extension was
 * enabled. Preferred over the admin button for large forums because it is not
 * bound by an HTTP request timeout.
 */
class ReparseMagnetsCommand extends AbstractCommand
{
    public function __construct(protected MagnetReparser $reparser)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('magnet:reparse')
            ->setDescription('Re-parse posts whose magnet links were saved before the Magnet Link extension was enabled.');
    }

    protected function fire(): int
    {
        $this->info('Scanning for posts with unprocessed magnet links…');

        $count = $this->reparser->reparseAll(function ($post) {
            $this->output->writeln("  • re-parsed post #{$post->id} (discussion #{$post->discussion_id})");
        });

        $this->info("Done. Re-parsed {$count} post(s).");

        return 0;
    }
}
