<?php

namespace App\Serializer;

use App\Entity\BudgetLine;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Outputs the categories of a BudgetLine as HAL links (see CategoryIriTrait).
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class BudgetLineNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    use CategoryIriTrait;

    private const string ALREADY_CALLED = 'BUDGET_LINE_NORMALIZER_ALREADY_CALLED';

    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public function normalize($data, $format = null, array $context = []): array
    {
        if (!$data instanceof BudgetLine) {
            throw new \LogicException('object must be an instance of BudgetLine');
        }

        return $this->normalizeWithCategoryIris($data, $format, $context, self::ALREADY_CALLED, ['category' => $data->getCategory()]);
    }

    public function supportsNormalization($data, $format = null, array $context = []): bool
    {
        // Make sure we're not called twice
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof BudgetLine;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            BudgetLine::class => false,
        ];
    }
}
