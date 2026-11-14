<?php

namespace App\Serializer;

use App\Entity\TransactionSplit;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Outputs the categories of a TransactionSplit as HAL links (see CategoryIriTrait).
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

        return $this->normalizeWithCategoryIris($data, $format, $context, self::ALREADY_CALLED, ['category' => $data->getCategory()]);
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
