<?php

namespace App\ApiResource;

use App\Entity\Category;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A saving category of the user (virtual envelope: the money stays on the savings accounts).
 * Only exposed embedded in SavingStatement.
 */
final class SavingEnvelope
{
    public function __construct(
        #[Groups(['saving:read'])]
        public Category $category,

        /**
         * Net amount assigned since the beginning, up to the end of the period.
         */
        #[Groups(['saving:read'])]
        public string $amount = '0.00',

        /**
         * Net amount assigned over the period.
         */
        #[Groups(['saving:read'])]
        public string $periodAmount = '0.00',

        #[Groups(['saving:read'])]
        public ?string $targetAmount = null,

        #[Groups(['saving:read'])]
        public ?\DateTimeImmutable $targetDate = null,
    ) {
    }
}
