<?php
namespace App\Serializer;

use App\Entity\AccountConnection;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class AccountConnectionHalNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'ACCOUNT_CONNECTION_NORMALIZER_ALREADY_CALLED';

    public function normalize($data, $format = null, array $context = []): float|int|bool|\ArrayObject|array|string|null
    {
        if(!$data instanceof AccountConnection) {
            throw new \LogicException("object must be an instance of AccountConnection");
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if($data->getExternalStatus()->needUserAction()) {
            $normalized['_links']['configure'] = [
                'href' => $data->getExternalLink()
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

        return $data instanceof AccountConnection;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            AccountConnection::class => false,
        ];
    }
}
