<?php

namespace App\Service;

use App\ApiResource\Bank;
use App\State\BankProvider;
use Psr\Log\LoggerInterface;

/**
 * Name of a bank from its GoCardless institution id, through the cached institution list (24 h).
 */
final readonly class BankNameResolver
{
    public function __construct(
        private BankProvider $bankProvider,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Null when the bank is unknown or the list is unavailable (GoCardless down or rate limited).
     */
    public function resolve(?string $bankId): ?string
    {
        if (null === $bankId || '' === $bankId) {
            return null;
        }

        try {
            $bank = array_find($this->bankProvider->getBanks(), static fn (Bank $bank) => $bank->getId() === $bankId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Bank list unavailable, bank name not resolved.', [
                'bank_id' => $bankId,
                'exception' => $exception,
            ]);

            return null;
        }

        return $bank?->getName();
    }
}
