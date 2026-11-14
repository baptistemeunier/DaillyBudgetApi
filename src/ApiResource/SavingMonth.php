<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Credits and debits of the savings accounts over one month. Only exposed embedded in SavingStatement.
 */
final class SavingMonth
{
    public function __construct(
        #[Groups(['saving:read'])]
        #[ApiProperty(example: '2026-11')]
        public string $month,

        #[Groups(['saving:read'])]
        public string $creditAmount = '0.00',

        /**
         * Positive.
         */
        #[Groups(['saving:read'])]
        public string $debitAmount = '0.00',
    ) {
    }
}
