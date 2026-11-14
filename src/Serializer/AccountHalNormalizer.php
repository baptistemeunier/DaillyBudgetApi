<?php
namespace App\Serializer;

use App\Entity\Account;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AutoconfigureTag('serializer.normalizer', ['priority' => 64])]
class AccountHalNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'ACCOUNT_NORMALIZER_ALREADY_CALLED';

    public function __construct(private UrlGeneratorInterface $router)
    {

    }

    public function normalize($data, $format = null, array $context = []): float|int|bool|\ArrayObject|array|string|null
    {
        if(!$data instanceof Account) {
            throw new \LogicException("object must be an instance of AccountConnection");
        }

        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if($data->getAccountConnection()) {
            $normalized['_links']['account-connection'] = [
                'href' => $this->router->generate(
                    '_api_/account_connections/{id}{._format}_get',
                    ['id' => $data->getAccountConnection()->getId()],
                )
            ];
        }

        if($data->getBankId()) {
            $normalized['_links']['bank'] = [
                'href' => $this->router->generate(
                    '_api_/banks/{id}{._format}_get',
                    ['id' => $data->getBankId()],
                )
            ];
        }

        $normalized['_links']['syncs'] = [
            'href' => $this->router->generate(
                // Custom uriTemplate: the route name has no {._format}
                '_api_/accounts/{id}/syncs_get_collection',
                ['id' => $data->getId()],
            )
        ];

        return $normalized;
    }

    public function supportsNormalization($data, $format = null, array $context = []): bool
    {
        // Make sure we're not called twice
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Account;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Account::class => false,
        ];
    }
}
