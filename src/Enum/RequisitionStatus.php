<?php

namespace App\Enum;

enum RequisitionStatus: string
{
    case CREATED = 'CR';
    case GIVING_CONSENT = 'GC';
    case UNDERGOING_AUTHENTICATION = 'UA';
    case REJECTED = 'RJ';
    case SELECTING_ACCOUNTS = 'SA';
    case GRANTING_ACCESS = 'GA';
    case LINKED = 'LN';
    case EXPIRED = 'EX';
    case SUSPENDED = 'SU';

    public function description(): string
    {
        return match ($this) {
            self::CREATED => 'Requisition has been successfully created',
            self::GIVING_CONSENT => "End-user is giving consent at GoCardless's consent screen",
            self::UNDERGOING_AUTHENTICATION => 'End-user is redirected to the financial institution for authentication',
            self::REJECTED => 'Either SSN verification has failed or end-user has entered incorrect credentials',
            self::SELECTING_ACCOUNTS => 'End-user is selecting accounts',
            self::GRANTING_ACCESS => 'End-user is granting access to their account information',
            self::LINKED => 'Account has been successfully linked to requisition',
            self::EXPIRED => 'Access to accounts has expired as set in End User Agreement',
            self::SUSPENDED => 'Access to accounts has been suspended. Create a new requisition to reconnect',
        };
    }

    public function step(): ?int
    {
        return match ($this) {
            self::CREATED => 1,
            self::GIVING_CONSENT => 2,
            self::UNDERGOING_AUTHENTICATION => 3,
            self::REJECTED => 4,
            self::SELECTING_ACCOUNTS => 5,
            self::GRANTING_ACCESS => 6,
            self::LINKED => 7,
            self::EXPIRED => 8,
            self::SUSPENDED => null,
        };
    }

    public function needUserAction(): bool
    {
        return match ($this) {
            self::LINKED, self::EXPIRED, self::SUSPENDED => false,
            default => true,
        };
    }
}
