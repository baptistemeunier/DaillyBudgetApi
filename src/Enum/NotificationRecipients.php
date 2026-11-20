<?php

namespace App\Enum;

/**
 * Who gets a notification of a type (NotificationType::recipients()). Every recipient gets their own notification
 * (inbox, read state) and the push on their own devices, according to their own settings.
 */
enum NotificationRecipients: string
{
    // All the users: the data is shared between them
    case ALL = 'ALL';

    // The owner of the subject only (e.g. the user who gave the bank consent: the only one who can renew it)
    case OWNER = 'OWNER';
}
