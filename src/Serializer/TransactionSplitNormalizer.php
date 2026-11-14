<?php

namespace App\Serializer;

use App\Entity\TransactionSplit;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Outputs the category and the saving counterpart (mirror transaction, its account) of a TransactionSplit as HAL links (see CategoryIriTrait).
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class TransactionSplitNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    use CategoryIriTrait;

    private const string ALREADY_CALLED = 'TRANSACTION_SPLIT_NORMALIZER_ALREADY_CALLED';

    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public function normalize($data, $format = null, array $context = []): array
    {
        if (!$data instanceof TransactionSplit) {
            throw new \LogicException('object must be an instance of TransactionSplit');
        }

        // Same as the categories: the counterpart is a HAL link, not an embedded transaction
        $context[AbstractNormalizer::IGNORED_ATTRIBUTES] = [...$context[AbstractNormalizer::IGNORED_ATTRIBUTES] ?? [], 'counterpartTransaction'];
        $normalized = $this->normalizeWithCategoryIris($data, $format, $context, self::ALREADY_CALLED, ['category' => $data->getCategory()]);

        // Saving split: the mirror and its manual account (the account is not stored on the split)
        $counterpart = $data->getCounterpartTransaction();
        if ($counterpart?->getId()) {
            $normalized['_links']['counterpartTransaction'] = [
                'href' => $this->router->generate('_api_/transactions/{id}{._format}_get', ['id' => $counterpart->getId()]),
            ];
            $normalized['_links']['counterpartAccount'] = [
                'href' => $this->router->generate('_api_/accounts/{id}{._format}_get', ['id' => $counterpart->getAccount()->getId()]),
            ];
        }

        return $normalized;
    }

    public function supportsNormalization($data, $format = null, array $context = []): bool
    {
        // Make sure we're not called twice
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof TransactionSplit;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            TransactionSplit::class => false,
        ];
    }
}
