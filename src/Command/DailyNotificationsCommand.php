<?php

namespace App\Command;

use App\Message\DailyNotificationsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs the daily notification task now, without waiting for the scheduler (8:00 UTC).
 * The purge runs here; the per-user notifications go to the "async" transport (run the worker to create them).
 * Harmless to run several times: notifications are deduplicated.
 */
#[AsCommand(
    name: 'app:notifications:daily',
    description: 'Run the daily notification task now (purge + per-user time-based notifications)',
)]
final readonly class DailyNotificationsCommand
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->bus->dispatch(new DailyNotificationsMessage());

        $io->success('Daily notifications dispatched. Run "messenger:consume async" to create them.');

        return Command::SUCCESS;
    }
}
