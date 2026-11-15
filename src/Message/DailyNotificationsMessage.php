<?php

namespace App\Message;

/**
 * Daily task (App\Scheduler\MainSchedule, 8:00 UTC): purges the old read notifications and sends
 * one UserNotificationsRequest per user, so that an error on one user does not block the others.
 */
final readonly class DailyNotificationsMessage
{
}
