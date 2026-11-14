<?php

namespace App\Serializer;

use App\ApiResource\StatementDetail;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Outputs the categories of a StatementDetail as HAL links (see CategoryIriTrait).
 */
#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class StatementDetailNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    use CategoryIriTrait;

    private const string ALREADY_CALLED = 'STATEMENT_DETAIL_NORMALIZER_ALREADY_CALLED';

    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public function normalize($data, $format = null, array $context = []): array
    {
        if (!$data instanceof StatementDetail) {
            throw new \LogicException('object must be an instance of StatementDetail');
        }

        return $this->normalizeWithCategoryIris($data, $format, $context, self::ALREADY_CALLED, ['category' => $data->category, 'parent' => $data->parent]);
    }

    public function supportsNormalization($data, $format = null, array $context = []): bool
    {
        // Make sure we're not called twice
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof StatementDetail;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            StatementDetail::class => false,
        ];
    }
}
