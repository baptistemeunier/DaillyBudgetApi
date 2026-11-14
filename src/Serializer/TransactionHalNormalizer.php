<?php
namespace App\Serializer;

use App\Entity\Transaction;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class TransactionHalNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'TRANSACTION_NORMALIZER_ALREADY_CALLED';

    public function __construct(private UrlGeneratorInterface $router)
    {
    }

    public function normalize($data, $format = null, array $context = []): float|int|bool|\ArrayObject|array|string|null
    {
        if (!$data instanceof Transaction) {
            throw new \LogicException('object must be an instance of Transaction');
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if ($data->getAccount()) {
            $normalized['_links']['account'] = [
                'href' => $this->router->generate(
                    '_api_/accounts/{id}{._format}_get',
                    ['id' => $data->getAccount()->getId()],
                ),
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

        return $data instanceof Transaction;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Transaction::class => false,
        ];
    }
}
