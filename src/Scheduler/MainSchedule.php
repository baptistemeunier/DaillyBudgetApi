<?php

namespace App\Scheduler;

use App\Message\DailyNotificationsMessage;
use App\Message\SyncAllAccountsMessage;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring tasks, at the server time (UTC). Consumed by the worker through the "scheduler_default" transport:
 * messenger:consume async scheduler_default
 */
#[AsSchedule]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
        private LockFactory $lockFactory,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::cron('0 8 * * *', new DailyNotificationsMessage()))
            // Periodic sync, then the alerts: twice a day leaves room for manual syncs in the GoCardless quota (4 / 24 h)
            ->add(RecurringMessage::cron('0 6,18 * * *', new SyncAllAccountsMessage()))
            // Survives a worker restart without running a missed tick twice
            ->stateful($this->cache)
            // After a long downtime, only the last missed run of each task is caught up
            ->processOnlyLastMissedRun(true)
            // Only one worker runs each tick if the worker is ever scaled
            ->lock($this->lockFactory->createLock('main-schedule'));
    }
}
